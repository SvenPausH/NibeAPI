<?php
/**
 * API Daten Abruf mit Auto-Refresh - Version 3.4.00 (Multi-Device Support)
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
$version = '3.4.10';
$devices = [];
$selectedDeviceId = 0; // Default-Wert

// Session starten für Device-Auswahl
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Device-Handling
if (USE_DB) {
    try {
        // Devices aus Datenbank laden
        $devices = getAllDevices();
        
        // Wenn keine Devices in DB, Discovery durchführen
        if (empty($devices)) {
            try {
                debugLog("Keine Devices in DB - starte Discovery", null, 'DEVICES');
                $discoveredDevices = discoverDevices();
                
                if (!empty($discoveredDevices)) {
                    foreach ($discoveredDevices as $device) {
                        saveDevice($device);
                    }
                    
                    $devices = getAllDevices();
                    debugLog("Device-Discovery abgeschlossen", ['anzahl' => count($devices)], 'DEVICES');
                } else {
                    debugLog("Device-Discovery lieferte keine Devices", null, 'WARNING');
                }
            } catch (Exception $e) {
                debugLog("Device-Discovery fehlgeschlagen", ['error' => $e->getMessage()], 'ERROR');
            }
        }
        
        // Ausgewähltes Device aus Session/GET bestimmen
        if (isset($_GET['deviceId']) && $_GET['deviceId'] !== '') {
            $selectedDeviceId = (int)$_GET['deviceId'];
            $_SESSION['selectedDeviceId'] = $selectedDeviceId;
            debugLog("Device aus GET", ['deviceId' => $selectedDeviceId], 'INFO');
        } elseif (isset($_SESSION['selectedDeviceId']) && $_SESSION['selectedDeviceId'] !== '') {
            $selectedDeviceId = (int)$_SESSION['selectedDeviceId'];
            debugLog("Device aus Session", ['deviceId' => $selectedDeviceId], 'INFO');
        } elseif (!empty($devices)) {
            $selectedDeviceId = (int)$devices[0]['deviceId'];
            $_SESSION['selectedDeviceId'] = $selectedDeviceId;
            debugLog("Device aus DB (erstes)", ['deviceId' => $selectedDeviceId], 'INFO');
        } else {
            // Fallback: Device 0 wenn keine Devices gefunden
            $selectedDeviceId = 0;
            $_SESSION['selectedDeviceId'] = $selectedDeviceId;
            debugLog("Fallback auf Device 0 - keine Devices in DB", null, 'WARNING');
        }
        
        // Sicherstellen dass deviceId nie NULL oder leer ist
        if ($selectedDeviceId === null || $selectedDeviceId === '') {
            $selectedDeviceId = 0;
            debugLog("FORCE: deviceId war null/leer - setze auf 0", null, 'WARNING');
        }
        
    } catch (Exception $e) {
        debugLog("Fehler beim Device-Handling", ['error' => $e->getMessage()], 'ERROR');
        $selectedDeviceId = 0; // Fallback
    }
} else {
    // Wenn DB deaktiviert, immer Device 0 verwenden
    $selectedDeviceId = 0;
    debugLog("DB deaktiviert - verwende Device 0", null, 'INFO');
}

// Final Check
$selectedDeviceId = (int)$selectedDeviceId; // Sicherstellen dass es ein Integer ist
debugLog("Finale Device ID", ['deviceId' => $selectedDeviceId, 'type' => gettype($selectedDeviceId)], 'INFO');

// API URL dynamisch setzen (auch für Device 0)
$apiUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $selectedDeviceId . '/points';

debugLog("API-URL", ['url' => $apiUrl, 'deviceId' => $selectedDeviceId], 'INFO');
// Update-Strategie prüfen
$checkBy = defined('NOTIFICATIONS_CHECK_BY') ? NOTIFICATIONS_CHECK_BY : 'WEB';
$checkInterval = defined('NOTIFICATIONS_CHECK_INTERVAL') ? NOTIFICATIONS_CHECK_INTERVAL : 300;
$skipFullUpdate = false;
$updateWarning = null;

if ($checkBy === 'CRON') {
    // CRON-Modus: Web macht KEINE Device-Discovery, KEINE Master-Updates, KEINE Notification-Checks
    // ABER: Zeigt Live-Daten von API an!
    debugLog("CRON-Modus aktiv - Device-Discovery/Master-Updates/Notifications überspringen", null, 'INFO');
    
    // Prüfen ob letztes Update zu lange her ist (Warnung)
    if (USE_DB && !empty($devices)) {
        try {
            $pdo = getDbConnection();
            
            // Ältestes last_updated aller Devices finden
            $stmt = $pdo->query("
                SELECT MIN(last_updated) as oldest_update,
                       TIMESTAMPDIFF(SECOND, MIN(last_updated), NOW()) as seconds_ago
                FROM nibe_device
                WHERE last_updated IS NOT NULL
            ");
            $result = $stmt->fetch();
            
            if ($result && $result['oldest_update']) {
                $secondsAgo = $result['seconds_ago'];
                $maxAge = $checkInterval * 2; // Doppelter Interval = Warnung
                
                if ($secondsAgo > $maxAge) {
                    $minutesAgo = round($secondsAgo / 60);
                    $expectedMinutes = round($checkInterval / 60);
                    
                    $updateWarning = [
                        'message' => "⚠️ WARNUNG: Letzte Aktualisierung vor {$minutesAgo} Minuten (erwartet: alle {$expectedMinutes} Min.)",
                        'details' => "Der Cronjob 'notification-monitor.php' läuft möglicherweise nicht!",
                        'lastUpdate' => $result['oldest_update'],
                        'secondsAgo' => $secondsAgo,
                        'maxAge' => $maxAge
                    ];
                    
                    debugLog("Update-Warnung", $updateWarning, 'WARNING');
                }
            }
            
        } catch (Exception $e) {
            debugLog("Fehler beim Prüfen des Update-Status", ['error' => $e->getMessage()], 'ERROR');
        }
    }
    
    $skipFullUpdate = true;
} else {
    // WEB-Modus: Web macht alles (wie bisher)
    debugLog("WEB-Modus aktiv - Web führt vollständige Updates durch", null, 'INFO');
}


try {
    // API-Daten IMMER abrufen (in beiden Modi für Live-Anzeige)
    $apiUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $selectedDeviceId . '/points';
    
    debugLog("API-URL", ['url' => $apiUrl, 'deviceId' => $selectedDeviceId], 'INFO');
    
    $jsonData = fetchApiData($apiUrl, API_KEY, API_USERNAME, API_PASSWORD);
    $rawData = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON Dekodierungs-Fehler');
    }
    
    $data = processApiData($rawData, $selectedDeviceId);
    
    // Datenbank-Operationen NUR im WEB-Modus
    if (USE_DB && !$skipFullUpdate) {
        // WEB-Modus: Volle DB-Updates
        try {
            // Device-Discovery (neue Devices erkennen)
            try {
                $discoveredDevices = discoverDevices();
                foreach ($discoveredDevices as $apiDevice) {
                    saveDevice($apiDevice);
                }
                debugLog("Device-Discovery durchgeführt", ['anzahl' => count($discoveredDevices)], 'INFO');
            } catch (Exception $e) {
                debugLog("Device-Discovery fehlgeschlagen", ['error' => $e->getMessage()], 'WARNING');
            }
            
            // Master-Tabelle aktualisieren
            $dbSaveResult = saveAllDatapoints($data, $selectedDeviceId);
            debugLog("Master-Tabelle aktualisiert", $dbSaveResult, 'DB');
            
            // Wertänderungen loggen
            logValueChanges($data, $selectedDeviceId);
            debugLog("Wertänderungen geloggt", null, 'DB_LOG');
            
            // Notifications prüfen
            try {
                $alarms = fetchNotifications($selectedDeviceId);
                foreach ($alarms as $alarm) {
                    saveNotification($selectedDeviceId, $alarm);
                }
                debugLog("Notifications geprüft", ['anzahl' => count($alarms)], 'INFO');
            } catch (Exception $e) {
                debugLog("Notification-Check fehlgeschlagen", ['error' => $e->getMessage()], 'WARNING');
            }
            
            // last_updated aktualisieren
            $pdo = getDbConnection();
            $stmt = $pdo->prepare("UPDATE nibe_device SET last_updated = NOW() WHERE deviceId = ?");
            $stmt->execute([$selectedDeviceId]);
            debugLog("last_updated gesetzt für Device {$selectedDeviceId}", null, 'DB');
            
        } catch (Exception $e) {
            debugLog("Fehler bei DB-Operationen", ['error' => $e->getMessage()], 'ERROR');
        }
    } elseif ($skipFullUpdate) {
        // CRON-Modus: Keine DB-Updates, nur Live-Anzeige
        debugLog("CRON-Modus: DB-Updates übersprungen, nur Live-Anzeige", null, 'INFO');
    } else {
        // DB deaktiviert
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
    <style>
        .device-selector {
            background: #e3f2fd;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 15px;
            flex-wrap: wrap;
        }
        .device-selector label {
            margin: 0;
            font-weight: 600;
            color: #1976D2;
        }
        .device-selector select {
            flex: 1;
            min-width: 300px;
            padding: 10px;
            border: 2px solid #2196F3;
            border-radius: 4px;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-refresh-devices {
            background: #2196F3;
            color: white;
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn-refresh-devices:hover {
            background: #1976D2;
        }
        .btn-notifications {
            background: #FF9800;
            color: white;
        }
        .btn-notifications:hover {
            background: #F57C00;
        }
        .device-info {
            font-size: 13px;
            color: #666;
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        .update-warning {
            background: #fff3cd;
            border-left: 4px solid #ff9800;
            padding: 15px 20px;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .update-warning-icon {
            font-size: 32px;
            flex-shrink: 0;
        }
        
        .update-warning-content {
            flex: 1;
        }
        
        .update-warning-title {
            font-weight: 600;
            color: #f57c00;
            margin-bottom: 5px;
        }
        
        .update-warning-details {
            color: #666;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .update-warning-actions {
            margin-top: 10px;
        }
        
        .btn-warning {
            background: #ff9800;
            color: white;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-warning:hover {
            background: #f57c00;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><span class="live-indicator" id="liveIndicator"></span>📊 Nibe API Datenpunkte Übersicht <?php echo $version?></h1>
        <?php if ($updateWarning): ?>
        <div class="update-warning">
            <div class="update-warning-icon">⚠️</div>
            <div class="update-warning-content">
                <div class="update-warning-title"><?php echo htmlspecialchars($updateWarning['message']); ?></div>
                <div class="update-warning-details">
                    <?php echo htmlspecialchars($updateWarning['details']); ?><br>
                    Letztes Update: <strong><?php echo htmlspecialchars($updateWarning['lastUpdate']); ?></strong>
                    (vor <?php echo round($updateWarning['secondsAgo'] / 60); ?> Minuten)
                </div>
                <div class="update-warning-actions">
                    <a href="https://<?php echo $_SERVER['HTTP_HOST']; ?>/path/to/notification-monitor.php" 
                       class="btn-warning" target="_blank">
                        📋 Cronjob-Dokumentation
                    </a>
                    <button class="btn-warning" onclick="checkCronjobStatus()">
                        🔄 Status prüfen
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
 
        <?php if (USE_DB && !empty($devices)): ?>
        <div class="device-selector">
            <label for="deviceSelect">🔧 Device:</label>
            <select id="deviceSelect" onchange="changeDevice(this.value)">
                <?php foreach ($devices as $device): ?>
                    <option value="<?php echo $device['deviceId']; ?>" 
                            <?php echo $device['deviceId'] == $selectedDeviceId ? 'selected' : ''; ?>>
                        Device <?php echo $device['deviceId']; ?> - <?php echo htmlspecialchars($device['name']); ?>
                        (<?php echo htmlspecialchars($device['serialNumber']); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn-refresh-devices" onclick="refreshDevices()">🔄 Devices aktualisieren</button>
        </div>
        
        <?php if ($selectedDeviceId !== null && $selectedDeviceId !== 0): ?>
            <?php 
            $currentDevice = array_filter($devices, fn($d) => $d['deviceId'] == $selectedDeviceId);
            $currentDevice = reset($currentDevice);
            if ($currentDevice):
            ?>
            <div class="device-info">
                <div><strong>Hersteller:</strong> <?php echo htmlspecialchars($currentDevice['manufacturer'] ?? '-'); ?></div>
                <div><strong>Firmware:</strong> <?php echo htmlspecialchars($currentDevice['firmwareId'] ?? '-'); ?></div>
                <div><strong>AID Mode:</strong> <?php echo htmlspecialchars($currentDevice['aidMode'] ?? '-'); ?></div>
                <div><strong>Smart Mode:</strong> <?php echo htmlspecialchars($currentDevice['smartMode'] ?? '-'); ?></div>
            </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php elseif (USE_DB): ?>
        <div class="device-selector" style="background: #fff3cd; border-left: 4px solid #ff9800;">
            <div style="flex: 1;">
                <strong>⚠️ Keine Devices gefunden</strong><br>
                <small>Klicken Sie auf "Devices aktualisieren" um eine automatische Erkennung durchzuführen.</small>
            </div>
            <button class="btn-refresh-devices" onclick="refreshDevices()">🔄 Devices aktualisieren</button>
        </div>
        <?php endif; ?>
        
        <div id="errorContainer"></div>
        
        <div class="filter-section">
            <div class="filter-toggles">
                <div class="toggle-option">
                    <input type="checkbox" id="filterSelectedOnly">
                    <label for="filterSelectedOnly">Nur ausgewählte anzeigen</label>
                </div>
                <div class="toggle-option">
                    <input type="checkbox" id="showTooltips" >
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
                    <label for="filterMenuepunkt">Menüpunkt</label>
                    <input type="text" id="filterMenuepunkt" placeholder="Filter nach Menüpunkt...">
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
                        <button class="btn-history" onclick="window.location.href='history.php'">📊 Historie</button>
                        <button class="btn-notifications" onclick="window.location.href='notifications.php'">🔔 Notifications</button>
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
                        <th class="sortable" onclick="sortTable(3)">Menüpunkt</th>
                        <th class="sortable" onclick="sortTable(4)">Title</th>
                        <th class="sortable" onclick="sortTable(5)">Modbus RT</th>
                        <th class="sortable" onclick="sortTable(6)">Value</th>
                        <?php if (USE_DB): ?>
                            <th>History</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if ($error): ?>
                        <tr><td colspan="<?php echo USE_DB ? '8' : '7'; ?>" class="no-data error">Fehler: <?php echo htmlspecialchars($error); ?></td></tr>
                    <?php elseif (empty($data)): ?>
                        <tr><td colspan="<?php echo USE_DB ? '8' : '7'; ?>" class="no-data">Keine Daten verfügbar</td></tr>
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
                                data-menuepunkt="<?php echo htmlspecialchars($point['menuepunkt'] ?? ''); ?>"
                                onmouseenter="showTooltip(event, this)"
                                onmouseleave="hideTooltip()">
                                <td class="checkbox-cell"><input type="checkbox" class="row-checkbox" onchange="updateSelectAllState()"></td>
                                <td><?php echo $point['variableid']; ?></td>
                                <td><?php echo $point['modbusregisterid']; ?></td>
                                <td class="menuepunkt-cell">
                                    <?php if (USE_DB): ?>
                                        <div class="menuepunkt-display">
                                            <span class="menuepunkt-value"><?php echo $point['menuepunkt'] ? htmlspecialchars($point['menuepunkt']) : '-'; ?></span>
                                            <button class="btn-menu" onclick="editMenuepunkt(<?php echo $point['variableid']; ?>, '<?php echo htmlspecialchars($point['title'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($point['menuepunkt'] ?? '', ENT_QUOTES); ?>')" title="Menüpunkt bearbeiten">📝</button>
                                        </div>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
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
    
    <!-- Alle Modals bleiben gleich -->
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
    
    <!-- Menüpunkt Modal -->
    <div class="edit-modal" id="menuepunktModal">
        <div class="edit-content">
            <div class="edit-header">
                <h2>📝 Menüpunkt bearbeiten</h2>
                <div class="edit-info" id="menuepunktTitle"></div>
                <div class="edit-info" id="menuepunktApiId"></div>
            </div>
            
            <div id="menuepunktFormArea">
                <div class="edit-current-value">
                    Aktueller Menüpunkt: <strong id="menuepunktCurrentValue">-</strong>
                </div>
                
                <div class="edit-form">
                    <div class="edit-form-group">
                        <label for="menuepunktInput">Menüpunkt (z.B. 1.30.1)</label>
                        <input type="text" id="menuepunktInput" placeholder="Menüpunkt eingeben (leer lassen zum Löschen)">
                        <div class="edit-limits" style="color: #666;">Format: Beliebiger Text, z.B. "1.30.1" oder "Heizung/Warmwasser"</div>
                    </div>
                </div>
                
                <div class="edit-buttons">
                    <button class="btn-edit-save" onclick="saveMenuepunkt()">💾 Speichern</button>
                    <button class="btn-edit-cancel" onclick="closeMenuepunktDialog()">✖ Abbrechen</button>
                </div>
            </div>
            
            <div id="menuepunktSaving" class="edit-saving" style="display: none;">
                💾 Speichere Menüpunkt...
            </div>
        </div>
    </div>
    
    <script src="assets/script.js"></script>
    <script>
        // Globale Device ID für AJAX-Requests
         currentDeviceId = <?php echo $selectedDeviceId ?? 0; ?>;
        
        // Device wechseln
        function changeDevice(deviceId) {
            window.location.href = '?deviceId=' + deviceId;
        }
        
        // Devices neu laden
        async function refreshDevices() {
            if (!confirm('Möchten Sie die Device-Liste von der API neu laden?')) {
                return;
            }
            
            try {
                const response = await fetch('?ajax=discoverDevices');
                const result = await response.json();
                
                if (result.success) {
                    if (result.discovered > 0) {
                        alert(`✅ ${result.discovered} Device(s) gefunden, ${result.saved} gespeichert`);
                        window.location.reload();
                    } else {
                        alert('⚠️ Keine Devices gefunden. Prüfen Sie:\n\n' +
                              '1. Ist die API erreichbar?\n' +
                              '2. Sind die Zugangsdaten korrekt?\n' +
                              '3. Läuft das Nibe-System?\n\n' +
                              'Details im Debug-Log (logs/nibe.log)');
                    }
                } else {
                    alert('❌ Fehler: ' + result.error);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler: ' + error.message);
            }
        }
        
        // App initialisieren mit Server-Daten
        window.addEventListener('DOMContentLoaded', () => {
            const initialData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
            const config = {
                hideValues: <?php echo json_encode(HIDE_VALUES); ?>,
                apiUpdateInterval: <?php echo API_UPDATE_INTERVAL; ?>,
                availableIntervals: <?php echo json_encode(API_UPDATE_INTERVALS); ?>,
                useDb: <?php echo USE_DB ? 'true' : 'false'; ?>,
                deviceId: currentDeviceId
            };
            
            initializeApp(initialData, config);
        });
        function checkCronjobStatus() {
            alert('Cronjob-Status prüfen:\n\n' +
                  '1. SSH zum Server verbinden\n' +
                  '2. Cronjob-Liste prüfen: crontab -l\n' +
                  '3. Log-Datei prüfen: <?php echo DEBUG_LOG_FULLPATH; ?>\n' +
                  '4. Manuell testen: php <?php echo __DIR__; ?>/notification-monitor.php --debug\n\n' +
                  'Erwarteter Cronjob:\n' +
                  '* * * * * /usr/bin/php <?php echo __DIR__; ?>/notification-monitor.php');
        }

    </script>
</body>
</html>
