<?php
/**
 * InfluxDB Integration für Nibe API Dashboard
 * influxdb.php - Version 1.0
 * 
 * Schreibt Datenpunkte in InfluxDB v2.x
 */

/**
 * Prüft ob eine Modbus ID in der Filterliste ist
 * 
 * @param int $modbusId Die zu prüfende Modbus ID
 * @param mixed $filter Filter-Definition ('all', Array mit IDs/Bereichen)
 * @return bool True wenn ID gefiltert werden soll
 */
function shouldWriteToInflux($modbusId, $filter) {
    // Wenn Filter 'all' ist, alle schreiben
    if ($filter === 'all') {
        return true;
    }
    
    // Wenn Filter kein Array ist, nichts schreiben
    if (!is_array($filter)) {
        return false;
    }
    
    // Filter durchgehen
    foreach ($filter as $item) {
        // Einzelne ID
        if (is_numeric($item) && $item == $modbusId) {
            return true;
        }
        
        // Bereich (z.B. "100-200")
        if (is_string($item) && strpos($item, '-') !== false) {
            $parts = explode('-', $item);
            if (count($parts) === 2) {
                $start = (int)trim($parts[0]);
                $end = (int)trim($parts[1]);
                
                if ($modbusId >= $start && $modbusId <= $end) {
                    return true;
                }
            }
        }
    }
    
    return false;
}

/**
 * Prüft ob ein Datenpunkt aufgrund seines Wertes versteckt werden soll
 * 
 * @param array $point Datenpunkt
 * @param array $hideValues Liste mit zu versteckenden Werten
 * @return bool True wenn Wert versteckt werden soll
 */
function shouldHideValue($point, $hideValues) {
    if (empty($hideValues)) {
        return false;
    }
    
    // Berechneten Wert prüfen (mit Divisor)
    $divisor = $point['divisor'] ?? 1;
    $rawValue = $point['rawvalue'];
    $calculatedValue = $divisor > 1 ? ($rawValue / $divisor) : $rawValue;
    
    // Value als String für Vergleich
    $valueStr = (string)$calculatedValue;
    
    foreach ($hideValues as $hideValue) {
        $hideValueStr = (string)$hideValue;
        
        // Exakter Vergleich
        if ($valueStr === $hideValueStr) {
            return true;
        }
        
        // Vergleich mit Toleranz für Floating Point
        if (is_numeric($hideValue) && is_numeric($calculatedValue)) {
            if (abs($calculatedValue - (float)$hideValue) < 0.001) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Schreibt Datenpunkte in InfluxDB
 * 
 * @param array $datapoints Array mit Datenpunkten (von processApiData)
 * @param int $deviceId Device ID
 * @return array Statistik ['success' => int, 'failed' => int, 'skipped' => int]
 */
function writeToInfluxDB($datapoints, $deviceId) {
    // Prüfen ob InfluxDB aktiviert ist
    if (!defined('INFLUX_ENABLED') || INFLUX_ENABLED !== true) {
        debugLog("InfluxDB ist deaktiviert", null, 'INFLUX');
        return ['success' => 0, 'failed' => 0, 'skipped' => count($datapoints)];
    }
    
    // Konfiguration prüfen
    if (!defined('INFLUX_URL') || !defined('INFLUX_TOKEN') || 
        !defined('INFLUX_ORG') || !defined('INFLUX_BUCKET')) {
        debugLog("InfluxDB: Unvollständige Konfiguration", null, 'ERROR');
        return ['success' => 0, 'failed' => 0, 'skipped' => count($datapoints)];
    }
    
    $holdingFilter = defined('INFLUX_HOLDING') ? INFLUX_HOLDING : [];
    $inputFilter = defined('INFLUX_INPUT') ? INFLUX_INPUT : [];
    $hideValues = defined('INFLUX_HIDE_VALUES') ? INFLUX_HIDE_VALUES : [];
    
    debugLog("InfluxDB: Starte Export", [
        'datapoints' => count($datapoints),
        'deviceId' => $deviceId,
        'holdingFilter' => $holdingFilter,
        'inputFilter' => $inputFilter,
        'hideValues' => $hideValues
    ], 'INFLUX');
    
    // Datenpunkte filtern und vorbereiten
    $lines = [];
    $success = 0;
    $failed = 0;
    $skipped = 0;
    
    foreach ($datapoints as $point) {
        $modbusId = $point['modbusregisterid'];
        $registerType = $point['modbusregistertype'];
        
        // Prüfen ob dieser Datenpunkt geschrieben werden soll
        $shouldWrite = false;
        
        if ($registerType === 'MODBUS_HOLDING_REGISTER') {
            $shouldWrite = shouldWriteToInflux($modbusId, $holdingFilter);
        } elseif ($registerType === 'MODBUS_INPUT_REGISTER') {
            $shouldWrite = shouldWriteToInflux($modbusId, $inputFilter);
        }
        
        if (!$shouldWrite) {
            $skipped++;
            continue;
        }
        
        // Prüfen ob Value versteckt werden soll
        if (shouldHideValue($point, $hideValues)) {
            $skipped++;
            debugLog("InfluxDB: Value versteckt", [
                'modbusId' => $modbusId,
                'value' => $point['value']
            ], 'DEBUG');
            continue;
        }
        
        // Line Protocol erstellen
        try {
            $line = buildInfluxLineProtocol($point, $deviceId);
            if ($line) {
                $lines[] = $line;
            } else {
                $failed++;
                debugLog("InfluxDB: Konnte Line Protocol nicht erstellen", [
                    'modbusId' => $modbusId
                ], 'WARNING');
            }
        } catch (Exception $e) {
            $failed++;
            debugLog("InfluxDB: Fehler beim Erstellen des Line Protocol", [
                'modbusId' => $modbusId,
                'error' => $e->getMessage()
            ], 'ERROR');
        }
    }
    
    debugLog("InfluxDB: Datenpunkte gefiltert", [
        'toWrite' => count($lines),
        'skipped' => $skipped,
        'failed' => $failed
    ], 'INFLUX');
    
    // Wenn keine Datenpunkte zu schreiben sind
    if (empty($lines)) {
        debugLog("InfluxDB: Keine Datenpunkte zu schreiben", null, 'INFLUX');
        return ['success' => 0, 'failed' => $failed, 'skipped' => $skipped];
    }
    
    // In Batches schreiben
    $batchSize = defined('INFLUX_BATCH_SIZE') ? INFLUX_BATCH_SIZE : 100;
    $batches = array_chunk($lines, $batchSize);
    
    debugLog("InfluxDB: Schreibe in {count} Batch(es)", [
        'count' => count($batches),
        'batchSize' => $batchSize
    ], 'INFLUX');
    
    foreach ($batches as $batchIndex => $batch) {
        try {
            $result = sendToInfluxDB($batch);
            
            if ($result) {
                $success += count($batch);
                debugLog("InfluxDB: Batch #{$batchIndex} erfolgreich", [
                    'count' => count($batch)
                ], 'INFLUX');
            } else {
                $failed += count($batch);
                debugLog("InfluxDB: Batch #{$batchIndex} fehlgeschlagen", [
                    'count' => count($batch)
                ], 'ERROR');
            }
            
        } catch (Exception $e) {
            $failed += count($batch);
            debugLog("InfluxDB: Batch #{$batchIndex} Exception", [
                'count' => count($batch),
                'error' => $e->getMessage()
            ], 'ERROR');
        }
    }
    
    debugLog("InfluxDB: Export abgeschlossen", [
        'success' => $success,
        'failed' => $failed,
        'skipped' => $skipped
    ], 'INFLUX');
    
    return [
        'success' => $success,
        'failed' => $failed,
        'skipped' => $skipped
    ];
}

/**
 * Erstellt InfluxDB Line Protocol aus einem Datenpunkt
 * 
 * Format: measurement,tags field=value timestamp
 * Beispiel: 40004-BT1_Outdoor_Temperature,device=0,type=holding value=12.5 1609459200000000000
 * 
 * @param array $point Datenpunkt (von processApiData)
 * @param int $deviceId Device ID
 * @return string|null Line Protocol String oder null bei Fehler
 */
function buildInfluxLineProtocol($point, $deviceId) {
    try {
        $modbusId = $point['modbusregisterid'];
        $title = $point['title'];
        $rawValue = $point['rawvalue'];
        $divisor = $point['divisor'] ?? 1;
        $decimal = $point['decimal'] ?? 0;
        $registerType = $point['modbusregistertype'];
        
        // Measurement: ModbusID-Title (Leerzeichen durch _ ersetzen)
        $measurement = $modbusId . '-' . str_replace(' ', '_', $title);
        
        // Sonderzeichen escapen (InfluxDB Line Protocol Requirements)
        $measurement = escapeInfluxMeasurement($measurement);
        
        // Tags
        $tags = "device={$deviceId}";
        
        if ($registerType === 'MODBUS_HOLDING_REGISTER') {
            $tags .= ",type=holding";
        } elseif ($registerType === 'MODBUS_INPUT_REGISTER') {
            $tags .= ",type=input";
        }
        
        // Value berechnen (mit Divisor)
        $value = $divisor > 1 ? round($rawValue / $divisor, $decimal) : $rawValue;
        
        // Timestamp in UTC-0 Nanosekunden
        $timestamp = time() * 1000000000; // Sekunden -> Nanosekunden
        
        // Line Protocol zusammenbauen
        // Format: measurement,tag1=value1,tag2=value2 field1=value1,field2=value2 timestamp
        $line = "{$measurement},{$tags} value={$value} {$timestamp}";
        
        return $line;
        
    } catch (Exception $e) {
        debugLog("InfluxDB: Fehler beim Erstellen Line Protocol", [
            'error' => $e->getMessage(),
            'point' => $point
        ], 'ERROR');
        return null;
    }
}

/**
 * Escaped Sonderzeichen für InfluxDB Measurement Namen
 * 
 * @param string $str String zum Escapen
 * @return string Escaped String
 */
function escapeInfluxMeasurement($str) {
    // Kommas, Leerzeichen und = müssen escaped werden
    $str = str_replace(',', '\,', $str);
    $str = str_replace(' ', '\ ', $str);
    $str = str_replace('=', '\=', $str);
    
    return $str;
}

/**
 * Sendet Line Protocol Daten an InfluxDB
 * Unterstützt InfluxDB 1.x und 2.x
 * 
 * @param array $lines Array mit Line Protocol Strings
 * @return bool True bei Erfolg, False bei Fehler
 */
function sendToInfluxDB($lines) {
    try {
        $version = defined('INFLUX_VERSION') ? INFLUX_VERSION : 2;
        $url = INFLUX_URL;
        $timeout = defined('INFLUX_TIMEOUT') ? INFLUX_TIMEOUT : 10;
        
        // Body: Line Protocol (Zeilen mit \n getrennt)
        $body = implode("\n", $lines);
        
        // DEBUG: In Datei schreiben wenn aktiviert
        writeInfluxDebugFile($body);
        
        // Je nach Version unterschiedliche Endpoints und Auth
        if ($version === 1) {
            // InfluxDB 1.x
            $database = defined('INFLUX_BUCKET') ? INFLUX_BUCKET : 'nibe';
            $username = defined('INFLUX_USERNAME') ? INFLUX_USERNAME : '';
            $password = defined('INFLUX_PASSWORD') ? INFLUX_PASSWORD : '';
            
            $fullUrl = $url . '/write?' . http_build_query([
                'db' => $database,
                'precision' => 'ns'
            ]);
            
            // Headers für v1.x
            $headers = ['Content-Type: text/plain; charset=utf-8'];
            
            // Basic Auth nur wenn Username/Password gesetzt
            if (!empty($username) && !empty($password)) {
                $auth = base64_encode($username . ':' . $password);
                $headers[] = 'Authorization: Basic ' . $auth;
            }
            
            debugLog("InfluxDB: Sende Request (v1.x)", [
                'url' => $fullUrl,
                'database' => $database,
                'auth' => !empty($username) ? 'Basic Auth' : 'Keine Auth',
                'lines' => count($lines),
                'bodySize' => strlen($body)
            ], 'INFLUX');
            
        } else {
            // InfluxDB 2.x
            $token = defined('INFLUX_TOKEN') ? INFLUX_TOKEN : '';
            $org = defined('INFLUX_ORG') ? INFLUX_ORG : '';
            $bucket = defined('INFLUX_BUCKET') ? INFLUX_BUCKET : 'nibe';
            
            if (empty($token) || empty($org)) {
                debugLog("InfluxDB: Token oder Organisation fehlt für v2.x", null, 'ERROR');
                return false;
            }
            
            $fullUrl = $url . '/api/v2/write?' . http_build_query([
                'org' => $org,
                'bucket' => $bucket,
                'precision' => 'ns'
            ]);
            
            // Headers für v2.x
            $headers = [
                'Authorization: Token ' . $token,
                'Content-Type: text/plain; charset=utf-8'
            ];
            
            debugLog("InfluxDB: Sende Request (v2.x)", [
                'url' => $fullUrl,
                'org' => $org,
                'bucket' => $bucket,
                'lines' => count($lines),
                'bodySize' => strlen($body)
            ], 'INFLUX');
        }
        
        // cURL Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);;
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        debugLog("InfluxDB: Response", [
            'httpCode' => $httpCode,
            'error' => $error ?: 'none',
            'response' => $response ?: 'empty'
        ], 'INFLUX');
        
        // HTTP 204 = Success (No Content)
        if ($httpCode === 204) {
            return true;
        }
        
        // Andere 2xx Codes auch als Erfolg werten
        if ($httpCode >= 200 && $httpCode < 300) {
            return true;
        }
        
        // Fehler
        debugLog("InfluxDB: Request fehlgeschlagen", [
            'httpCode' => $httpCode,
            'response' => $response
        ], 'ERROR');
        
        return false;
        
    } catch (Exception $e) {
        debugLog("InfluxDB: Exception beim Senden", [
            'error' => $e->getMessage()
        ], 'ERROR');
        
        return false;
    }
}

/**
 * Schreibt InfluxDB Line Protocol in Debug-Datei
 * Nur wenn INFLUX_DEBUG_FILE aktiviert ist
 * 
 * @param string $lineProtocol Line Protocol String
 */
function writeInfluxDebugFile($lineProtocol) {
    // Prüfen ob Debug aktiviert ist
    if (!defined('INFLUX_DEBUG_FILE') || INFLUX_DEBUG_FILE !== true) {
        return;
    }
    
    if (!defined('INFLUX_DEBUG_PATH')) {
        return;
    }
    
    try {
        $debugFile = INFLUX_DEBUG_PATH;
        
        // Verzeichnis erstellen falls nicht vorhanden
        $dir = dirname($debugFile);
        if (!file_exists($dir)) {
            @mkdir($dir, 0755, true);
        }
        
        // Datei rotieren wenn zu groß
        $maxSize = defined('INFLUX_DEBUG_MAX_SIZE') ? INFLUX_DEBUG_MAX_SIZE : (10 * 1024 * 1024);
        
        if (file_exists($debugFile) && filesize($debugFile) > $maxSize) {
            $backupFile = $dir . '/' . date('Y-m-d_His') . '_influx_debug.txt';
            @rename($debugFile, $backupFile);
            debugLog("InfluxDB Debug-Datei rotiert", ['backup' => $backupFile], 'INFLUX');
        }
        
        // Header mit Timestamp
        $timestamp = date('Y-m-d H:i:s');
        $header = "\n" . str_repeat('=', 80) . "\n";
        $header .= "TIMESTAMP: {$timestamp}\n";
        $header .= "LINES: " . substr_count($lineProtocol, "\n") + 1 . "\n";
        $header .= str_repeat('=', 80) . "\n";
        
        // In Datei schreiben
        $content = $header . $lineProtocol . "\n";
        
        $result = @file_put_contents($debugFile, $content, FILE_APPEND | LOCK_EX);
        
        if ($result !== false) {
            debugLog("InfluxDB Debug geschrieben", [
                'file' => $debugFile,
                'bytes' => $result
            ], 'INFLUX');
        } else {
            debugLog("InfluxDB Debug konnte nicht geschrieben werden", [
                'file' => $debugFile
            ], 'WARNING');
        }
        
    } catch (Exception $e) {
        debugLog("Fehler beim Schreiben der InfluxDB Debug-Datei", [
            'error' => $e->getMessage()
        ], 'ERROR');
    }
}

/**
 * Testet die InfluxDB Verbindung
 * Unterstützt InfluxDB 1.x und 2.x
 * 
 * @return array ['success' => bool, 'message' => string, 'details' => array]
 */
function testInfluxDBConnection() {
    try {
        // Konfiguration prüfen
        if (!defined('INFLUX_ENABLED') || INFLUX_ENABLED !== true) {
            return [
                'success' => false,
                'message' => 'InfluxDB ist deaktiviert',
                'details' => ['enabled' => false]
            ];
        }
        
        if (!defined('INFLUX_URL')) {
            return [
                'success' => false,
                'message' => 'InfluxDB: URL nicht konfiguriert',
                'details' => ['url' => false]
            ];
        }
        
        $version = defined('INFLUX_VERSION') ? INFLUX_VERSION : 2;
        $url = INFLUX_URL;
        
        // Version-spezifische Prüfungen
        if ($version === 1) {
            // InfluxDB 1.x - nur URL und Database nötig
            if (!defined('INFLUX_BUCKET')) {
                return [
                    'success' => false,
                    'message' => 'InfluxDB: Database nicht konfiguriert',
                    'details' => ['database' => false]
                ];
            }
            
            // Ping Request (v1.x)
            $pingUrl = $url . '/ping';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $pingUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            
            // Basic Auth wenn konfiguriert
            if (defined('INFLUX_USERNAME') && defined('INFLUX_PASSWORD') && 
                !empty(INFLUX_USERNAME) && !empty(INFLUX_PASSWORD)) {
                curl_setopt($ch, CURLOPT_USERPWD, INFLUX_USERNAME . ':' . INFLUX_PASSWORD);
            }
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 204 || $httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'InfluxDB v1.x Verbindung erfolgreich',
                    'details' => [
                        'version' => '1.x',
                        'url' => $url,
                        'database' => INFLUX_BUCKET,
                        'auth' => defined('INFLUX_USERNAME') && !empty(INFLUX_USERNAME) ? 'Basic Auth' : 'Keine',
                        'httpCode' => $httpCode
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'InfluxDB v1.x Verbindung fehlgeschlagen',
                    'details' => [
                        'version' => '1.x',
                        'url' => $url,
                        'httpCode' => $httpCode,
                        'error' => $error ?: 'none',
                        'response' => $response ?: 'empty'
                    ]
                ];
            }
            
        } else {
            // InfluxDB 2.x - Token, Org und Bucket nötig
            if (!defined('INFLUX_TOKEN') || !defined('INFLUX_ORG') || !defined('INFLUX_BUCKET')) {
                return [
                    'success' => false,
                    'message' => 'InfluxDB: Unvollständige Konfiguration für v2.x',
                    'details' => [
                        'url' => defined('INFLUX_URL'),
                        'token' => defined('INFLUX_TOKEN'),
                        'org' => defined('INFLUX_ORG'),
                        'bucket' => defined('INFLUX_BUCKET')
                    ]
                ];
            }
            
            // Ping Request (v2.x)
            $pingUrl = $url . '/ping';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $pingUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Token ' . INFLUX_TOKEN
            ]);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 204 || $httpCode === 200) {
                return [
                    'success' => true,
                    'message' => 'InfluxDB v2.x Verbindung erfolgreich',
                    'details' => [
                        'version' => '2.x',
                        'url' => $url,
                        'org' => INFLUX_ORG,
                        'bucket' => INFLUX_BUCKET,
                        'httpCode' => $httpCode
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'InfluxDB v2.x Verbindung fehlgeschlagen',
                    'details' => [
                        'version' => '2.x',
                        'url' => $url,
                        'httpCode' => $httpCode,
                        'error' => $error ?: 'none',
                        'response' => $response ?: 'empty'
                    ]
                ];
            }
        }
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'InfluxDB Test Exception',
            'details' => [
                'error' => $e->getMessage()
            ]
        ];
    }
}
?>