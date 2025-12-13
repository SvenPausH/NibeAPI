<?php
/**
 * API Daten Abruf mit Auto-Refresh
 * Hauptdatei: index.php
 */

// UTF-8 Encoding sicherstellen
header('Content-Type: text/html; charset=utf-8');
mb_internal_encoding('UTF-8');

// Konfigurationsdatei einbinden
require_once 'config.php';

/**
 * Debug-Logging Funktion
 */
function debugLog($message, $data = null, $type = 'INFO') {
    if (!DEBUG_LOGGING) {
        return;
    }
    
    try {
        // Prüfen ob Log-Datei zu groß ist (Log-Rotation)
        if (file_exists(DEBUG_LOG_FULLPATH) && filesize(DEBUG_LOG_FULLPATH) > DEBUG_LOG_MAX_SIZE) {
            $backupFile = DEBUG_LOG_PATH . date('Y-m-d_His') . '_' . DEBUG_LOG_FILE;
            @rename(DEBUG_LOG_FULLPATH, $backupFile);
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "[$timestamp] [$type] $message";
        
        if ($data !== null) {
            if (is_array($data) || is_object($data)) {
                $logEntry .= "\n" . print_r($data, true);
            } else {
                $logEntry .= " | Data: " . $data;
            }
        }
        
        $logEntry .= "\n" . str_repeat('-', 80) . "\n";
        
        // In Datei schreiben
        @file_put_contents(DEBUG_LOG_FULLPATH, $logEntry, FILE_APPEND | LOCK_EX);
        
    } catch (Exception $e) {
        // Fehler beim Logging ignorieren, um Hauptfunktionalität nicht zu stören
        error_log("Debug-Log Fehler: " . $e->getMessage());
    }
}

/**
 * Funktion zum Abrufen der API-Daten
 */
function fetchApiData($url, $apiKey = '', $username = '', $password = '') {
    debugLog("API-Aufruf gestartet", ['url' => $url, 'hasApiKey' => !empty($apiKey), 'hasBasicAuth' => !empty($username)], 'API');
    
    $ch = curl_init();
    
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Headers setzen mit deutscher Sprache
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Language: de-DE,de;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
    ];
    
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        debugLog("Bearer Token hinzugefügt", null, 'AUTH');
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Browser-ähnliche Cookie-Behandlung
    curl_setopt($ch, CURLOPT_COOKIEJAR, '');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');
    
    // Basic Authentication (falls benötigt)
    if (!empty($username) && !empty($password)) {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
        debugLog("Basic Auth hinzugefügt", ['username' => $username], 'AUTH');
    }
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    // Request-Start-Zeit
    $startTime = microtime(true);
    
    $response = curl_exec($ch);
    
    // Request-Dauer berechnen
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        debugLog("cURL Fehler", ['error' => $error, 'duration_ms' => $duration], 'ERROR');
        throw new Exception('cURL Fehler: ' . $error);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlInfo = curl_getinfo($ch);
    curl_close($ch);
    
    debugLog("API-Antwort erhalten", [
        'httpCode' => $httpCode,
        'duration_ms' => $duration,
        'response_size' => strlen($response),
        'content_type' => $curlInfo['content_type'] ?? 'unknown'
    ], 'API');
    
    if ($httpCode !== 200) {
        debugLog("HTTP Fehler", ['httpCode' => $httpCode, 'response' => substr($response, 0, 500)], 'ERROR');
        throw new Exception('HTTP Fehler: Status Code ' . $httpCode);
    }
    
    return $response;
}

// Prüfen ob AJAX-Request zum Schreiben
if (isset($_GET['ajax']) && $_GET['ajax'] === 'write') {
    header('Content-Type: application/json; charset=utf-8');
    
    debugLog("PATCH-Request empfangen", null, 'WRITE');
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $variableId = $input['variableId'] ?? null;
        $newValue = $input['value'] ?? null;
        
        debugLog("PATCH-Request Daten", ['variableId' => $variableId, 'newValue' => $newValue], 'WRITE');
        
        if ($variableId === null || $newValue === null) {
            throw new Exception('Fehlende Parameter');
        }
        
        // PATCH Request an die API senden
        $writeUrl = API_URL;
        
        debugLog("PATCH-Request wird gesendet", ['url' => $writeUrl], 'WRITE');
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $writeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'Accept-Language: de-DE,de;q=0.9'
        ];
        
        // Basic Auth (wenn konfiguriert)
        if (!empty(API_USERNAME) && !empty(API_PASSWORD)) {
            $basicAuth = base64_encode(API_USERNAME . ':' . API_PASSWORD);
            $headers[] = 'Authorization: Basic ' . $basicAuth;
            debugLog("Basic Auth hinzugefügt", ['username' => API_USERNAME], 'AUTH');
        }
        // Fallback auf Bearer Token
        elseif (!empty(API_KEY)) {
            $headers[] = 'Authorization: Bearer ' . API_KEY;
            debugLog("Bearer Token hinzugefügt", null, 'AUTH');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // JSON Body mit vollständiger Struktur gemäß API-Spezifikation
        // Body ist ein Array von datavalue-Objekten
        $bodyData = [
            [
                'type' => 'datavalue',
                'isOk' => true,
                'variableId' => (int)$variableId,
                'integerValue' => (int)$newValue,
                'stringValue' => ''
            ]
        ];
        
        $postData = json_encode($bodyData);
        
        debugLog("PATCH Request Body (vollständig)", ['body' => $postData, 'bodyArray' => $bodyData], 'WRITE');
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        
        $startTime = microtime(true);
        $response = curl_exec($ch);
        $duration = round((microtime(true) - $startTime) * 1000, 2);
        
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            debugLog("PATCH cURL Fehler", ['error' => $error], 'ERROR');
            curl_close($ch);
            throw new Exception('cURL Fehler: ' . $error);
        }
        
        debugLog("PATCH Response erhalten", [
            'httpCode' => $httpCode,
            'duration_ms' => $duration,
            'response' => $response
        ], 'WRITE');
        
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            debugLog("PATCH erfolgreich", ['variableId' => $variableId, 'value' => $newValue], 'SUCCESS');
            echo json_encode([
                'success' => true,
                'message' => 'Wert erfolgreich gespeichert',
                'httpCode' => $httpCode
            ], JSON_UNESCAPED_UNICODE);
        } else {
            debugLog("PATCH Fehler", ['httpCode' => $httpCode, 'response' => $response], 'ERROR');
            throw new Exception('HTTP Fehler: ' . $httpCode . ' - ' . $response);
        }
        
    } catch (Exception $e) {
        debugLog("PATCH Exception", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Prüfen ob AJAX-Request zum Lesen
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    
    debugLog("AJAX Fetch-Request empfangen", null, 'FETCH');
    
    try {
        $jsonData = fetchApiData(API_URL, API_KEY, API_USERNAME, API_PASSWORD);
        
        // UTF-8 Encoding sicherstellen
        if (mb_detect_encoding($jsonData, 'UTF-8', true) === false) {
            $jsonData = utf8_encode($jsonData);
            debugLog("JSON zu UTF-8 konvertiert", null, 'FETCH');
        }
        
        $rawData = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            debugLog("JSON Dekodierungs-Fehler", ['error' => json_last_error_msg()], 'ERROR');
            throw new Exception('JSON Dekodierungs-Fehler: ' . json_last_error_msg());
        }
        
        debugLog("JSON erfolgreich dekodiert", ['datapoints' => count($rawData)], 'FETCH');
        
        // JSON-Struktur umwandeln
        $data = [];
        foreach ($rawData as $id => $point) {
            $variableId = $point['metadata']['variableId'] ?? $id;
            $modbusRegisterID = $point['metadata']['modbusRegisterID'] ?? '-';
            $title = $point['title'] ?? '-';
            $modbusRegisterType = $point['metadata']['modbusRegisterType'] ?? '-';
            $isWritable = $point['metadata']['isWritable'] ?? false;
            
            $intValue = $point['value']['integerValue'] ?? 0;
            $divisor = $point['metadata']['divisor'] ?? 1;
            $unit = $point['metadata']['unit'] ?? '';
            $decimal = $point['metadata']['decimal'] ?? 0;
            
            if ($divisor > 1) {
                $calculatedValue = number_format($intValue / $divisor, $decimal, ',', '.');
            } else {
                $calculatedValue = $intValue;
            }
            
            $value = $calculatedValue . ($unit ? ' ' . $unit : '');
            
            $data[] = [
                'variableid' => $variableId,
                'modbusregisterid' => $modbusRegisterID,
                'title' => $title,
                'modbusregistertype' => $modbusRegisterType,
                'value' => $value,
                'rawvalue' => $intValue,
                'unit' => $unit,
                'divisor' => $divisor,
                'decimal' => $decimal,
                'isWritable' => $isWritable
            ];
        }
        
        debugLog("Daten erfolgreich verarbeitet", ['count' => count($data)], 'SUCCESS');
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        debugLog("Fetch Exception", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Initiale Daten laden für erste Anzeige
$error = null;
$data = [];

debugLog("Seite geladen - Initiale Daten werden abgerufen", null, 'INIT');

try {
    $jsonData = fetchApiData(API_URL, API_KEY, API_USERNAME, API_PASSWORD);
    
    if (mb_detect_encoding($jsonData, 'UTF-8', true) === false) {
        $jsonData = utf8_encode($jsonData);
    }
    
    $rawData = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON Dekodierungs-Fehler: ' . json_last_error_msg());
    }
    
    foreach ($rawData as $id => $point) {
        $variableId = $point['metadata']['variableId'] ?? $id;
        $modbusRegisterID = $point['metadata']['modbusRegisterID'] ?? '-';
        $title = $point['title'] ?? '-';
        $modbusRegisterType = $point['metadata']['modbusRegisterType'] ?? '-';
        $isWritable = $point['metadata']['isWritable'] ?? false;
        
        $intValue = $point['value']['integerValue'] ?? 0;
        $divisor = $point['metadata']['divisor'] ?? 1;
        $unit = $point['metadata']['unit'] ?? '';
        $decimal = $point['metadata']['decimal'] ?? 0;
        
        if ($divisor > 1) {
            $calculatedValue = number_format($intValue / $divisor, $decimal, ',', '.');
        } else {
            $calculatedValue = $intValue;
        }
        
        $value = $calculatedValue . ($unit ? ' ' . $unit : '');
        
        $data[] = [
            'variableid' => $variableId,
            'modbusregisterid' => $modbusRegisterID,
            'title' => $title,
            'modbusregistertype' => $modbusRegisterType,
            'value' => $value,
            'rawvalue' => $intValue,
            'unit' => $unit,
            'divisor' => $divisor,
            'decimal' => $decimal,
            'isWritable' => $isWritable
        ];
    }
    
    debugLog("Initiale Daten erfolgreich geladen", ['count' => count($data)], 'SUCCESS');
    
} catch (Exception $e) {
    $error = $e->getMessage();
    debugLog("Fehler beim Laden der initialen Daten", ['error' => $error], 'ERROR');
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Datenpunkte - Live</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 20px;
            font-size: 28px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .live-indicator {
            width: 12px;
            height: 12px;
            background: #4CAF50;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .live-indicator.error {
            background: #f44336;
            animation: none;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .filter-row {
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-group {
            flex: 1;
            min-width: 200px;
        }
        
        label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
            font-size: 14px;
        }
        
        input[type="text"], select {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        input[type="text"]:focus, select:focus {
            outline: none;
            border-color: #4CAF50;
        }
        
        .button-group {
            display: flex;
            gap: 10px;
            align-items: flex-end;
        }
        
        button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        .btn-toggle {
            background: #4CAF50;
            color: white;
        }
        
        .btn-toggle:hover {
            background: #45a049;
        }
        
        .btn-toggle.paused {
            background: #ff9800;
        }
        
        .info-bar {
            background: #e7f3ff;
            padding: 12px;
            border-left: 4px solid #2196F3;
            margin-bottom: 20px;
            border-radius: 4px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .info-left {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .last-update {
            font-size: 13px;
            color: #666;
        }
        
        .error {
            background: #ffebee;
            color: #c62828;
            padding: 15px;
            border-left: 4px solid #c62828;
            border-radius: 4px;
            margin-bottom: 20px;
        }
        
        .table-wrapper {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }
        
        thead {
            background: #2196F3;
            color: white;
        }
        
        th {
            padding: 15px;
            text-align: left;
            font-weight: 600;
            cursor: pointer;
            user-select: none;
        }
        
        th:hover {
            background: #1976D2;
        }
        
        th.sortable::after {
            content: ' ⇅';
            opacity: 0.5;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.3s;
        }
        
        tbody tr:hover {
            background: #f5f5f5;
        }
        
        tbody tr:nth-child(even) {
            background: #fafafa;
        }
        
        tbody tr:nth-child(even):hover {
            background: #f0f0f0;
        }
        
        .value-changed {
            background: #fff9c4 !important;
        }
        
        .value-cell {
            position: relative;
        }
        
        .editable-value {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .value-display {
            flex: 1;
        }
        
        .btn-edit {
            padding: 4px 8px;
            background: #2196F3;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            transition: background 0.3s;
        }
        
        .btn-edit:hover {
            background: #1976D2;
        }
        
        .edit-input {
            width: 150px;
            padding: 6px;
            border: 2px solid #2196F3;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .btn-save {
            padding: 4px 12px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            margin-right: 4px;
        }
        
        .btn-save:hover {
            background: #45a049;
        }
        
        .btn-cancel {
            padding: 4px 12px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
        }
        
        .btn-cancel:hover {
            background: #da190b;
        }
        
        .saving-indicator {
            color: #2196F3;
            font-size: 12px;
            font-style: italic;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
            font-style: italic;
        }
        
        @media (max-width: 768px) {
            .filter-row {
                flex-direction: column;
            }
            
            .filter-group {
                width: 100%;
            }
            
            table {
                font-size: 12px;
            }
            
            th, td {
                padding: 8px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>
            <span class="live-indicator" id="liveIndicator"></span>
            📊 API Datenpunkte Übersicht - Live
        </h1>
        
        <div id="errorContainer"></div>
        
        <div class="filter-section">
            <div class="filter-row">
                <div class="filter-group">
                    <label for="filterVariableId">API ID</label>
                    <input type="text" id="filterVariableId" placeholder="Filter nach API ID...">
                </div>
                <div class="filter-group">
                    <label for="filterModbusRegisterID">Modbus ID</label>
                    <input type="text" id="filterModbusRegisterID" placeholder="Filter nach modbusRegisterID...">
                </div>                
                
                <div class="filter-group">
                    <label for="filterTitle">Title</label>
                    <input type="text" id="filterTitle" placeholder="Filter nach Title...">
                </div>
                
                <div class="filter-group">
                    <label for="filterRegisterType">Modbus Register Type</label>
                    <select id="filterRegisterType">
                        <option value="">Alle</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label for="filterValue">Value</label>
                    <input type="text" id="filterValue" placeholder="Filter nach Value...">
                </div>
                
                <div class="button-group">
                    <button class="btn-reset" onclick="resetFilters()">Zurücksetzen</button>
                    <button class="btn-toggle" id="toggleButton" onclick="toggleAutoUpdate()">Pause</button>
                </div>
            </div>
        </div>
        
        <div class="info-bar">
            <div class="info-left">
                <div>Gesamt: <strong><span id="totalCount">0</span></strong> Einträge</div>
                <div>Angezeigt: <strong><span id="visibleCount">0</span></strong> Einträge</div>
            </div>
            <div class="last-update">
                Letzte Aktualisierung: <strong><span id="lastUpdate">-</span></strong>
            </div>
        </div>
        
        <div class="table-wrapper">
            <table id="dataTable">
                <thead>
                    <tr>
                        <th class="sortable" onclick="sortTable(0)">API ID</th>
                        <th class="sortable" onclick="sortTable(1)">Modbus ID</th>
                        <th class="sortable" onclick="sortTable(2)">Title</th>
                        <th class="sortable" onclick="sortTable(3)">Modbus Register Type</th>
                        <th class="sortable" onclick="sortTable(4)">Value</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if ($error): ?>
                        <tr>
                            <td colspan="4" class="no-data error">Fehler: <?php echo htmlspecialchars($error); ?></td>
                        </tr>
                    <?php elseif (empty($data)): ?>
                        <tr>
                            <td colspan="4" class="no-data">Keine Daten verfügbar</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $point): ?>
                            <tr data-variableid="<?php echo htmlspecialchars($point['variableid']); ?>">
                                <td><?php echo htmlspecialchars($point['variableid']); ?></td>
                                <td><?php echo htmlspecialchars($point['modbusregisterid']); ?></td>                                
                                <td><?php echo htmlspecialchars($point['title']); ?></td>
                                <td><?php echo htmlspecialchars($point['modbusregistertype']); ?></td>
                                <td class="value-cell">
                                    <?php if ($point['modbusregistertype'] === 'MODBUS_HOLDING_REGISTER' && $point['isWritable']): ?>
                                        <div class="editable-value">
                                            <span class="value-display"><?php echo htmlspecialchars($point['value']); ?></span>
                                            <button class="btn-edit" onclick="editValue(this)" title="Bearbeiten">✏️</button>
                                        </div>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($point['value']); ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <script>
        // Initiale Daten
        let tableData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
        let autoUpdateEnabled = true;
        let updateInterval = null;
        
        // Auto-Update starten
        function startAutoUpdate() {
            if (updateInterval) {
                clearInterval(updateInterval);
            }
            
            updateInterval = setInterval(fetchData, 10000); // Alle 10 Sekunden
            autoUpdateEnabled = true;
            
            document.getElementById('toggleButton').textContent = 'Pause';
            document.getElementById('toggleButton').classList.remove('paused');
            document.getElementById('liveIndicator').classList.remove('error');
        }
        
        // Auto-Update stoppen
        function stopAutoUpdate() {
            if (updateInterval) {
                clearInterval(updateInterval);
                updateInterval = null;
            }
            autoUpdateEnabled = false;
            
            document.getElementById('toggleButton').textContent = 'Start';
            document.getElementById('toggleButton').classList.add('paused');
        }
        
        // Toggle Auto-Update
        function toggleAutoUpdate() {
            if (autoUpdateEnabled) {
                stopAutoUpdate();
            } else {
                startAutoUpdate();
            }
        }
        
        // Daten vom Server abrufen
        async function fetchData() {
            try {
                const response = await fetch('?ajax=fetch');
                const result = await response.json();
                
                if (result.success) {
                    updateTable(result.data);
                    document.getElementById('lastUpdate').textContent = result.timestamp;
                    document.getElementById('liveIndicator').classList.remove('error');
                    clearError();
                } else {
                    showError('Fehler beim Laden der Daten: ' + result.error);
                    document.getElementById('liveIndicator').classList.add('error');
                }
            } catch (error) {
                showError('Verbindungsfehler: ' + error.message);
                document.getElementById('liveIndicator').classList.add('error');
            }
        }
        
        // Tabelle aktualisieren
        function updateTable(newData) {
            tableData = newData;
            const tbody = document.getElementById('tableBody');
            
            // Alte Werte speichern
            const oldValues = {};
            tbody.querySelectorAll('tr').forEach(row => {
                const variableId = row.getAttribute('data-variableid');
                const valueCell = row.cells[4];
                if (variableId && valueCell) {
                    oldValues[variableId] = valueCell.textContent.trim();
                }
            });
            
            // Tabelle neu aufbauen
            tbody.innerHTML = '';
            
            newData.forEach(point => {
                const row = tbody.insertRow();
                row.setAttribute('data-variableid', point.variableid);
                
                row.insertCell(0).textContent = point.variableid;
                row.insertCell(1).textContent = point.modbusregisterid;
                row.insertCell(2).textContent = point.title;
                row.insertCell(3).textContent = point.modbusregistertype;
                
                const valueCell = row.insertCell(4);
                valueCell.className = 'value-cell';
                
                // Editierbare Felder für MODBUS_HOLDING_REGISTER
                if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
                    const editableDiv = document.createElement('div');
                    editableDiv.className = 'editable-value';
                    
                    const valueSpan = document.createElement('span');
                    valueSpan.className = 'value-display';
                    valueSpan.textContent = point.value;
                    
                    const editBtn = document.createElement('button');
                    editBtn.className = 'btn-edit';
                    editBtn.textContent = '✏️';
                    editBtn.title = 'Bearbeiten';
                    editBtn.onclick = function() { editValue(this); };
                    
                    editableDiv.appendChild(valueSpan);
                    editableDiv.appendChild(editBtn);
                    valueCell.appendChild(editableDiv);
                    
                    // Zusätzliche Daten speichern
                    valueCell.setAttribute('data-rawvalue', point.rawvalue);
                    valueCell.setAttribute('data-divisor', point.divisor);
                    valueCell.setAttribute('data-decimal', point.decimal);
                    valueCell.setAttribute('data-unit', point.unit);
                } else {
                    valueCell.textContent = point.value;
                }
                
                // Wert-Änderung hervorheben
                const currentDisplayValue = point.value;
                if (oldValues[point.variableid] && oldValues[point.variableid] !== currentDisplayValue) {
                    valueCell.classList.add('value-changed');
                    setTimeout(() => {
                        valueCell.classList.remove('value-changed');
                    }, 2000);
                }
            });
            
            // Filter und Zähler aktualisieren
            initRegisterTypeFilter();
            filterTable();
            updateCounts();
        }
        
        // Wert bearbeiten
        function editValue(button) {
            const valueCell = button.closest('.value-cell');
            const editableDiv = valueCell.querySelector('.editable-value');
            const valueSpan = editableDiv.querySelector('.value-display');
            const currentValue = valueSpan.textContent;
            const variableId = valueCell.closest('tr').getAttribute('data-variableid');
            
            // Nur numerischen Wert extrahieren (ohne Einheit)
            const numericValue = currentValue.replace(/[^\d,.-]/g, '').replace(',', '.');
            
            // Input-Feld erstellen
            const input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.className = 'edit-input';
            input.value = numericValue;
            
            // Save Button
            const saveBtn = document.createElement('button');
            saveBtn.className = 'btn-save';
            saveBtn.textContent = '💾 Speichern';
            saveBtn.onclick = function() { saveValue(variableId, valueCell, input.value); };
            
            // Cancel Button
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-cancel';
            cancelBtn.textContent = '❌ Abbrechen';
            cancelBtn.onclick = function() { cancelEdit(valueCell, currentValue); };
            
            // Enter-Taste zum Speichern
            input.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    saveValue(variableId, valueCell, input.value);
                }
            });
            
            // ESC-Taste zum Abbrechen
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    cancelEdit(valueCell, currentValue);
                }
            });
            
            // Alten Inhalt durch Edit-Elemente ersetzen
            editableDiv.innerHTML = '';
            editableDiv.appendChild(input);
            editableDiv.appendChild(saveBtn);
            editableDiv.appendChild(cancelBtn);
            
            input.focus();
            input.select();
        }
        
        // Wert speichern
        async function saveValue(variableId, valueCell, newValue) {
            const editableDiv = valueCell.querySelector('.editable-value');
            const divisor = parseInt(valueCell.getAttribute('data-divisor')) || 1;
            const unit = valueCell.getAttribute('data-unit') || '';
            const decimal = parseInt(valueCell.getAttribute('data-decimal')) || 0;
            
            // Anzeige während des Speicherns
            editableDiv.innerHTML = '<span class="saving-indicator">💾 Speichere...</span>';
            
            try {
                // Wert mit Divisor multiplizieren für API (Rohwert)
                const rawValue = Math.round(parseFloat(newValue) * divisor);
                
                const response = await fetch('?ajax=write', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        variableId: variableId,
                        value: rawValue
                    })
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Formatierter Wert für Anzeige
                    let displayValue;
                    if (divisor > 1) {
                        displayValue = parseFloat(newValue).toFixed(decimal).replace('.', ',');
                    } else {
                        displayValue = newValue;
                    }
                    displayValue += (unit ? ' ' + unit : '');
                    
                    // Erfolgreiche Speicherung - Wert anzeigen
                    editableDiv.innerHTML = `
                        <span class="value-display">${displayValue}</span>
                        <button class="btn-edit" onclick="editValue(this)" title="Bearbeiten">✏️</button>
                    `;
                    
                    // Kurz grün markieren
                    valueCell.style.background = '#c8e6c9';
                    setTimeout(() => {
                        valueCell.style.background = '';
                    }, 1000);
                    
                    // Daten sofort neu laden
                    setTimeout(() => {
                        fetchData();
                    }, 500);
                    
                } else {
                    throw new Error(result.error || 'Unbekannter Fehler');
                }
                
            } catch (error) {
                alert('Fehler beim Speichern: ' + error.message);
                // Original-Wert wiederherstellen
                const point = tableData.find(p => p.variableid == variableId);
                if (point) {
                    editableDiv.innerHTML = `
                        <span class="value-display">${point.value}</span>
                        <button class="btn-edit" onclick="editValue(this)" title="Bearbeiten">✏️</button>
                    `;
                }
            }
        }
        
        // Bearbeitung abbrechen
        function cancelEdit(valueCell, originalValue) {
            const editableDiv = valueCell.querySelector('.editable-value');
            editableDiv.innerHTML = `
                <span class="value-display">${originalValue}</span>
                <button class="btn-edit" onclick="editValue(this)" title="Bearbeiten">✏️</button>
            `;
        }
        
        // Fehler anzeigen
        function showError(message) {
            const errorContainer = document.getElementById('errorContainer');
            errorContainer.innerHTML = `<div class="error"><strong>Fehler:</strong> ${message}</div>`;
        }
        
        // Fehler löschen
        function clearError() {
            document.getElementById('errorContainer').innerHTML = '';
        }
        
        // Filter-Optionen für Register Type initialisieren
        function initRegisterTypeFilter() {
            const registerTypes = new Set();
            tableData.forEach(point => {
                if (point.modbusregistertype) {
                    registerTypes.add(point.modbusregistertype);
                }
            });
            
            const select = document.getElementById('filterRegisterType');
            const currentValue = select.value;
            select.innerHTML = '<option value="">Alle</option>';
            
            registerTypes.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                if (type === currentValue) {
                    option.selected = true;
                }
                select.appendChild(option);
            });
        }
        
        // Tabelle filtern
        function filterTable() {
            const filterVariableId = document.getElementById('filterVariableId').value.toLowerCase();
            const filterModbusRegisterID = document.getElementById('filterModbusRegisterID').value.toLowerCase();
            const filterTitle = document.getElementById('filterTitle').value.toLowerCase();
            const filterRegisterType = document.getElementById('filterRegisterType').value.toLowerCase();
            const filterValue = document.getElementById('filterValue').value.toLowerCase();
            
            const tbody = document.getElementById('tableBody');
            const rows = tbody.getElementsByTagName('tr');
            let visibleCount = 0;
            
            for (let row of rows) {
                const cells = row.getElementsByTagName('td');
                if (cells.length === 0) continue;
                
                const variableId = cells[0].textContent.toLowerCase();
                const modbusRegisterID = cells[1].textContent.toLowerCase();
                const title = cells[2].textContent.toLowerCase();
                const registerType = cells[3].textContent.toLowerCase();
                const value = cells[4].textContent.toLowerCase();
                
                const matchVariableId = variableId.includes(filterVariableId);
                const matchModbusRegisterID = modbusRegisterID.includes(filterModbusRegisterID);
                const matchTitle = title.includes(filterTitle);
                const matchRegisterType = filterRegisterType === '' || registerType.includes(filterRegisterType);
                const matchValue = value.includes(filterValue);
                
                if (matchVariableId && matchModbusRegisterID && matchTitle && matchRegisterType && matchValue) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            }
            
            document.getElementById('visibleCount').textContent = visibleCount;
        }
        
        // Zähler aktualisieren
        function updateCounts() {
            const totalCount = tableData.length;
            document.getElementById('totalCount').textContent = totalCount;
        }
        
        // Filter zurücksetzen
        function resetFilters() {
            document.getElementById('filterVariableId').value = '';
            document.getElementById('filterModbusRegisterID').value = '';
            document.getElementById('filterTitle').value = '';
            document.getElementById('filterRegisterType').value = '';
            document.getElementById('filterValue').value = '';
            filterTable();
        }
        
        // Tabelle sortieren
        let sortDirection = {};
        function sortTable(columnIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.getElementsByTagName('tr'));
            
            const direction = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            sortDirection[columnIndex] = direction;
            
            rows.sort((a, b) => {
                const aValue = a.getElementsByTagName('td')[columnIndex].textContent.trim();
                const bValue = b.getElementsByTagName('td')[columnIndex].textContent.trim();
                
                const aNum = parseFloat(aValue);
                const bNum = parseFloat(bValue);
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return direction === 'asc' ? aNum - bNum : bNum - aNum;
                }
                
                return direction === 'asc' 
                    ? aValue.localeCompare(bValue)
                    : bValue.localeCompare(aValue);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }
        
        // Event Listener für Live-Filter
        document.getElementById('filterVariableId').addEventListener('input', filterTable);
        document.getElementById('filterModbusRegisterID').addEventListener('input', filterTable);
        document.getElementById('filterTitle').addEventListener('input', filterTable);
        document.getElementById('filterRegisterType').addEventListener('change', filterTable);
        document.getElementById('filterValue').addEventListener('input', filterTable);
        
        // Initialisierung
        window.addEventListener('DOMContentLoaded', () => {
            initRegisterTypeFilter();
            updateCounts();
            filterTable();
            document.getElementById('lastUpdate').textContent = new Date().toLocaleString('de-DE');
            
            // Auto-Update starten
            startAutoUpdate();
        });
        
        // Cleanup beim Verlassen der Seite
        window.addEventListener('beforeunload', () => {
            stopAutoUpdate();
        });
    </script>
</body>
</html>
