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

// alle VALUE Werte die hier stehen werden in der Ausgabe ausgeblendet. Grund dafür sind z.b. Sensoren die nicht aktiv sind und eine min bzw. Maximal Wert haben. die ein Temperatursensor der mit -3.276,8 C angezeigt wird.
define('HIDE_VALUES', [
    '-3.276',
    '-320000',
]);
// Datenpunkte bei automatischen Refreh von Datenbank Log ausschließen weil die WP die Datenpunkte ständig ändert.
define('NO_DB_UPDATE_APIID', [
    781, // Gradminunten
    1704, // Begrenzung GM
    // Beispiel: 47011, 47012, 47013
]);


// Optional: Weitere Konfigurationsoptionen
define('USE_DB', true); // true = Datenbank nutzen, false = Datenbank deaktiviert
// DB Zugriff Maria / Mysql 
define('DB_HOST', 'localhost');
define('DB_NAME', 'nibeapi');
define('DB_USER', 'root');
define('DB_PASSWORD', '');
define('DB_CHARSET', 'utf8mb4');

// Timeout für API-Anfragen in Sekunden
define('API_TIMEOUT', 30);

// Schnelle Updates (5 Sekunden)
//define('API_UPDATE_INTERVAL', 5000);

// Standard (10 Sekunden)
define('API_UPDATE_INTERVAL', 10000);

// Langsame Updates (30 Sekunden)
//define('API_UPDATE_INTERVAL', 30000);

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
