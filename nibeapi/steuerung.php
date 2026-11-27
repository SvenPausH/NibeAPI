<?php
/**
 * Steuerung: Regeln mit Zeitfenster, Wochentagen, Bedingungen und Aktionen
 * steuerung.php v3.5.0
 *
 * Die Regeln werden hier gepflegt und vom Cronjob control-cron.php ausgeführt.
 */

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once 'config.php';
require_once 'functions.php';
require_once 'database.php';

// AJAX-Requests verarbeiten (beendet die Ausführung wenn AJAX)
require_once 'ajax-handlers.php';

if (!USE_DB) {
    die('Die Steuerung benötigt die Datenbank. Bitte USE_DB in der config.php aktivieren.');
}

require_once 'selections.php';
$installError = null;
try {
    ensureExtensionTables();
} catch (Exception $e) {
    $installError = $e->getMessage();
}

$version = '3.5.0';
$backDeviceId = isset($_GET['deviceId']) ? (int)$_GET['deviceId'] : 0;
$cronCommand = '* * * * * /usr/bin/php ' . __DIR__ . '/control-cron.php >/dev/null 2>&1';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nibe Steuerung <?php echo $version; ?></title>
    <link rel="stylesheet" href="assets/styles.css?v=<?php echo @filemtime(__DIR__ . '/assets/styles.css'); ?>">
    <link rel="stylesheet" href="assets/steuerung.css?v=<?php echo @filemtime(__DIR__ . '/assets/steuerung.css'); ?>">
</head>
<body>
<div class="container ctl-container">
    <div class="header-actions">
        <h1>⚙️ Steuerung <?php echo $version; ?></h1>
        <button class="btn-reset" onclick="window.location.href='index.php?deviceId=<?php echo $backDeviceId; ?>'">← Zurück zur Übersicht</button>
    </div>

    <?php if ($installError): ?>
    <div class="ctl-error">⚠ Datenbank-Update 3.5.0 nicht möglich: <?php echo htmlspecialchars($installError); ?></div>
    <?php endif; ?>

    <div id="ctlStatus" class="ctl-status"></div>

    <div class="ctl-tabs" role="tablist">
        <button type="button" class="ctl-tab active" data-tab="rules">📋 Regeln</button>
        <button type="button" class="ctl-tab" data-tab="log">📜 Protokoll</button>
        <button type="button" class="ctl-tab" data-tab="help">❓ Hilfe</button>
    </div>

    <!-- ===================== Regeln ===================== -->
    <section id="tab-rules" class="ctl-section">
        <div class="ctl-toolbar">
            <button type="button" class="ctl-btn ctl-btn-primary" id="btnNewRule">➕ Neue Regel</button>
            <button type="button" class="ctl-btn" id="btnPreview">🔍 Live prüfen</button>
            <span class="ctl-muted" id="previewInfo">Es wird nichts gesendet, nur gelesen und ausgewertet.</span>
        </div>
        <div id="ruleList"><div class="ctl-empty">Lade Regeln…</div></div>
    </section>

    <!-- ===================== Protokoll ===================== -->
    <section id="tab-log" class="ctl-section" hidden>
        <div class="ctl-toolbar">
            <label for="logRule">Regel:</label>
            <select id="logRule"><option value="0">Alle</option></select>
            <label class="ctl-check"><input type="checkbox" id="logOnlyEvents" checked> Zweigwechsel ausblenden</label>
            <button type="button" class="ctl-btn" id="btnReloadLog">🔄 Aktualisieren</button>
        </div>
        <div class="ctl-table-wrap">
            <table class="ctl-table" id="logTable">
                <thead><tr>
                    <th>Zeit</th><th>Regel</th><th>Zweig</th><th>Datenpunkt</th><th>Wert</th><th>Status</th><th>Meldung</th>
                </tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </section>

    <!-- ===================== Hilfe ===================== -->
    <section id="tab-help" class="ctl-section" hidden>
        <div class="ctl-help">
            <h3>So arbeitet die Steuerung</h3>
            <p>Der Cronjob <code>control-cron.php</code> läuft jede Minute und wertet alle aktiven Regeln aus. Ein Wert wird <strong>nur gesendet, wenn er vom Zielwert abweicht</strong>.</p>
            <pre><?php echo htmlspecialchars($cronCommand); ?></pre>

            <h3>Aufbau einer Regel</h3>
            <ol>
                <li><strong>Zeitfenster:</strong> Wochentage und Uhrzeit von–bis. Ist „von“ später als „bis“ (z. B. 22:00–06:00), geht das Fenster über Mitternacht; der Wochentag gilt für den Tag, an dem das Fenster beginnt. „von = bis“ bedeutet ganztägig.</li>
                <li><strong>WENN:</strong> Bedingungen auf beliebige Datenpunkte (auch anderer Geräte), angegeben über ihre <strong>API-ID</strong> mit &lt;, ≤, =, ≥, &gt;, ≠. <strong>UND bindet stärker als ODER.</strong> Ohne Bedingung gilt die Regel im ganzen Zeitfenster.</li>
                <li><strong>DANN:</strong> Datenpunkte, die gesetzt werden, wenn die Bedingung wahr ist.</li>
                <li><strong>SONST:</strong> optional, wird gesetzt, wenn die Bedingung falsch ist. Mit „Außerhalb des Zeitfensters“ kann der SONST-Zweig auch dann gelten, wenn das Zeitfenster nicht aktiv ist.</li>
            </ol>

            <h3>Damit nichts ständig hin- und herschaltet</h3>
            <ul>
                <li><strong>Hysterese</strong> (je Bedingung): Ist die Regel im DANN-Zweig, bleibt die Bedingung um diesen Betrag länger wahr. Beispiel: <code>Gradminuten &lt; −200</code> mit Hysterese 50 schaltet bei −200 ein und erst über −150 wieder aus.</li>
                <li><strong>Mindest-Haltezeit:</strong> Mindestdauer in Minuten, bevor die Regel zwischen DANN und SONST wechseln darf.</li>
                <li><strong>Erneutes Senden:</strong> Nimmt die Anlage einen Wert nicht an, wird er frühestens nach <code>CONTROL_RESEND_MINUTES</code> (config.php) erneut gesendet.</li>
            </ul>

            <h3>API-ID statt Name</h3>
            <p>Datenpunkte werden in den Regeln ausschließlich über die <strong>API-ID</strong> festgelegt (die Spalte „API ID“ der Übersicht). Sie bleibt auch nach einem Nibe-Update gleich. Der Name wird nur zur Anzeige live von der Anlage gelesen und nie gespeichert. Mit 🔍 findest du eine API-ID über Name, Zahl oder Menüpunkt.</p>

            <h3>Mehrere Regeln</h3>
            <p>Regeln werden nach <strong>Priorität</strong> (kleinere Zahl = wichtiger) ausgewertet. Zielt eine Regel auf einen Datenpunkt, den eine wichtigere Regel gerade setzt, wird sie für diesen Datenpunkt übersteuert.</p>

            <h3>Sicherheit</h3>
            <ul>
                <li>Kann ein Datenpunkt nicht gelesen werden (Gerät offline), tut die Regel <strong>nichts</strong>.</li>
                <li>Zielwerte außerhalb des erlaubten Bereichs der Anlage werden nicht gesendet.</li>
                <li>Alle Werte werden in der <strong>Anzeigeeinheit</strong> angegeben (z. B. 45,5 °C, nicht 455).</li>
                <li>Testen ohne zu senden: <code>php control-cron.php --dry-run</code></li>
            </ul>
        </div>
    </section>
</div>

<!-- ===================== Regel-Editor ===================== -->
<div class="edit-modal" id="ruleModal">
    <div class="edit-content ctl-modal-content">
        <div class="edit-header">
            <h2 id="ruleModalTitle">Regel</h2>
        </div>

        <div class="ctl-form">
            <div class="ctl-grid">
                <div class="ctl-field ctl-grow">
                    <label for="fName">Name</label>
                    <input type="text" id="fName" maxlength="100" placeholder="z. B. S135 Nachtunterstützung">
                </div>
                <div class="ctl-field">
                    <label for="fPrio" title="Kleinere Zahl = wichtiger">Priorität</label>
                    <input type="number" id="fPrio" value="100" step="1">
                </div>
                <div class="ctl-field ctl-check-field">
                    <label class="ctl-check"><input type="checkbox" id="fAktiv" checked> Regel aktiv</label>
                </div>
            </div>
            <div class="ctl-field">
                <label for="fBeschreibung">Beschreibung (optional)</label>
                <input type="text" id="fBeschreibung" maxlength="255">
            </div>

            <fieldset class="ctl-box">
                <legend>🕒 Wann</legend>
                <div class="ctl-days" id="fDays"></div>
                <div class="ctl-grid">
                    <div class="ctl-field">
                        <label for="fVon">von</label>
                        <input type="time" id="fVon" value="00:00">
                    </div>
                    <div class="ctl-field">
                        <label for="fBis">bis</label>
                        <input type="time" id="fBis" value="00:00">
                    </div>
                    <div class="ctl-field">
                        <label for="fHalte" title="Mindestdauer, bevor zwischen DANN und SONST gewechselt werden darf">Mindest-Haltezeit (Min.)</label>
                        <input type="number" id="fHalte" min="0" max="1440" value="0">
                    </div>
                    <div class="ctl-field ctl-grow">
                        <label for="fAusserhalb">Außerhalb des Zeitfensters</label>
                        <select id="fAusserhalb">
                            <option value="nichts">nichts tun</option>
                            <option value="sonst">SONST-Aktionen ausführen</option>
                        </select>
                    </div>
                </div>
                <div class="ctl-muted">von = bis bedeutet ganztägig. von &gt; bis geht über Mitternacht (z. B. 22:00–06:00).</div>
            </fieldset>

            <fieldset class="ctl-box">
                <legend>❓ WENN <span class="ctl-muted">(leer = immer wahr · UND vor ODER)</span></legend>
                <div id="condRows"></div>
                <button type="button" class="ctl-btn ctl-btn-small" id="btnAddCond">➕ Bedingung</button>
            </fieldset>

            <fieldset class="ctl-box ctl-box-then">
                <legend>✅ DANN setze</legend>
                <div id="thenRows"></div>
                <button type="button" class="ctl-btn ctl-btn-small" data-add-action="dann">➕ Aktion</button>
            </fieldset>

            <fieldset class="ctl-box ctl-box-else">
                <legend>↩️ SONST setze <span class="ctl-muted">(optional)</span></legend>
                <div id="elseRows"></div>
                <button type="button" class="ctl-btn ctl-btn-small" data-add-action="sonst">➕ Aktion</button>
            </fieldset>

            <div class="ctl-error" id="ruleError" hidden></div>
            <div class="edit-buttons">
                <button type="button" class="btn-edit-save" id="btnSaveRule">💾 Speichern</button>
                <button type="button" class="btn-edit-cancel" id="btnCancelRule">✖ Abbrechen</button>
            </div>
        </div>
    </div>
</div>

<!-- ===================== Datenpunkt-Suche ===================== -->
<div class="edit-modal" id="pickerModal">
    <div class="edit-content ctl-picker-content">
        <div class="edit-header"><h2 id="pickerTitle">Datenpunkt suchen</h2></div>
        <div class="ctl-error" id="pickerError" hidden></div>
        <input type="text" id="pickerSearch" class="ctl-picker-search" placeholder="Suchen nach Name, API-ID oder Menüpunkt …" autocomplete="off">
        <div class="ctl-muted" id="pickerCount"></div>
        <div class="ctl-table-wrap ctl-picker-wrap">
            <table class="ctl-table ctl-picker-table">
                <thead><tr><th>API-ID</th><th>Name</th><th>Menü</th><th>Aktuell</th></tr></thead>
                <tbody id="pickerList"></tbody>
            </table>
        </div>
        <div class="edit-buttons"><button type="button" class="btn-edit-cancel" id="btnClosePicker">✖ Schließen</button></div>
    </div>
</div>

<script src="assets/steuerung.js?v=<?php echo @filemtime(__DIR__ . '/assets/steuerung.js'); ?>"></script>
</body>
</html>
