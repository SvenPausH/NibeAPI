<?php
/**
 * Nibe Notification Monitor - Cronjob Script
 * notification-monitor.php
 * 
 * Prüft alle konfigurierten Devices auf neue Notifications und 
 * versendet Benachrichtigungen per E-Mail und/oder Telegram
 * 
 * Verwendung:
 * 1. Konfiguration in config.php anpassen (siehe unten)
 * 2. Cronjob einrichten: * * * * * /usr/bin/php /pfad/zu/notification-monitor.php
 * 3. Oder manuell testen: php notification-monitor.php
 * 
 * Test-Modi (per Kommandozeile):
 * - php notification-monitor.php --debug              Verbose Output + normale Ausführung
 * - php notification-monitor.php --test-email         Sendet Test-E-Mail
 * - php notification-monitor.php --test-telegram      Sendet Test-Telegram
 * - php notification-monitor.php --test-all           Testet E-Mail UND Telegram
 * - php notification-monitor.php --dry-run            Alles prüfen, nichts senden
 */

// Kommandozeilen-Argumente parsen
$options = getopt('', ['debug', 'test-email', 'test-telegram', 'test-all', 'dry-run']);
$debugMode = isset($options['debug']) || isset($options['test-email']) || isset($options['test-telegram']) || isset($options['test-all']);
$testEmailMode = isset($options['test-email']) || isset($options['test-all']);
$testTelegramMode = isset($options['test-telegram']) || isset($options['test-all']);
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
        'MONITOR' => "\033[0;34m"  // Blue
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

consoleLog("=== Nibe Notification Monitor gestartet ===", null, 'INFO');

// ============================================================================
// KONFIGURATION - In config.php einfügen:
// ============================================================================
/*
// ============================================================================
// NOTIFICATION MONITOR KONFIGURATION
// ============================================================================

// E-Mail Benachrichtigungen
define('NOTIFY_EMAIL_ENABLED', true);
define('NOTIFY_EMAIL_METHOD', 'smtp'); // 'smtp' oder 'mail' (PHP mail())

// SMTP Einstellungen (nur wenn NOTIFY_EMAIL_METHOD = 'smtp')
define('NOTIFY_SMTP_HOST', 'smtp.example.com'); // z.B. smtp.gmail.com, smtp.ionos.de
define('NOTIFY_SMTP_PORT', 587); // 587 (TLS), 465 (SSL), 25 (unsicher)
define('NOTIFY_SMTP_SECURITY', 'tls'); // 'tls', 'ssl' oder '' (keine)
define('NOTIFY_SMTP_AUTH', true); // true wenn Authentifizierung erforderlich
define('NOTIFY_SMTP_USERNAME', 'your-email@example.com');
define('NOTIFY_SMTP_PASSWORD', 'your-password');

// E-Mail Absender/Empfänger
define('NOTIFY_EMAIL_FROM', 'nibe@example.com');
define('NOTIFY_EMAIL_FROM_NAME', 'Nibe Notification Monitor');
define('NOTIFY_EMAIL_TO', 'admin@example.com'); // Mehrere: 'mail1@ex.com,mail2@ex.com'
define('NOTIFY_EMAIL_SUBJECT', '[Nibe] Neue Notification');

// Telegram Benachrichtigungen
define('NOTIFY_TELEGRAM_ENABLED', false);
define('NOTIFY_TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN'); // Von @BotFather
define('NOTIFY_TELEGRAM_CHAT_ID', 'YOUR_CHAT_ID'); // Deine Chat-ID

// Monitor Einstellungen
define('NOTIFY_MIN_SEVERITY', 1); // 0=Info, 1=Warnung, 2=Alarm, 3=Kritisch
define('NOTIFY_COOLDOWN_MINUTES', 5); // Gleiche Notification nicht öfter als alle X Minuten
*/

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
    exit(0);
}

if ($testTelegramMode) {
    consoleLog("=== TELEGRAM TEST-MODUS ===", null, 'INFO');
    testTelegram();
    exit(0);
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

consoleLog("Konfiguration:", [
    'E-Mail' => $emailEnabled ? 'AKTIV' : 'INAKTIV',
    'Telegram' => $telegramEnabled ? 'AKTIV' : 'INAKTIV',
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
    consoleLog("Starte Notification-Prüfung...", null, 'MONITOR');
    
    // Alle Devices abrufen
    $devices = getAllDevices();
    
    if (empty($devices)) {
        consoleLog("Keine Devices gefunden - beende Monitor", null, 'WARNING');
        debugLog("Keine Devices gefunden - beende Monitor", null, 'WARNING');
        exit(0);
    }
    
    consoleLog("Gefundene Devices: " . count($devices), null, 'INFO');
    debugLog("Prüfe " . count($devices) . " Device(s)", null, 'MONITOR');
    
    $totalNewNotifications = 0;
    $notificationsToSend = [];
    
    // Jedes Device prüfen
    foreach ($devices as $device) {
        $deviceId = $device['deviceId'];
        
        consoleLog("Prüfe Device {$deviceId}: {$device['name']}", null, 'INFO');
        debugLog("Prüfe Device $deviceId", ['name' => $device['name']], 'MONITOR');
        
        try {
            // Notifications von API abrufen
            $alarms = fetchNotifications($deviceId);
            
            consoleLog("  └─ {count} Notification(s) von API empfangen", ['count' => count($alarms)], 'DEBUG');
            debugLog("Device $deviceId: " . count($alarms) . " Notification(s) von API", null, 'MONITOR');
            
            // Jede Notification prüfen und ggf. speichern
            foreach ($alarms as $alarm) {
                $severity = $alarm['severity'] ?? 0;
                $severityText = getSeverityText($severity);
                
                consoleLog("  └─ Alarm {$alarm['alarmId']}: {$severityText} - {$alarm['header']}", null, 'DEBUG');
                
                // Mindest-Severity prüfen
                $minSeverity = defined('NOTIFY_MIN_SEVERITY') ? NOTIFY_MIN_SEVERITY : 1;
                if ($severity < $minSeverity) {
                    consoleLog("     └─ Übersprungen (Severity zu niedrig: $severity < $minSeverity)", null, 'DEBUG');
                    continue;
                }
                
                // In DB speichern (gibt false zurück wenn bereits vorhanden)
                $isNew = saveNotification($deviceId, $alarm);
                
                if ($isNew) {
                    $totalNewNotifications++;
                    consoleLog("     └─ NEU in Datenbank gespeichert", null, 'SUCCESS');
                    
                    // Cooldown prüfen (verhindert Spam bei gleichen Notifications)
                    if (shouldNotify($deviceId, $alarm)) {
                        $notificationsToSend[] = [
                            'device' => $device,
                            'alarm' => $alarm
                        ];
                        consoleLog("     └─ Wird gesendet (Cooldown OK)", null, 'SUCCESS');
                    } else {
                        consoleLog("     └─ NICHT gesendet (Cooldown aktiv)", null, 'WARNING');
                    }
                } else {
                    consoleLog("     └─ Bereits in Datenbank vorhanden", null, 'DEBUG');
                }
            }
            
        } catch (Exception $e) {
            consoleLog("Fehler beim Prüfen von Device $deviceId: " . $e->getMessage(), null, 'ERROR');
            debugLog("Fehler beim Prüfen von Device $deviceId", ['error' => $e->getMessage()], 'ERROR');
        }
    }
    
    consoleLog("\n=== ZUSAMMENFASSUNG ===", null, 'INFO');
    consoleLog("Geprüfte Devices: " . count($devices), null, 'INFO');
    consoleLog("Neue Notifications: " . $totalNewNotifications, null, 'INFO');
    consoleLog("Zu versendende Benachrichtigungen: " . count($notificationsToSend), null, 'INFO');
    
    debugLog("Monitor-Durchlauf abgeschlossen", [
        'devices' => count($devices),
        'newNotifications' => $totalNewNotifications,
        'toSend' => count($notificationsToSend)
    ], 'MONITOR');
    
    // Benachrichtigungen versenden
    if (!empty($notificationsToSend)) {
        if ($dryRunMode) {
            consoleLog("\nDRY-RUN: " . count($notificationsToSend) . " Benachrichtigung(en) würden gesendet", null, 'WARNING');
            foreach ($notificationsToSend as $item) {
                consoleLog("  - Device {$item['device']['deviceId']}: {$item['alarm']['header']}", null, 'INFO');
            }
        } else {
            consoleLog("\nVersende Benachrichtigungen...", null, 'INFO');
            sendNotifications($notificationsToSend);
        }
    } else {
        consoleLog("\nKeine neuen Notifications zum Versenden", null, 'INFO');
        debugLog("Keine neuen Notifications zum Versenden", null, 'MONITOR');
    }
    
    consoleLog("\n=== Monitor erfolgreich beendet ===", null, 'SUCCESS');
    
} catch (Exception $e) {
    consoleLog("KRITISCHER FEHLER: " . $e->getMessage(), null, 'ERROR');
    debugLog("Monitor Fehler", ['error' => $e->getMessage()], 'ERROR');
    exit(1);
}

exit(0);

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
