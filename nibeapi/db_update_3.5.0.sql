-- ============================================================================
-- NibeAPI 3.5.0 - Datenbank-Erweiterung
--   1. Gespeicherte Datenpunkt-Auswahlen
--   2. Steuerung mit Regeln (Zeit / Wochentage / Bedingungen / Aktionen)
--
-- Alle Statements sind idempotent (CREATE TABLE IF NOT EXISTS) und koennen
-- mehrfach ausgefuehrt werden. Die Tabellen werden ausserdem beim ersten
-- Aufruf von index.php / steuerung.php / control-cron.php automatisch
-- angelegt, falls sie noch fehlen.
--
-- Hinweis: Keine Semikolons innerhalb von Kommentaren oder Strings verwenden,
-- die Datei wird vom PHP-Code Statement fuer Statement ausgefuehrt.
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. Gespeicherte Auswahlen
-- ----------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS `nibe_auswahl` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `deviceId` int(10) NOT NULL DEFAULT 0,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_auswahl_device_name` (`deviceId`,`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `nibe_auswahl_punkte` (
  `auswahl_id` int(10) unsigned NOT NULL,
  `api_id` int(10) NOT NULL,
  PRIMARY KEY (`auswahl_id`,`api_id`),
  CONSTRAINT `fk_auswahl_punkte` FOREIGN KEY (`auswahl_id`) REFERENCES `nibe_auswahl` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ----------------------------------------------------------------------------
-- 2. Steuerung
-- ----------------------------------------------------------------------------

-- Regel: Zeitfenster + Wochentage + (Bedingungen -> dann/sonst Aktionen)
--   wochentage      ISO-Wochentage 1=Mo ... 7=So, kommagetrennt
--   zeit_von/bis    von = bis bedeutet ganztaegig. von > bis geht ueber Mitternacht
--   ausserhalb      Verhalten ausserhalb des Zeitfensters: nichts tun oder SONST-Aktionen
--   min_haltezeit   Mindestdauer in Minuten bevor zwischen DANN und SONST gewechselt wird
--   letzter_zweig   zuletzt aktiver Zweig (fuer Hysterese und Haltezeit)
CREATE TABLE IF NOT EXISTS `nibe_regeln` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `beschreibung` varchar(255) DEFAULT NULL,
  `aktiv` tinyint(1) NOT NULL DEFAULT 1,
  `prioritaet` int(11) NOT NULL DEFAULT 100,
  `wochentage` varchar(20) NOT NULL DEFAULT '1,2,3,4,5,6,7',
  `zeit_von` time NOT NULL DEFAULT '00:00:00',
  `zeit_bis` time NOT NULL DEFAULT '00:00:00',
  `ausserhalb` enum('nichts','sonst') NOT NULL DEFAULT 'nichts',
  `min_haltezeit` int(11) NOT NULL DEFAULT 0,
  `letzter_zweig` enum('dann','sonst') DEFAULT NULL,
  `letzter_wechsel` datetime DEFAULT NULL,
  `letzte_auswertung` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_aktiv_prio` (`aktiv`,`prioritaet`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Bedingungen: WENN <Datenpunkt> <Operator> <Wert>   (UND bindet staerker als ODER)
--   vergleichswert / hysterese werden in der Anzeigeeinheit angegeben (z.B. Grad, nicht Rohwert)
CREATE TABLE IF NOT EXISTS `nibe_regel_bedingungen` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `regel_id` int(10) unsigned NOT NULL,
  `position` int(11) NOT NULL DEFAULT 0,
  `verknuepfung` enum('UND','ODER') NOT NULL DEFAULT 'UND',
  `deviceId` int(10) NOT NULL DEFAULT 0,
  `api_id` int(10) NOT NULL,
  `operator` enum('<','<=','=','>=','>','!=') NOT NULL DEFAULT '<',
  `vergleichswert` decimal(14,3) NOT NULL DEFAULT 0,
  `hysterese` decimal(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_regel` (`regel_id`),
  CONSTRAINT `fk_bedingung_regel` FOREIGN KEY (`regel_id`) REFERENCES `nibe_regeln` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Aktionen: Datenpunkt auf Wert setzen (zweig = dann / sonst)
CREATE TABLE IF NOT EXISTS `nibe_regel_aktionen` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `regel_id` int(10) unsigned NOT NULL,
  `zweig` enum('dann','sonst') NOT NULL DEFAULT 'dann',
  `position` int(11) NOT NULL DEFAULT 0,
  `deviceId` int(10) NOT NULL DEFAULT 0,
  `api_id` int(10) NOT NULL,
  `wert` decimal(14,3) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_regel` (`regel_id`),
  CONSTRAINT `fk_aktion_regel` FOREIGN KEY (`regel_id`) REFERENCES `nibe_regeln` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Protokoll: nur tatsaechliche Ereignisse (gesendet / Fehler / Zweigwechsel), nicht jede Minute
CREATE TABLE IF NOT EXISTS `nibe_regel_log` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `regel_id` int(10) unsigned DEFAULT NULL,
  `regel_name` varchar(100) NOT NULL DEFAULT '',
  `zeitstempel` datetime NOT NULL,
  `zweig` varchar(10) DEFAULT NULL,
  `deviceId` int(10) DEFAULT NULL,
  `api_id` int(10) DEFAULT NULL,
  `alter_wert` decimal(14,3) DEFAULT NULL,
  `neuer_wert` decimal(14,3) DEFAULT NULL,
  `status` enum('gesendet','fehler','info') NOT NULL DEFAULT 'info',
  `meldung` varchar(255) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  KEY `idx_zeit` (`zeitstempel`),
  KEY `idx_regel_zeit` (`regel_id`,`zeitstempel`),
  KEY `idx_ziel` (`deviceId`,`api_id`,`zeitstempel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Lock + Lebenszeichen fuer den Steuerungs-Cronjob
INSERT IGNORE INTO `nibe_system_config` (`config_key`, `value`) VALUES ('control_lock', '0');
INSERT IGNORE INTO `nibe_system_config` (`config_key`, `value`) VALUES ('control_last_run', '0');
