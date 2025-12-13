<?php
/**
 * API Konfigurationsdatei
 * Dateiname: config.php v2
 * 
 * WICHTIG: Diese Datei sollte aus Sicherheitsgründen NICHT in öffentlich
 * zugänglichen Verzeichnissen liegen. Alternativ mit .htaccess schützen.
 */

// API Basis-URL
define('API_URL', 'https://192.168.1.44:8443/api/v1/devices/0/points');

// API Authentifizierung - Bearer Token (falls benötigt)
define('API_KEY', '');

// API Authentifizierung - Basic Auth (falls benötigt)
define('API_USERNAME', '');
define('API_PASSWORD', '');

// Optional: Weitere Konfigurationsoptionen

// Timeout für API-Anfragen in Sekunden
define('API_TIMEOUT', 30);

// SSL-Zertifikat-Überprüfung (false für selbstsignierte Zertifikate)
define('API_SSL_VERIFY', false);

// Debug-Modus (true = Fehlermeldungen anzeigen, false = produktiv)
define('DEBUG_MODE', false);

// Debug-Logging aktivieren (true = aktiviert, false = deaktiviert)
define('DEBUG_LOGGING', false);

// Debug-Log Datei Pfad und Name
define('DEBUG_LOG_PATH', '/var/www/html/test/logs/'); // Pfad mit abschließendem /
define('DEBUG_LOG_FILE', 'nibe.log'); // Dateiname
define('DEBUG_LOG_FULLPATH', DEBUG_LOG_PATH . DEBUG_LOG_FILE); // Vollständiger Pfad

// Maximale Debug-Log Dateigröße in Bytes (10 MB)
define('DEBUG_LOG_MAX_SIZE', 10 * 1024 * 1024);

// Cache-Einstellungen (optional für zukünftige Erweiterungen)
define('CACHE_ENABLED', false);
define('CACHE_DURATION', 300); // in Sekunden (5 Minuten)

// Weitere API Endpoints (falls benötigt)
define('API_BASE_URL', 'https://192.168.1.44:8443/api/v1');
define('API_DEVICES_ENDPOINT', API_BASE_URL . '/devices');
define('API_POINTS_ENDPOINT', API_BASE_URL . '/devices/0/points');

// Spracheinstellungen
define('LANGUAGE', 'de');
define('TIMEZONE', 'Europe/Berlin');

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
