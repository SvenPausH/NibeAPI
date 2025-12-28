<?php
/**
 * AJAX Request Handler
 * ajax-handlers.php
 */

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
        
        $historyData = getHistoryData($apiId);
        
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
        $fileName = $input['fileName'] ?? 'unknown';
        
        if (!$fileContent) {
            throw new Exception('Keine Datei-Inhalte erhalten');
        }
        
        debugLog("Import gestartet", ['fileName' => $fileName], 'IMPORT');
        
        $result = importLogData($fileContent, $fileName);
        
        echo json_encode([
            'success' => true,
            'totalRecords' => $result['totalRecords'],
            'importedRecords' => $result['importedRecords'],
            'failedRecords' => $result['failedRecords'],
            'newMasterRecords' => $result['newMasterRecords']
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
        
        $data = processApiData($rawData);
        
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
?>
