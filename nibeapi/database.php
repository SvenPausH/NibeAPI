<?php
/**
 * Datenbankfunktionen
 * database.php
 */

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
 * Device in Datenbank speichern/aktualisieren
 * KORRIGIERT für API v3.4.00 - Struktur angepasst
 */
function saveDevice($device) {
    try {
        $pdo = getDbConnection();
        
        // API Response Struktur:
        // {
        //   "product": {"serialNumber": "...", "name": "...", "manufacturer": "...", "firmwareId": "..."},
        //   "deviceIndex": 0,
        //   "aidMode": "off",
        //   "smartMode": "normal"
        // }
        
        $deviceId = $device['deviceIndex']; // WICHTIG: deviceIndex nicht deviceId!
        $aidMode = $device['aidMode'] ?? 'off';
        $smartMode = $device['smartMode'] ?? 'normal';
        
        // Product-Daten extrahieren
        $serialNumber = $device['product']['serialNumber'] ?? '';
        $name = $device['product']['name'] ?? 'NIBE Device';
        $manufacturer = $device['product']['manufacturer'] ?? '';
        $firmwareId = $device['product']['firmwareId'] ?? '';
        
        // Name darf nicht leer sein - verwende Fallback
        if (empty($name) || trim($name) === '') {
            $name = 'Device ' . $deviceId;
        }
        
        // Längen-Beschränkungen
        $name = substr($name, 0, 50);
        $manufacturer = substr($manufacturer, 0, 50);
        $serialNumber = substr($serialNumber, 0, 15);
        $firmwareId = substr($firmwareId, 0, 15);
        
        debugLog("Speichere Device", [
            'deviceId' => $deviceId,
            'serialNumber' => $serialNumber,
            'name' => $name,
            'manufacturer' => $manufacturer,
            'firmwareId' => $firmwareId,
            'aidMode' => $aidMode,
            'smartMode' => $smartMode
        ], 'DB');
        
        // Prüfen ob Device bereits existiert
        $checkStmt = $pdo->prepare("SELECT deviceId FROM nibe_device WHERE deviceId = ?");
        $checkStmt->execute([$deviceId]);
        
        if ($checkStmt->fetch()) {
            // UPDATE
            $stmt = $pdo->prepare("
                UPDATE nibe_device 
                SET aidMode = ?, smartMode = ?, serialNumber = ?, name = ?, manufacturer = ?, firmwareId = ?
                WHERE deviceId = ?
            ");
            $stmt->execute([$aidMode, $smartMode, $serialNumber, $name, $manufacturer, $firmwareId, $deviceId]);
            debugLog("Device aktualisiert", ['deviceId' => $deviceId], 'DB');
        } else {
            // INSERT
            $stmt = $pdo->prepare("
                INSERT INTO nibe_device (deviceId, aidMode, smartMode, serialNumber, name, manufacturer, firmwareId)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$deviceId, $aidMode, $smartMode, $serialNumber, $name, $manufacturer, $firmwareId]);
            debugLog("Device neu eingefügt", ['deviceId' => $deviceId], 'DB');
        }
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Speichern des Devices", [
            'error' => $e->getMessage(),
            'device' => $device
        ], 'ERROR');
        throw new Exception('Datenbankfehler: ' . $e->getMessage());
    }
}

/**
 * Alle Devices aus Datenbank abrufen
 */
function getAllDevices() {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->query("SELECT * FROM nibe_device ORDER BY deviceId ASC");
        $devices = $stmt->fetchAll();
        
        return $devices;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Abrufen der Devices", ['error' => $e->getMessage()], 'ERROR');
        return [];
    }
}

/**
 * Notification in Datenbank speichern (nur wenn noch nicht vorhanden)
 */
function saveNotification($deviceId, $alarm) {
    try {
        $pdo = getDbConnection();
        
        $alarmId = $alarm['alarmId'];
        $description = substr($alarm['description'] ?? '', 0, 255);
        $header = substr($alarm['header'] ?? '', 0, 255);
        $severity = $alarm['severity'] ?? 0;
        $timeStr = $alarm['time'] ?? '';
        $equipName = substr($alarm['equipName'] ?? '', 0, 50);
        
        // Zeit konvertieren (Format: "2019-05-02 13:38:06")
        try {
            $time = new DateTime($timeStr);
            $zeitstempel = $time->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            debugLog("Ungültiges Zeitformat in Notification", ['time' => $timeStr], 'WARNING');
            $zeitstempel = date('Y-m-d H:i:s');
        }
        
        // Prüfen ob bereits vorhanden (unique: deviceId, alarmId, time)
        $checkStmt = $pdo->prepare("
            SELECT id FROM nibe_notifications 
            WHERE deviceId = ? AND alarmId = ? AND time = ?
        ");
        $checkStmt->execute([$deviceId, $alarmId, $zeitstempel]);
        
        if ($checkStmt->fetch()) {
            // Bereits vorhanden, nicht erneut speichern
            return false;
        }
        
        // Neu einfügen
        $stmt = $pdo->prepare("
            INSERT INTO nibe_notifications 
            (deviceId, alarmId, description, header, severity, time, equipName)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$deviceId, $alarmId, $description, $header, $severity, $zeitstempel, $equipName]);
        
        debugLog("Notification gespeichert", [
            'deviceId' => $deviceId,
            'alarmId' => $alarmId,
            'header' => $header
        ], 'DB');
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Speichern der Notification", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Alle Notifications abrufen (optional gefiltert nach Device)
 */
function getAllNotifications($deviceId = null, $onlyActive = false) {
    try {
        $pdo = getDbConnection();
        
        $sql = "SELECT n.*, d.name as deviceName, d.serialNumber
                FROM nibe_notifications n
                LEFT JOIN nibe_device d ON n.deviceId = d.deviceId
                WHERE 1=1";
        $params = [];
        
        if ($deviceId !== null) {
            $sql .= " AND n.deviceId = ?";
            $params[] = $deviceId;
        }
        
        if ($onlyActive) {
            $sql .= " AND n.resetNotifications IS NULL";
        }
        
        $sql .= " ORDER BY n.time DESC, n.severity DESC";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll();
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Abrufen der Notifications", ['error' => $e->getMessage()], 'ERROR');
        return [];
    }
}

/**
 * Notification als zurückgesetzt markieren
 */
function markNotificationReset($notificationId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            UPDATE nibe_notifications 
            SET resetNotifications = CURRENT_TIMESTAMP
            WHERE id = ?
        ");
        $stmt->execute([$notificationId]);
        
        debugLog("Notification als zurückgesetzt markiert", ['id' => $notificationId], 'DB');
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Markieren der Notification", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Alle Notifications eines Devices als zurückgesetzt markieren
 */
function markAllNotificationsReset($deviceId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            UPDATE nibe_notifications 
            SET resetNotifications = CURRENT_TIMESTAMP
            WHERE deviceId = ? AND resetNotifications IS NULL
        ");
        $stmt->execute([$deviceId]);
        
        $affected = $stmt->rowCount();
        
        debugLog("Alle Notifications zurückgesetzt", ['deviceId' => $deviceId, 'anzahl' => $affected], 'DB');
        
        return $affected;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Zurücksetzen aller Notifications", ['error' => $e->getMessage()], 'ERROR');
        return 0;
    }
}

/**
 * ALLE Datenpunkte in Datenbank speichern/aktualisieren (nicht nur beschreibbare)
 * KORRIGIERT: deviceId ist NICHT in nibe_datenpunkte (nur in nibe_datenpunkte_log)
 */
function saveAllDatapoints($datapoints, $deviceId = null) {
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
            // ALLE Datenpunkte speichern (nicht nur isWritable)
            $apiId = $point['variableid'];
            $modbusId = $point['modbusregisterid'];
            $title = substr($point['title'], 0, 150);
            $registerType = substr($point['modbusregistertype'], 0, 30);
            
            $checkStmt->execute([$apiId]);
            $exists = $checkStmt->fetch();
            
            if ($exists) {
                $updateStmt->execute([$modbusId, $title, $registerType, $apiId]);
                $updateCount++;
            } else {
                $insertStmt->execute([$apiId, $modbusId, $title, $registerType]);
                $insertCount++;
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
 * Wertänderungen in nibe_datenpunkte_log protokollieren (NUR für beschreibbare)
 * KORRIGIERT: deviceId ist in nibe_datenpunkte_log
 */
function logValueChanges($datapoints, $deviceId = null) {
    try {
        $pdo = getDbConnection();
        
        $getDatapointIdStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $getLastValueStmt = $pdo->prepare("
            SELECT wert 
            FROM nibe_datenpunkte_log 
            WHERE nibe_datenpunkte_id = ? AND deviceId = ?
            ORDER BY zeitstempel DESC 
            LIMIT 1
        ");
        $insertLogStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (deviceId, nibe_datenpunkte_id, wert, cwna, zeitstempel) 
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $loggedCount = 0;
        $skippedCount = 0;
        $zeitstempel = date('Y-m-d H:i:s');
        
        foreach ($datapoints as $point) {
            // NUR beschreibbare Datenpunkte loggen
            if (!isset($point['isWritable']) || !$point['isWritable']) {
                continue;
            }
            
            $apiId = $point['variableid'];
            $rawValue = $point['rawvalue'];
            
            $getDatapointIdStmt->execute([$apiId]);
            $datenpunkt = $getDatapointIdStmt->fetch();
            
            if (!$datenpunkt) {
                continue;
            }
            
            $datenpunktId = $datenpunkt['id'];
            
            // Letzten Wert für dieses Device holen
            $getLastValueStmt->execute([$datenpunktId, $deviceId]);
            $lastLog = $getLastValueStmt->fetch();
            
            $isInNoUpdateList = in_array($apiId, NO_DB_UPDATE_APIID);
            
            if (!$lastLog) {
                // Erster Wert für dieses Device
                $insertLogStmt->execute([$deviceId, $datenpunktId, $rawValue, '', $zeitstempel]);
                $loggedCount++;
            } elseif ($lastLog['wert'] != $rawValue) {
                // Wert hat sich geändert
                if ($isInNoUpdateList) {
                    $skippedCount++;
                } else {
                    $insertLogStmt->execute([$deviceId, $datenpunktId, $rawValue, '', $zeitstempel]);
                    $loggedCount++;
                }
            }
        }
        
        if ($loggedCount > 0 || $skippedCount > 0) {
            debugLog("Wertänderungen verarbeitet", [
                'deviceId' => $deviceId,
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
function logManualWrite($apiId, $rawValue, $deviceId = null) {
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
        $zeitstempel = date('Y-m-d H:i:s');
        
        $insertStmt = $pdo->prepare("
            INSERT INTO nibe_datenpunkte_log (deviceId, nibe_datenpunkte_id, wert, cwna, zeitstempel) 
            VALUES (?, ?, ?, 'X', ?)
        ");
        $insertStmt->execute([$deviceId, $datenpunktId, $rawValue, $zeitstempel]);
        
        debugLog("Manueller Schreibvorgang geloggt", [
            'deviceId' => $deviceId,
            'api_id' => $apiId,
            'datenpunkt_id' => $datenpunktId,
            'wert' => $rawValue,
            'cwna' => 'X',
            'zeitstempel' => $zeitstempel
        ], 'DB_LOG');
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Loggen des manuellen Schreibvorgangs", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * History-Daten für einen Datenpunkt abrufen
 */
function getHistoryData($apiId) {
    try {
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
        
        return $historyStmt->fetchAll();
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Abrufen der History", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Datenbankfehler: ' . $e->getMessage());
    }
}

/**
 * Menüpunkt für API ID abrufen
 */
function getMenupunkt($apiId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("SELECT menuepunkt FROM nibe_menuepunkte WHERE api_id = ?");
        $stmt->execute([$apiId]);
        $result = $stmt->fetch();
        
        return $result ? $result['menuepunkt'] : null;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Abrufen des Menüpunkts", ['error' => $e->getMessage()], 'ERROR');
        return null;
    }
}

/**
 * Alle Menüpunkte abrufen
 */
function getAllMenupunkte() {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->query("SELECT api_id, menuepunkt FROM nibe_menuepunkte");
        $results = $stmt->fetchAll();
        
        $menuepunkte = [];
        foreach ($results as $row) {
            $menuepunkte[$row['api_id']] = $row['menuepunkt'];
        }
        
        return $menuepunkte;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Abrufen aller Menüpunkte", ['error' => $e->getMessage()], 'ERROR');
        return [];
    }
}

/**
 * Menüpunkt speichern oder aktualisieren
 */
function saveMenupunkt($apiId, $menuepunkt) {
    try {
        $pdo = getDbConnection();
        
        // Prüfen ob API ID in nibe_datenpunkte existiert
        $checkStmt = $pdo->prepare("SELECT id FROM nibe_datenpunkte WHERE api_id = ?");
        $checkStmt->execute([$apiId]);
        
        if (!$checkStmt->fetch()) {
            throw new Exception('API ID existiert nicht in nibe_datenpunkte');
        }
        
        // Prüfen ob Menüpunkt bereits existiert
        $existsStmt = $pdo->prepare("SELECT api_id FROM nibe_menuepunkte WHERE api_id = ?");
        $existsStmt->execute([$apiId]);
        
        if ($existsStmt->fetch()) {
            // Update
            $stmt = $pdo->prepare("UPDATE nibe_menuepunkte SET menuepunkt = ? WHERE api_id = ?");
            $stmt->execute([$menuepunkt, $apiId]);
            debugLog("Menüpunkt aktualisiert", ['api_id' => $apiId, 'menuepunkt' => $menuepunkt], 'DB');
        } else {
            // Insert
            $stmt = $pdo->prepare("INSERT INTO nibe_menuepunkte (api_id, menuepunkt) VALUES (?, ?)");
            $stmt->execute([$apiId, $menuepunkt]);
            debugLog("Menüpunkt erstellt", ['api_id' => $apiId, 'menuepunkt' => $menuepunkt], 'DB');
        }
        
        return true;
        
    } catch (Exception $e) {
        debugLog("Fehler beim Speichern des Menüpunkts", ['error' => $e->getMessage()], 'ERROR');
        throw $e;
    }
}

/**
 * Menüpunkt löschen
 */
function deleteMenupunkt($apiId) {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("DELETE FROM nibe_menuepunkte WHERE api_id = ?");
        $stmt->execute([$apiId]);
        
        debugLog("Menüpunkt gelöscht", ['api_id' => $apiId], 'DB');
        
        return true;
        
    } catch (PDOException $e) {
        debugLog("Fehler beim Löschen des Menüpunkts", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Fehler beim Löschen: ' . $e->getMessage());
    }
}

/**
 * Import-Daten in Datenbank schreiben
 */
function importLogData($fileContent, $fileName) {
    try {
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
        
        return [
            'totalRecords' => $totalRecords,
            'importedRecords' => $importedRecords,
            'failedRecords' => $failedRecords,
            'newMasterRecords' => $newMasterRecords
        ];
        
    } catch (Exception $e) {
        debugLog("Import-Fehler", ['error' => $e->getMessage()], 'ERROR');
        throw new Exception('Import-Fehler: ' . $e->getMessage());
    }
}
?>
