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
 * API-Daten verarbeiten und in einheitliches Format konvertieren
 */
function processApiData($rawData) {
    $data = [];
    
    foreach ($rawData as $id => $point) {
        $intValue = $point['value']['integerValue'] ?? 0;
        $divisor = $point['metadata']['divisor'] ?? 1;
        $decimal = $point['metadata']['decimal'] ?? 0;
        $unit = $point['metadata']['unit'] ?? '';
        
        $calculatedValue = $divisor > 1 ? 
            number_format($intValue / $divisor, $decimal, ',', '.') : $intValue;
        
        $data[] = [
            'variableid' => $point['metadata']['variableId'] ?? $id,
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
            'maxValue' => $point['metadata']['maxValue'] ?? 0
        ];
    }
    
    return $data;
}
?>
