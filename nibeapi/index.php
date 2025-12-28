<?php
/**
 * API Daten Abruf mit Auto-Refresh - Version 3.3.00 (Modular)
 * Hauptdatei: index.php
 */

// UTF-8 Encoding sicherstellen
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Konfiguration und Funktionen einbinden
require_once 'config.php';
require_once 'functions.php';
require_once 'database.php';

// AJAX-Requests verarbeiten (beendet die Ausführung wenn AJAX)
require_once 'ajax-handlers.php';

// Initiale Daten laden
$error = null;
$data = [];
$dbSaveResult = null;
$version = '3.2.00';

try {
    $jsonData = fetchApiData(API_URL, API_KEY, API_USERNAME, API_PASSWORD);
    $rawData = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON Dekodierungs-Fehler');
    }
    
    $data = processApiData($rawData);
    
    if (USE_DB) {
        try {
            $dbSaveResult = saveWritableDatapoints($data);
            debugLog("Initiales Speichern in Master-Tabelle erfolgreich", $dbSaveResult, 'DB');
            
            logValueChanges($data);
            debugLog("Initiale Werte in Log-Tabelle geschrieben", null, 'DB_LOG');
            
        } catch (Exception $e) {
            debugLog("Fehler beim initialen DB-Speichern", ['error' => $e->getMessage()], 'ERROR');
        }
    } else {
        debugLog("Datenbank-Funktionen deaktiviert (USE_DB = false)", null, 'INFO');
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nibe API Datenpunkte - Live <?php echo $version?></title>
    <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
    <div class="container">
        <h1><span class="live-indicator" id="liveIndicator"></span>📊 Nibe API Datenpunkte Übersicht <?php echo $version?></h1>
        
        <div id="errorContainer"></div>
        
        <div class="filter-section">
            <div class="filter-toggles">
                <div class="toggle-option">
                    <input type="checkbox" id="filterSelectedOnly">
                    <label for="filterSelectedOnly">Nur ausgewählte anzeigen</label>
                </div>
                <div class="toggle-option">
                    <input type="checkbox" id="showTooltips" checked>
                    <label for="showTooltips">Zusatzinformationen bei Hover anzeigen</label>
                </div>
                <div class="toggle-option">
                    <input type="checkbox" id="hideValuesActive" checked>
                    <label for="hideValuesActive">Hide Values aktiv</label>
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
                    <label for="filterTitle">Title</label>
                    <input type="text" id="filterTitle" placeholder="Filter nach Title...">
                </div>
                <div class="filter-group">
                    <label for="filterRegisterType">Modbus Register Type</label>
                    <select id="filterRegisterType"><option value="">Alle</option></select>
                </div>
                <div class="filter-group">
                    <label for="filterValue">Value</label>
                    <input type="text" id="filterValue" placeholder="Filter nach Value...">
                </div>
                <div class="filter-group">
                    <label for="updateInterval">Update-Intervall</label>
                    <select id="updateInterval" class="interval-select">
                        <?php 
                        foreach (API_UPDATE_INTERVALS as $ms => $label) {
                            $selected = ($ms == API_UPDATE_INTERVAL) ? ' selected' : '';
                            echo "<option value=\"$ms\"$selected>$label</option>";
                        }
                        ?>
                    </select>
                </div>
                <div class="button-group">
                    <button class="btn-reset" onclick="resetFilters()">Zurücksetzen</button>
                    <button class="btn-toggle" id="toggleButton" onclick="toggleAutoUpdate()">Pause</button>
                    <?php if (USE_DB): ?>
                        <button class="btn-import" onclick="showImportDialog()">📥 Import Nibe logs</button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="info-bar">
            <div class="info-left">
                <div>Gesamt: <strong><span id="totalCount">0</span></strong> Einträge</div>
                <div>Angezeigt: <strong><span id="visibleCount">0</span></strong> Einträge</div>
                <?php if (USE_DB && $dbSaveResult): ?>
                    <div style="color: #4CAF50;">DB: <strong><?php echo $dbSaveResult['inserted']; ?></strong> neu, <strong><?php echo $dbSaveResult['updated']; ?></strong> aktualisiert</div>
                <?php elseif (!USE_DB): ?>
                    <div style="color: #ff9800;">DB: deaktiviert</div>
                <?php endif; ?>
            </div>
            <div class="last-update">Letzte Aktualisierung: <strong><span id="lastUpdate">-</span></strong></div>
        </div>
        
        <div class="table-wrapper">
            <div class="row-tooltip" id="rowTooltip"></div>
            <table id="dataTable">
                 <thead>
                    <tr>
                        <th class="checkbox-cell"><input type="checkbox" id="selectAll" onchange="toggleSelectAll()" title="Alle auswählen/abwählen"></th>
                        <th class="sortable" onclick="sortTable(1)">API ID</th>
                        <th class="sortable" onclick="sortTable(2)">Modbus ID</th>
                        <th class="sortable" onclick="sortTable(3)">Title</th>
                        <th class="sortable" onclick="sortTable(4)">Modbus RT</th>
                        <th class="sortable" onclick="sortTable(5)">Value</th>
                        <?php if (USE_DB): ?>
                            <th>History</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if ($error): ?>
                        <tr><td colspan="<?php echo USE_DB ? '7' : '6'; ?>" class="no-data error">Fehler: <?php echo htmlspecialchars($error); ?></td></tr>
                    <?php elseif (empty($data)): ?>
                        <tr><td colspan="<?php echo USE_DB ? '7' : '6'; ?>" class="no-data">Keine Daten verfügbar</td></tr>
                    <?php else: ?>
                        <?php foreach ($data as $point): ?>
                            <tr data-variableid="<?php echo $point['variableid']; ?>"
                                data-variabletype="<?php echo $point['variableType']; ?>"
                                data-variablesize="<?php echo $point['variableSize']; ?>"
                                data-divisor="<?php echo $point['divisor']; ?>"
                                data-decimal="<?php echo $point['decimal']; ?>"
                                data-minvalue="<?php echo $point['minValue']; ?>"
                                data-maxvalue="<?php echo $point['maxValue']; ?>"
                                data-description="<?php echo htmlspecialchars($point['description']); ?>"
                                onmouseenter="showTooltip(event, this)"
                                onmouseleave="hideTooltip()">
                                <td class="checkbox-cell"><input type="checkbox" class="row-checkbox" onchange="updateSelectAllState()"></td>
                                <td><?php echo $point['variableid']; ?></td>
                                <td><?php echo $point['modbusregisterid']; ?></td>
                                <td><?php echo htmlspecialchars($point['title']); ?></td>
                                <td><?php 
                                    $rt = $point['modbusregistertype'];
                                    if ($rt === 'MODBUS_INPUT_REGISTER') {
                                        echo 'Input';
                                    } elseif ($rt === 'MODBUS_HOLDING_REGISTER') {
                                        echo 'Holding';
                                    } else {
                                        echo htmlspecialchars($rt);
                                    }
                                ?></td>
                                <td class="value-cell" 
                                    data-rawvalue="<?php echo $point['rawvalue']; ?>"
                                    data-divisor="<?php echo $point['divisor']; ?>"
                                    data-decimal="<?php echo $point['decimal']; ?>"
                                    data-unit="<?php echo htmlspecialchars($point['unit']); ?>">
                                    <?php if ($point['modbusregistertype'] === 'MODBUS_HOLDING_REGISTER' && $point['isWritable']): ?>
                                        <div class="editable-value">
                                            <span class="value-display"><?php echo htmlspecialchars($point['value']); ?></span>
                                            <button class="btn-edit" onclick="editValue(<?php echo $point['variableid']; ?>, '<?php echo htmlspecialchars($point['title'], ENT_QUOTES); ?>')">✏️</button>
                                        </div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($point['value']); ?>
                                    <?php endif; ?>
                                </td>
                                <?php if (USE_DB): ?>
                                    <td style="text-align: center;">
                                        <?php if ($point['modbusregistertype'] === 'MODBUS_HOLDING_REGISTER' && $point['isWritable']): ?>
                                            <button class="btn-edit" onclick="showHistory(<?php echo $point['variableid']; ?>, '<?php echo htmlspecialchars($point['title'], ENT_QUOTES); ?>')">
                                                📜
                                            </button>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- History Modal -->
    <div class="history-modal" id="historyModal">
        <div class="history-content">
            <div class="history-header">
                <h2>📜 Werteverlauf</h2>
                <span id="historyTitle" style="color: #666;"></span>
            </div>
            <table class="history-table">
                <thead>
                    <tr>
                        <th>Zeitstempel</th>
                        <th>Wert</th>
                        <th>Manuell</th>
                        <th>Aktion</th>
                    </tr>
                </thead>
                <tbody id="historyTableBody">
                </tbody>
            </table>
            <div class="history-footer">
                <button class="btn-close-history" onclick="closeHistory()">✖ Schließen</button>
            </div>
        </div>
    </div>
    
    <!-- Import Modal -->
    <div class="import-modal" id="importModal">
        <div class="import-content">
            <div class="import-header">
                <h2>📥 Nibe Logs Importieren</h2>
            </div>
            
            <div id="importUploadArea">
                <div class="file-upload-area" id="fileUploadArea" onclick="document.getElementById('fileInput').click()">
                    <p style="font-size: 18px; margin: 10px 0;">📄 Datei auswählen oder hierher ziehen</p>
                    <p style="color: #666; font-size: 14px;">Tab-getrennte Textdatei (.txt, .csv)</p>
                    <p id="selectedFileName" style="margin-top: 10px; font-weight: bold; color: #9C27B0;"></p>
                </div>
                <input type="file" id="fileInput" class="file-input" accept=".txt,.csv,.log" onchange="handleFileSelect(event)">
                
                <div class="import-buttons">
                    <button class="btn-import-ok" id="btnImportOk" onclick="processImport()" disabled>✔ OK</button>
                    <button class="btn-import-cancel" onclick="closeImportDialog()">✗ Abbrechen</button>
                </div>
            </div>
            
            <div id="importResult" style="display: none;">
            </div>
        </div>
    </div>
    
    <!-- Edit Modal -->
    <div class="edit-modal" id="editModal">
        <div class="edit-content">
            <div class="edit-header">
                <h2>✏️ Wert bearbeiten</h2>
                <div class="edit-info" id="editTitle"></div>
                <div class="edit-info" id="editApiId"></div>
            </div>
            
            <div id="editFormArea">
                <div class="edit-current-value">
                    Aktueller Wert: <strong id="editCurrentValue"></strong>
                </div>
                
                <div class="edit-form">
                    <div class="edit-form-group">
                        <label for="editInput">Neuer Wert</label>
                        <input type="number" id="editInput" step="any" placeholder="Neuen Wert eingeben">
                        <div class="edit-limits" id="editLimits"></div>
                    </div>
                </div>
                
                <div class="edit-buttons">
                    <button class="btn-edit-save" onclick="saveEditValue()">💾 Speichern</button>
                    <button class="btn-edit-cancel" onclick="closeEditDialog()">✖ Abbrechen</button>
                </div>
            </div>
            
            <div id="editSaving" class="edit-saving" style="display: none;">
                💾 Speichere Wert...
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        // App initialisieren mit Server-Daten
        window.addEventListener('DOMContentLoaded', () => {
            const initialData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
            const config = {
                hideValues: <?php echo json_encode(HIDE_VALUES); ?>,
                apiUpdateInterval: <?php echo API_UPDATE_INTERVAL; ?>,
                availableIntervals: <?php echo json_encode(API_UPDATE_INTERVALS); ?>,
                useDb: <?php echo USE_DB ? 'true' : 'false'; ?>
            };
            
            initializeApp(initialData, config);
        });
    </script>
</body>
</html>
