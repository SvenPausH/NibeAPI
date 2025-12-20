<?php
/**
 * API Daten Abruf mit Auto-Refresh - Version Version 3.0.47
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
 * Datenbankverbindung herstellen
 */
function getDbConnection() {
    if (!USE_DB) {
        throw new Exception('Datenbankfunktionen sind deaktiviert (USE_DB = false)');
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        
        $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
        debugLog("Datenbankverbindung erfolgreich hergestellt", null, 'DB');
        return $pdo;
        
    } catch (PDOException $e) {
        debugLog("Datenbankverbindung fehlgeschlagen", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
    }
}

/**
 * Beschreibbare Datenpunkte in Datenbank speichern/aktualisieren
 * Wird nur beim initialen Aufruf ausgeführt
 */
function saveWritableDatapoints($datapoints) {
    try {
        $pdo = getDbConnection();
        
        // Prepared Statements für Insert und Update
        $checkStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $insertStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte (api_id, modbus_id, title, modbus_register_type) 
            VALUES (?, ?, ?, ?)
        ");
        $updateStmt = $pdo->prepare("
            UPDATE nibe_datenpunkte 
            SET modbus_id = ?, title = ?, modbus_register_type = ? 
            WHERE api_id = ?
        ");
        
        $insertCount = 0;
        $updateCount = 0;
        
        foreach ($datapoints as $point) {
            // Nur beschreibbare Datenpunkte speichern
            if (!isset($point['isWritable']) || !$point['isWritable']) {
                continue;
            }
            
            $apiId = $point['variableid'];
            $modbusId = $point['modbusregisterid'];
            $title = substr($point['title'], 0, 150); // Max 150 Zeichen laut DB-Schema
            $registerType = substr($point['modbusregistertype'], 0, 30); // Max 30 Zeichen
            
            // Prüfen ob Datenpunkt bereits existiert
            $checkStmt->execute([$apiId]);
            $exists = $checkStmt->fetch();
            
            if ($exists) {
                // Update
                $updateStmt->execute([$modbusId, $title, $registerType, $apiId]);
                $updateCount++;
                debugLog("Datenpunkt aktualisiert", ['api_id' => $apiId, 'title' => $title], 'DB');
            } else {
                // Insert
                $insertStmt->execute([$apiId, $modbusId, $title, $registerType]);
                $insertCount++;
                debugLog("Datenpunkt neu eingefügt", ['api_id' => $apiId, 'title' => $title], 'DB');
            }
        }
        
        debugLog("Datenpunkte gespeichert", [
            'neu' => $insertCount,
            'aktualisiert' => $updateCount,
            'gesamt' => count($datapoints)
        ], 'DB');
        
        return ['inserted' => $insertCount, 'updated' => $updateCount];
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Speichern der Datenpunkte", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Datenbankfehler: ' . $e->getMessage());
    }
}

/**
 * Wertänderungen in nibe_datenpunkte_log protokollieren
 */
function logValueChanges($datapoints) {
    try {
        $pdo = getDbConnection();
        
        // Prepared Statements
        $getDatapointIdStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $getLastValueStmt = $pdo->prepare("
            SELECT wert 
            FROM nibe_datenpunkte_log 
            WHERE nibe_datenpunkte_id = ? 
            ORDER BY zeitstempel DESC 
            LIMIT 1
        ");
        $insertLogStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (nibe_datenpunkte_id, wert, cwna) 
            VALUES (?, ?, ?)
        ");
        
        $loggedCount = 0;
        $skippedCount = 0;
        
        foreach ($datapoints as $point) {
            // Nur für Datenpunkte die in der Master-Tabelle sind (beschreibbare)
            $apiId = $point['variableid'];
            $rawValue = $point['rawvalue']; // Integer-Wert ohne Divisor
            
            // Datenpunkt-ID aus Master-Tabelle holen
            $getDatapointIdStmt->execute([$apiId]);
            $datenpunkt = $getDatapointIdStmt->fetch();
            
            if (!$datenpunkt) {
                // Datenpunkt nicht in Master-Tabelle (nicht beschreibbar)
                continue;
            }
            
            $datenpunktId = $datenpunkt['id'];
            
            // Letzten Wert aus Log holen
            $getLastValueStmt->execute([$datenpunktId]);
            $lastLog = $getLastValueStmt->fetch();
            
            // Prüfen ob API ID in NO_DB_UPDATE_APIID Liste ist
            $isInNoUpdateList = in_array($apiId, NO_DB_UPDATE_APIID);
            
            if (!$lastLog) {
                // Kein vorheriger Eintrag - initialen Wert IMMER schreiben (auch wenn in NO_DB_UPDATE_APIID)
                $insertLogStmt->execute([$datenpunktId, $rawValue, '']);
                $loggedCount++;
                debugLog("Initialer Wert geloggt", [
                    'api_id' => $apiId,
                    'datenpunkt_id' => $datenpunktId,
                    'wert' => $rawValue
                ], 'DB_LOG');
            } elseif ($lastLog['wert'] != $rawValue) {
                // Wert hat sich geändert
                if ($isInNoUpdateList) {
                    // API ID ist in NO_DB_UPDATE_APIID - NICHT loggen
                    $skippedCount++;
                    debugLog("Wertänderung NICHT geloggt (in NO_DB_UPDATE_APIID)", [
                        'api_id' => $apiId,
                        'datenpunkt_id' => $datenpunktId,
                        'alter_wert' => $lastLog['wert'],
                        'neuer_wert' => $rawValue
                    ], 'DB_LOG');
                } else {
                    // Normal loggen
                    $insertLogStmt->execute([$datenpunktId, $rawValue, '']);
                    $loggedCount++;
                    debugLog("Wertänderung geloggt", [
                        'api_id' => $apiId,
                        'datenpunkt_id' => $datenpunktId,
                        'alter_wert' => $lastLog['wert'],
                        'neuer_wert' => $rawValue
                    ], 'DB_LOG');
                }
            }
        }
        
        if ($loggedCount > 0 || $skippedCount > 0) {
            debugLog("Wertänderungen verarbeitet", [
                'geloggt' => $loggedCount,
                'übersprungen' => $skippedCount
            ], 'DB_LOG');
        }
        
        return $loggedCount;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Loggen der Wertänderungen", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Datenbankfehler beim Loggen: ' . $e->getMessage());
    }
}

/**
 * Manuell geschriebenen Wert in Log schreiben
 * NO_DB_UPDATE_APIID wird hier IGNORIERT
 * cwna Spalte wird mit "X" gefüllt
 */
function logManualWrite($apiId, $rawValue) {
    try {
        $pdo = getDbConnection();
        
        // Datenpunkt-ID aus Master-Tabelle holen
        $stmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $stmt->execute([$apiId]);
        $datenpunkt = $stmt->fetch();
        
        if (!$datenpunkt) {
            debugLog("Datenpunkt nicht in Master-Tabelle gefunden", ['api_id' => $apiId], 'WARNING');
            return false;
        }
        
        $datenpunktId = $datenpunkt['id'];
        
        // Wert in Log schreiben - cwna mit "X" füllen (Change Was Not Automatic)
        $insertStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (nibe_datenpunkte_id, wert, cwna) 
            VALUES (?, ?, 'X')
        ");
        $insertStmt->execute([$datenpunktId, $rawValue]);
        
        debugLog("Manueller Schreibvorgang geloggt", [
            'api_id' => $apiId,
            'datenpunkt_id' => $datenpunktId,
            'wert' => $rawValue,
            'cwna' => 'X',
            'no_db_update_list_ignored' => in_array($apiId, NO_DB_UPDATE_APIID) ? 'ja' : 'nein'
        ], 'DB_LOG');
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Loggen des manuellen Schreibvorgangs", ['error' => $e->getMessage()], 'ERROR');
        return false;
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

// AJAX-Request zum Schreiben
if (isset($_GET['ajax']) && $_GET['ajax'] === 'write') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $variableId = $input['variableId'] ?? null;
        $newValue = $input['value'] ?? null;
        
        if ($variableId === null || $newValue === null) {
            throw new Exception('Fehlende Parameter');
        }
        
        $writeUrl = API_URL;
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $writeUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        
        if (!empty(API_USERNAME) && !empty(API_PASSWORD)) {
            $basicAuth = base64_encode(API_USERNAME . ':' . API_PASSWORD);
            $headers[] = 'Authorization: Basic ' . $basicAuth;
        } elseif (!empty(API_KEY)) {
            $headers[] = 'Authorization: Bearer ' . API_KEY;
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $bodyData = [[
            'type' => 'datavalue',
            'isOk' => true,
            'variableId' => (int)$variableId,
            'integerValue' => (int)$newValue,
            'stringValue' => ''
        ]];
        
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($bodyData));
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode >= 200 && $httpCode < 300) {
            // Erfolgreich geschrieben - in Log schreiben (falls DB aktiviert)
            if (USE_DB) {
                try {
                    logManualWrite($variableId, $newValue);
                } catch (Exception $e) {
                    debugLog("Fehler beim Loggen des Schreibvorgangs", ['error' => $e->getMessage()], 'ERROR');
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Wert erfolgreich gespeichert'], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('HTTP Fehler: ' . $httpCode);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX-Request zum Lesen
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $jsonData = fetchApiData(API_URL, API_KEY, API_USERNAME, API_PASSWORD);
        $rawData = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Dekodierungs-Fehler');
        }
        
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
        
        // Wertänderungen in Log-Tabelle schreiben (falls DB aktiviert)
        if (USE_DB) {
            try {
                logValueChanges($data);
            } catch (Exception $e) {
                // Fehler loggen, aber AJAX-Request nicht abbrechen
                debugLog("Fehler beim Loggen der Wertänderungen (AJAX)", ['error' => $e->getMessage()], 'ERROR');
            }
        }
        
        echo json_encode(['success' => true, 'data' => $data, 'timestamp' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// Initiale Daten laden
$error = null;
$data = [];
$dbSaveResult = null;

try {
    $jsonData = fetchApiData(API_URL, API_KEY, API_USERNAME, API_PASSWORD);
    $rawData = json_decode($jsonData, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('JSON Dekodierungs-Fehler');
    }
    
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
    
    // Beschreibbare Datenpunkte in Master-Tabelle speichern (nur beim initialen Aufruf und falls DB aktiviert)
    if (USE_DB) {
        try {
            $dbSaveResult = saveWritableDatapoints($data);
            debugLog("Initiales Speichern in Master-Tabelle erfolgreich", $dbSaveResult, 'DB');
            
            // Initiale Werte in Log-Tabelle schreiben
            logValueChanges($data);
            debugLog("Initiale Werte in Log-Tabelle geschrieben", null, 'DB_LOG');
            
        } catch (Exception $e) {
            // Fehler loggen, aber Seite nicht abbrechen
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
    <title>API Datenpunkte - Live v42</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); padding: 30px; }
        h1 { color: #333; margin-bottom: 20px; font-size: 28px; display: flex; align-items: center; gap: 10px; }
        .live-indicator { width: 12px; height: 12px; background: #4CAF50; border-radius: 50%; animation: pulse 2s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
        .live-indicator.error { background: #f44336; animation: none; }
        .filter-section { background: #f8f9fa; padding: 20px; border-radius: 6px; margin-bottom: 20px; }
        .filter-toggles { display: flex; gap: 15px; padding: 10px 0; flex-wrap: wrap; margin-bottom: 15px; }
        .toggle-option { display: flex; align-items: center; gap: 8px; }
        .toggle-option input[type="checkbox"] { width: 18px; height: 18px; cursor: pointer; }
        .toggle-option label { margin: 0; cursor: pointer; font-weight: 500; font-size: 14px; }
        .filter-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: center; }
        .filter-group { flex: 1; min-width: 200px; }
        label { display: block; font-weight: 600; color: #555; margin-bottom: 5px; font-size: 14px; }
        input[type="text"], select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        input[type="text"]:focus, select:focus { outline: none; border-color: #4CAF50; }
        .button-group { display: flex; gap: 10px; align-items: flex-end; }
        button { padding: 10px 20px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; font-weight: 600; transition: background 0.3s; }
        .btn-reset { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; }
        .btn-toggle { background: #4CAF50; color: white; }
        .btn-toggle:hover { background: #45a049; }
        .btn-toggle.paused { background: #ff9800; }
        .info-bar { background: #e7f3ff; padding: 12px; border-left: 4px solid #2196F3; margin-bottom: 20px; border-radius: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; }
        .info-left { display: flex; gap: 20px; flex-wrap: wrap; }
        .last-update { font-size: 13px; color: #666; }
        .error { background: #ffebee; color: #c62828; padding: 15px; border-left: 4px solid #c62828; border-radius: 4px; margin-bottom: 20px; }
        .table-wrapper { overflow-x: auto; position: relative; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        thead { background: #2196F3; color: white; }
        th { padding: 15px; text-align: left; font-weight: 600; cursor: pointer; user-select: none; }
        th:hover { background: #1976D2; }
        th.sortable::after { content: ' ⇅'; opacity: 0.5; }
        td { padding: 12px 15px; border-bottom: 1px solid #eee; transition: background-color 0.3s; }
        tbody tr:hover { background: #f5f5f5; }
        tbody tr:nth-child(even) { background: #fafafa; }
        tbody tr:nth-child(even):hover { background: #f0f0f0; }
        .value-changed { background: #fff9c4 !important; }
        .value-cell { position: relative; }
        .editable-value { display: flex; align-items: center; gap: 8px; }
        .value-display { flex: 1; }
        .btn-edit { padding: 4px 8px; background: #2196F3; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: background 0.3s; }
        .btn-edit:hover { background: #1976D2; }
        .edit-input { width: 150px; padding: 6px; border: 2px solid #2196F3; border-radius: 4px; font-size: 14px; }
        .btn-save { padding: 4px 12px; background: #4CAF50; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; margin-right: 4px; }
        .btn-save:hover { background: #45a049; }
        .btn-cancel { padding: 4px 12px; background: #f44336; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px; font-weight: 600; }
        .btn-cancel:hover { background: #da190b; }
        .saving-indicator { color: #2196F3; font-size: 12px; font-style: italic; }
        .checkbox-cell { text-align: center; width: 40px; }
        .row-checkbox { cursor: pointer; width: 18px; height: 18px; }
        .row-tooltip { position: fixed; background: #333; color: white; padding: 10px; border-radius: 6px; font-size: 12px; z-index: 1000; display: none; min-width: 250px; box-shadow: 0 4px 6px rgba(0,0,0,0.3); pointer-events: none; }
        .row-tooltip.show { display: block; }
        .row-tooltip::before { content: ''; position: absolute; top: -5px; left: 20px; width: 0; height: 0; border-left: 5px solid transparent; border-right: 5px solid transparent; border-bottom: 5px solid #333; }
        .tooltip-row { display: flex; justify-content: space-between; padding: 3px 0; border-bottom: 1px solid #555; }
        .tooltip-row:last-child { border-bottom: none; }
        .tooltip-label { font-weight: 600; color: #aaa; }
        .tooltip-value { color: #fff; }
        .no-data { text-align: center; padding: 40px; color: #999; font-style: italic; }
    </style>
</head>
<body>
    <div class="container">
        <h1><span class="live-indicator" id="liveIndicator"></span>📊 API Datenpunkte Übersicht - Live v42</h1>
        
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
                        <th class="sortable" onclick="sortTable(4)">Modbus Register Type</th>
                        <th class="sortable" onclick="sortTable(5)">Value</th>
                    </tr>
                </thead>
                <tbody id="tableBody">
                    <?php if ($error): ?>
                        <tr><td colspan="6" class="no-data error">Fehler: <?php echo htmlspecialchars($error); ?></td></tr>
                    <?php elseif (empty($data)): ?>
                        <tr><td colspan="6" class="no-data">Keine Daten verfügbar</td></tr>
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
                                <td><?php echo $point['modbusregistertype']; ?></td>
                                <td class="value-cell">
                                    <?php if ($point['modbusregistertype'] === 'MODBUS_HOLDING_REGISTER' && $point['isWritable']): ?>
                                        <div class="editable-value">
                                            <span class="value-display"><?php echo htmlspecialchars($point['value']); ?></span>
                                            <button class="btn-edit" onclick="editValue(this)">✏️</button>
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
        let tableData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
        let autoUpdateEnabled = true;
        let updateInterval = null;
        const hideValues = <?php echo json_encode(HIDE_VALUES); ?>;
        const apiUpdateInterval = <?php echo API_UPDATE_INTERVAL; ?>; // Intervall aus PHP Config
        
        function showTooltip(event, row) {
            if (!document.getElementById('showTooltips').checked) return;
            const tooltip = document.getElementById('rowTooltip');
            const description = row.dataset.description || '-';
            tooltip.innerHTML = `
                <div class="tooltip-row"><span class="tooltip-label">Description:</span><span class="tooltip-value">${description}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Variable Type:</span><span class="tooltip-value">${row.dataset.variabletype}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Variable Size:</span><span class="tooltip-value">${row.dataset.variablesize}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Divisor:</span><span class="tooltip-value">${row.dataset.divisor}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Decimal:</span><span class="tooltip-value">${row.dataset.decimal}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Min Value:</span><span class="tooltip-value">${row.dataset.minvalue}</span></div>
                <div class="tooltip-row"><span class="tooltip-label">Max Value:</span><span class="tooltip-value">${row.dataset.maxvalue}</span></div>
            `;
            
            // Position berechnen - direkt an der Zeile
            const rect = row.getBoundingClientRect();
            
            // Tooltip unterhalb der Zeile positionieren
            tooltip.style.left = rect.left + 'px';
            tooltip.style.top = (rect.bottom + 5) + 'px';
            
            // Sicherstellen, dass Tooltip nicht aus dem Viewport läuft
            tooltip.classList.add('show');
            
            // Überprüfen ob Tooltip rechts aus dem Viewport läuft
            const tooltipRect = tooltip.getBoundingClientRect();
            if (tooltipRect.right > window.innerWidth) {
                tooltip.style.left = (window.innerWidth - tooltipRect.width - 10) + 'px';
            }
            
            // Überprüfen ob Tooltip unten aus dem Viewport läuft
            if (tooltipRect.bottom > window.innerHeight) {
                // Tooltip über der Zeile anzeigen
                tooltip.style.top = (rect.top - tooltipRect.height - 5) + 'px';
            }
        }
        
        function hideTooltip() {
            document.getElementById('rowTooltip').classList.remove('show');
        }
        
        function toggleSelectAll() {
            const selectAll = document.getElementById('selectAll');
            document.querySelectorAll('.row-checkbox').forEach(cb => {
                if (cb.closest('tr').style.display !== 'none') cb.checked = selectAll.checked;
            });
        }
        
        function updateSelectAllState() {
            const checkboxes = Array.from(document.querySelectorAll('.row-checkbox')).filter(cb => cb.closest('tr').style.display !== 'none');
            const checkedCount = checkboxes.filter(cb => cb.checked).length;
            const selectAll = document.getElementById('selectAll');
            selectAll.checked = checkboxes.length > 0 && checkedCount === checkboxes.length;
            selectAll.indeterminate = checkedCount > 0 && checkedCount < checkboxes.length;
        }
        
        function startAutoUpdate() {
            if (updateInterval) clearInterval(updateInterval);
            updateInterval = setInterval(fetchData, 10000);
            autoUpdateEnabled = true;
            document.getElementById('toggleButton').textContent = 'Pause';
            document.getElementById('toggleButton').classList.remove('paused');
            document.getElementById('liveIndicator').classList.remove('error');
        }
        
        function stopAutoUpdate() {
            if (updateInterval) {
                clearInterval(updateInterval);
                updateInterval = null;
            }
            autoUpdateEnabled = false;
            document.getElementById('toggleButton').textContent = 'Start';
            document.getElementById('toggleButton').classList.add('paused');
        }
        
        function toggleAutoUpdate() {
            autoUpdateEnabled ? stopAutoUpdate() : startAutoUpdate();
        }
        
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
                    showError('Fehler beim Laden: ' + result.error);
                    document.getElementById('liveIndicator').classList.add('error');
                }
            } catch (error) {
                showError('Verbindungsfehler: ' + error.message);
                document.getElementById('liveIndicator').classList.add('error');
            }
        }
        
        function updateTable(newData) {
            tableData = newData;
            const tbody = document.getElementById('tableBody');
            const oldValues = {};
            const oldChecked = {};
            
            tbody.querySelectorAll('tr').forEach(row => {
                const id = row.dataset.variableid;
                const cb = row.querySelector('.row-checkbox');
                if (id && row.cells[5]) oldValues[id] = row.cells[5].textContent.trim();
                if (id && cb) oldChecked[id] = cb.checked;
            });
            
            tbody.innerHTML = '';
            
            newData.forEach(point => {
                const row = tbody.insertRow();
                Object.entries({
                    variableid: point.variableid,
                    variabletype: point.variableType,
                    variablesize: point.variableSize,
                    divisor: point.divisor,
                    decimal: point.decimal,
                    minvalue: point.minValue,
                    maxvalue: point.maxValue,
                    description: point.description || ''
                }).forEach(([k, v]) => row.dataset[k] = v);
                
                row.onmouseenter = e => showTooltip(e, row);
                row.onmouseleave = hideTooltip;
                
                const cbCell = row.insertCell(0);
                cbCell.className = 'checkbox-cell';
                const cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'row-checkbox';
                cb.checked = oldChecked[point.variableid] || false;
                cb.onchange = updateSelectAllState;
                cbCell.appendChild(cb);
                
                row.insertCell(1).textContent = point.variableid;
                row.insertCell(2).textContent = point.modbusregisterid;
                row.insertCell(3).textContent = point.title;
                row.insertCell(4).textContent = point.modbusregistertype;
                
                const valueCell = row.insertCell(5);
                valueCell.className = 'value-cell';
                
                if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
                    const div = document.createElement('div');
                    div.className = 'editable-value';
                    const span = document.createElement('span');
                    span.className = 'value-display';
                    span.textContent = point.value;
                    const btn = document.createElement('button');
                    btn.className = 'btn-edit';
                    btn.textContent = '✏️';
                    btn.onclick = () => editValue(btn);
                    div.appendChild(span);
                    div.appendChild(btn);
                    valueCell.appendChild(div);
                    valueCell.dataset.rawvalue = point.rawvalue;
                    valueCell.dataset.divisor = point.divisor;
                    valueCell.dataset.decimal = point.decimal;
                    valueCell.dataset.unit = point.unit;
                } else {
                    valueCell.textContent = point.value;
                }
                
                if (oldValues[point.variableid] && oldValues[point.variableid] !== point.value) {
                    valueCell.classList.add('value-changed');
                    setTimeout(() => valueCell.classList.remove('value-changed'), 2000);
                }
            });
            
            initRegisterTypeFilter();
            filterTable();
            updateCounts();
            updateSelectAllState();
        }
        
        function editValue(button) {
            const valueCell = button.closest('.value-cell');
            const editableDiv = valueCell.querySelector('.editable-value');
            const valueSpan = editableDiv.querySelector('.value-display');
            const currentValue = valueSpan.textContent;
            const variableId = valueCell.closest('tr').dataset.variableid;
            const numericValue = currentValue.replace(/[^\d,.-]/g, '').replace(',', '.');
            
            const input = document.createElement('input');
            input.type = 'number';
            input.step = 'any';
            input.className = 'edit-input';
            input.value = numericValue;
            
            const saveBtn = document.createElement('button');
            saveBtn.className = 'btn-save';
            saveBtn.textContent = '💾 Speichern';
            saveBtn.onclick = () => saveValue(variableId, valueCell, input.value);
            
            const cancelBtn = document.createElement('button');
            cancelBtn.className = 'btn-cancel';
            cancelBtn.textContent = '❌ Abbrechen';
            cancelBtn.onclick = () => cancelEdit(valueCell, currentValue);
            
            input.onkeypress = e => e.key === 'Enter' && saveValue(variableId, valueCell, input.value);
            input.onkeydown = e => e.key === 'Escape' && cancelEdit(valueCell, currentValue);
            
            editableDiv.innerHTML = '';
            editableDiv.appendChild(input);
            editableDiv.appendChild(saveBtn);
            editableDiv.appendChild(cancelBtn);
            
            input.focus();
            input.select();
        }
        
        async function saveValue(variableId, valueCell, newValue) {
            const editableDiv = valueCell.querySelector('.editable-value');
            const divisor = parseInt(valueCell.dataset.divisor) || 1;
            const unit = valueCell.dataset.unit || '';
            const decimal = parseInt(valueCell.dataset.decimal) || 0;
            
            editableDiv.innerHTML = '<span class="saving-indicator">💾 Speichere...</span>';
            
            try {
                const rawValue = Math.round(parseFloat(newValue) * divisor);
                const response = await fetch('?ajax=write', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({variableId: variableId, value: rawValue})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    let displayValue = divisor > 1 ? parseFloat(newValue).toFixed(decimal).replace('.', ',') : newValue;
                    displayValue += (unit ? ' ' + unit : '');
                    
                    editableDiv.innerHTML = `
                        <span class="value-display">${displayValue}</span>
                        <button class="btn-edit" onclick="editValue(this)">✏️</button>
                    `;
                    
                    valueCell.style.background = '#c8e6c9';
                    setTimeout(() => valueCell.style.background = '', 1000);
                    setTimeout(fetchData, 500);
                } else {
                    throw new Error(result.error || 'Unbekannter Fehler');
                }
            } catch (error) {
                alert('Fehler beim Speichern: ' + error.message);
                const point = tableData.find(p => p.variableid == variableId);
                if (point) {
                    editableDiv.innerHTML = `
                        <span class="value-display">${point.value}</span>
                        <button class="btn-edit" onclick="editValue(this)">✏️</button>
                    `;
                }
            }
        }
        
        function cancelEdit(valueCell, originalValue) {
            const editableDiv = valueCell.querySelector('.editable-value');
            editableDiv.innerHTML = `
                <span class="value-display">${originalValue}</span>
                <button class="btn-edit" onclick="editValue(this)">✏️</button>
            `;
        }
        
        function showError(message) {
            document.getElementById('errorContainer').innerHTML = `<div class="error"><strong>Fehler:</strong> ${message}</div>`;
        }
        
        function clearError() {
            document.getElementById('errorContainer').innerHTML = '';
        }
        
        function initRegisterTypeFilter() {
            const types = new Set();
            tableData.forEach(point => point.modbusregistertype && types.add(point.modbusregistertype));
            const select = document.getElementById('filterRegisterType');
            const currentValue = select.value;
            select.innerHTML = '<option value="">Alle</option>';
            types.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                option.textContent = type;
                if (type === currentValue) option.selected = true;
                select.appendChild(option);
            });
        }
        
        function filterTable() {
            const filters = {
                variableId: document.getElementById('filterVariableId').value.toLowerCase(),
                modbusRegisterID: document.getElementById('filterModbusRegisterID').value.toLowerCase(),
                title: document.getElementById('filterTitle').value.toLowerCase(),
                registerType: document.getElementById('filterRegisterType').value.toLowerCase(),
                value: document.getElementById('filterValue').value.toLowerCase(),
                selectedOnly: document.getElementById('filterSelectedOnly').checked,
                hideValuesActive: document.getElementById('hideValuesActive').checked
            };
            
            const tbody = document.getElementById('tableBody');
            let visibleCount = 0;
            
            Array.from(tbody.rows).forEach(row => {
                const cells = row.cells;
                if (cells.length === 0) return;
                
                const checkbox = row.querySelector('.row-checkbox');
                const matches = {
                    variableId: cells[1].textContent.toLowerCase().includes(filters.variableId),
                    modbusRegisterID: cells[2].textContent.toLowerCase().includes(filters.modbusRegisterID),
                    title: cells[3].textContent.toLowerCase().includes(filters.title),
                    registerType: !filters.registerType || cells[4].textContent.toLowerCase().includes(filters.registerType),
                    value: cells[5].textContent.toLowerCase().includes(filters.value),
                    selected: !filters.selectedOnly || (checkbox && checkbox.checked),
                    hideValues: !filters.hideValuesActive || !hideValues.some(v => cells[5].textContent.toLowerCase().includes(v.toLowerCase()))
                };
                
                if (Object.values(matches).every(m => m)) {
                    row.style.display = '';
                    visibleCount++;
                } else {
                    row.style.display = 'none';
                }
            });
            
            document.getElementById('visibleCount').textContent = visibleCount;
            updateSelectAllState();
        }
        
        function updateCounts() {
            document.getElementById('totalCount').textContent = tableData.length;
        }
        
        function resetFilters() {
            document.getElementById('filterVariableId').value = '';
            document.getElementById('filterModbusRegisterID').value = '';
            document.getElementById('filterTitle').value = '';
            document.getElementById('filterRegisterType').value = '';
            document.getElementById('filterValue').value = '';
            document.getElementById('filterSelectedOnly').checked = false;
            document.getElementById('hideValuesActive').checked = true;
            filterTable();
        }
        
        let sortDirection = {};
        function sortTable(columnIndex) {
            const tbody = document.getElementById('tableBody');
            const rows = Array.from(tbody.rows);
            const direction = sortDirection[columnIndex] === 'asc' ? 'desc' : 'asc';
            sortDirection[columnIndex] = direction;
            
            rows.sort((a, b) => {
                const aValue = a.cells[columnIndex].textContent.trim();
                const bValue = b.cells[columnIndex].textContent.trim();
                const aNum = parseFloat(aValue);
                const bNum = parseFloat(bValue);
                
                if (!isNaN(aNum) && !isNaN(bNum)) {
                    return direction === 'asc' ? aNum - bNum : bNum - aNum;
                }
                return direction === 'asc' ? aValue.localeCompare(bValue) : bValue.localeCompare(aValue);
            });
            
            rows.forEach(row => tbody.appendChild(row));
        }
        
        window.addEventListener('DOMContentLoaded', () => {
            document.getElementById('filterVariableId').addEventListener('input', filterTable);
            document.getElementById('filterModbusRegisterID').addEventListener('input', filterTable);
            document.getElementById('filterTitle').addEventListener('input', filterTable);
            document.getElementById('filterRegisterType').addEventListener('change', filterTable);
            document.getElementById('filterValue').addEventListener('input', filterTable);
            document.getElementById('filterSelectedOnly').addEventListener('change', filterTable);
            document.getElementById('hideValuesActive').addEventListener('change', filterTable);
            
            initRegisterTypeFilter();
            updateCounts();
            filterTable();
            updateSelectAllState();
            document.getElementById('lastUpdate').textContent = new Date().toLocaleString('de-DE');
            startAutoUpdate();
        });
        
        window.addEventListener('beforeunload', stopAutoUpdate);
    </script>
</body>
</html>
