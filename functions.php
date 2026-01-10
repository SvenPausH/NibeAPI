<?php
/**
 * Allgemeine Hilfsfunktionen
 * functions.php
 */

/**
 * Debug-Logging Funktion
 */
function debugLog($message, $data = null, $type = 'INFO') {
    if (!DEBUG_LOGGING) {
        return;
    }
    
    try {
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
        @file_put_contents(DEBUG_LOG_FULLPATH, $logEntry, FILE_APPEND | LOCK_EX);
        
    } catch (Exception $e) {
        error_log("Debug-Log Fehler: " . $e->getMessage());
    }
}

/**
 * Funktion zum Abrufen der API-Daten
 */
function fetchApiData($url, $apiKey = '', $username = '', $password = '') {
    debugLog("API-Aufruf gestartet", ['url' => $url], 'API');
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Accept-Language: de-DE,de;q=0.9',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ];
    
    if (!empty($apiKey)) {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_COOKIEJAR, '');
    curl_setopt($ch, CURLOPT_COOKIEFILE, '');
    
    if (!empty($username) && !empty($password)) {
        curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    }
    
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $startTime = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $startTime) * 1000, 2);
    
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        debugLog("cURL Fehler", ['error' => $error], 'ERROR');
        throw new Exception('cURL Fehler: ' . $error);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    debugLog("API-Antwort erhalten", ['httpCode' => $httpCode, 'duration_ms' => $duration], 'API');
    
    if ($httpCode !== 200) {
        throw new Exception('HTTP Fehler: Status Code ' . $httpCode);
    }
    
    return $response;
}

/**
 * Alle Devices von der API abrufen
 * Version 3.4.00 - Mit verbessertem Debugging
 */
function discoverDevices() {
    try {
        $url = API_DEVICES_ENDPOINT;
        debugLog("Device-Discovery gestartet", ['url' => $url], 'DEVICES');
        
        $jsonData = fetchApiData($url, API_KEY, API_USERNAME, API_PASSWORD);
        $data = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Dekodierungs-Fehler bei Device-Discovery: ' . json_last_error_msg());
        }
        
        debugLog("Device-Discovery JSON Response", ['raw' => $data], 'DEVICES');
        
        if (!isset($data['devices'])) {
            debugLog("Keine 'devices' Key in API-Antwort", ['keys' => array_keys($data)], 'WARNING');
            throw new Exception('Keine Devices in API-Antwort gefunden. Verfügbare Keys: ' . implode(', ', array_keys($data)));
        }
        
        if (!is_array($data['devices'])) {
            throw new Exception('devices ist kein Array');
        }
        
        debugLog("Devices entdeckt", ['anzahl' => count($data['devices'])], 'DEVICES');
        
        return $data['devices'];
        
    } catch (Exception $e) {
        debugLog("Fehler bei Device-Discovery", ['error' => $e->getMessage()], 'ERROR');
        throw $e;
    }
}
/**
 * Notifications für ein Device abrufen
 */
function fetchNotifications($deviceId) {
    try {
        $url = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/notifications';
        $jsonData = fetchApiData($url, API_KEY, API_USERNAME, API_PASSWORD);
        $data = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Dekodierungs-Fehler bei Notifications');
        }
        
        $alarms = $data['alarms'] ?? [];
        
        debugLog("Notifications abgerufen", ['deviceId' => $deviceId, 'anzahl' => count($alarms)], 'API');
        
        return $alarms;
        
    } catch (Exception $e) {
        debugLog("Fehler beim Abrufen von Notifications", ['deviceId' => $deviceId, 'error' => $e->getMessage()], 'ERROR');
        throw $e;
    }
}

/**
 * Notifications für ein Device zurücksetzen
 */
function resetNotifications($deviceId) {
    try {
        $url = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/notifications';
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = [
            'Accept: application/json',
            'Content-Type: application/json'
        ];
        
        if (!empty(API_USERNAME) && !empty(API_PASSWORD)) {
            $basicAuth = base64_encode(API_USERNAME . ':' . API_PASSWORD);
            $headers[] = 'Authorization: Basic ' . $basicAuth;
        } elseif (!empty(API_KEY)) {
            $headers[] = 'Authorization: Bearer ' . API_KEY;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            debugLog("Notifications zurückgesetzt", ['deviceId' => $deviceId], 'API');
            return true;
        } else {
            throw new Exception('HTTP Fehler: ' . $httpCode);
        }
        
    } catch (Exception $e) {
        debugLog("Fehler beim Zurücksetzen von Notifications", ['deviceId' => $deviceId, 'error' => $e->getMessage()], 'ERROR');
        throw $e;
    }
}

/**
 * API-Daten verarbeiten und in einheitliches Format konvertieren
 * Version 3.4.01 - MIT METADATA SPEICHERUNG
 */
function processApiData($rawData, $deviceId = null) {
    $data = [];
    
    // Menüpunkte laden wenn DB aktiv
    $menuepunkte = [];
    if (USE_DB) {
        try {
            $menuepunkte = getAllMenupunkte();
        } catch (Exception $e) {
            debugLog("Fehler beim Laden der Menüpunkte", ['error' => $e->getMessage()], 'WARNING');
        }
    }
    
    foreach ($rawData as $id => $point) {
        $intValue = $point['value']['integerValue'] ?? 0;
        $divisor = $point['metadata']['divisor'] ?? 1;
        $decimal = $point['metadata']['decimal'] ?? 0;
        $unit = $point['metadata']['unit'] ?? '';
        $apiId = $point['metadata']['variableId'] ?? $id;
        
        $calculatedValue = $divisor > 1 ? 
            number_format($intValue / $divisor, $decimal, ',', '.') : $intValue;
        
        $dataPoint = [
            'deviceId' => $deviceId,
            'variableid' => $apiId,
            'modbusregisterid' => $point['metadata']['modbusRegisterID'] ?? '-',
            'title' => $point['title'] ?? '-',
            'description' => $point['description'] ?? '',
            'modbusregistertype' => $point['metadata']['modbusRegisterType'] ?? '-',
            'value' => $calculatedValue . ($unit ? ' ' . $unit : ''),
            'rawvalue' => $intValue,
            'unit' => $unit,
            'divisor' => $divisor,
            'decimal' => $decimal,
            'isWritable' => $point['metadata']['isWritable'] ?? false,
            'variableType' => $point['metadata']['variableType'] ?? '-',
            'variableSize' => $point['metadata']['variableSize'] ?? '-',
            'minValue' => $point['metadata']['minValue'] ?? 0,
            'maxValue' => $point['metadata']['maxValue'] ?? 0,
            'menuepunkt' => $menuepunkte[$apiId] ?? null
        ];
        
        // NEU: Metadaten in DB speichern (falls Spalten existieren)
        if (USE_DB) {
            try {
                saveDatapointMetadata($apiId, $divisor, $decimal, $unit);
            } catch (Exception $e) {
                // Fehler beim Speichern ignorieren (Spalten existieren evtl. noch nicht)
                // debugLog würde zu viel Output erzeugen
            }
        }
        
        $data[] = $dataPoint;
    }
    
    return $data;
}

/**
 * Hilfsfunktion: Speichert Divisor, Decimal und Unit in DB
 * Wird von processApiData() automatisch aufgerufen
 */
function saveDatapointMetadata($apiId, $divisor, $decimal, $unit) {
    static $columnsChecked = false;
    static $columnsExist = false;
    
    // Einmalig prüfen ob Spalten existieren
    if (!$columnsChecked) {
        try {
            $pdo = getDbConnection();
            $stmt = $pdo->query("SHOW COLUMNS FROM nibe_datenpunkte LIKE 'divisor'");
            $columnsExist = (bool)$stmt->fetch();
            $columnsChecked = true;
        } catch (Exception $e) {
            $columnsChecked = true;
            $columnsExist = false;
            return false;
        }
    }
    
    // Wenn Spalten nicht existieren, abbrechen
    if (!$columnsExist) {
        return false;
    }
    
    try {
        $pdo = getDbConnection();
        
        // Nur aktualisieren wenn sich Werte geändert haben
        $stmt = $pdo->prepare("
            UPDATE nibe_datenpunkte 
            SET divisor = ?, decimal_places = ?, unit = ?
            WHERE api_id = ? 
            AND (divisor != ? OR decimal_places != ? OR unit != ?)
        ");
        $stmt->execute([$divisor, $decimal, $unit, $apiId, $divisor, $decimal, $unit]);
        
        return $stmt->rowCount() > 0;
        
    } catch (Exception $e) {
        // Fehler ignorieren
        return false;
    }
}
?>
