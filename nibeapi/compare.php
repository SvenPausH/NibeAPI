<?php
/**
 * Datenpunkt-Vergleich
 * compare.php v1.1
 *
 * Importiert eine NibeAPI Export-JSON-Datei und vergleicht
 * die gespeicherten Werte mit den aktuellen API-Daten.
 * Gleicher Stil wie index.php – verwendet assets/styles.css.
 */

header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';

// ============================================================================
// DEVICE-ID aus GET oder Session
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$selectedDeviceId = 0;
if (isset($_GET['deviceId']) && $_GET['deviceId'] !== '') {
    $selectedDeviceId = (int)$_GET['deviceId'];
} elseif (isset($_SESSION['selectedDeviceId'])) {
    $selectedDeviceId = (int)$_SESSION['selectedDeviceId'];
}

// ============================================================================
// VERGLEICHS-DATEN VERARBEITEN (POST = Datei-Upload)
// ============================================================================

$importedMap   = [];   // api_id => importierter Datenpunkt
$currentData   = [];   // processApiData()-Ergebnis
$compareRows   = [];   // Fertige Vergleichszeilen
$exportMeta    = [];
$error         = '';
$hasData       = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ── JSON-Datei lesen ──────────────────────────────────────────────────
    $uploadedFile = $_FILES['import_file'] ?? null;

    if (!$uploadedFile || $uploadedFile['error'] !== UPLOAD_ERR_OK) {
        $error = 'Fehler beim Datei-Upload (Code: ' . ($uploadedFile['error'] ?? 'n/a') . ')';
    } elseif (strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)) !== 'json') {
        $error = 'Bitte eine JSON-Datei auswählen (.json)';
    } else {
        $jsonContent  = file_get_contents($uploadedFile['tmp_name']);
        $importedJson = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($importedJson['datapoints'])) {
            $error = 'Ungültige JSON-Datei. Bitte eine NibeAPI Export-Datei verwenden.';
        } else {
            $exportMeta = $importedJson['meta'] ?? [];

            // Device-ID: aus Export-Meta oder aus GET/Session
            $exportDeviceId = (int)($exportMeta['deviceId'] ?? $selectedDeviceId);
            if ($exportDeviceId !== 0) {
                $selectedDeviceId = $exportDeviceId;
            }

            // Importierte Datenpunkte nach api_id indexieren
            foreach ($importedJson['datapoints'] as $dp) {
                $importedMap[(int)$dp['api_id']] = $dp;
            }

            // ── Aktuelle API-Daten abrufen ────────────────────────────────
            try {
                $url     = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $selectedDeviceId . '/points';
                $rawJson = fetchApiData($url, API_KEY, API_USERNAME, API_PASSWORD);
                $rawData = json_decode($rawJson, true);

                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('Ungültige API-Antwort');
                }

                $currentData = processApiData($rawData, $selectedDeviceId);

            } catch (Exception $e) {
                $error = 'Fehler beim Abrufen der aktuellen Daten: ' . htmlspecialchars($e->getMessage());
            }
        }
    }

    // ── Vergleichszeilen aufbauen ─────────────────────────────────────────
    if (!$error && $currentData) {
        foreach ($currentData as $point) {
            // Hide Values (identisch zu index.php)
            $skip = false;
            foreach (HIDE_VALUES as $hideVal) {
                if (strpos((string)$point['rawvalue'], (string)$hideVal) !== false) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) continue;

            $apiId       = (int)$point['variableid'];
            $importPoint = $importedMap[$apiId] ?? null;

            // Differenz berechnen
            $diffDisplay = null;
            $diffClass   = '';
            if ($importPoint !== null) {
                $curRaw  = (int)$point['rawvalue'];
                $impRaw  = (int)$importPoint['rawvalue'];
                $rawDiff = $curRaw - $impRaw;

                if ($rawDiff !== 0) {
                    $divisor  = ($point['divisor'] > 0) ? (int)$point['divisor'] : 1;
                    $decimal  = (int)$point['decimal'];
                    $diffVal  = $rawDiff / $divisor;
                    $diffDisplay = ($rawDiff > 0 ? '+' : '') .
                                   number_format($diffVal, $decimal, ',', '.') .
                                   ($point['unit'] ? ' ' . $point['unit'] : '');
                    $diffClass = ($rawDiff > 0) ? 'diff-positive' : 'diff-negative';
                } else {
                    $diffDisplay = '—';
                    $diffClass   = 'diff-neutral';
                }
            }

            // Menüpunkt-Text für Modbus RT (identisch zu index.php)
            $rt = $point['modbusregistertype'];
            if ($rt === 'MODBUS_INPUT_REGISTER') {
                $rtDisplay = 'Input';
            } elseif ($rt === 'MODBUS_HOLDING_REGISTER') {
                $rtDisplay = 'Holding';
            } else {
                $rtDisplay = $rt;
            }

            $compareRows[] = [
                'api_id'        => $apiId,
                'modbus_id'     => $point['modbusregisterid'],
                'title'         => $point['title'],
                'register_type' => $rt,
                'rt_display'    => $rtDisplay,
                'menuepunkt'    => $point['menuepunkt'] ?? '',
                'current_value' => $point['value'],
                'import_value'  => $importPoint ? $importPoint['value'] : null,
                'rawvalue'      => $point['rawvalue'],
                'diff_display'  => $diffDisplay,
                'diff_class'    => $diffClass,
                'has_diff'      => ($diffClass === 'diff-positive' || $diffClass === 'diff-negative'),
            ];
        }
        $hasData = true;
    }
}

// Register-Typen für Filter sammeln
$registerTypes = [];
foreach ($compareRows as $row) {
    if ($row['register_type'] && !in_array($row['register_type'], $registerTypes)) {
        $registerTypes[] = $row['register_type'];
    }
}
sort($registerTypes);

// Anzahl Unterschiede
$diffCount = count(array_filter($compareRows, fn($r) => $r['has_diff']));

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nibe API – Datenpunkt-Vergleich</title>
    <link rel="stylesheet" href="assets/styles.css">
    <style>
        /* ── Upload-Zone ───────────────────────────────────────────────── */
        .upload-zone-wrapper {
            max-width: 560px;
            margin: 40px auto;
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 32px;
            box-shadow: 0 2px 8px rgba(0,0,0,.06);
        }
        .upload-zone-wrapper h2 {
            margin: 0 0 8px;
            font-size: 18px;
            color: #333;
        }
        .upload-zone-wrapper p {
            color: #666;
            font-size: 14px;
            margin: 0 0 20px;
        }
        .upload-zone {
            border: 2px dashed #bdbdbd;
            border-radius: 8px;
            padding: 40px 20px;
            text-align: center;
            cursor: pointer;
            transition: border-color .2s, background .2s;
            background: #fafafa;
        }
        .upload-zone:hover, .upload-zone.dragover {
            border-color: #2196F3;
            background: #e3f2fd;
        }
        .upload-zone-icon { font-size: 40px; display: block; margin-bottom: 10px; }
        .upload-zone-text { font-size: 15px; color: #444; margin: 0 0 4px; font-weight: 500; }
        .upload-zone-sub  { font-size: 13px; color: #888; margin: 0; }
        .upload-file-info {
            margin-top: 12px;
            padding: 8px 14px;
            background: #e8f5e9;
            border-radius: 4px;
            font-size: 14px;
            color: #2e7d32;
            display: none;
        }
        .upload-file-info.show { display: block; }
        .btn-upload-submit {
            margin-top: 20px;
            width: 100%;
            padding: 12px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
        }
        .btn-upload-submit:hover:not(:disabled) { background: #1976D2; }
        .btn-upload-submit:disabled {
            background: #90caf9;
            cursor: not-allowed;
        }

        /* ── Seiten-Header ─────────────────────────────────────────────── */
        .compare-back {
            display: inline-block;
            margin-bottom: 15px;
            color: #2196F3;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .compare-back:hover { text-decoration: underline; }

        /* ── Meta-Infobox ──────────────────────────────────────────────── */
        .compare-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 16px;
            align-items: center;
        }
        .meta-chip {
            background: #e3f2fd;
            border-radius: 20px;
            padding: 5px 14px;
            font-size: 13px;
            color: #1565c0;
            font-weight: 500;
        }
        .meta-chip.diff { background: #fff3e0; color: #e65100; }
        .btn-new-compare, .btn-export-now {
            padding: 7px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        .btn-new-compare  { background: #e0e0e0; color: #333; }
        .btn-new-compare:hover { background: #bdbdbd; }
        .btn-export-now   { background: #4CAF50; color: white; margin-left: auto; }
        .btn-export-now:hover { background: #388E3C; }

        /* ── Tabellen-Spalten ──────────────────────────────────────────── */
        .col-import-val { background: rgba(33, 150, 243, 0.05); }
        .diff-positive  { color: #2e7d32; font-weight: 700; }
        .diff-negative  { color: #c62828; font-weight: 700; }
        .diff-neutral   { color: #9e9e9e; }
        tr.has-diff td  { background: rgba(255, 193, 7, 0.07); }
        tr.no-import td { opacity: .65; }

        /* ── Filter: Diff-Only Toggle ──────────────────────────────────── */
        .toggle-option-diff {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .toggle-option-diff input { cursor: pointer; }

        /* ── Hilfsklasse ───────────────────────────────────────────────── */
        .row-hidden { display: none !important; }

        /* ── Fehler-Banner ─────────────────────────────────────────────── */
        .compare-error {
            background: #ffebee;
            border-left: 4px solid #f44336;
            padding: 14px 18px;
            margin-bottom: 20px;
            border-radius: 4px;
            color: #b71c1c;
            font-weight: 500;
        }

        /* ── Kein Import vorhanden ─────────────────────────────────────── */
        .import-missing { color: #bdbdbd; font-style: italic; font-size: 13px; }
    </style>
</head>
<body>
<div class="container">

    <a class="compare-back" href="index.php?deviceId=<?= $selectedDeviceId ?>">← Zurück zur Übersicht</a>

    <h1>🔀 Nibe API – Datenpunkt-Vergleich</h1>

    <?php if ($error): ?>
    <div class="compare-error">⚠️ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>


    <?php if (!$hasData): /* ═══════════ UPLOAD-FORMULAR ═══════════ */ ?>

    <div class="upload-zone-wrapper">
        <h2>📂 Export-Datei importieren</h2>
        <p>
            Wähle eine zuvor exportierte <strong>NibeAPI JSON-Datei</strong> aus.
            Die gespeicherten Werte werden mit den aktuellen API-Daten verglichen.
        </p>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-zone" id="uploadZone">
                <span class="upload-zone-icon" id="zoneIcon">📄</span>
                <p class="upload-zone-text" id="zoneText">JSON-Datei hierher ziehen oder klicken</p>
                <p class="upload-zone-sub">NibeAPI Export (.json)</p>
                <input type="file" name="import_file" id="fileInput"
                       accept=".json,application/json" class="file-input" required>
            </div>
            <div class="upload-file-info" id="fileInfo"></div>
            <button type="submit" class="btn-upload-submit" id="submitBtn" disabled>
                🔀 Vergleich starten
            </button>
        </form>
    </div>

    <?php else: /* ═══════════ VERGLEICHS-TABELLE ═══════════ */ ?>

    <!-- ── Meta-Informationen ────────────────────────────────────────────── -->
    <div class="compare-meta">
        <span class="meta-chip">📅 Export: <?= htmlspecialchars($exportMeta['exported_at'] ?? '—') ?></span>
        <span class="meta-chip">🔧 Device: <?= (int)($exportMeta['deviceId'] ?? 0) ?></span>
        <span class="meta-chip">📦 Importiert: <?= count($importedMap) ?> Punkte</span>
        <span class="meta-chip">📡 Aktuell: <?= count($compareRows) ?> Punkte</span>
        <span class="meta-chip diff">🔄 Unterschiede: <?= $diffCount ?></span>
        <a class="btn-new-compare" href="compare.php?deviceId=<?= $selectedDeviceId ?>">📂 Neue Datei</a>
        <a class="btn-export-now" href="export.php?deviceId=<?= $selectedDeviceId ?>">📤 Aktuell exportieren</a>
    </div>

    <!-- ── Filter-Section (identisch zu index.php) ──────────────────────── -->
    <div class="filter-section" id="filterSection">
        <button type="button" class="filter-collapse-btn" id="filterCollapseBtn"
                onclick="toggleFilterSection()" title="Filter ein-/ausklappen">
            <span class="filter-collapse-icon">▲</span>
            <span class="filter-collapse-label">Filter</span>
            <span class="filter-active-badge" id="filterActiveBadge" style="display:none">●</span>
        </button>
        <div class="filter-body" id="filterBody">
            <div class="filter-toggles">
                <div class="toggle-option">
                    <input type="checkbox" id="hideValuesActive" checked>
                    <label for="hideValuesActive">Hide Values aktiv</label>
                </div>
                <div class="toggle-option toggle-option-diff">
                    <input type="checkbox" id="filterDiffOnly">
                    <label for="filterDiffOnly">Nur Unterschiede anzeigen</label>
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <label for="filterVariableId">API ID</label>
                    <input type="text" id="filterVariableId" placeholder="Filter nach API ID...">
                </div>
                <div class="filter-group">
                    <label for="filterModbusRegisterID">Modbus ID</label>
                    <input type="text" id="filterModbusRegisterID" placeholder="Filter nach Modbus ID...">
                </div>
                <div class="filter-group">
                    <label for="filterMenuepunkt">Menüpunkt</label>
                    <input type="text" id="filterMenuepunkt" placeholder="z.B. 1. oder ^1\.2">
                </div>
                <div class="filter-group">
                    <label for="filterTitle">Title</label>
                    <input type="text" id="filterTitle" placeholder="Filter nach Title...">
                </div>
                <div class="filter-group">
                    <label for="filterRegisterType">Modbus Register Type</label>
                    <select id="filterRegisterType">
                        <option value="">Alle</option>
                        <?php foreach ($registerTypes as $rt): ?>
                        <option value="<?= htmlspecialchars($rt) ?>">
                            <?= $rt === 'MODBUS_INPUT_REGISTER'   ? 'Input (Input Register)'   :
                               ($rt === 'MODBUS_HOLDING_REGISTER'  ? 'Holding (Holding Register)' :
                                htmlspecialchars($rt)) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label for="filterValue">Aktueller Wert</label>
                    <input type="text" id="filterValue" placeholder="Filter nach Wert...">
                </div>
                <div class="button-group">
                    <button class="btn-reset" onclick="resetFilters()">Zurücksetzen</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Info-Bar ──────────────────────────────────────────────────────── -->
    <div class="info-bar">
        <div class="info-left">
            <div>Gesamt: <strong><span id="totalCount"><?= count($compareRows) ?></span></strong> Einträge</div>
            <div>Angezeigt: <strong><span id="visibleCount"><?= count($compareRows) ?></span></strong> Einträge</div>
            <div>Mit Unterschied: <strong><span id="diffCountDisplay"><?= $diffCount ?></span></strong></div>
        </div>
        <div class="last-update">Export vom: <strong><?= htmlspecialchars($exportMeta['exported_at'] ?? '—') ?></strong></div>
    </div>

    <!-- ── Vergleichs-Tabelle ────────────────────────────────────────────── -->
    <div class="table-wrapper">
        <table id="compareTable">
            <thead>
                <tr>
                    <th class="sortable" onclick="sortTable(0)">API ID</th>
                    <th class="sortable" onclick="sortTable(1)">Modbus ID</th>
                    <th class="sortable" onclick="sortTable(2)">Menüpunkt</th>
                    <th class="sortable" onclick="sortTable(3)">Title</th>
                    <th class="sortable" onclick="sortTable(4)">Modbus RT</th>
                    <th class="sortable" onclick="sortTable(5)">Aktuell</th>
                    <th class="col-import-val sortable" onclick="sortTable(6)">📦 Import-Wert</th>
                    <th class="sortable" onclick="sortTable(7)">Differenz</th>
                </tr>
            </thead>
            <tbody id="tableBody">
            <?php foreach ($compareRows as $row):
                $trClass  = $row['has_diff'] ? 'has-diff' : '';
                $trClass .= ($row['import_value'] === null) ? ' no-import' : '';
            ?>
            <tr class="<?= trim($trClass) ?>"
                data-apiid="<?= $row['api_id'] ?>"
                data-modbusid="<?= htmlspecialchars((string)$row['modbus_id']) ?>"
                data-menuepunkt="<?= htmlspecialchars($row['menuepunkt']) ?>"
                data-title="<?= htmlspecialchars(strtolower($row['title'])) ?>"
                data-regtype="<?= htmlspecialchars($row['register_type']) ?>"
                data-value="<?= htmlspecialchars(strtolower($row['current_value'])) ?>"
                data-rawvalue="<?= (int)$row['rawvalue'] ?>"
                data-hasdiff="<?= $row['has_diff'] ? '1' : '0' ?>">

                <td><?= $row['api_id'] ?></td>
                <td><?= htmlspecialchars((string)$row['modbus_id']) ?></td>
                <td><?= $row['menuepunkt'] ? htmlspecialchars($row['menuepunkt']) : '-' ?></td>
                <td><?= htmlspecialchars($row['title']) ?></td>
                <td><?= htmlspecialchars($row['rt_display']) ?></td>
                <td><?= htmlspecialchars($row['current_value']) ?></td>
                <td class="col-import-val">
                    <?php if ($row['import_value'] !== null): ?>
                        <?= htmlspecialchars($row['import_value']) ?>
                    <?php else: ?>
                        <span class="import-missing">nicht vorhanden</span>
                    <?php endif; ?>
                </td>
                <td class="<?= htmlspecialchars($row['diff_class']) ?>">
                    <?= $row['diff_display'] !== null ? htmlspecialchars($row['diff_display']) : '—' ?>
                </td>
            </tr>
            <?php endforeach; ?>

            <?php if (empty($compareRows)): ?>
            <tr><td colspan="8" class="no-data">Keine Datenpunkte gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>

</div><!-- /.container -->

<script>
// ============================================================================
// UPLOAD-ZONE
// ============================================================================
(function () {
    const zone      = document.getElementById('uploadZone');
    const fileInput = document.getElementById('fileInput');
    const fileInfo  = document.getElementById('fileInfo');
    const submitBtn = document.getElementById('submitBtn');
    const zoneIcon  = document.getElementById('zoneIcon');
    const zoneText  = document.getElementById('zoneText');

    if (!zone) return;

    zone.addEventListener('click', () => fileInput.click());

    zone.addEventListener('dragover', e => {
        e.preventDefault();
        zone.classList.add('dragover');
    });
    zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            fileInput.files = e.dataTransfer.files;
            handleFile(e.dataTransfer.files[0]);
        }
    });
    fileInput.addEventListener('change', () => {
        if (fileInput.files.length > 0) handleFile(fileInput.files[0]);
    });

    function handleFile(file) {
        if (!file.name.endsWith('.json')) {
            alert('Bitte eine .json Datei auswählen.');
            return;
        }
        fileInfo.textContent = '✅ ' + file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
        fileInfo.classList.add('show');
        submitBtn.disabled = false;
        zoneIcon.textContent = '📂';
        zoneText.textContent = 'Datei ausgewählt';
    }
})();

// ============================================================================
// FILTER (identisch zu index.php-Logik)
// ============================================================================

// HIDE_VALUES aus PHP übergeben
const hideValues = <?= json_encode(HIDE_VALUES) ?>;

// Sortierung
let currentSortColumn = null;
let currentSortDirection = 'asc';

function filterTable() {
    const filters = {
        variableId:      (document.getElementById('filterVariableId')?.value      || '').toLowerCase().trim(),
        modbusId:        (document.getElementById('filterModbusRegisterID')?.value || '').toLowerCase().trim(),
        menuepunkt:      (document.getElementById('filterMenuepunkt')?.value       || '').trim(),
        title:           (document.getElementById('filterTitle')?.value            || '').toLowerCase().trim(),
        regType:         (document.getElementById('filterRegisterType')?.value     || ''),
        value:           (document.getElementById('filterValue')?.value            || '').toLowerCase().trim(),
        hideValActive:   document.getElementById('hideValuesActive')?.checked  ?? true,
        diffOnly:        document.getElementById('filterDiffOnly')?.checked    ?? false,
    };

    const rows = document.querySelectorAll('#tableBody tr');
    let visible = 0;
    let visibleDiff = 0;

    rows.forEach(tr => {
        const currentValue = (tr.dataset.value || '').toLowerCase();

        const matchApiId   = !filters.variableId   || (tr.dataset.apiid    || '').includes(filters.variableId);
        const matchModbus  = !filters.modbusId     || (tr.dataset.modbusid || '').toLowerCase().includes(filters.modbusId);
        const matchMenu    = matchesMenuepunktFilter(tr.dataset.menuepunkt || '', filters.menuepunkt);
        const matchTitle   = !filters.title        || (tr.dataset.title    || '').includes(filters.title);
        const matchReg     = !filters.regType      || (tr.dataset.regtype  || '') === filters.regType;
        const matchValue   = !filters.value        || currentValue.includes(filters.value);
        const matchHide    = !filters.hideValActive || !hideValues.some(v => currentValue.includes(String(v).toLowerCase()));
        const matchDiff    = !filters.diffOnly     || (tr.dataset.hasdiff === '1');

        const show = matchApiId && matchModbus && matchMenu && matchTitle &&
                     matchReg && matchValue && matchHide && matchDiff;

        tr.classList.toggle('row-hidden', !show);
        if (show) {
            visible++;
            if (tr.dataset.hasdiff === '1') visibleDiff++;
        }
    });

    const vc = document.getElementById('visibleCount');
    const dc = document.getElementById('diffCountDisplay');
    if (vc) vc.textContent = visible;
    if (dc) dc.textContent = visibleDiff;

    updateFilterBadge();
}

// Menüpunkt-Filter (identisch zu script.js)
function matchesMenuepunktFilter(menuepunkt, filterValue) {
    if (!filterValue) return true;
    if (!menuepunkt) return false;
    const filter = filterValue.trim().toLowerCase();
    const menu   = menuepunkt.toLowerCase();
    if (filter.startsWith('^') || filter.endsWith('$')) {
        try { return new RegExp(filter).test(menu); } catch (e) { return menu.includes(filter); }
    }
    if (filter.endsWith('.')) return menu.startsWith(filter);
    return menu.includes(filter);
}

function resetFilters() {
    ['filterVariableId','filterModbusRegisterID','filterMenuepunkt','filterTitle','filterValue'].forEach(id => {
        const el = document.getElementById(id);
        if (el) el.value = '';
    });
    const rt = document.getElementById('filterRegisterType');
    if (rt) rt.value = '';
    const hv = document.getElementById('hideValuesActive');
    if (hv) hv.checked = true;
    const df = document.getElementById('filterDiffOnly');
    if (df) df.checked = false;
    filterTable();
}

function updateFilterBadge() {
    const ids = ['filterVariableId','filterModbusRegisterID','filterMenuepunkt','filterTitle','filterValue'];
    const hasText = ids.some(id => (document.getElementById(id)?.value || '').trim() !== '');
    const sel = document.getElementById('filterRegisterType');
    const hasSel = sel && sel.value !== '';
    const diff = document.getElementById('filterDiffOnly');
    const hasDiff = diff && diff.checked;
    const badge = document.getElementById('filterActiveBadge');
    if (badge) badge.style.display = (hasText || hasSel || hasDiff) ? 'inline' : 'none';
}

// ── Filter Events ─────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    const filterIds = ['filterVariableId','filterModbusRegisterID','filterMenuepunkt',
                       'filterTitle','filterValue'];
    filterIds.forEach(id => {
        document.getElementById(id)?.addEventListener('input', filterTable);
    });
    document.getElementById('filterRegisterType')?.addEventListener('change', filterTable);
    document.getElementById('hideValuesActive')?.addEventListener('change', filterTable);
    document.getElementById('filterDiffOnly')?.addEventListener('change', filterTable);

    // Filter-Collapse-Status wiederherstellen
    if (localStorage.getItem('filterCollapsed') === '1') {
        document.getElementById('filterSection')?.classList.add('filter-collapsed');
        const icon = document.querySelector('.filter-collapse-icon');
        if (icon) icon.textContent = '▼';
    }

    filterTable(); // Initiale Zähler setzen
});

function toggleFilterSection() {
    const section   = document.getElementById('filterSection');
    const icon      = document.querySelector('.filter-collapse-icon');
    const collapsed = section.classList.toggle('filter-collapsed');
    if (icon) icon.textContent = collapsed ? '▼' : '▲';
    localStorage.setItem('filterCollapsed', collapsed ? '1' : '0');
}

// ============================================================================
// SORTIERUNG (identisch zu script.js)
// ============================================================================

function sortTable(colIndex) {
    const tbody = document.getElementById('tableBody');
    if (!tbody) return;

    if (currentSortColumn === colIndex) {
        currentSortDirection = currentSortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        currentSortColumn    = colIndex;
        currentSortDirection = 'asc';
    }

    const rows = Array.from(tbody.querySelectorAll('tr:not(.row-hidden), tr.row-hidden'));
    rows.sort((a, b) => {
        const aVal = (a.cells[colIndex]?.textContent || '').trim();
        const bVal = (b.cells[colIndex]?.textContent || '').trim();

        // Menüpunkt-Spalte (Index 2) – numerisch
        if (colIndex === 2) {
            const cmp = compareMenuepunkt(aVal, bVal);
            return currentSortDirection === 'asc' ? cmp : -cmp;
        }

        const aNum = parseFloat(aVal);
        const bNum = parseFloat(bVal);
        if (!isNaN(aNum) && !isNaN(bNum)) {
            return currentSortDirection === 'asc' ? aNum - bNum : bNum - aNum;
        }
        const cmp = aVal.localeCompare(bVal, 'de');
        return currentSortDirection === 'asc' ? cmp : -cmp;
    });

    rows.forEach(r => tbody.appendChild(r));
    applySortIndicator();
}

function compareMenuepunkt(a, b) {
    if (!a && !b) return 0;
    if (!a) return 1;
    if (!b) return -1;
    const parse = s => s.split('.').map(p => { const n = parseInt(p); return isNaN(n) ? p : n; });
    const pa = parse(a), pb = parse(b);
    for (let i = 0; i < Math.max(pa.length, pb.length); i++) {
        if (pa[i] === undefined) return -1;
        if (pb[i] === undefined) return  1;
        if (typeof pa[i] === 'number' && typeof pb[i] === 'number') {
            if (pa[i] !== pb[i]) return pa[i] - pb[i];
        } else {
            const c = String(pa[i]).localeCompare(String(pb[i]));
            if (c !== 0) return c;
        }
    }
    return 0;
}

function applySortIndicator() {
    document.querySelectorAll('#compareTable th.sortable').forEach((th, i) => {
        th.classList.remove('sorted-asc', 'sorted-desc');
        if (i === currentSortColumn) {
            th.classList.add(currentSortDirection === 'asc' ? 'sorted-asc' : 'sorted-desc');
        }
    });
}
</script>
</body>
</html>
