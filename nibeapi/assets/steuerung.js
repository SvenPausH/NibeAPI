/**
 * Steuerung - Oberfläche (v3.5.0)
 * assets/steuerung.js
 */
(function () {
    'use strict';

    // ------------------------------------------------------------------
    // Zustand
    // ------------------------------------------------------------------
    let rules = [];
    let devices = [];
    let catalog = [];          // [{deviceId, name, points:[...], error}]
    let catalogIndex = {};     // deviceId -> { apiId -> point }
    let previewByRule = {};    // regel_id -> Auswertung
    let editingId = null;
    let previewTimer = null;

    const DAY_NAMES = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];
    const OP_LABEL = { '<': '<', '<=': '≤', '=': '=', '>=': '≥', '>': '>', '!=': '≠' };

    const $ = (sel, root) => (root || document).querySelector(sel);
    const $$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));

    function esc(v) {
        return String(v ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function fmtNum(n) {
        return Number(n).toLocaleString('de-DE', { maximumFractionDigits: 3 });
    }

    async function api(action, body, query) {
        const options = body !== undefined
            ? { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body) }
            : {};
        const response = await fetch('?ajax=' + action + (query || ''), options);
        const result = await response.json();
        if (!result.success) {
            throw new Error(result.error || 'Unbekannter Fehler');
        }
        return result;
    }

    // ------------------------------------------------------------------
    // Hilfsfunktionen für Anzeige
    // ------------------------------------------------------------------
    function deviceName(id) {
        const d = devices.find(x => Number(x.deviceId) === Number(id));
        return d ? d.name : 'Device ' + id;
    }

    function point(deviceId, apiId) {
        return (catalogIndex[deviceId] || {})[apiId] || null;
    }

    function pointTitle(deviceId, apiId) {
        const p = point(deviceId, apiId);
        return p ? p.title : 'API-ID ' + apiId;
    }

    function formatDays(csv) {
        const days = csv.split(',').map(Number).sort((a, b) => a - b);
        if (days.length === 7) return 'täglich';
        // zusammenhängende Bereiche als "Mo–Fr"
        const parts = [];
        let start = days[0], prev = days[0];
        for (let i = 1; i <= days.length; i++) {
            if (days[i] === prev + 1) { prev = days[i]; continue; }
            parts.push(start === prev ? DAY_NAMES[start - 1]
                : (prev - start === 1 ? DAY_NAMES[start - 1] + ', ' + DAY_NAMES[prev - 1] : DAY_NAMES[start - 1] + '–' + DAY_NAMES[prev - 1]));
            start = prev = days[i];
        }
        return parts.join(', ');
    }

    function formatWindow(r) {
        const von = r.zeit_von.slice(0, 5), bis = r.zeit_bis.slice(0, 5);
        if (von === bis) return 'ganztägig';
        return von + '–' + bis + (von > bis ? ' (über Mitternacht)' : '');
    }

    function formatDateTime(s) {
        if (!s) return '–';
        const d = new Date(s.replace(' ', 'T'));
        return isNaN(d) ? s : d.toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
    }

    // ------------------------------------------------------------------
    // Laden
    // ------------------------------------------------------------------
    async function loadRules() {
        const result = await api('controlGetRules');
        rules = result.rules;
        devices = result.devices;
        renderStatus(result);
        renderRules();
        fillLogRuleSelect();
    }

    async function loadCatalog() {
        try {
            const result = await api('controlCatalog');
            catalog = result.catalog;
        } catch (e) {
            catalog = [];
            showToast('Datenpunkte konnten nicht geladen werden: ' + e.message, true);
        }
        catalogIndex = {};
        catalog.forEach(dev => {
            catalogIndex[dev.deviceId] = {};
            dev.points.forEach(p => { catalogIndex[dev.deviceId][p.id] = p; });
        });
        renderRules(); // Titel nachtragen
    }

    async function runPreview() {
        const btn = $('#btnPreview');
        btn.disabled = true;
        try {
            const result = await api('controlPreview');
            previewByRule = {};
            result.results.forEach(r => { previewByRule[r.regel_id] = r; });
            $('#previewInfo').textContent = 'Live-Stand von ' + new Date().toLocaleTimeString('de-DE') + ' – es wurde nichts gesendet.';
            renderRules();
        } catch (e) {
            $('#previewInfo').textContent = 'Live-Prüfung fehlgeschlagen: ' + e.message;
        } finally {
            btn.disabled = false;
        }
    }

    // ------------------------------------------------------------------
    // Status (Cronjob-Lebenszeichen)
    // ------------------------------------------------------------------
    function renderStatus(info) {
        const el = $('#ctlStatus');
        let cls = 'ok', text;
        if (!info.enabled) {
            cls = 'warn';
            text = '⏸ Die Steuerung ist in der config.php deaktiviert (CONTROL_ENABLED = false). Der Cronjob sendet nichts.';
        } else if (!info.lastRun) {
            cls = 'warn';
            text = '⚠ Der Cronjob <code>control-cron.php</code> hat noch nie gelaufen. Regeln werden erst ausgeführt, wenn er jede Minute aufgerufen wird (siehe „Hilfe“).';
        } else {
            const min = Math.floor((info.now - info.lastRun) / 60);
            if (min >= 3) {
                cls = 'warn';
                text = '⚠ Letzter Cronjob-Lauf vor ' + min + ' Minuten. Läuft <code>control-cron.php</code> jede Minute?';
            } else {
                text = '✓ Cronjob aktiv (letzter Lauf vor ' + (min < 1 ? 'weniger als 1 Minute' : min + ' Min.') + ').';
            }
        }
        el.className = 'ctl-status ' + cls;
        el.innerHTML = text;
    }

    // ------------------------------------------------------------------
    // Regelliste
    // ------------------------------------------------------------------
    function conditionText(c) {
        let t = esc(deviceName(c.deviceId)) + ' · ' + esc(pointTitle(c.deviceId, c.api_id)) + ' <span class="ctl-muted">(API ' + c.api_id + ')</span>'
            + ' <b>' + OP_LABEL[c.operator] + ' ' + fmtNum(c.vergleichswert) + '</b>';
        if (Number(c.hysterese) > 0) t += ' <span class="ctl-muted">(Hysterese ' + fmtNum(c.hysterese) + ')</span>';
        return t;
    }

    function actionText(a) {
        return esc(deviceName(a.deviceId)) + ' · ' + esc(pointTitle(a.deviceId, a.api_id)) + ' <span class="ctl-muted">(API ' + a.api_id + ')</span> = <b>' + fmtNum(a.wert) + '</b>';
    }

    function liveBadge(r, ev) {
        if (!r.aktiv) return '<span class="ctl-badge off">inaktiv</span>';
        if (!ev) return '';
        if (ev.gehalten) return '<span class="ctl-badge hold">⏳ ' + esc(ev.hinweis) + '</span>';
        if (ev.zweig === 'dann') return '<span class="ctl-badge then">▶ DANN aktiv</span>';
        if (ev.zweig === 'sonst') return '<span class="ctl-badge else">▶ SONST aktiv</span>';
        if (!ev.imZeitfenster) return '<span class="ctl-badge idle">⏸ außerhalb des Zeitfensters</span>';
        return '<span class="ctl-badge warn">⚠ ' + esc(ev.hinweis || 'nicht auswertbar') + '</span>';
    }

    function liveDetails(r, ev) {
        if (!ev || !r.aktiv) return '';
        let html = '<div class="ctl-live">';
        ev.bedingungen.forEach(c => {
            const icon = c.ergebnis === null ? '❔' : (c.ergebnis ? '✔' : '✘');
            html += '<div class="ctl-live-line ' + (c.ergebnis === null ? 'unk' : (c.ergebnis ? 'yes' : 'no')) + '">'
                + icon + ' ' + (c.verknuepfung ? '<span class="ctl-muted">' + c.verknuepfung + '</span> ' : '')
                + esc(c.titel || 'API-ID ' + c.api_id) + ': <b>' + esc(c.anzeige ?? 'nicht lesbar') + '</b> '
                + OP_LABEL[c.operator] + ' ' + fmtNum(c.vergleichswert)
                + (c.fehler ? ' <span class="ctl-err">' + esc(c.fehler) + '</span>' : '') + '</div>';
        });
        ev.aktionen.forEach(a => {
            let state;
            if (a.uebersteuertVon) state = '<span class="ctl-muted">übersteuert von „' + esc(a.uebersteuertVon) + '“</span>';
            else if (a.fehler) state = '<span class="ctl-err">' + esc(a.fehler) + '</span>';
            else if (a.schreiben) state = '<b class="ctl-send">' + esc(a.anzeigeAktuell) + ' → ' + esc(a.anzeigeZiel) + '</b> (wird beim nächsten Lauf gesendet)';
            else state = 'steht bereits auf ' + esc(a.anzeigeAktuell) + ' ✓';
            html += '<div class="ctl-live-line act">➜ ' + esc(a.titel || 'API-ID ' + a.api_id) + ': ' + state + '</div>';
        });
        if (r.letzter_wechsel) {
            html += '<div class="ctl-live-line ctl-muted">Letzter Wechsel: ' + formatDateTime(r.letzter_wechsel) + '</div>';
        }
        return html + '</div>';
    }

    function renderRules() {
        const list = $('#ruleList');
        if (!rules.length) {
            list.innerHTML = '<div class="ctl-empty">Noch keine Regeln angelegt. Mit „➕ Neue Regel“ geht es los.</div>';
            return;
        }

        list.innerHTML = rules.map(r => {
            const ev = previewByRule[r.id];
            const cond = r.bedingungen.length
                ? r.bedingungen.map((c, i) => '<div>' + (i > 0 ? '<span class="ctl-link">' + c.verknuepfung + '</span> ' : '') + conditionText(c) + '</div>').join('')
                : '<div class="ctl-muted">immer (keine Bedingung)</div>';
            const dann = r.dann.map(a => '<div>' + actionText(a) + '</div>').join('');
            const sonst = r.sonst.length
                ? r.sonst.map(a => '<div>' + actionText(a) + '</div>').join('')
                : '<div class="ctl-muted">–</div>';

            return `
            <div class="ctl-card ${r.aktiv ? '' : 'inactive'}" data-id="${r.id}">
                <div class="ctl-card-head">
                    <label class="ctl-switch" title="Regel aktivieren / deaktivieren">
                        <input type="checkbox" data-act="toggle" ${r.aktiv ? 'checked' : ''}>
                        <span class="ctl-slider"></span>
                    </label>
                    <div class="ctl-card-title">
                        <strong>${esc(r.name)}</strong>
                        <span class="ctl-muted">Priorität ${r.prioritaet}</span>
                        ${liveBadge(r, ev)}
                    </div>
                    <div class="ctl-card-actions">
                        <button type="button" class="ctl-icon" data-act="edit" title="Bearbeiten">✏️</button>
                        <button type="button" class="ctl-icon" data-act="copy" title="Kopieren">📄</button>
                        <button type="button" class="ctl-icon" data-act="delete" title="Löschen">🗑️</button>
                    </div>
                </div>
                ${r.beschreibung ? '<div class="ctl-desc">' + esc(r.beschreibung) + '</div>' : ''}
                <div class="ctl-card-body">
                    <div class="ctl-col"><div class="ctl-col-h">🕒 Wann</div>
                        <div>${formatDays(r.wochentage)}</div><div>${formatWindow(r)}</div>
                        <div class="ctl-muted">außerhalb: ${r.ausserhalb === 'sonst' ? 'SONST ausführen' : 'nichts tun'}${r.min_haltezeit > 0 ? ' · Haltezeit ' + r.min_haltezeit + ' Min.' : ''}</div></div>
                    <div class="ctl-col"><div class="ctl-col-h">❓ WENN</div>${cond}</div>
                    <div class="ctl-col"><div class="ctl-col-h then">✅ DANN</div>${dann}</div>
                    <div class="ctl-col"><div class="ctl-col-h else">↩️ SONST</div>${sonst}</div>
                </div>
                ${liveDetails(r, ev)}
            </div>`;
        }).join('');
    }

    async function onRuleListClick(e) {
        const act = e.target.closest('[data-act]');
        if (!act || act.type === 'checkbox') return;
        const id = Number(act.closest('.ctl-card').dataset.id);
        const rule = rules.find(r => r.id === id);

        try {
            if (act.dataset.act === 'edit') {
                openEditor(rule);
            } else if (act.dataset.act === 'copy') {
                await api('controlDuplicateRule', { id });
                await loadRules();
                showToast('Regel kopiert (die Kopie ist inaktiv)');
            } else if (act.dataset.act === 'delete') {
                if (!confirm('Regel "' + rule.name + '" wirklich löschen?')) return;
                await api('controlDeleteRule', { id });
                await loadRules();
            }
        } catch (err) {
            showToast(err.message, true);
        }
    }

    async function onRuleListChange(e) {
        const act = e.target.closest('[data-act="toggle"]');
        if (!act) return;
        const id = Number(act.closest('.ctl-card').dataset.id);
        try {
            await api('controlToggleRule', { id, active: act.checked });
            await loadRules();
            runPreview();
        } catch (err) {
            showToast(err.message, true);
            await loadRules();
        }
    }

    // ------------------------------------------------------------------
    // API-ID lesen / Geräteauswahl
    // ------------------------------------------------------------------
    function parsePointId(text) {
        const m = /^\s*(\d+)\s*$/.exec(text || '');
        return m ? Number(m[1]) : null;
    }

    function deviceSelectHtml(selected) {
        const list = devices.length ? devices : [{ deviceId: 0, name: 'Device 0' }];
        return list.map(d => '<option value="' + d.deviceId + '"' + (Number(d.deviceId) === Number(selected) ? ' selected' : '') + '>'
            + esc(d.name) + ' (Device ' + d.deviceId + ')</option>').join('');
    }

    // ------------------------------------------------------------------
    // Editor
    // ------------------------------------------------------------------
    function buildDaysUi(selected) {
        const box = $('#fDays');
        box.innerHTML = DAY_NAMES.map((n, i) =>
            '<label class="ctl-day"><input type="checkbox" value="' + (i + 1) + '"' + (selected.includes(i + 1) ? ' checked' : '') + '><span>' + n + '</span></label>'
        ).join('') + '<span class="ctl-day-presets">'
            + '<button type="button" class="ctl-link-btn" data-days="1,2,3,4,5">Mo–Fr</button>'
            + '<button type="button" class="ctl-link-btn" data-days="6,7">Wochenende</button>'
            + '<button type="button" class="ctl-link-btn" data-days="1,2,3,4,5,6,7">Alle</button></span>';
    }

    function condRowHtml(c, first) {
        return `
        <div class="ctl-row cond-row">
            ${first ? '<span class="ctl-row-label">WENN</span>'
                    : '<select class="c-link"><option value="UND"' + (c.verknuepfung === 'UND' ? ' selected' : '') + '>UND</option><option value="ODER"' + (c.verknuepfung === 'ODER' ? ' selected' : '') + '>ODER</option></select>'}
            <select class="c-dev">${deviceSelectHtml(c.deviceId)}</select>
            <input type="text" inputmode="numeric" class="c-point" placeholder="API-ID" title="API-ID des Datenpunkts (Spalte „API ID“ der Übersicht)" value="${esc(c.api_id || '')}">
            <button type="button" class="ctl-pick" title="Datenpunkt suchen (Name, API-ID, Menüpunkt)">🔍</button>
            <select class="c-op">${['<', '<=', '=', '>=', '>', '!='].map(o => '<option value="' + o + '"' + (c.operator === o ? ' selected' : '') + '>' + OP_LABEL[o] + '</option>').join('')}</select>
            <input type="text" inputmode="decimal" class="c-val" placeholder="Wert" value="${esc(c.vergleichswert ?? '')}">
            <input type="text" inputmode="decimal" class="c-hyst" placeholder="Hysterese" title="Hält die Bedingung im DANN-Zustand um diesen Betrag länger wahr" value="${esc(c.hysterese ? c.hysterese : '')}">
            <button type="button" class="ctl-del" title="Entfernen">✕</button>
            <div class="ctl-hint"></div>
        </div>`;
    }

    function actionRowHtml(a) {
        return `
        <div class="ctl-row act-row">
            <select class="a-dev">${deviceSelectHtml(a.deviceId)}</select>
            <input type="text" inputmode="numeric" class="a-point" placeholder="API-ID" title="API-ID eines schreibbaren Datenpunkts" value="${esc(a.api_id || '')}">
            <button type="button" class="ctl-pick" title="Schreibbaren Datenpunkt suchen (Name, API-ID, Menüpunkt)">🔍</button>
            <span class="ctl-eq">=</span>
            <input type="text" inputmode="decimal" class="a-val" placeholder="Wert" value="${esc(a.wert ?? '')}">
            <button type="button" class="ctl-del" title="Entfernen">✕</button>
            <div class="ctl-hint"></div>
        </div>`;
    }

    function refreshCondRows() {
        // erste Zeile hat keine UND/ODER-Auswahl
        const rows = $$('#condRows .cond-row');
        rows.forEach((row, i) => {
            const hasLink = !!$('.c-link', row);
            if ((i === 0) === hasLink) {
                const data = readCondRow(row, true);
                row.outerHTML = condRowHtml(data, i === 0);
            }
        });
        $$('#condRows .cond-row').forEach(updateHint);
    }

    function addCondRow(c) {
        const wrap = document.createElement('div');
        const first = $$('#condRows .cond-row').length === 0;
        wrap.innerHTML = condRowHtml(Object.assign({ verknuepfung: 'UND', deviceId: devices.length ? devices[0].deviceId : 0, api_id: 0, operator: '<', vergleichswert: '', hysterese: 0 }, c || {}), first);
        $('#condRows').appendChild(wrap.firstElementChild);
        updateHint(wrap.firstElementChild);
    }

    function addActionRow(zweig, a) {
        const wrap = document.createElement('div');
        wrap.innerHTML = actionRowHtml(Object.assign({ deviceId: devices.length ? devices[0].deviceId : 0, api_id: 0, wert: '' }, a || {}));
        $(zweig === 'dann' ? '#thenRows' : '#elseRows').appendChild(wrap.firstElementChild);
        updateHint(wrap.firstElementChild);
    }

    function updateHint(row) {
        if (!row) return;
        const isCond = row.classList.contains('cond-row');
        const dev = Number($(isCond ? '.c-dev' : '.a-dev', row).value);
        const raw = $(isCond ? '.c-point' : '.a-point', row).value.trim();
        const id = parsePointId(raw);
        const hint = $('.ctl-hint', row);
        hint.className = 'ctl-hint';
        hint.textContent = '';

        if (raw === '') return;
        if (id === null) {
            hint.classList.add('bad');
            hint.textContent = '⚠ Bitte nur die Zahl der API-ID eintragen (oder mit 🔍 suchen)';
            return;
        }
        const devInfo = catalog.find(d => d.deviceId === dev);
        if (devInfo && devInfo.error) {
            hint.classList.add('bad');
            hint.textContent = '⚠ Gerät nicht erreichbar – API-ID ' + id + ' kann nicht geprüft werden';
            return;
        }
        const p = point(dev, id);
        if (!p) {
            if (catalog.length) {
                hint.classList.add('bad');
                hint.textContent = '⚠ API-ID ' + id + ' gibt es auf diesem Gerät nicht';
            }
            return;
        }
        let html = '<b>' + esc(p.title) + '</b>' + (p.menu ? ' <span class="ctl-muted">[' + esc(p.menu) + ']</span>' : '')
            + ' · aktuell: ' + esc(p.display);
        if (!isCond) {
            if (!p.writable) { hint.classList.add('bad'); html += ' · ⚠ nicht schreibbar'; }
            else if (p.min !== null) html += ' · erlaubt: ' + fmtNum(p.min) + ' bis ' + fmtNum(p.max) + (p.unit ? ' ' + esc(p.unit) : '');
        }
        hint.innerHTML = html;
    }

    function readCondRow(row, lenient) {
        const dev = Number($('.c-dev', row).value);
        return {
            verknuepfung: $('.c-link', row) ? $('.c-link', row).value : 'UND',
            deviceId: dev,
            api_id: parsePointId($('.c-point', row).value) || 0,
            operator: $('.c-op', row).value,
            vergleichswert: $('.c-val', row).value,
            hysterese: $('.c-hyst', row).value
        };
    }

    function readActionRow(row) {
        return {
            deviceId: Number($('.a-dev', row).value),
            api_id: parsePointId($('.a-point', row).value) || 0,
            wert: $('.a-val', row).value
        };
    }

    function openEditor(rule) {
        editingId = rule ? rule.id : null;
        const r = rule || {
            name: '', beschreibung: '', aktiv: 1, prioritaet: 100, wochentage: '1,2,3,4,5,6,7',
            zeit_von: '00:00:00', zeit_bis: '00:00:00', ausserhalb: 'nichts', min_haltezeit: 0,
            bedingungen: [], dann: [], sonst: []
        };

        $('#ruleModalTitle').textContent = rule ? '✏️ Regel bearbeiten' : '➕ Neue Regel';
        $('#fName').value = r.name;
        $('#fBeschreibung').value = r.beschreibung || '';
        $('#fAktiv').checked = !!r.aktiv;
        $('#fPrio').value = r.prioritaet;
        $('#fVon').value = r.zeit_von.slice(0, 5);
        $('#fBis').value = r.zeit_bis.slice(0, 5);
        $('#fHalte').value = r.min_haltezeit;
        $('#fAusserhalb').value = r.ausserhalb;
        buildDaysUi(r.wochentage.split(',').map(Number));

        $('#condRows').innerHTML = '';
        $('#thenRows').innerHTML = '';
        $('#elseRows').innerHTML = '';
        r.bedingungen.forEach(c => addCondRow(c));
        r.dann.forEach(a => addActionRow('dann', a));
        r.sonst.forEach(a => addActionRow('sonst', a));
        if (!r.dann.length) addActionRow('dann');

        $('#ruleError').hidden = true;
        $('#ruleModal').classList.add('show');
        setTimeout(() => $('#fName').focus(), 50);
    }

    function closeEditor() {
        $('#ruleModal').classList.remove('show');
        editingId = null;
    }

    function collectRule() {
        return {
            name: $('#fName').value,
            beschreibung: $('#fBeschreibung').value,
            aktiv: $('#fAktiv').checked,
            prioritaet: $('#fPrio').value,
            wochentage: $$('#fDays input[type=checkbox]:checked').map(cb => Number(cb.value)),
            zeit_von: $('#fVon').value,
            zeit_bis: $('#fBis').value,
            ausserhalb: $('#fAusserhalb').value,
            min_haltezeit: $('#fHalte').value || 0,
            bedingungen: $$('#condRows .cond-row').map(r => readCondRow(r)),
            dann: $$('#thenRows .act-row').map(readActionRow),
            sonst: $$('#elseRows .act-row').map(readActionRow)
        };
    }

    async function saveRule() {
        const err = $('#ruleError');
        err.hidden = true;
        const btn = $('#btnSaveRule');
        btn.disabled = true;
        try {
            await api('controlSaveRule', { id: editingId, rule: collectRule() });
            closeEditor();
            await loadRules();
            runPreview();
            showToast('Regel gespeichert');
        } catch (e) {
            err.textContent = '⚠ ' + e.message;
            err.hidden = false;
            err.scrollIntoView({ block: 'nearest' });
        } finally {
            btn.disabled = false;
        }
    }

    function onEditorInput(e) {
        const row = e.target.closest('.ctl-row');
        if (!row) return;
        updateHint(row);
    }

    function onEditorClick(e) {
        const pick = e.target.closest('.ctl-pick');
        if (pick) {
            openPicker(pick.closest('.ctl-row'));
            return;
        }
        const del = e.target.closest('.ctl-del');
        if (del) {
            const row = del.closest('.ctl-row');
            const wasCond = row.classList.contains('cond-row');
            row.remove();
            if (wasCond) refreshCondRows();
            return;
        }
        const preset = e.target.closest('[data-days]');
        if (preset) {
            const days = preset.dataset.days.split(',');
            $$('#fDays input[type=checkbox]').forEach(cb => { cb.checked = days.includes(cb.value); });
            return;
        }
        const add = e.target.closest('[data-add-action]');
        if (add) addActionRow(add.dataset.addAction);
    }

    // ------------------------------------------------------------------
    // Datenpunkt-Suche (Name, API-ID oder Menüpunkt -> API-ID)
    // ------------------------------------------------------------------
    let pickerRow = null;
    const PICKER_MAX = 150;

    function openPicker(row) {
        pickerRow = row;
        const isCond = row.classList.contains('cond-row');
        const dev = Number($(isCond ? '.c-dev' : '.a-dev', row).value);
        const devInfo = catalog.find(d => d.deviceId === dev);
        $('#pickerTitle').textContent = (isCond ? 'Datenpunkt suchen' : 'Schreibbaren Datenpunkt suchen') + ' – ' + deviceName(dev);
        $('#pickerSearch').value = '';
        $('#pickerList').dataset.dev = dev;
        $('#pickerList').dataset.onlyWritable = isCond ? '0' : '1';
        $('#pickerError').hidden = !(devInfo && devInfo.error) && catalog.length > 0;
        $('#pickerError').textContent = !catalog.length ? 'Die Datenpunkte konnten nicht geladen werden.' : (devInfo && devInfo.error ? devInfo.error : '');
        renderPicker();
        $('#pickerModal').classList.add('show');
        setTimeout(() => $('#pickerSearch').focus(), 50);
    }

    function closePicker() {
        $('#pickerModal').classList.remove('show');
        pickerRow = null;
    }

    function pickerMatches() {
        const list = $('#pickerList');
        const dev = Number(list.dataset.dev);
        const onlyW = list.dataset.onlyWritable === '1';
        const terms = $('#pickerSearch').value.toLowerCase().split(/\s+/).filter(Boolean);
        const devInfo = catalog.find(d => d.deviceId === dev);
        return ((devInfo && devInfo.points) || []).filter(p => {
            if (onlyW && !p.writable) return false;
            const hay = (p.id + ' ' + p.title + ' ' + p.menu).toLowerCase();
            return terms.every(t => hay.includes(t));
        });
    }

    function renderPicker() {
        const matches = pickerMatches();
        const shown = matches.slice(0, PICKER_MAX);
        $('#pickerList').innerHTML = shown.length
            ? shown.map(p => '<tr data-id="' + p.id + '"><td>' + p.id + '</td><td>' + esc(p.title) + '</td><td>' + esc(p.menu) + '</td><td>' + esc(p.display) + '</td></tr>').join('')
            : '<tr><td colspan="4" class="ctl-empty">Nichts gefunden</td></tr>';
        $('#pickerCount').textContent = matches.length > PICKER_MAX
            ? matches.length + ' Treffer – angezeigt werden die ersten ' + PICKER_MAX + ', bitte Suche eingrenzen'
            : matches.length + ' Treffer';
    }

    function choosePoint(apiId) {
        if (!pickerRow) return;
        const input = $(pickerRow.classList.contains('cond-row') ? '.c-point' : '.a-point', pickerRow);
        input.value = apiId;
        const row = pickerRow;
        closePicker();
        updateHint(row);
        input.focus();
    }

    // ------------------------------------------------------------------
    // Protokoll
    // ------------------------------------------------------------------
    function fillLogRuleSelect() {
        const sel = $('#logRule');
        const current = sel.value;
        sel.innerHTML = '<option value="0">Alle</option>' + rules.map(r => '<option value="' + r.id + '">' + esc(r.name) + '</option>').join('');
        sel.value = current || '0';
    }

    async function loadLog() {
        const tbody = $('#logTable tbody');
        tbody.innerHTML = '<tr><td colspan="7" class="ctl-empty">Lade…</td></tr>';
        try {
            const q = '&limit=200&regelId=' + $('#logRule').value + ($('#logOnlyEvents').checked ? '&nurAenderungen=1' : '');
            const result = await api('controlLog', undefined, q);
            if (!result.log.length) {
                tbody.innerHTML = '<tr><td colspan="7" class="ctl-empty">Keine Einträge</td></tr>';
                return;
            }
            tbody.innerHTML = result.log.map(l => {
                const target = l.api_id !== null
                    ? esc(deviceName(l.deviceId)) + ' · ' + esc(pointTitle(l.deviceId, l.api_id)) + ' <span class="ctl-muted">(API ' + l.api_id + ')</span>' : '–';
                const val = (l.neuer_wert !== null)
                    ? (l.alter_wert !== null ? fmtNum(l.alter_wert) + ' → ' : '') + fmtNum(l.neuer_wert) : '–';
                return '<tr><td>' + esc(l.zeitstempel) + '</td><td>' + esc(l.regel_name) + '</td><td>' + esc((l.zweig || '–').toUpperCase())
                    + '</td><td>' + target + '</td><td>' + val + '</td><td><span class="ctl-badge st-' + esc(l.status) + '">' + esc(l.status) + '</span></td><td>' + esc(l.meldung) + '</td></tr>';
            }).join('');
        } catch (e) {
            tbody.innerHTML = '<tr><td colspan="7" class="ctl-empty ctl-err">' + esc(e.message) + '</td></tr>';
        }
    }

    // ------------------------------------------------------------------
    // Toast
    // ------------------------------------------------------------------
    function showToast(text, isError) {
        let t = $('#ctlToast');
        if (!t) {
            t = document.createElement('div');
            t.id = 'ctlToast';
            document.body.appendChild(t);
        }
        t.textContent = text;
        t.className = 'show' + (isError ? ' error' : '');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => { t.className = ''; }, isError ? 6000 : 2500);
    }

    // ------------------------------------------------------------------
    // Start
    // ------------------------------------------------------------------
    function switchTab(name) {
        $$('.ctl-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === name));
        $$('.ctl-section').forEach(s => { s.hidden = s.id !== 'tab-' + name; });
        if (name === 'log') loadLog();
    }

    document.addEventListener('DOMContentLoaded', async () => {
        $$('.ctl-tab').forEach(b => b.addEventListener('click', () => switchTab(b.dataset.tab)));
        $('#btnNewRule').addEventListener('click', () => openEditor(null));
        $('#btnPreview').addEventListener('click', runPreview);
        $('#btnReloadLog').addEventListener('click', loadLog);
        $('#logRule').addEventListener('change', loadLog);
        $('#logOnlyEvents').addEventListener('change', loadLog);
        $('#ruleList').addEventListener('click', onRuleListClick);
        $('#ruleList').addEventListener('change', onRuleListChange);
        $('#ruleModal').addEventListener('input', onEditorInput);
        $('#ruleModal').addEventListener('change', onEditorInput);
        $('#ruleModal').addEventListener('click', onEditorClick);
        $('#btnAddCond').addEventListener('click', () => addCondRow());
        $('#btnSaveRule').addEventListener('click', saveRule);
        $('#btnCancelRule').addEventListener('click', closeEditor);
        $('#ruleModal').addEventListener('mousedown', e => { if (e.target === $('#ruleModal')) closeEditor(); });
        $('#pickerSearch').addEventListener('input', renderPicker);
        $('#pickerSearch').addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                const first = pickerMatches()[0];
                if (first) choosePoint(first.id);
            }
        });
        $('#pickerList').addEventListener('click', e => {
            const tr = e.target.closest('tr[data-id]');
            if (tr) choosePoint(Number(tr.dataset.id));
        });
        $('#btnClosePicker').addEventListener('click', closePicker);
        $('#pickerModal').addEventListener('mousedown', e => { if (e.target === $('#pickerModal')) closePicker(); });
        document.addEventListener('keydown', e => {
            if (e.key !== 'Escape') return;
            if ($('#pickerModal').classList.contains('show')) closePicker();
            else if ($('#ruleModal').classList.contains('show')) closeEditor();
        });

        try {
            await loadRules();
        } catch (e) {
            $('#ruleList').innerHTML = '<div class="ctl-empty ctl-err">Fehler: ' + esc(e.message) + '</div>';
            return;
        }

        // Datenpunkt-Katalog und Live-Stand parallel laden (lesen live von der Anlage)
        await Promise.all([loadCatalog(), runPreview()]);

        // Live-Stand jede Minute auffrischen, solange die Seite sichtbar und kein Editor offen ist
        previewTimer = setInterval(() => {
            if (!document.hidden && !$('#ruleModal').classList.contains('show') && !$('#tab-rules').hidden) {
                runPreview();
                api('controlGetRules').then(renderStatus).catch(() => {});
            }
        }, 60000);
    });
})();
