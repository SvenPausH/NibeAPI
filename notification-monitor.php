<?php
/**
 * Nibe Notification Monitor - Cronjob Script mit InfluxDB Support
 * notification-monitor.php v2.0
 * 
 * NEU: Schreibt Datenpunkte in InfluxDB
 * 
 * Verwendung:
 * 1. Konfiguration in config.php anpassen
 * 2. Cronjob einrichten: * * * * * /usr/bin/php /pfad/zu/notification-monitor.php
 * 3. Oder manuell testen: php notification-monitor.php
 * 
 * Test-Modi (per Kommandozeile):
 * - php notification-monitor.php --debug              Verbose Output + normale Ausführung
 * - php notification-monitor.php --test-email         Sendet Test-E-Mail
 * - php notification-monitor.php --test-telegram      Sendet Test-Telegram
 * - php notification-monitor.php --test-influx        Testet InfluxDB Verbindung
 * - php notification-monitor.php --test-all           Testet E-Mail, Telegram UND InfluxDB
 * - php notification-monitor.php --dry-run            Alles prüfen, nichts senden
 */

// Kommandozeilen-Argumente parsen
$options = getopt('', ['debug', 'test-email', 'test-telegram', 'test-influx', 'test-all', 'dry-run']);
$debugMode = isset($options['debug']) || isset($options['test-email']) || isset($options['test-telegram']) || isset($options['test-influx']) || isset($options['test-all']);
$testEmailMode = isset($options['test-email']) || isset($options['test-all']);
$testTelegramMode = isset($options['test-telegram']) || isset($options['test-all']);
$testInfluxMode = isset($options['test-influx']) || isset($options['test-all']);
$dryRunMode = isset($options['dry-run']);

// Console Output Helper
function consoleLog($message, $data = null, $type = 'INFO') {
    global $debugMode;
    
    if (!$debugMode && $type !== 'ERROR') {
        return;
    }
    
    $colors = [
        'INFO' => "\033[0;36m",    // Cyan
        'SUCCESS' => "\033[0;32m", // Green
        'WARNING' => "\033[0;33m", // Yellow
        'ERROR' => "\033[0;31m",   // Red
        'DEBUG' => "\033[0;35m",   // Magenta
        'MONITOR' => "\033[0;34m", // Blue
        'INFLUX' => "\033[0;96m"   // Bright Cyan
    ];
    
    $reset = "\033[0m";
    $color = $colors[$type] ?? "\033[0m";
    
    $timestamp = date('Y-m-d H:i:s');
    echo "{$color}[{$timestamp}] [{$type}]{$reset} {$message}\n";
    
    if ($data !== null && $debugMode) {
        echo "{$color}";
        print_r($data);
        echo "{$reset}\n";
    }
}

// Konfiguration laden
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/influxdb.php'; // NEU!

consoleLog("=== Nibe Notification Monitor gestartet ===", null, 'INFO');

// Prüfen ob DB aktiv ist
if (!USE_DB) {
    consoleLog("DB ist deaktiviert - Monitor kann nicht ausgeführt werden", null, 'ERROR');
    debugLog("Notification Monitor: DB ist deaktiviert", null, 'ERROR');
    exit(1);
}

// Test-Modi ausführen
if ($testEmailMode) {
    consoleLog("=== E-MAIL TEST-MODUS ===", null, 'INFO');
    testEmail();
    if (!isset($options['test-all'])) exit(0);
}

if ($testTelegramMode) {
    consoleLog("=== TELEGRAM TEST-MODUS ===", null, 'INFO');
    testTelegram();
    if (!isset($options['test-all'])) exit(0);
}

if ($testInfluxMode) {
    consoleLog("=== INFLUXDB TEST-MODUS ===", null, 'INFO');
    testInfluxDB();
    if (!isset($options['test-all'])) exit(0);
}

if ($dryRunMode) {
    consoleLog("=== DRY-RUN MODUS (keine Benachrichtigungen werden gesendet) ===", null, 'WARNING');
}

// Prüfen ob Benachrichtigungen aktiviert sind
if (!defined('NOTIFY_EMAIL_ENABLED') && !defined('NOTIFY_TELEGRAM_ENABLED')) {
    consoleLog("Keine Benachrichtigungsmethode konfiguriert", null, 'ERROR');
    debugLog("Notification Monitor: Keine Benachrichtigungsmethode konfiguriert", null, 'ERROR');
    echo "\nERROR: Bitte konfigurieren Sie E-Mail oder Telegram in config.php\n";
    echo "Beispiel-Konfiguration:\n";
    echo "  define('NOTIFY_EMAIL_ENABLED', true);\n";
    echo "  define('NOTIFY_TELEGRAM_ENABLED', true);\n\n";
    exit(1);
}

$emailEnabled = defined('NOTIFY_EMAIL_ENABLED') && NOTIFY_EMAIL_ENABLED === true;
$telegramEnabled = defined('NOTIFY_TELEGRAM_ENABLED') && NOTIFY_TELEGRAM_ENABLED === true;
$influxEnabled = defined('INFLUX_ENABLED') && INFLUX_ENABLED === true;

consoleLog("Konfiguration:", [
    'E-Mail' => $emailEnabled ? 'AKTIV' : 'INAKTIV',
    'Telegram' => $telegramEnabled ? 'AKTIV' : 'INAKTIV',
    'InfluxDB' => $influxEnabled ? 'AKTIV' : 'INAKTIV',
    'Min Severity' => defined('NOTIFY_MIN_SEVERITY') ? NOTIFY_MIN_SEVERITY : 1,
    'Cooldown' => (defined('NOTIFY_COOLDOWN_MINUTES') ? NOTIFY_COOLDOWN_MINUTES : 5) . ' Minuten'
], 'INFO');

if (!$emailEnabled && !$telegramEnabled) {
    consoleLog("Alle Benachrichtigungen deaktiviert - beende Monitor", null, 'WARNING');
    debugLog("Notification Monitor: Alle Benachrichtigungen deaktiviert", null, 'INFO');
    exit(0);
}

// ============================================================================
// HAUPTFUNKTION
// ============================================================================

try {
    debugLog("Notification Monitor gestartet", null, 'MONITOR');
    consoleLog("Starte Monitor-Durchlauf...", null, 'MONITOR');
    
    // Prüfen ob CRON-Modus aktiv ist
    $checkBy = defined('NOTIFICATIONS_CHECK_BY') ? NOTIFICATIONS_CHECK_BY : 'WEB';
    
    if ($checkBy !== 'CRON') {
        consoleLog("ABBRUCH: Monitor ist auf '{$checkBy}' konfiguriert, nicht auf 'CRON'", null, 'WARNING');
        consoleLog("Bitte setzen Sie in config.php: define('NOTIFICATIONS_CHECK_BY', 'CRON');", null, 'INFO');
        exit(0);
    }
    
    // Prüfen ob bereits ein Update läuft (Lock-Check)
    if (!acquireUpdateLock()) {
        consoleLog("ABBRUCH: Ein anderer Update-Prozess läuft bereits", null, 'WARNING');
        debugLog("Update-Lock aktiv - überspringe Durchlauf", null, 'MONITOR');
        exit(0);
    }
    
    consoleLog("Update-Lock erfolgreich gesetzt", null, 'SUCCESS');
    
    // SCHRITT 1: Device-Discovery (neue Devices erkennen)
    consoleLog("\n[1/5] Device-Discovery...", null, 'INFO');
    try {
        $discoveredDevices = discoverDevices();
        consoleLog("  └─ {count} Device(s) von API gefunden", ['count' => count($discoveredDevices)], 'DEBUG');
        
        $newDeviceCount = 0;
        foreach ($discoveredDevices as $apiDevice) {
            try {
                // Prüfen ob Device schon existiert
                $pdo = getDbConnection();
                $stmt = $pdo->prepare("SELECT deviceId FROM nibe_device WHERE deviceId = ?");
                $stmt->execute([$apiDevice['deviceIndex']]);
                $exists = $stmt->fetch();
                
                saveDevice($apiDevice);
                
                if (!$exists) {
                    $newDeviceCount++;
                    consoleLog("  └─ NEUES Device gespeichert: {$apiDevice['product']['name']} (ID: {$apiDevice['deviceIndex']})", null, 'SUCCESS');
                } else {
                    consoleLog("  └─ Device aktualisiert: {$apiDevice['product']['name']} (ID: {$apiDevice['deviceIndex']})", null, 'DEBUG');
                }
            } catch (Exception $e) {
                consoleLog("  └─ Fehler beim Speichern: " . $e->getMessage(), null, 'ERROR');
            }
        }
        
        if ($newDeviceCount > 0) {
            consoleLog("  ✓ {$newDeviceCount} neue Device(s) gefunden und gespeichert", null, 'SUCCESS');
        } else {
            consoleLog("  ✓ Keine neuen Devices", null, 'INFO');
        }
        
    } catch (Exception $e) {
        consoleLog("  ✗ Device-Discovery fehlgeschlagen: " . $e->getMessage(), null, 'ERROR');
    }
    
    // Alle Devices aus DB laden
    $devices = getAllDevices();
    
    if (empty($devices)) {
        consoleLog("\n✗ Keine Devices in Datenbank - beende Monitor", null, 'WARNING');
        debugLog("Keine Devices gefunden - beende Monitor", null, 'WARNING');
        exit(0);
    }
    
    consoleLog("\n[2/5] Datenpunkte aktualisieren...", null, 'INFO');
    consoleLog("  └─ Verarbeite {count} Device(s)", ['count' => count($devices)], 'INFO');
    
    $totalNewDatapoints = 0;
    $totalUpdatedDatapoints = 0;
    
    // SCHRITT 2: Datenpunkte aktualisieren für jedes Device
    foreach ($devices as $device) {
        $deviceId = $device['deviceId'];
        
        consoleLog("  └─ Device {$deviceId}: {$device['name']}", null, 'DEBUG');
        
        try {
            // API-Daten abrufen
            $apiUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
            $jsonData = fetchApiData($apiUrl, API_KEY, API_USERNAME, API_PASSWORD);
            $rawData = json_decode($jsonData, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('JSON Dekodierungs-Fehler');
            }
            
            $data = processApiData($rawData, $deviceId);
            consoleLog("     └─ {count} Datenpunkte von API", ['count' => count($data)], 'DEBUG');
            
            // Datenpunkte in Master-Tabelle speichern/aktualisieren
            $saveResult = saveAllDatapoints($data, $deviceId);
            $totalNewDatapoints += $saveResult['inserted'];
            $totalUpdatedDatapoints += $saveResult['updated'];
            
            if ($saveResult['inserted'] > 0) {
                consoleLog("     └─ {$saveResult['inserted']} neue Datenpunkte", null, 'SUCCESS');
            }
            if ($saveResult['updated'] > 0) {
                consoleLog("     └─ {$saveResult['updated']} aktualisierte Datenpunkte", null, 'DEBUG');
            }
            
            // Wertänderungen in Log schreiben
            $loggedChanges = logValueChanges($data, $deviceId);
            if ($loggedChanges > 0) {
                consoleLog("     └─ {$loggedChanges} Wertänderungen geloggt", null, 'DEBUG');
            }
            
        } catch (Exception $e) {
            consoleLog("     └─ Fehler: " . $e->getMessage(), null, 'ERROR');
            debugLog("Fehler beim Aktualisieren von Device $deviceId", ['error' => $e->getMessage()], 'ERROR');
        }
    }
    
    if ($totalNewDatapoints > 0 || $totalUpdatedDatapoints > 0) {
        consoleLog("  ✓ Datenpunkte: {$totalNewDatapoints} neu, {$totalUpdatedDatapoints} aktualisiert", null, 'SUCCESS');
    } else {
        consoleLog("  ✓ Keine neuen Datenpunkte", null, 'INFO');
    }
    
    consoleLog("\n[3/5] Notifications prüfen...", null, 'INFO');
    debugLog("Prüfe " . count($devices) . " Device(s) auf Notifications", null, 'MONITOR');
    
    $totalNewNotifications = 0;
    $notificationsToSend = [];
    
    // SCHRITT 3: Notifications prüfen für jedes Device
    foreach ($devices as $device) {
        $deviceId = $device['deviceId'];
        
        consoleLog("  └─ Device {$deviceId}: {$device['name']}", null, 'DEBUG');
        
        try {
            // Notifications von API abrufen
            $alarms = fetchNotifications($deviceId);
            
            consoleLog("     └─ {count} Notification(s) von API", ['count' => count($alarms)], 'DEBUG');
            debugLog("Device $deviceId: " . count($alarms) . " Notification(s) von API", null, 'MONITOR');
            
            // Jede Notification prüfen und ggf. speichern
            foreach ($alarms as $alarm) {
                $severity = $alarm['severity'] ?? 0;
                $severityText = getSeverityText($severity);
                
                consoleLog("     └─ Alarm {$alarm['alarmId']}: {$severityText} - {$alarm['header']}", null, 'DEBUG');
                
                // Mindest-Severity prüfen
                $minSeverity = defined('NOTIFY_MIN_SEVERITY') ? NOTIFY_MIN_SEVERITY : 1;
                if ($severity < $minSeverity) {
                    consoleLog("        └─ Übersprungen (Severity zu niedrig: $severity < $minSeverity)", null, 'DEBUG');
                    continue;
                }
                
                // In DB speichern (gibt false zurück wenn bereits vorhanden)
                $isNew = saveNotification($deviceId, $alarm);
                
                if ($isNew) {
                    $totalNewNotifications++;
                    consoleLog("        └─ NEU in Datenbank gespeichert", null, 'SUCCESS');
                    
                    // Cooldown prüfen (verhindert Spam bei gleichen Notifications)
                    if (shouldNotify($deviceId, $alarm)) {
                        $notificationsToSend[] = [
                            'device' => $device,
                            'alarm' => $alarm
                        ];
                        consoleLog("        └─ Wird gesendet (Cooldown OK)", null, 'SUCCESS');
                    } else {
                        consoleLog("        └─ NICHT gesendet (Cooldown aktiv)", null, 'WARNING');
                    }
                } else {
                    consoleLog("        └─ Bereits vorhanden", null, 'DEBUG');
                }
            }
            
        } catch (Exception $e) {
            consoleLog("     └─ Fehler: " . $e->getMessage(), null, 'ERROR');
            debugLog("Fehler beim Prüfen von Device $deviceId", ['error' => $e->getMessage()], 'ERROR');
        }
    }
    
    if ($totalNewNotifications > 0) {
        consoleLog("  ✓ {$totalNewNotifications} neue Notification(s) gefunden", null, 'SUCCESS');
    } else {
        consoleLog("  ✓ Keine neuen Notifications", null, 'INFO');
    }
    
    // ============================================================================
    // SCHRITT 4: INFLUXDB EXPORT (NEU!)
    // ============================================================================
    
    consoleLog("\n[4/5] InfluxDB Export...", null, 'INFO');
    
    if ($influxEnabled) {
        $totalInfluxSuccess = 0;
        $totalInfluxFailed = 0;
        $totalInfluxSkipped = 0;
        
        foreach ($devices as $device) {
            $deviceId = $device['deviceId'];
            
            consoleLog("  └─ Device {$deviceId}: {$device['name']}", null, 'DEBUG');
            
            try {
                // API-Daten abrufen (falls noch nicht vorhanden)
                $apiUrl = API_BASE_URL . '/api/' . API_VERSION . '/devices/' . $deviceId . '/points';
                $jsonData = fetchApiData($apiUrl, API_KEY, API_USERNAME, API_PASSWORD);
                $rawData = json_decode($jsonData, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new Exception('JSON Dekodierungs-Fehler');
                }
                
                $data = processApiData($rawData, $deviceId);
                
                // In InfluxDB schreiben
                if (!$dryRunMode) {
                    $result = writeToInfluxDB($data, $deviceId);
                    
                    $totalInfluxSuccess += $result['success'];
                    $totalInfluxFailed += $result['failed'];
                    $totalInfluxSkipped += $result['skipped'];
                    
                    if ($result['success'] > 0) {
                        consoleLog("     └─ {$result['success']} Datenpunkte geschrieben", null, 'SUCCESS');
                    }
                    if ($result['failed'] > 0) {
                        consoleLog("     └─ {$result['failed']} Fehler", null, 'ERROR');
                    }
                    if ($result['skipped'] > 0) {
                        consoleLog("     └─ {$result['skipped']} übersprungen (Filter)", null, 'DEBUG');
                    }
                } else {
                    consoleLog("     └─ DRY-RUN: Würde Datenpunkte schreiben", null, 'WARNING');
                }
                
            } catch (Exception $e) {
                consoleLog("     └─ Fehler: " . $e->getMessage(), null, 'ERROR');
                debugLog("InfluxDB Export Fehler für Device $deviceId", ['error' => $e->getMessage()], 'ERROR');
            }
        }
        
        consoleLog("  ✓ InfluxDB Export: {$totalInfluxSuccess} erfolgreich, {$totalInfluxFailed} fehlgeschlagen, {$totalInfluxSkipped} übersprungen", null, 'SUCCESS');
        
    } else {
        consoleLog("  ⊘ InfluxDB ist deaktiviert", null, 'INFO');
    }
    
    // ============================================================================
    // ZUSAMMENFASSUNG
    // ============================================================================
    
    consoleLog("\n=== ZUSAMMENFASSUNG ===", null, 'INFO');
    consoleLog("Devices: " . count($devices), null, 'INFO');
    consoleLog("Neue Datenpunkte: {$totalNewDatapoints}", null, 'INFO');
    consoleLog("Aktualisierte Datenpunkte: {$totalUpdatedDatapoints}", null, 'INFO');
    consoleLog("Neue Notifications: {$totalNewNotifications}", null, 'INFO');
    consoleLog("Zu versendende Benachrichtigungen: " . count($notificationsToSend), null, 'INFO');
    
    if ($influxEnabled) {
        consoleLog("InfluxDB: {$totalInfluxSuccess} geschrieben, {$totalInfluxFailed} fehlgeschlagen", null, 'INFO');
    }
    
    debugLog("Monitor-Durchlauf abgeschlossen", [
        'devices' => count($devices),
        'newDatapoints' => $totalNewDatapoints,
        'updatedDatapoints' => $totalUpdatedDatapoints,
        'newNotifications' => $totalNewNotifications,
        'toSend' => count($notificationsToSend),
        'influxSuccess' => $totalInfluxSuccess ?? 0,
        'influxFailed' => $totalInfluxFailed ?? 0
    ], 'MONITOR');
    
    // SCHRITT 5: Benachrichtigungen versenden
    consoleLog("\n[5/5] Benachrichtigungen versenden...", null, 'INFO');
    
    // Benachrichtigungen versenden
    if (!empty($notificationsToSend)) {
        if ($dryRunMode) {
            consoleLog("  DRY-RUN: " . count($notificationsToSend) . " Benachrichtigung(en) würden gesendet", null, 'WARNING');
            foreach ($notificationsToSend as $item) {
                consoleLog("    - Device {$item['device']['deviceId']}: {$item['alarm']['header']}", null, 'INFO');
            }
        } else {
            sendNotifications($notificationsToSend);
        }
    } else {
        consoleLog("  ✓ Keine Benachrichtigungen zu versenden", null, 'INFO');
        debugLog("Keine neuen Notifications zum Versenden", null, 'MONITOR');
    }
    
    consoleLog("\n=== Monitor erfolgreich beendet ===", null, 'SUCCESS');
    
    // last_updated für alle Devices aktualisieren
    consoleLog("\nAktualisiere last_updated Timestamp...", null, 'INFO');
    try {
        $pdo = getDbConnection();
        $stmt = $pdo->prepare("UPDATE nibe_device SET last_updated = NOW()");
        $stmt->execute();
        $affectedRows = $stmt->rowCount();
        consoleLog("  ✓ {$affectedRows} Device(s) aktualisiert", null, 'SUCCESS');
        debugLog("last_updated für {$affectedRows} Device(s) gesetzt", null, 'MONITOR');
    } catch (Exception $e) {
        consoleLog("  ✗ Fehler beim Aktualisieren: " . $e->getMessage(), null, 'ERROR');
        debugLog("Fehler beim Setzen von last_updated", ['error' => $e->getMessage()], 'ERROR');
    }
    
    // Update-Lock freigeben
    releaseUpdateLock();
    consoleLog("Update-Lock freigegeben", null, 'SUCCESS');
    
} catch (Exception $e) {
    consoleLog("KRITISCHER FEHLER: " . $e->getMessage(), null, 'ERROR');
    debugLog("Monitor Fehler", ['error' => $e->getMessage()], 'ERROR');
    
    // Lock freigeben auch bei Fehler
    releaseUpdateLock();
    
    exit(1);
}

exit(0);

// ============================================================================
// LOCK-MECHANISMUS (verhindert gleichzeitige Updates)
// ============================================================================

/**
 * Versucht Update-Lock zu setzen
 * Gibt true zurück wenn erfolgreich, false wenn bereits ein Update läuft
 */
function acquireUpdateLock() {
    try {
        $pdo = getDbConnection();
        
        // Prüfen ob bereits ein Lock existiert
        $checkInterval = defined('NOTIFICATIONS_CHECK_INTERVAL') ? NOTIFICATIONS_CHECK_INTERVAL : 300;
        
        // Lock ist gültig wenn er nicht älter als CHECK_INTERVAL * 2 ist
        $maxLockAge = $checkInterval * 2;
        
        $stmt = $pdo->prepare("
            SELECT value, updated_at, 
                   TIMESTAMPDIFF(SECOND, updated_at, NOW()) as age_seconds
            FROM nibe_system_config 
            WHERE config_key = 'update_lock'
        ");
        $stmt->execute();
        $lock = $stmt->fetch();
        
        if ($lock) {
            // Lock existiert - prüfen ob er noch gültig ist
            if ($lock['value'] === '1' && $lock['age_seconds'] < $maxLockAge) {
                debugLog("Update-Lock aktiv", [
                    'age_seconds' => $lock['age_seconds'],
                    'max_age' => $maxLockAge
                ], 'MONITOR');
                return false; // Lock ist aktiv
            }
            
            // Alter Lock - überschreiben
            if ($lock['age_seconds'] >= $maxLockAge) {
                debugLog("Alter Lock überschrieben", ['age_seconds' => $lock['age_seconds']], 'WARNING');
            }
            
            // Lock aktualisieren
            $stmt = $pdo->prepare("
                UPDATE nibe_system_config 
                SET value = '1', updated_at = NOW()
                WHERE config_key = 'update_lock'
            ");
            $stmt->execute();
        } else {
            // Lock erstellen
            $stmt = $pdo->prepare("
                INSERT INTO nibe_system_config (config_key, value, updated_at)
                VALUES ('update_lock', '1', NOW())
            ");
            $stmt->execute();
        }
        
        debugLog("Update-Lock gesetzt", null, 'MONITOR');
        return true;
        
    } catch (Exception $e) {
        debugLog("Fehler beim Setzen des Update-Locks", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Gibt Update-Lock frei
 */
function releaseUpdateLock() {
    try {
        $pdo = getDbConnection();
        
        $stmt = $pdo->prepare("
            UPDATE nibe_system_config 
            SET value = '0', updated_at = NOW()
            WHERE config_key = 'update_lock'
        ");
        $stmt->execute();
        
        debugLog("Update-Lock freigegeben", null, 'MONITOR');
        return true;
        
    } catch (Exception $e) {
        debugLog("Fehler beim Freigeben des Update-Locks", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

// ============================================================================
// TEST-FUNKTIONEN
// ============================================================================

/**
 * Testet E-Mail Versand
 */
function testEmail() {
    consoleLog("Teste E-Mail Konfiguration...", null, 'INFO');
    
    if (!defined('NOTIFY_EMAIL_ENABLED') || NOTIFY_EMAIL_ENABLED !== true) {
        consoleLog("E-Mail ist NICHT aktiviert", null, 'ERROR');
        echo "\nBitte setzen Sie in config.php:\n";
        echo "  define('NOTIFY_EMAIL_ENABLED', true);\n\n";
        return false;
    }
    
    consoleLog("E-Mail ist aktiviert", null, 'SUCCESS');
    
    // Konfiguration prüfen
    $config = [
        'NOTIFY_EMAIL_METHOD' => defined('NOTIFY_EMAIL_METHOD') ? NOTIFY_EMAIL_METHOD : 'mail',
        'NOTIFY_EMAIL_FROM' => defined('NOTIFY_EMAIL_FROM') ? NOTIFY_EMAIL_FROM : 'nicht gesetzt',
        'NOTIFY_EMAIL_TO' => defined('NOTIFY_EMAIL_TO') ? NOTIFY_EMAIL_TO : 'nicht gesetzt'
    ];
    
    if ($config['NOTIFY_EMAIL_METHOD'] === 'smtp') {
        $config['NOTIFY_SMTP_HOST'] = defined('NOTIFY_SMTP_HOST') ? NOTIFY_SMTP_HOST : 'nicht gesetzt';
        $config['NOTIFY_SMTP_PORT'] = defined('NOTIFY_SMTP_PORT') ? NOTIFY_SMTP_PORT : 'nicht gesetzt';
        $config['NOTIFY_SMTP_SECURITY'] = defined('NOTIFY_SMTP_SECURITY') ? NOTIFY_SMTP_SECURITY : 'keine';
        $config['NOTIFY_SMTP_AUTH'] = defined('NOTIFY_SMTP_AUTH') && NOTIFY_SMTP_AUTH ? 'JA' : 'NEIN';
        $config['NOTIFY_SMTP_USERNAME'] = defined('NOTIFY_SMTP_USERNAME') ? NOTIFY_SMTP_USERNAME : 'nicht gesetzt';
    }
    
    consoleLog("Konfiguration:", $config, 'INFO');
    
    // Test-Daten erstellen
    $testDevice = [
        'deviceId' => 999,
        'name' => 'TEST DEVICE',
        'serialNumber' => 'TEST-12345'
    ];
    
    $testAlarm = [
        'alarmId' => 99999,
        'severity' => 2,
        'header' => 'TEST NOTIFICATION',
        'description' => 'Dies ist eine Test-Benachrichtigung vom Nibe Notification Monitor',
        'equipName' => 'TEST Equipment',
        'time' => date('Y-m-d H:i:s')
    ];
    
    consoleLog("\nSende Test-E-Mail...", null, 'INFO');
    consoleLog("Von: {$config['NOTIFY_EMAIL_FROM']}", null, 'INFO');
    consoleLog("An: {$config['NOTIFY_EMAIL_TO']}", null, 'INFO');
    
    $success = sendEmailNotification($testDevice, $testAlarm);
    
    if ($success) {
        consoleLog("\n✓ Test-E-Mail erfolgreich gesendet!", null, 'SUCCESS');
        consoleLog("Bitte prüfen Sie Ihr Postfach (auch Spam-Ordner)", null, 'INFO');
    } else {
        consoleLog("\n✗ Test-E-Mail konnte NICHT gesendet werden", null, 'ERROR');
        consoleLog("Bitte prüfen Sie das Debug-Log für Details: " . DEBUG_LOG_FULLPATH, null, 'INFO');
    }
    
    return $success;
}

/**
 * Testet Telegram Versand
 */
function testTelegram() {
    consoleLog("Teste Telegram Konfiguration...", null, 'INFO');
    
    if (!defined('NOTIFY_TELEGRAM_ENABLED') || NOTIFY_TELEGRAM_ENABLED !== true) {
        consoleLog("Telegram ist NICHT aktiviert", null, 'ERROR');
        echo "\nBitte setzen Sie in config.php:\n";
        echo "  define('NOTIFY_TELEGRAM_ENABLED', true);\n\n";
        return false;
    }
    
    consoleLog("Telegram ist aktiviert", null, 'SUCCESS');
    
    // Konfiguration prüfen
    $config = [
        'NOTIFY_TELEGRAM_BOT_TOKEN' => defined('NOTIFY_TELEGRAM_BOT_TOKEN') ? 
            (strlen(NOTIFY_TELEGRAM_BOT_TOKEN) > 20 ? 'gesetzt (' . strlen(NOTIFY_TELEGRAM_BOT_TOKEN) . ' Zeichen)' : 'zu kurz/ungültig') : 
            'nicht gesetzt',
        'NOTIFY_TELEGRAM_CHAT_ID' => defined('NOTIFY_TELEGRAM_CHAT_ID') ? NOTIFY_TELEGRAM_CHAT_ID : 'nicht gesetzt'
    ];
    
    consoleLog("Konfiguration:", $config, 'INFO');
    
    if (!defined('NOTIFY_TELEGRAM_BOT_TOKEN') || empty(NOTIFY_TELEGRAM_BOT_TOKEN)) {
        consoleLog("\n✗ Bot-Token fehlt!", null, 'ERROR');
        echo "\nSo erhalten Sie einen Bot-Token:\n";
        echo "  1. Telegram öffnen\n";
        echo "  2. Nach @BotFather suchen\n";
        echo "  3. /newbot eingeben und Anweisungen folgen\n";
        echo "  4. Token kopieren und in config.php eintragen\n\n";
        return false;
    }
    
    if (!defined('NOTIFY_TELEGRAM_CHAT_ID') || empty(NOTIFY_TELEGRAM_CHAT_ID)) {
        consoleLog("\n✗ Chat-ID fehlt!", null, 'ERROR');
        echo "\nSo erhalten Sie Ihre Chat-ID:\n";
        echo "  1. Telegram öffnen\n";
        echo "  2. Nach @userinfobot suchen\n";
        echo "  3. Bot starten - er zeigt Ihre Chat-ID\n";
        echo "  4. Chat-ID in config.php eintragen\n\n";
        return false;
    }
    
    // Test-Daten erstellen
    $testDevice = [
        'deviceId' => 999,
        'name' => 'TEST DEVICE',
        'serialNumber' => 'TEST-12345'
    ];
    
    $testAlarm = [
        'alarmId' => 99999,
        'severity' => 2,
        'header' => 'TEST NOTIFICATION',
        'description' => 'Dies ist eine Test-Benachrichtigung vom Nibe Notification Monitor',
        'equipName' => 'TEST Equipment',
        'time' => date('Y-m-d H:i:s')
    ];
    
    consoleLog("\nSende Test-Telegram...", null, 'INFO');
    consoleLog("An Chat-ID: {$config['NOTIFY_TELEGRAM_CHAT_ID']}", null, 'INFO');
    
    $success = sendTelegramNotification($testDevice, $testAlarm);
    
    if ($success) {
        consoleLog("\n✓ Test-Telegram erfolgreich gesendet!", null, 'SUCCESS');
        consoleLog("Bitte prüfen Sie Telegram", null, 'INFO');
    } else {
        consoleLog("\n✗ Test-Telegram konnte NICHT gesendet werden", null, 'ERROR');
        consoleLog("Bitte prüfen Sie das Debug-Log für Details: " . DEBUG_LOG_FULLPATH, null, 'INFO');
        echo "\nMögliche Ursachen:\n";
        echo "  - Bot-Token ungültig\n";
        echo "  - Chat-ID ungültig\n";
        echo "  - Bot wurde nicht gestartet (senden Sie /start an den Bot)\n";
        echo "  - Keine Internetverbindung\n\n";
    }
    
    return $success;
}

function testInfluxDB() {
    consoleLog("Teste InfluxDB Verbindung...", null, 'INFO');
    
    if (!defined('INFLUX_ENABLED') || INFLUX_ENABLED !== true) {
        consoleLog("InfluxDB ist NICHT aktiviert", null, 'ERROR');
        echo "\nBitte setzen Sie in config.php:\n";
        echo "  define('INFLUX_ENABLED', true);\n\n";
        return false;
    }
    
    consoleLog("InfluxDB ist aktiviert", null, 'SUCCESS');
    
    $version = defined('INFLUX_VERSION') ? INFLUX_VERSION : 2;
    
    // Konfiguration prüfen
    $config = [
        'INFLUX_VERSION' => $version . '.x',
        'INFLUX_URL' => defined('INFLUX_URL') ? INFLUX_URL : 'nicht gesetzt',
    ];
    
    if ($version === 1) {
        // InfluxDB 1.x
        $config['INFLUX_BUCKET (Database)'] = defined('INFLUX_BUCKET') ? INFLUX_BUCKET : 'nicht gesetzt';
        $config['INFLUX_USERNAME'] = defined('INFLUX_USERNAME') && !empty(INFLUX_USERNAME) ? INFLUX_USERNAME : 'nicht gesetzt (keine Auth)';
        $config['INFLUX_PASSWORD'] = defined('INFLUX_PASSWORD') && !empty(INFLUX_PASSWORD) ? '***gesetzt***' : 'nicht gesetzt (keine Auth)';
    } else {
        // InfluxDB 2.x
        $config['INFLUX_TOKEN'] = defined('INFLUX_TOKEN') ? (strlen(INFLUX_TOKEN) > 20 ? 'gesetzt (' . strlen(INFLUX_TOKEN) . ' Zeichen)' : 'zu kurz') : 'nicht gesetzt';
        $config['INFLUX_ORG'] = defined('INFLUX_ORG') ? INFLUX_ORG : 'nicht gesetzt';
        $config['INFLUX_BUCKET'] = defined('INFLUX_BUCKET') ? INFLUX_BUCKET : 'nicht gesetzt';
    }
    
    $config['INFLUX_HOLDING'] = defined('INFLUX_HOLDING') ? INFLUX_HOLDING : 'nicht gesetzt';
    $config['INFLUX_INPUT'] = defined('INFLUX_INPUT') ? INFLUX_INPUT : 'nicht gesetzt';
    $config['INFLUX_HIDE_VALUES'] = defined('INFLUX_HIDE_VALUES') ? INFLUX_HIDE_VALUES : 'nicht gesetzt';
    $config['INFLUX_DEBUG_FILE'] = defined('INFLUX_DEBUG_FILE') && INFLUX_DEBUG_FILE ? 'AKTIV' : 'INAKTIV';
    
    if (defined('INFLUX_DEBUG_FILE') && INFLUX_DEBUG_FILE === true) {
        $config['INFLUX_DEBUG_PATH'] = defined('INFLUX_DEBUG_PATH') ? INFLUX_DEBUG_PATH : 'nicht gesetzt';
    }
    
    consoleLog("Konfiguration:", $config, 'INFO');
    
    consoleLog("\nTeste Verbindung...", null, 'INFO');
    
    $result = testInfluxDBConnection();
    
    if ($result['success']) {
        consoleLog("\n✓ InfluxDB Verbindung erfolgreich!", null, 'SUCCESS');
        consoleLog("Details:", $result['details'], 'INFO');
        
        // Wenn Debug aktiv, Info anzeigen
        if (defined('INFLUX_DEBUG_FILE') && INFLUX_DEBUG_FILE === true) {
            consoleLog("\nℹ️  InfluxDB Debug ist AKTIV", null, 'INFO');
            consoleLog("Debug-Datei: " . INFLUX_DEBUG_PATH, null, 'INFO');
            consoleLog("Alle Line Protocol Daten werden in diese Datei geschrieben.", null, 'INFO');
        }
    } else {
        consoleLog("\n✗ InfluxDB Verbindung fehlgeschlagen", null, 'ERROR');
        consoleLog("Fehler: " . $result['message'], null, 'ERROR');
        consoleLog("Details:", $result['details'], 'ERROR');
    }
    
    return $result['success'];
}

// ============================================================================
// HILFSFUNKTIONEN 
// ============================================================================

/**
 * Prüft ob für diese Notification eine Benachrichtigung gesendet werden soll
 * (Cooldown-Check)
 */
function shouldNotify($deviceId, $alarm) {
    $cooldownMinutes = defined('NOTIFY_COOLDOWN_MINUTES') ? NOTIFY_COOLDOWN_MINUTES : 5;
    
    try {
        $pdo = getDbConnection();
        
        // Prüfen ob wir kürzlich schon für diese Alarm-ID benachrichtigt haben
        $stmt = $pdo->prepare("
            SELECT MAX(time) as last_time
            FROM nibe_notifications
            WHERE deviceId = ? 
            AND alarmId = ?
            AND time >= DATE_SUB(NOW(), INTERVAL ? MINUTE)
        ");
        
        $stmt->execute([$deviceId, $alarm['alarmId'], $cooldownMinutes]);
        $result = $stmt->fetch();
        
        // Wenn last_time NULL ist, gab es keine kürzliche Notification
        return $result['last_time'] === null;
        
    } catch (Exception $e) {
        debugLog("Cooldown-Check fehlgeschlagen", ['error' => $e->getMessage()], 'ERROR');
        return true; // Im Fehlerfall senden wir die Notification
    }
}

/**
 * Versendet Benachrichtigungen per E-Mail und/oder Telegram
 */
function sendNotifications($notifications) {
    global $debugMode;
    
    $emailEnabled = defined('NOTIFY_EMAIL_ENABLED') && NOTIFY_EMAIL_ENABLED === true;
    $telegramEnabled = defined('NOTIFY_TELEGRAM_ENABLED') && NOTIFY_TELEGRAM_ENABLED === true;
    
    consoleLog("Versende " . count($notifications) . " Benachrichtigung(en)", [
        'E-Mail' => $emailEnabled ? 'JA' : 'NEIN',
        'Telegram' => $telegramEnabled ? 'JA' : 'NEIN'
    ], 'MONITOR');
    
    debugLog("Versende " . count($notifications) . " Benachrichtigung(en)", [
        'email' => $emailEnabled,
        'telegram' => $telegramEnabled
    ], 'MONITOR');
    
    $emailSuccess = 0;
    $emailFailed = 0;
    $telegramSuccess = 0;
    $telegramFailed = 0;
    
    foreach ($notifications as $item) {
        $device = $item['device'];
        $alarm = $item['alarm'];
        
        consoleLog("Sende Notification für Device {$device['deviceId']}: {$alarm['header']}", null, 'INFO');
        
        if ($emailEnabled) {
            consoleLog("  └─ E-Mail...", null, 'DEBUG');
            if (sendEmailNotification($device, $alarm)) {
                $emailSuccess++;
                consoleLog("     └─ ✓ Erfolgreich", null, 'SUCCESS');
            } else {
                $emailFailed++;
                consoleLog("     └─ ✗ Fehlgeschlagen", null, 'ERROR');
            }
        }
        
        if ($telegramEnabled) {
            consoleLog("  └─ Telegram...", null, 'DEBUG');
            if (sendTelegramNotification($device, $alarm)) {
                $telegramSuccess++;
                consoleLog("     └─ ✓ Erfolgreich", null, 'SUCCESS');
            } else {
                $telegramFailed++;
                consoleLog("     └─ ✗ Fehlgeschlagen", null, 'ERROR');
            }
        }
    }
    
    consoleLog("\nVersand-Statistik:", [
        'E-Mail' => $emailEnabled ? "$emailSuccess erfolgreich, $emailFailed fehlgeschlagen" : 'deaktiviert',
        'Telegram' => $telegramEnabled ? "$telegramSuccess erfolgreich, $telegramFailed fehlgeschlagen" : 'deaktiviert'
    ], 'INFO');
}

/**
 * Sendet E-Mail Benachrichtigung
 */
function sendEmailNotification($device, $alarm) {
    try {
        $severityText = getSeverityText($alarm['severity']);
        $severityEmoji = getSeverityEmoji($alarm['severity']);
        
        $subject = defined('NOTIFY_EMAIL_SUBJECT') ? NOTIFY_EMAIL_SUBJECT : '[Nibe] Neue Notification';
        $subject .= " - " . $severityText;
        
        $time = new DateTime($alarm['time']);
        
        // E-Mail Body (HTML)
        $body = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #2196F3; color: white; padding: 20px; border-radius: 8px 8px 0 0; }
                .content { background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px; }
                .severity { display: inline-block; padding: 8px 15px; border-radius: 4px; font-weight: bold; color: white; }
                .severity-0 { background: #2196F3; }
                .severity-1 { background: #FF9800; }
                .severity-2 { background: #f44336; }
                .severity-3 { background: #9C27B0; }
                .info-row { margin: 10px 0; padding: 10px; background: white; border-left: 4px solid #2196F3; }
                .label { font-weight: bold; color: #555; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>{$severityEmoji} Nibe Notification</h1>
                </div>
                <div class='content'>
                    <div class='info-row'>
                        <span class='label'>Device:</span> {$device['name']} (ID: {$device['deviceId']})
                    </div>
                    <div class='info-row'>
                        <span class='label'>Severity:</span> <span class='severity severity-{$alarm['severity']}'>{$severityText}</span>
                    </div>
                    <div class='info-row'>
                        <span class='label'>Zeit:</span> {$time->format('d.m.Y H:i:s')}
                    </div>
                    <div class='info-row'>
                        <span class='label'>Header:</span> {$alarm['header']}
                    </div>
                    <div class='info-row'>
                        <span class='label'>Beschreibung:</span> {$alarm['description']}
                    </div>
                    <div class='info-row'>
                        <span class='label'>Equipment:</span> {$alarm['equipName']}
                    </div>
                    <div class='info-row'>
                        <span class='label'>Alarm ID:</span> {$alarm['alarmId']}
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Empfänger prüfen
        $to = defined('NOTIFY_EMAIL_TO') ? NOTIFY_EMAIL_TO : '';
        
        if (empty($to)) {
            debugLog("E-Mail: Kein Empfänger konfiguriert", null, 'ERROR');
            return false;
        }
        
        // E-Mail Methode wählen
        $method = defined('NOTIFY_EMAIL_METHOD') ? NOTIFY_EMAIL_METHOD : 'mail';
        
        if ($method === 'smtp') {
            return sendEmailViaSMTP($to, $subject, $body, $device, $alarm);
        } else {
            return sendEmailViaPHPMail($to, $subject, $body, $device, $alarm);
        }
        
    } catch (Exception $e) {
        debugLog("E-Mail Fehler", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Sendet E-Mail via PHP mail() Funktion
 */
function sendEmailViaPHPMail($to, $subject, $body, $device, $alarm) {
    global $debugMode;
    
    try {
        $from = defined('NOTIFY_EMAIL_FROM') ? NOTIFY_EMAIL_FROM : 'nibe@localhost';
        $fromName = defined('NOTIFY_EMAIL_FROM_NAME') ? NOTIFY_EMAIL_FROM_NAME : 'Nibe Monitor';
        
        consoleLog("Sende via PHP mail()", [
            'Von' => "$fromName <$from>",
            'An' => $to
        ], 'DEBUG');
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . $fromName . ' <' . $from . '>',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $success = mail($to, $subject, $body, implode("\r\n", $headers));
        
        if ($success) {
            debugLog("E-Mail erfolgreich gesendet (PHP mail)", [
                'to' => $to,
                'subject' => $subject,
                'deviceId' => $device['deviceId'],
                'alarmId' => $alarm['alarmId']
            ], 'MONITOR');
        } else {
            consoleLog("mail() gab FALSE zurück", error_get_last(), 'ERROR');
            debugLog("E-Mail konnte nicht gesendet werden", [
                'to' => $to,
                'error' => error_get_last()
            ], 'ERROR');
        }
        
        return $success;
        
    } catch (Exception $e) {
        consoleLog("PHP mail() Exception: " . $e->getMessage(), null, 'ERROR');
        debugLog("PHP mail() Fehler", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Sendet E-Mail via SMTP
 */
function sendEmailViaSMTP($to, $subject, $body, $device, $alarm) {
    global $debugMode;
    
    try {
        // SMTP Konfiguration prüfen
        if (!defined('NOTIFY_SMTP_HOST') || !defined('NOTIFY_SMTP_PORT')) {
            consoleLog("SMTP: Host oder Port nicht konfiguriert", null, 'ERROR');
            debugLog("SMTP: Host oder Port nicht konfiguriert", null, 'ERROR');
            return false;
        }
        
        $host = NOTIFY_SMTP_HOST;
        $port = NOTIFY_SMTP_PORT;
        $security = defined('NOTIFY_SMTP_SECURITY') ? NOTIFY_SMTP_SECURITY : '';
        $auth = defined('NOTIFY_SMTP_AUTH') ? NOTIFY_SMTP_AUTH : false;
        $username = defined('NOTIFY_SMTP_USERNAME') ? NOTIFY_SMTP_USERNAME : '';
        $password = defined('NOTIFY_SMTP_PASSWORD') ? NOTIFY_SMTP_PASSWORD : '';
        
        $from = defined('NOTIFY_EMAIL_FROM') ? NOTIFY_EMAIL_FROM : 'nibe@localhost';
        $fromName = defined('NOTIFY_EMAIL_FROM_NAME') ? NOTIFY_EMAIL_FROM_NAME : 'Nibe Monitor';
        
        consoleLog("Verbinde mit SMTP Server...", [
            'Host' => $host,
            'Port' => $port,
            'Security' => $security ?: 'keine',
            'Auth' => $auth ? 'JA' : 'NEIN'
        ], 'DEBUG');
        
        // Socket öffnen
        $socket = null;
        $errno = 0;
        $errstr = '';
        
        if ($security === 'ssl') {
            consoleLog("Öffne SSL Socket...", null, 'DEBUG');
            $socket = @fsockopen('ssl://' . $host, $port, $errno, $errstr, 30);
        } else {
            consoleLog("Öffne TCP Socket...", null, 'DEBUG');
            $socket = @fsockopen($host, $port, $errno, $errstr, 30);
        }
        
        if (!$socket) {
            consoleLog("SMTP Verbindung fehlgeschlagen", [
                'errno' => $errno,
                'errstr' => $errstr
            ], 'ERROR');
            debugLog("SMTP: Verbindung fehlgeschlagen", [
                'host' => $host,
                'port' => $port,
                'errno' => $errno,
                'errstr' => $errstr
            ], 'ERROR');
            return false;
        }
        
        stream_set_timeout($socket, 30);
        
        // SMTP Response lesen
        $response = fgets($socket, 515);
        consoleLog("SMTP Server: " . trim($response), null, 'DEBUG');
        debugLog("SMTP: Verbindung hergestellt", ['response' => trim($response)], 'MONITOR');
        
        // EHLO senden
        consoleLog("Sende EHLO...", null, 'DEBUG');
        fputs($socket, "EHLO " . gethostname() . "\r\n");
        $response = fgets($socket, 515);
        consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        
        // STARTTLS wenn TLS aktiviert
        if ($security === 'tls') {
            consoleLog("Starte STARTTLS...", null, 'DEBUG');
            fputs($socket, "STARTTLS\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
            
            if (strpos($response, '220') === false) {
                consoleLog("STARTTLS fehlgeschlagen: " . trim($response), null, 'ERROR');
                debugLog("SMTP: STARTTLS fehlgeschlagen", ['response' => trim($response)], 'ERROR');
                fclose($socket);
                return false;
            }
            
            consoleLog("Aktiviere TLS Verschlüsselung...", null, 'DEBUG');
            $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            
            if (!$crypto) {
                consoleLog("TLS Aktivierung fehlgeschlagen", null, 'ERROR');
                debugLog("SMTP: TLS Aktivierung fehlgeschlagen", null, 'ERROR');
                fclose($socket);
                return false;
            }
            
            consoleLog("TLS aktiv", null, 'SUCCESS');
            
            // EHLO erneut nach STARTTLS
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        }
        
        // Authentifizierung
        if ($auth) {
            consoleLog("Starte Authentifizierung...", null, 'DEBUG');
            fputs($socket, "AUTH LOGIN\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
            
            fputs($socket, base64_encode($username) . "\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
            
            fputs($socket, base64_encode($password) . "\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
            
            if (strpos($response, '235') === false) {
                consoleLog("Authentifizierung fehlgeschlagen", [
                    'username' => $username,
                    'response' => trim($response)
                ], 'ERROR');
                debugLog("SMTP: Authentifizierung fehlgeschlagen", [
                    'username' => $username,
                    'response' => trim($response)
                ], 'ERROR');
                fclose($socket);
                return false;
            }
            
            consoleLog("Authentifizierung erfolgreich", null, 'SUCCESS');
            debugLog("SMTP: Authentifizierung erfolgreich", null, 'MONITOR');
        }
        
        // MAIL FROM
        consoleLog("Sende MAIL FROM...", null, 'DEBUG');
        fputs($socket, "MAIL FROM: <" . $from . ">\r\n");
        $response = fgets($socket, 515);
        consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        
        // RCPT TO (mehrere Empfänger unterstützen)
        $recipients = array_map('trim', explode(',', $to));
        foreach ($recipients as $recipient) {
            consoleLog("Sende RCPT TO: $recipient", null, 'DEBUG');
            fputs($socket, "RCPT TO: <" . $recipient . ">\r\n");
            $response = fgets($socket, 515);
            consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        }
        
        // DATA
        consoleLog("Sende DATA...", null, 'DEBUG');
        fputs($socket, "DATA\r\n");
        $response = fgets($socket, 515);
        consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        
        // E-Mail Headers und Body
        $emailData = "From: " . $fromName . " <" . $from . ">\r\n";
        $emailData .= "To: " . $to . "\r\n";
        $emailData .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $emailData .= "MIME-Version: 1.0\r\n";
        $emailData .= "Content-Type: text/html; charset=UTF-8\r\n";
        $emailData .= "Content-Transfer-Encoding: 8bit\r\n";
        $emailData .= "X-Mailer: PHP/" . phpversion() . "\r\n";
        $emailData .= "Date: " . date('r') . "\r\n";
        $emailData .= "\r\n";
        $emailData .= $body . "\r\n";
        $emailData .= ".\r\n";
        
        consoleLog("Sende E-Mail Daten...", null, 'DEBUG');
        fputs($socket, $emailData);
        $response = fgets($socket, 515);
        consoleLog("SMTP: " . trim($response), null, 'DEBUG');
        
        // QUIT
        consoleLog("Sende QUIT...", null, 'DEBUG');
        fputs($socket, "QUIT\r\n");
        fclose($socket);
        
        consoleLog("E-Mail erfolgreich versendet", null, 'SUCCESS');
        debugLog("E-Mail erfolgreich gesendet (SMTP)", [
            'host' => $host,
            'port' => $port,
            'security' => $security,
            'to' => $to,
            'subject' => $subject,
            'deviceId' => $device['deviceId'],
            'alarmId' => $alarm['alarmId']
        ], 'MONITOR');
        
        return true;
        
    } catch (Exception $e) {
        consoleLog("SMTP Exception: " . $e->getMessage(), null, 'ERROR');
        debugLog("SMTP Fehler", ['error' => $e->getMessage()], 'ERROR');
        if (isset($socket) && is_resource($socket)) {
            fclose($socket);
        }
        return false;
    }
}

/**
 * Sendet Telegram Benachrichtigung
 */
function sendTelegramNotification($device, $alarm) {
    global $debugMode;
    
    try {
        $botToken = defined('NOTIFY_TELEGRAM_BOT_TOKEN') ? NOTIFY_TELEGRAM_BOT_TOKEN : '';
        $chatId = defined('NOTIFY_TELEGRAM_CHAT_ID') ? NOTIFY_TELEGRAM_CHAT_ID : '';
        
        if (empty($botToken) || empty($chatId)) {
            consoleLog("Telegram: Bot-Token oder Chat-ID fehlt", null, 'ERROR');
            debugLog("Telegram: Bot-Token oder Chat-ID fehlt", null, 'ERROR');
            return false;
        }
        
        $severityText = getSeverityText($alarm['severity']);
        $severityEmoji = getSeverityEmoji($alarm['severity']);
        
        $time = new DateTime($alarm['time']);
        
        // Telegram Message (Markdown)
        $message = "*{$severityEmoji} Nibe Notification*\n\n";
        $message .= "*Device:* {$device['name']} (ID: {$device['deviceId']})\n";
        $message .= "*Severity:* {$severityText}\n";
        $message .= "*Zeit:* {$time->format('d.m.Y H:i:s')}\n";
        $message .= "*Header:* {$alarm['header']}\n";
        $message .= "*Beschreibung:* {$alarm['description']}\n";
        $message .= "*Equipment:* {$alarm['equipName']}\n";
        $message .= "*Alarm ID:* {$alarm['alarmId']}\n";
        
        // Telegram API URL
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        consoleLog("Sende an Telegram API...", [
            'Chat-ID' => $chatId,
            'URL' => $url
        ], 'DEBUG');
        
        // POST Daten
        $postData = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'Markdown'
        ];
        
        // cURL Request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        consoleLog("Telegram API Response: HTTP $httpCode", null, 'DEBUG');
        
        if ($debugMode && $response) {
            $responseData = json_decode($response, true);
            if ($responseData) {
                consoleLog("API Response:", $responseData, 'DEBUG');
            }
        }
        
        if ($httpCode === 200) {
            consoleLog("Telegram erfolgreich versendet", null, 'SUCCESS');
            debugLog("Telegram erfolgreich gesendet", [
                'chatId' => $chatId,
                'deviceId' => $device['deviceId'],
                'alarmId' => $alarm['alarmId']
            ], 'MONITOR');
            return true;
        } else {
            consoleLog("Telegram Fehler HTTP $httpCode", [
                'response' => $response,
                'curlError' => $curlError
            ], 'ERROR');
            debugLog("Telegram Fehler", [
                'httpCode' => $httpCode,
                'response' => $response
            ], 'ERROR');
            return false;
        }
        
    } catch (Exception $e) {
        consoleLog("Telegram Exception: " . $e->getMessage(), null, 'ERROR');
        debugLog("Telegram Fehler", ['error' => $e->getMessage()], 'ERROR');
        return false;
    }
}

/**
 * Severity Text zurückgeben
 */
function getSeverityText($severity) {
    $texts = [
        0 => 'Info',
        1 => 'Warnung',
        2 => 'Alarm',
        3 => 'Kritisch'
    ];
    return $texts[$severity] ?? 'Unbekannt';
}

/**
 * Severity Emoji zurückgeben
 */
function getSeverityEmoji($severity) {
    $emojis = [
        0 => 'ℹ️',
        1 => '⚠️',
        2 => '🚨',
        3 => '🔴'
    ];
    return $emojis[$severity] ?? '❓';
}
?>
