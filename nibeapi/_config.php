<?php
/**
 * API Konfigurationsdatei
 * Dateiname: config.php v3.4.00
 * 
 * WICHTIG: Diese Datei sollte aus Sicherheitsgründen NICHT in öffentlich
 * zugänglichen Verzeichnissen liegen. Alternativ mit .htaccess schützen.
 */

// ============================================================================
// API KONFIGURATION
// ============================================================================

// API Basis-URL (nur IP/Hostname, keine Pfade!)
define('API_BASE_URL', 'https://192.168.1.44:8443');
define('API_VERSION', 'v1'); // API Version

// Automatisch generierte Endpoints
define('API_DEVICES_ENDPOINT', API_BASE_URL . '/api/' . API_VERSION . '/devices');

// Backwards Compatibility: Wird dynamisch beim ersten Device gesetzt
if (!defined('API_URL')) {
    define('API_URL', API_DEVICES_ENDPOINT . '/0/points'); // Fallback für alten Code
}

// ============================================================================
// NOTIFICATIONS KONFIGURATION
// ============================================================================

// Notifications Update-Intervall (in Sekunden, 0 = deaktiviert)
define('NOTIFICATIONS_CHECK_INTERVAL', 300); // Alle 5 Minuten

// ============================================================================
// NOTIFICATION MONITOR KONFIGURATION
// ============================================================================

/ E-Mail Benachrichtigungen
define('NOTIFY_EMAIL_ENABLED', true);
define('NOTIFY_EMAIL_METHOD', 'smtp'); // 'smtp' oder 'mail' (PHP mail())

// SMTP Einstellungen
define('NOTIFY_SMTP_HOST', 'smtp.example.com'); // z.B. smtp.gmail.com, smtp.ionos.de
define('NOTIFY_SMTP_PORT', 587); // 587 (TLS), 465 (SSL), 25 (unsicher)
define('NOTIFY_SMTP_SECURITY', 'tls'); // 'tls', 'ssl' oder '' (keine)
define('NOTIFY_SMTP_AUTH', true); // true wenn Authentifizierung erforderlich
define('NOTIFY_SMTP_USERNAME', 'your-email@example.com');
define('NOTIFY_SMTP_PASSWORD', 'your-password');

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

// Notifications Update-Intervall (in Sekunden, 0 = deaktiviert)
define('NOTIFICATIONS_CHECK_INTERVAL', 300); // Alle 5 Minuten
define('NOTIFICATIONS_CHECK_BY', 'CRON'); // 'CRON' oder 'WEB'

// ============================================================================
// AUTHENTIFIZIERUNG
// ============================================================================

// API Authentifizierung - Bearer Token (falls benötigt)
define('API_KEY', '');

// API Authentifizierung - Basic Auth (falls benötigt)
define('API_USERNAME', 'username');
define('API_PASSWORD', 'password');

// ============================================================================
// FILTER & AUSSCHLÜSSE
// ============================================================================

// Alle VALUE Werte die hier stehen werden in der Ausgabe ausgeblendet
// Grund: z.B. Sensoren die nicht aktiv sind mit Min/Max-Werten wie -3.276,8°C
define('HIDE_VALUES', [
    '-3.276',
    '-320000',
]);

// Datenpunkte bei automatischem Refresh von Datenbank-Log ausschließen
// Grund: WP ändert diese Datenpunkte ständig
define('NO_DB_UPDATE_APIID', [
    781,  // Gradminuten
    1704, // Begrenzung GM
    // Weitere API IDs hier hinzufügen...
]);

// ============================================================================
// DATENBANK KONFIGURATION
// ============================================================================

// Datenbank aktivieren/deaktivieren
define('USE_DB', true); // true = Datenbank nutzen, false = deaktiviert

// Datenbank Zugangsdaten (MariaDB / MySQL)
define('DB_HOST', 'localhost');
define('DB_NAME', 'nibeapi');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// Maximal angezeigte History Datensätze pro Datenpunkt
define('API_MAX_HISTORY', 5); // Zeigt 5 Einträge

// ============================================================================
// UPDATE INTERVALL KONFIGURATION
// ============================================================================

// Standard Update-Intervall in Millisekunden (wird beim Start verwendet)
define('API_UPDATE_INTERVAL', 10000); // 10 Sekunden Default

// Verfügbare Update-Intervalle für das Dropdown-Menü
// Format: [Wert in Millisekunden => Anzeigetext]
define('API_UPDATE_INTERVALS', [
    5000  => '5 Sekunden',
    10000 => '10 Sekunden',
    15000 => '15 Sekunden',
    30000 => '30 Sekunden',
    60000 => '60 Sekunden'
]);

// ============================================================================
// API EINSTELLUNGEN
// ============================================================================

// Timeout für API-Anfragen in Sekunden
define('API_TIMEOUT', 30);

// SSL-Zertifikat-Überprüfung (false für selbstsignierte Zertifikate)
define('API_SSL_VERIFY', false);

// ============================================================================
// DEBUG & LOGGING
// ============================================================================

// Debug-Modus (true = Fehlermeldungen anzeigen, false = produktiv)
define('DEBUG_MODE', false);

// Debug-Logging aktivieren (true = aktiviert, false = deaktiviert)
define('DEBUG_LOGGING', false);

// Debug-Log Datei Pfad und Name
define('DEBUG_LOG_PATH', '/var/www/html/test/nibeapi2/logs/'); // Pfad mit abschließendem /
define('DEBUG_LOG_FILE', 'nibe.log'); // Dateiname
define('DEBUG_LOG_FULLPATH', DEBUG_LOG_PATH . DEBUG_LOG_FILE); // Vollständiger Pfad

// Maximale Debug-Log Dateigröße in Bytes (10 MB)
define('DEBUG_LOG_MAX_SIZE', 10 * 1024 * 1024);

// ============================================================================
// CACHE EINSTELLUNGEN
// ============================================================================

// Cache-Einstellungen (optional für zukünftige Erweiterungen)
define('CACHE_ENABLED', false);
define('CACHE_DURATION', 300); // in Sekunden (5 Minuten)

// ============================================================================
// SPRACHEINSTELLUNGEN
// ============================================================================

define('LANGUAGE', 'de');
define('TIMEZONE', 'Europe/Berlin');

// ============================================================================
// SYSTEM KONFIGURATION
// ============================================================================

// Fehlerbehandlung
if (DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Zeitzone setzen
date_default_timezone_set(TIMEZONE);

// Log-Verzeichnis erstellen, falls nicht vorhanden
if (DEBUG_LOGGING && !file_exists(DEBUG_LOG_PATH)) {
    @mkdir(DEBUG_LOG_PATH, 0755, true);
}
?>
