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
