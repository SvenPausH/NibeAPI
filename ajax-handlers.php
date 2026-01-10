<?php
/**
 * AJAX Request Handler - Version 3.4.00
 * ajax-handlers.php - KOMPLETT
 */

// Output Buffering starten um versehentliche Ausgaben zu verhindern
ob_start();

// Alle Warnings unterdrücken für AJAX-Requests
error_reporting(0);
ini_set('display_errors', 0);

// ============================================================================
// AJAX-Request für einzelne Notification zurücksetzen
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'resetSingleNotification') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $notificationId = isset($_GET['notificationId']) ? (int)$_GET['notificationId'] : null;
        
        if ($notificationId === null || $notificationId <= 0) {
            throw new Exception('Ungültige Notification ID');
        }
        
        debugLog("Einzelne Notification zurücksetzen", ['notificationId' => $notificationId], 'NOTIFICATIONS');
        
        // Hole Notification Details (brauchen deviceId für API-Call)
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("SELECT deviceId, alarmId, header FROM nibe_notifications WHERE id = ?");
        $stmt->execute([$notificationId]);
        $notification = $stmt->fetch();
        
        if (!$notification) {
            throw new Exception('Notification nicht gefunden');
        }
        
        $deviceId = $notification['deviceId'];
        
        debugLog("Notification Details", [
            'notificationId' => $notificationId,
            'deviceId' => $deviceId,
            'alarmId' => $notification['alarmId'],
            'header' => $notification['header']
        ], 'NOTIFICATIONS');
        
        // WICHTIG: API aufrufen um Notification in Anlage zurückzusetzen
        // Die Nibe API kann nur ALLE Notifications eines Devices zurücksetzen
        debugLog("Rufe API auf um Notifications in Anlage zurückzusetzen", [
            'deviceId' => $deviceId,
            'method' => 'DELETE',
            'url' => API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/notifications'
        ], 'NOTIFICATIONS');
        
        try {
            resetNotifications($deviceId);
            debugLog("API-Call erfolgreich - Notifications in Anlage zurückgesetzt", ['deviceId' => $deviceId], 'NOTIFICATIONS');
        } catch (Exception $e) {
            debugLog("API-Call fehlgeschlagen", [
                'deviceId' => $deviceId,
                'error' => $e->getMessage()
            ], 'WARNING');
            // Trotzdem weitermachen - DB wird aktualisiert
        }
        
        // In Datenbank markieren
        $success = markNotificationReset($notificationId);
        
        if ($success) {
            debugLog("Notification in DB als zurückgesetzt markiert", ['notificationId' => $notificationId], 'NOTIFICATIONS');
            
            echo json_encode([
                'success' => true,
                'message' => 'Notification zurückgesetzt (inkl. API-Call)',
                'notificationId' => $notificationId,
                'deviceId' => $deviceId
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('Fehler beim Zurücksetzen in DB');
        }
        
    } catch (Exception $e) {
        debugLog("Reset Single Notification Fehler", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request zum Schreiben
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'write') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $variableId = $input['variableId'] ?? null;
        $newValue = $input['value'] ?? null;
        $deviceId = $input['deviceId'] ?? 0;
        
        if ($variableId === null || $newValue === null) {
            throw new Exception('Fehlende Parameter');
        }
        
        $writeUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
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
                    logManualWrite($variableId, $newValue, $deviceId);
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

// ============================================================================
// AJAX-Request für History-Daten
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'history') {
    ob_clean();
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

// ============================================================================
// AJAX-Request für Import
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'import') {
    ob_clean();
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

// ============================================================================
// AJAX-Request zum Lesen (mit Device-Support und CRON-Modus)
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetch') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        $deviceId = $_GET['deviceId'] ?? 0;
        $apiUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
        
        // API-Daten abrufen (IMMER)
        $jsonData = fetchApiData($apiUrl, API_KEY, API_USERNAME, API_PASSWORD);
        $rawData = json_decode($jsonData, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('JSON Dekodierungs-Fehler');
        }
        
        $data = processApiData($rawData, $deviceId);
        
        // DB-Updates nur im WEB-Modus
        $checkBy = defined('NOTIFICATIONS_CHECK_BY') ? NOTIFICATIONS_CHECK_BY : 'WEB';
        
        if (USE_DB && $checkBy === 'WEB') {
            try {
                saveAllDatapoints($data, $deviceId);
                logValueChanges($data, $deviceId);
                
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("UPDATE nibe_device SET last_updated = NOW() WHERE deviceId = ?");
                $stmt->execute([$deviceId]);
            } catch (Exception $e) {
                debugLog("AJAX DB-Update Fehler", ['error' => $e->getMessage()], 'ERROR');
            }
        }
        
        echo json_encode([
            'success' => true, 
            'data' => $data, 
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Menüpunkt speichern
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'saveMenupunkt') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $input = json_decode(file_get_contents('php://input'), true);
        $apiId = $input['apiId'] ?? null;
        $menuepunkt = $input['menuepunkt'] ?? null;
        
        if (!$apiId) {
            throw new Exception('API ID fehlt');
        }
        
        if ($menuepunkt === null || trim($menuepunkt) === '') {
            deleteMenupunkt($apiId);
            echo json_encode(['success' => true, 'message' => 'Menüpunkt gelöscht'], JSON_UNESCAPED_UNICODE);
        } else {
            saveMenupunkt($apiId, $menuepunkt);
            echo json_encode(['success' => true, 'message' => 'Menüpunkt gespeichert'], JSON_UNESCAPED_UNICODE);
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Device-Discovery
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'discoverDevices') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        debugLog("Device-Discovery gestartet", null, 'DEVICES');
        
        $devices = discoverDevices();
        $savedCount = 0;
        
        foreach ($devices as $device) {
            try {
                saveDevice($device);
                $savedCount++;
            } catch (Exception $e) {
                debugLog("Fehler beim Speichern eines Devices", [
                    'deviceId' => $device['deviceIndex'],
                    'error' => $e->getMessage()
                ], 'ERROR');
            }
        }
        
        echo json_encode([
            'success' => true,
            'discovered' => count($devices),
            'saved' => $savedCount,
            'devices' => $devices
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        debugLog("Device-Discovery Fehler", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Alle Devices aus DB
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getDevices') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $devices = getAllDevices();
        
        echo json_encode([
            'success' => true,
            'devices' => $devices
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Notifications abrufen
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'fetchNotifications') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $deviceId = isset($_GET['deviceId']) ? (int)$_GET['deviceId'] : null;
        
        if ($deviceId === null) {
            throw new Exception('Device ID fehlt');
        }
        
        debugLog("Notifications abrufen", ['deviceId' => $deviceId], 'NOTIFICATIONS');
        
        $alarms = fetchNotifications($deviceId);
        
        if (!is_array($alarms)) {
            debugLog("fetchNotifications lieferte kein Array", ['type' => gettype($alarms)], 'ERROR');
            throw new Exception('Keine gültige Response von API');
        }
        
        $savedCount = 0;
        
        foreach ($alarms as $alarm) {
            try {
                $saved = saveNotification($deviceId, $alarm);
                if ($saved) {
                    $savedCount++;
                }
            } catch (Exception $e) {
                debugLog("Fehler beim Speichern einer Notification", [
                    'deviceId' => $deviceId,
                    'alarmId' => isset($alarm['alarmId']) ? $alarm['alarmId'] : 'unknown',
                    'error' => $e->getMessage()
                ], 'ERROR');
            }
        }
        
        $dbNotifications = getAllNotifications($deviceId, true);
        
        echo json_encode([
            'success' => true,
            'fetched' => count($alarms),
            'saved' => $savedCount,
            'active' => count($dbNotifications),
            'notifications' => $dbNotifications
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        debugLog("Notifications Fehler", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Notifications zurücksetzen
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'resetNotifications') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $deviceId = $_GET['deviceId'] ?? null;
        
        if ($deviceId === null) {
            throw new Exception('Device ID fehlt');
        }
        
        debugLog("Notifications zurücksetzen", ['deviceId' => $deviceId], 'NOTIFICATIONS');
        
        resetNotifications($deviceId);
        
        $affected = markAllNotificationsReset($deviceId);
        
        echo json_encode([
            'success' => true,
            'resetCount' => $affected,
            'message' => "$affected Notification(s) zurückgesetzt"
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        debugLog("Reset Notifications Fehler", ['error' => $e->getMessage()], 'ERROR');
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ============================================================================
// AJAX-Request für Alle Notifications aus DB
// ============================================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'getAllNotifications') {
    ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    
    if (!USE_DB) {
        echo json_encode(['success' => false, 'error' => 'Datenbank ist deaktiviert'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    try {
        $deviceId = $_GET['deviceId'] ?? null;
        $onlyActive = isset($_GET['onlyActive']) && $_GET['onlyActive'] === 'true';
        
        $notifications = getAllNotifications($deviceId, $onlyActive);
        
        echo json_encode([
            'success' => true,
            'notifications' => $notifications,
            'count' => count($notifications)
        ], JSON_UNESCAPED_UNICODE);
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
?>
