<?php
/**
 * API Daten Abruf mit Auto-Refresh - Version 3.1.50
 * Hauptdatei: nibe49.php
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
 */
function saveWritableDatapoints($datapoints) {
    try {
        $pdo = getDbConnection();
        
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
            if (!isset($point['isWritable']) || !$point['isWritable']) {
                continue;
            }
            
            $apiId = $point['variableid'];
            $modbusId = $point['modbusregisterid'];
            $title = substr($point['title'], 0, 150);
            $registerType = substr($point['modbusregistertype'], 0, 30);
            
            $checkStmt->execute([$apiId]);
            $exists = $checkStmt->fetch();
            
            if ($exists) {
                $updateStmt->execute([$modbusId, $title, $registerType, $apiId]);
                $updateCount++;
                debugLog("Datenpunkt aktualisiert", ['api_id' => $apiId, 'title' => $title], 'DB');
            } else {
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
        
        $getDatapointIdStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $getLastValueStmt = $pdo->prepare("
            SELECT wert 
            FROM nibe_datenpunkte_log 
            WHERE nibe_datenpunkte_id = ? 
            ORDER BY zeitstempel DESC 
            LIMIT 1
        ");
        $insertLogStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (nibe_datenpunkte_id, wert, cwna, zeitstempel) 
            VALUES (?, ?, ?, ?)
        ");
        
        $loggedCount = 0;
        $skippedCount = 0;
        
        // Aktuellen Zeitstempel einmal generieren für alle Einträge
        $zeitstempel = date('Y-m-d H:i:s');
        
        foreach ($datapoints as $point) {
            $apiId = $point['variableid'];
            $rawValue = $point['rawvalue'];
            
            $getDatapointIdStmt->execute([$apiId]);
            $datenpunkt = $getDatapointIdStmt->fetch();
            
            if (!$datenpunkt) {
                continue;
            }
            
            $datenpunktId = $datenpunkt['id'];
            
            $getLastValueStmt->execute([$datenpunktId]);
            $lastLog = $getLastValueStmt->fetch();
            
            $isInNoUpdateList = in_array($apiId, NO_DB_UPDATE_APIID);
            
            if (!$lastLog) {
                $insertLogStmt->execute([$datenpunktId, $rawValue, '', $zeitstempel]);
                $loggedCount++;
                debugLog("Initialer Wert geloggt", [
                    'api_id' => $apiId,
                    'datenpunkt_id' => $datenpunktId,
                    'wert' => $rawValue,
                    'zeitstempel' => $zeitstempel
                ], 'DB_LOG');
            } elseif ($lastLog['wert'] != $rawValue) {
                if ($isInNoUpdateList) {
                    $skippedCount++;
                    debugLog("Wertänderung NICHT geloggt (in NO_DB_UPDATE_APIID)", [
                        'api_id' => $apiId,
                        'datenpunkt_id' => $datenpunktId,
                        'alter_wert' => $lastLog['wert'],
                        'neuer_wert' => $rawValue
                    ], 'DB_LOG');
                } else {
                    $insertLogStmt->execute([$datenpunktId, $rawValue, '', $zeitstempel]);
                    $loggedCount++;
                    debugLog("Wertänderung geloggt", [
                        'api_id' => $apiId,
                        'datenpunkt_id' => $datenpunktId,
                        'alter_wert' => $lastLog['wert'],
                        'neuer_wert' => $rawValue,
                        'zeitstempel' => $zeitstempel
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
 */
function logManualWrite($apiId, $rawValue) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $stmt->execute([$apiId]);
        $datenpunkt = $stmt->fetch();
        
        if (!$datenpunkt) {
            debugLog("Datenpunkt nicht in Master-Tabelle gefunden", ['api_id' => $apiId], 'WARNING');
            return false;
        }
        
        $datenpunktId = $datenpunkt['id'];
        
        // Aktuellen Zeitstempel generieren
        $zeitstempel = date('Y-m-d H:i:s');
        
        $insertStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (nibe_datenpunkte_id, wert, cwna, zeitstempel) 
            VALUES (?, ?, 'X', ?)
        ");
        $insertStmt->execute([$datenpunktId, $rawValue, $zeitstempel]);
        
        debugLog("Manueller Schreibvorgang geloggt", [
            'api_id' => $apiId,
            'datenpunkt_id' => $datenpunktId,
            'wert' => $rawValue,
            'cwna' => 'X',
            'zeitstempel' => $zeitstempel,
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

// AJAX-Request für History-Daten
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $apiId = $_GET['apiId'] ?? null;
        
        if (!$apiId) {
            throw new Exception('API ID fehlt');
        }
        
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $stmt->execute([$apiId]);
        $datenpunkt = $stmt->fetch();
        
        if (!$datenpunkt) {
            throw new Exception('Datenpunkt nicht gefunden');
        }
        
        $historyStmt = $pdo->prepare("
            SELECT id, wert, cwna, zeitstempel 
            FROM nibe_datenpunkte_log 
            WHERE nibe_datenpunkte_id = ? 
            ORDER BY zeitstempel DESC 
            LIMIT ?
        ");
        $historyStmt->execute([$datenpunkt['id'], API_MAX_HISTORY]);
        $historyData = $historyStmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'history' => $historyData,
            'apiId' => $apiId
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// AJAX-Request für Import
if (isset($_GET['ajax']) && $_GET['ajax'] === 'import') {
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $fileContent = $input['fileContent'] ?? null;
        
        if (!$fileContent) {
            throw new Exception('Keine Datei-Inhalte erhalten');
        }
        
        debugLog("Import gestartet", ['fileName' => $input['fileName'] ?? 'unknown'], 'IMPORT');
        
        $lines = explode("\n", $fileContent);
        $totalRecords = count($lines) - 1;
        $importedRecords = 0;
        $failedRecords = [];
        $newMasterRecords = 0;
        
        $pdo = getDbConnection();
        
        $checkMasterStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $insertMasterStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte (api_id, modbus_id, title, modbus_register_type) 
            VALUES (?, -1, ?, '-')
        ");
        $insertLogStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (nibe_datenpunkte_id, wert, cwna, zeitstempel) 
            VALUES (?, ?, 'I', ?)
        ");
        
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) {
                continue;
            }
            
            $parts = explode("\t", $line);
            
            if (count($parts) < 4) {
                $failedRecords[] = [
                    'line' => $i + 1,
                    'reason' => 'Unvollständige Daten (weniger als 4 Spalten)'
                ];
                continue;
            }
            
            $zeitStr = trim($parts[0]);
            $tag = trim($parts[1]);
            $apiId = trim($parts[2]);
            $wert = trim($parts[3]);
            
            try {
                $timestamp = new DateTime($zeitStr);
                $zeitstempel = $timestamp->format('Y-m-d H:i:s');
            } catch (Exception $e) {
                $failedRecords[] = [
                    'line' => $i + 1,
                    'reason' => 'Ungültiges Zeitformat: ' . $zeitStr
                ];
                continue;
            }
            
            $checkMasterStmt->execute([$apiId]);
            $master = $checkMasterStmt->fetch();
            
            if (!$master) {
                try {
                    $insertMasterStmt->execute([$apiId, $tag]);
                    $datenpunktId = $pdo->lastInsertId();
                    $newMasterRecords++;
                    debugLog("Neuer Master-Eintrag", ['api_id' => $apiId, 'title' => $tag], 'IMPORT');
                } catch (PDOException $e) {
                    $failedRecords[] = [
                        'line' => $i + 1,
                        'reason' => 'Fehler beim Anlegen Master-Eintrag: ' . $e->getMessage()
                    ];
                    continue;
                }
            } else {
                $datenpunktId = $master['id'];
            }
            
            try {
                $insertLogStmt->execute([$datenpunktId, (int)$wert, $zeitstempel]);
                $importedRecords++;
            } catch (PDOException $e) {
                $failedRecords[] = [
                    'line' => $i + 1,
                    'reason' => 'Fehler beim Schreiben Log: ' . $e->getMessage()
                ];
            }
        }
        
        debugLog("Import abgeschlossen", [
            'total' => $totalRecords,
            'imported' => $importedRecords,
            'failed' => count($failedRecords),
            'newMaster' => $newMasterRecords
        ], 'IMPORT');
        
        echo json_encode([
            'success' => true,
            'totalRecords' => $totalRecords,
            'importedRecords' => $importedRecords,
            'failedRecords' => $failedRecords,
            'newMasterRecords' => $newMasterRecords
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        debugLog("Import-Fehler", ['error' => $e->getMessage()], 'ERROR');
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
        
        if (USE_DB) {
            try {
                logValueChanges($data);
            } catch (Exception $e) {
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
    <title>API Datenpunkte - Live v3.1.50 mit History</title>
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
        .btn-import { background: #9C27B0; color: white; }
        .btn-import:hover { background: #7B1FA2; }
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
        
        /* History Modal */
        .history-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .history-modal.show {
            display: flex;
        }
        
        .history-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 800px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .history-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2196F3;
        }
        
        .history-header h2 {
            color: #333;
            font-size: 24px;
            margin: 0;
        }
        
        .history-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        .history-table thead {
            background: #2196F3;
            color: white;
        }
        
        .history-table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .history-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        .history-table tbody tr:hover {
            background: #f5f5f5;
        }
        
        .history-table tbody tr:nth-child(even) {
            background: #fafafa;
        }
        
        .history-table tbody tr:nth-child(even):hover {
            background: #f0f0f0;
        }
        
        .btn-undo {
            padding: 6px 12px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.3s;
        }
        
        .btn-undo:hover {
            background: #45a049;
        }
        
        .btn-close-history {
            padding: 10px 30px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-close-history:hover {
            background: #da190b;
        }
        
        .history-footer {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid #ddd;
        }
        
        .cwna-badge {
            display: inline-block;
            padding: 2px 6px;
            background: #FF9800;
            color: white;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
        }
        
        .cwna-badge.import {
            background: #2196F3;
        }
        
        /* Import Modal */
        .import-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .import-modal.show {
            display: flex;
        }
        
        .import-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .import-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #9C27B0;
        }
        
        .import-header h2 {
            color: #333;
            font-size: 24px;
            margin: 0;
        }
        
        .file-upload-area {
            border: 2px dashed #9C27B0;
            border-radius: 8px;
            padding: 30px;
            text-align: center;
            margin: 20px 0;
            background: #f9f9f9;
            cursor: pointer;
            transition: background 0.3s;
        }
        
        .file-upload-area:hover {
            background: #f0f0f0;
        }
        
        .file-upload-area.dragover {
            background: #e0e0e0;
            border-color: #7B1FA2;
        }
        
        .file-input {
            display: none;
        }
        
        .import-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 20px;
        }
        
        .btn-import-ok {
            padding: 10px 30px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-import-ok:hover {
            background: #45a049;
        }
        
        .btn-import-ok:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .btn-import-cancel {
            padding: 10px 30px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-import-cancel:hover {
            background: #da190b;
        }
        
        .import-result {
            margin-top: 20px;
            padding: 15px;
            border-radius: 6px;
            background: #e7f3ff;
            border-left: 4px solid #2196F3;
        }
        
        .import-result h3 {
            margin: 0 0 10px 0;
            color: #1976D2;
        }
        
        .import-result.success {
            background: #e8f5e9;
            border-left-color: #4CAF50;
        }
        
        .import-result.success h3 {
            color: #2E7D32;
        }
        
        .import-result.error {
            background: #ffebee;
            border-left-color: #f44336;
        }
        
        .import-result.error h3 {
            color: #c62828;
        }
        
        .failed-records {
            margin-top: 10px;
            max-height: 200px;
            overflow-y: auto;
            background: white;
            padding: 10px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 12px;
        }
        
        .failed-record {
            padding: 5px;
            border-bottom: 1px solid #eee;
        }
        
        /* Edit Modal */
        .edit-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 2000;
            justify-content: center;
            align-items: center;
        }
        
        .edit-modal.show {
            display: flex;
        }
        
        .edit-content {
            background: white;
            border-radius: 8px;
            padding: 30px;
            max-width: 500px;
            width: 90%;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        
        .edit-header {
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #2196F3;
        }
        
        .edit-header h2 {
            color: #333;
            font-size: 24px;
            margin: 0 0 10px 0;
        }
        
        .edit-info {
            color: #666;
            font-size: 14px;
            margin: 5px 0;
        }
        
        .edit-form {
            margin: 20px 0;
        }
        
        .edit-form-group {
            margin-bottom: 20px;
        }
        
        .edit-form-group label {
            display: block;
            font-weight: 600;
            color: #555;
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .edit-form-group input[type="number"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #2196F3;
            border-radius: 4px;
            font-size: 16px;
            font-weight: 500;
        }
        
        .edit-form-group input[type="number"]:focus {
            outline: none;
            border-color: #1976D2;
            box-shadow: 0 0 0 3px rgba(33, 150, 243, 0.1);
        }
        
        .edit-limits {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        
        .edit-current-value {
            background: #f5f5f5;
            padding: 10px;
            border-radius: 4px;
            margin-bottom: 15px;
        }
        
        .edit-current-value strong {
            color: #2196F3;
        }
        
        .edit-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
            margin-top: 25px;
        }
        
        .btn-edit-save {
            padding: 12px 30px;
            background: #4CAF50;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-edit-save:hover {
            background: #45a049;
        }
        
        .btn-edit-cancel {
            padding: 12px 30px;
            background: #f44336;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            transition: background 0.3s;
        }
        
        .btn-edit-cancel:hover {
            background: #da190b;
        }
        
        .edit-saving {
            text-align: center;
            padding: 20px;
            color: #2196F3;
            font-size: 16px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1><span class="live-indicator" id="liveIndicator"></span>📊 API Datenpunkte Übersicht - Live 3.1.50 mit History</h1>
        
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
                                        echo 'I';
                                    } elseif ($rt === 'MODBUS_HOLDING_REGISTER') {
                                        echo 'H';
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
    
    <script>
        let tableData = <?php echo json_encode($data, JSON_UNESCAPED_UNICODE); ?>;
        let autoUpdateEnabled = true;
        let updateInterval = null;
        const hideValues = <?php echo json_encode(HIDE_VALUES); ?>;
        const apiUpdateInterval = <?php echo API_UPDATE_INTERVAL; ?>;
        const USE_DB_ENABLED = <?php echo USE_DB ? 'true' : 'false'; ?>;
        window.USE_DB_ENABLED = USE_DB_ENABLED;
        
        // Import-Funktionen
        let selectedFile = null;
        
        // Edit Modal Variablen
        let currentEditVariableId = null;
        let currentEditValueCell = null;
        
        function showImportDialog() {
            document.getElementById('importModal').classList.add('show');
            document.getElementById('importUploadArea').style.display = 'block';
            document.getElementById('importResult').style.display = 'none';
            document.getElementById('selectedFileName').textContent = '';
            document.getElementById('btnImportOk').disabled = true;
            selectedFile = null;
        }
        
        function closeImportDialog() {
            document.getElementById('importModal').classList.remove('show');
            selectedFile = null;
        }
        
        function handleFileSelect(event) {
            const file = event.target.files[0];
            if (file) {
                selectedFile = file;
                document.getElementById('selectedFileName').textContent = '📄 ' + file.name;
                document.getElementById('btnImportOk').disabled = false;
            }
        }
        
        async function processImport() {
            if (!selectedFile) {
                alert('Bitte wählen Sie eine Datei aus');
                return;
            }
            
            document.getElementById('btnImportOk').disabled = true;
            document.getElementById('btnImportOk').textContent = '⏳ Importiere...';
            
            try {
                const fileContent = await selectedFile.text();
                
                const response = await fetch('?ajax=import', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({
                        fileContent: fileContent,
                        fileName: selectedFile.name
                    })
                });
                
                const result = await response.json();
                displayImportResult(result);
                
            } catch (error) {
                alert('Fehler beim Import: ' + error.message);
                document.getElementById('btnImportOk').disabled = false;
                document.getElementById('btnImportOk').textContent = '✔ OK';
            }
        }
        
        function displayImportResult(result) {
            document.getElementById('importUploadArea').style.display = 'none';
            
            const resultDiv = document.getElementById('importResult');
            resultDiv.style.display = 'block';
            
            if (result.success) {
                let html = `
                    <div class="import-result success">
                        <h3>✔ Import erfolgreich abgeschlossen</h3>
                        <p><strong>Datei enthielt:</strong> ${result.totalRecords} Datensätze</p>
                        <p><strong>Erfolgreich importiert:</strong> ${result.importedRecords} Datensätze</p>
                        <p><strong>Neue Master-Einträge:</strong> ${result.newMasterRecords || 0} Datensätze</p>
                `;
                
                if (result.failedRecords && result.failedRecords.length > 0) {
                    html += `
                        <p><strong>Nicht importiert:</strong> ${result.failedRecords.length} Datensätze</p>
                        <div class="failed-records">
                    `;
                    result.failedRecords.forEach(failed => {
                        html += `<div class="failed-record">Zeile ${failed.line}: ${failed.reason}</div>`;
                    });
                    html += '</div>';
                }
                
                html += `</div>
                    <div class="import-buttons">
                        <button class="btn-import-cancel" onclick="closeImportDialog()">Schließen</button>
                    </div>
                `;
                
                resultDiv.innerHTML = html;
            } else {
                resultDiv.innerHTML = `
                    <div class="import-result error">
                        <h3>✗ Import fehlgeschlagen</h3>
                        <p>${result.error}</p>
                    </div>
                    <div class="import-buttons">
                        <button class="btn-import-cancel" onclick="closeImportDialog()">Schließen</button>
                    </div>
                `;
            }
        }
        
        // History-Funktionen
        async function showHistory(variableId, title) {
            if (!USE_DB_ENABLED) {
                alert('Datenbank-Funktionen sind deaktiviert');
                return;
            }
            
            document.getElementById('historyModal').classList.add('show');
            document.getElementById('historyTitle').textContent = title + ' (API ID: ' + variableId + ')';
            document.getElementById('historyTableBody').innerHTML = '<tr><td colspan="4" style="text-align: center;">Lade Daten...</td></tr>';
            
            try {
                const response = await fetch('?ajax=history&apiId=' + variableId);
                const result = await response.json();
                
                if (result.success) {
                    displayHistoryData(result.history, variableId);
                } else {
                    document.getElementById('historyTableBody').innerHTML = 
                        '<tr><td colspan="4" class="no-data error">Fehler: ' + result.error + '</td></tr>';
                }
            } catch (error) {
                document.getElementById('historyTableBody').innerHTML = 
                    '<tr><td colspan="4" class="no-data error">Verbindungsfehler: ' + error.message + '</td></tr>';
            }
        }
        
        function displayHistoryData(historyData, apiId) {
            const tbody = document.getElementById('historyTableBody');
            
            if (!historyData || historyData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="no-data">Keine History-Daten vorhanden</td></tr>';
                return;
            }
            
            const currentPoint = tableData.find(p => p.variableid == apiId);
            const divisor = currentPoint ? (currentPoint.divisor || 1) : 1;
            const decimal = currentPoint ? (currentPoint.decimal || 0) : 0;
            const unit = currentPoint ? (currentPoint.unit || '') : '';
            
            tbody.innerHTML = '';
            
            historyData.forEach(entry => {
                const row = tbody.insertRow();
                
                row.insertCell(0).textContent = entry.zeitstempel;
                
                let displayValue = entry.wert;
                if (divisor > 1) {
                    displayValue = (entry.wert / divisor).toFixed(decimal).replace('.', ',');
                }
                displayValue += (unit ? ' ' + unit : '');
                row.insertCell(1).textContent = displayValue;
                
                const manualCell = row.insertCell(2);
                if (entry.cwna === 'X') {
                    manualCell.innerHTML = '<span class="cwna-badge">MANUELL</span>';
                } else if (entry.cwna === 'I') {
                    manualCell.innerHTML = '<span class="cwna-badge import">IMPORT</span>';
                } else {
                    manualCell.textContent = '-';
                }
                
                const actionCell = row.insertCell(3);
                if (entry.cwna === 'X') {
                    const undoBtn = document.createElement('button');
                    undoBtn.className = 'btn-undo';
                    undoBtn.textContent = '↩️ Wiederherstellen';
                    undoBtn.onclick = () => restoreHistoryValue(apiId, entry.wert);
                    actionCell.appendChild(undoBtn);
                } else {
                    actionCell.textContent = '-';
                }
            });
        }
        
        async function restoreHistoryValue(variableId, rawValue) {
            if (!confirm('Möchten Sie diesen Wert wirklich wiederherstellen?')) {
                return;
            }
            
            try {
                const response = await fetch('?ajax=write', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({variableId: variableId, value: rawValue})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    alert('✅ Wert erfolgreich wiederhergestellt');
                    closeHistory();
                    setTimeout(fetchData, 500);
                } else {
                    alert('❌ Fehler beim Wiederherstellen: ' + result.error);
                }
            } catch (error) {
                alert('❌ Verbindungsfehler: ' + error.message);
            }
        }
        
        function closeHistory() {
            document.getElementById('historyModal').classList.remove('show');
        }
        
        // Tooltip-Funktionen
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
            
            const rect = row.getBoundingClientRect();
            tooltip.style.left = rect.left + 'px';
            tooltip.style.top = (rect.bottom + 5) + 'px';
            tooltip.classList.add('show');
            
            const tooltipRect = tooltip.getBoundingClientRect();
            if (tooltipRect.right > window.innerWidth) {
                tooltip.style.left = (window.innerWidth - tooltipRect.width - 10) + 'px';
            }
            
            if (tooltipRect.bottom > window.innerHeight) {
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
            updateInterval = setInterval(fetchData, apiUpdateInterval);
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
                row.insertCell(4).textContent = point.modbusregistertype === 'MODBUS_INPUT_REGISTER' ? 'I' : (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' ? 'H' : point.modbusregistertype);
                
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
                    btn.onclick = () => editValue(point.variableid, point.title);
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
                
                if (USE_DB_ENABLED) {
                    const historyCell = row.insertCell();
                    historyCell.style.textAlign = 'center';
                    
                    if (point.modbusregistertype === 'MODBUS_HOLDING_REGISTER' && point.isWritable) {
                        const historyBtn = document.createElement('button');
                        historyBtn.className = 'btn-edit';
                        historyBtn.textContent = '📜';
                        historyBtn.onclick = () => showHistory(point.variableid, point.title);
                        historyCell.appendChild(historyBtn);
                    } else {
                        historyCell.textContent = '-';
                    }
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
        
        function editValue(variableId, title) {
            // Finde den Datenpunkt
            const point = tableData.find(p => p.variableid == variableId);
            if (!point) {
                alert('Datenpunkt nicht gefunden');
                return;
            }
            
            // Finde die entsprechende Zeile in der Tabelle
            const row = document.querySelector(`tr[data-variableid="${variableId}"]`);
            if (!row) {
                alert('Zeile nicht gefunden');
                return;
            }
            
            const valueCell = row.cells[5];
            
            // Speichere Referenzen
            currentEditVariableId = variableId;
            currentEditValueCell = valueCell;
            
            // Extrahiere numerischen Wert
            const currentValue = valueCell.querySelector('.value-display').textContent;
            const numericValue = currentValue.replace(/[^\d,.-]/g, '').replace(',', '.');
            
            // Fülle Modal mit Daten
            document.getElementById('editTitle').textContent = '📊 ' + title;
            document.getElementById('editApiId').textContent = 'API ID: ' + variableId;
            document.getElementById('editCurrentValue').textContent = currentValue;
            document.getElementById('editInput').value = numericValue;
            
            // Min/Max Limits anzeigen
            const minValue = row.dataset.minvalue;
            const maxValue = row.dataset.maxvalue;
            if (minValue && maxValue && minValue != 0 && maxValue != 0) {
                const divisor = parseInt(valueCell.dataset.divisor) || 1;
                const decimal = parseInt(valueCell.dataset.decimal) || 0;
                const displayMin = divisor > 1 ? (minValue / divisor).toFixed(decimal) : minValue;
                const displayMax = divisor > 1 ? (maxValue / divisor).toFixed(decimal) : maxValue;
                document.getElementById('editLimits').textContent = `Erlaubter Bereich: ${displayMin} bis ${displayMax}`;
            } else {
                document.getElementById('editLimits').textContent = '';
            }
            
            // Zeige Modal
            document.getElementById('editFormArea').style.display = 'block';
            document.getElementById('editSaving').style.display = 'none';
            document.getElementById('editModal').classList.add('show');
            
            // Focus auf Input
            setTimeout(() => {
                const input = document.getElementById('editInput');
                input.focus();
                input.select();
            }, 100);
        }
        
        async function saveEditValue() {
            const newValue = document.getElementById('editInput').value;
            
            if (!newValue || newValue.trim() === '') {
                alert('Bitte geben Sie einen Wert ein');
                return;
            }
            
            const valueCell = currentEditValueCell;
            const variableId = currentEditVariableId;
            const divisor = parseInt(valueCell.dataset.divisor) || 1;
            const unit = valueCell.dataset.unit || '';
            const decimal = parseInt(valueCell.dataset.decimal) || 0;
            
            // Zeige Speicher-Status
            document.getElementById('editFormArea').style.display = 'none';
            document.getElementById('editSaving').style.display = 'block';
            
            try {
                const rawValue = Math.round(parseFloat(newValue) * divisor);
                const response = await fetch('?ajax=write', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({variableId: variableId, value: rawValue})
                });
                
                const result = await response.json();
                
                if (result.success) {
                    // Schließe Modal
                    closeEditDialog();
                    
                    // Zeige Erfolg in der Tabelle
                    valueCell.style.background = '#c8e6c9';
                    setTimeout(() => valueCell.style.background = '', 1000);
                    
                    // Aktualisiere Daten
                    setTimeout(fetchData, 500);
                } else {
                    throw new Error(result.error || 'Unbekannter Fehler');
                }
            } catch (error) {
                alert('Fehler beim Speichern: ' + error.message);
                // Zeige Form wieder an
                document.getElementById('editFormArea').style.display = 'block';
                document.getElementById('editSaving').style.display = 'none';
            }
        }
        
        function closeEditDialog() {
            document.getElementById('editModal').classList.remove('show');
            currentEditVariableId = null;
            currentEditValueCell = null;
        }
        
        function showError(message) {
            document.getElementById('errorContainer').innerHTML = `<div class="error"><strong>Fehler:</strong> ${message}</div>`;
        }
        
        function clearError() {
            document.getElementById('errorContainer').innerHTML = '';
        }
        
        function initRegisterTypeFilter() {
            const types = new Set();
            tableData.forEach(point => {
                if (point.modbusregistertype) {
                    types.add(point.modbusregistertype);
                }
            });
            const select = document.getElementById('filterRegisterType');
            const currentValue = select.value;
            select.innerHTML = '<option value="">Alle</option>';
            types.forEach(type => {
                const option = document.createElement('option');
                option.value = type;
                // Kurzform für Anzeige
                if (type === 'MODBUS_INPUT_REGISTER') {
                    option.textContent = 'I (Input Register)';
                } else if (type === 'MODBUS_HOLDING_REGISTER') {
                    option.textContent = 'H (Holding Register)';
                } else {
                    option.textContent = type;
                }
                if (type === currentValue) option.selected = true;
                select.appendChild(option);
            });
        }
        
        function filterTable() {
            const filters = {
                variableId: document.getElementById('filterVariableId').value.toLowerCase(),
                modbusRegisterID: document.getElementById('filterModbusRegisterID').value.toLowerCase(),
                title: document.getElementById('filterTitle').value.toLowerCase(),
                registerType: document.getElementById('filterRegisterType').value, // Vollständiger Wert, kein toLowerCase
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
                const variableId = row.dataset.variableid;
                
                // Finde den Datenpunkt für registerType Filter
                const point = tableData.find(p => p.variableid == variableId);
                
                const matches = {
                    variableId: cells[1].textContent.toLowerCase().includes(filters.variableId),
                    modbusRegisterID: cells[2].textContent.toLowerCase().includes(filters.modbusRegisterID),
                    title: cells[3].textContent.toLowerCase().includes(filters.title),
                    registerType: !filters.registerType || (point && point.modbusregistertype === filters.registerType),
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
            console.log('USE_DB_ENABLED:', USE_DB_ENABLED);
            console.log('API_UPDATE_INTERVAL:', apiUpdateInterval);
            
            document.getElementById('filterVariableId').addEventListener('input', filterTable);
            document.getElementById('filterModbusRegisterID').addEventListener('input', filterTable);
            document.getElementById('filterTitle').addEventListener('input', filterTable);
            document.getElementById('filterRegisterType').addEventListener('change', filterTable);
            document.getElementById('filterValue').addEventListener('input', filterTable);
            document.getElementById('filterSelectedOnly').addEventListener('change', filterTable);
            document.getElementById('hideValuesActive').addEventListener('change', filterTable);
            
            document.getElementById('historyModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeHistory();
                }
            });
            
            document.getElementById('importModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeImportDialog();
                }
            });
            
            document.getElementById('editModal').addEventListener('click', function(e) {
                if (e.target === this) {
                    closeEditDialog();
                }
            });
            
            // Enter-Taste im Edit Input
            document.getElementById('editInput').addEventListener('keypress', function(e) {
                if (e.key === 'Enter') {
                    saveEditValue();
                }
            });
            
            // Escape-Taste im Edit Input
            document.getElementById('editInput').addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    closeEditDialog();
                }
            });
            
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
