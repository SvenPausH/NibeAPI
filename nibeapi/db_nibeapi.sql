-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Erstellungszeit: 11. Jan 2026 um 11:40
-- Server-Version: 10.6.24-MariaDB-ubu2204
-- PHP-Version: 8.3.26

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Datenbank: `nibeapi`
--

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte`
--

CREATE TABLE IF NOT EXISTS `nibe_datenpunkte` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `api_id` int(10) NOT NULL,
  `modbus_id` int(10) NOT NULL,
  `title` varchar(150) NOT NULL,
  `modbus_register_type` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_id_unique` (`api_id`) USING BTREE,
  KEY `idx_api_id` (`api_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte_log`
--

CREATE TABLE IF NOT EXISTS `nibe_datenpunkte_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nibe_datenpunkte_id` int(10) NOT NULL,
  `wert` int(10) NOT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `cwna` varchar(1) NOT NULL COMMENT 'Changed by NibeApi',
  `deviceId` int(10) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `fq_datenpunkte_id` (`nibe_datenpunkte_id`),
  KEY `deviceid` (`deviceId`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `nibe_daten_view`
-- (Siehe unten für die tatsächliche Ansicht)
--
CREATE TABLE IF NOT EXISTS `nibe_daten_view` (
`api_id` int(10)
,`modbus_id` int(10)
,`title` varchar(150)
,`wert` int(10)
,`zeitstempel` timestamp
,`cwna` varchar(1)
,`deviceId` int(10)
);

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_device`
--

CREATE TABLE IF NOT EXISTS `nibe_device` (
  `deviceId` int(10) NOT NULL,
  `aidMode` varchar(10) NOT NULL,
  `smartMode` varchar(10) NOT NULL,
  `serialNumber` varchar(15) NOT NULL,
  `name` varchar(10) NOT NULL,
  `manufacturer` varchar(10) NOT NULL,
  `firmwareId` varchar(15) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`deviceId`),
  UNIQUE KEY `serialNumber` (`serialNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_menuepunkte`
--

CREATE TABLE IF NOT EXISTS `nibe_menuepunkte` (
  `api_id` int(10) NOT NULL,
  `menuepunkt` varchar(15) NOT NULL,
  PRIMARY KEY (`api_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_notifications`
--

CREATE TABLE IF NOT EXISTS `nibe_notifications` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `deviceId` int(10) NOT NULL,
  `alarmId` int(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `header` varchar(255) NOT NULL,
  `severity` int(10) NOT NULL,
  `time` datetime NOT NULL,
  `equipName` varchar(50) NOT NULL,
  `resetNotifications` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_notification` (`deviceId`,`alarmId`,`time`),
  KEY `idx_device_time` (`deviceId`,`time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_system_config`
--

CREATE TABLE IF NOT EXISTS `nibe_system_config` (
  `config_key` varchar(50) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`config_key`),
  KEY `idx_updated_at` (`updated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Struktur des Views `nibe_daten_view`
--
DROP TABLE IF EXISTS `nibe_daten_view`;

CREATE ALGORITHM=UNDEFINED SQL SECURITY DEFINER VIEW `nibe_daten_view`  AS SELECT `nibe_datenpunkte`.`api_id` AS `api_id`, `nibe_datenpunkte`.`modbus_id` AS `modbus_id`, `nibe_datenpunkte`.`title` AS `title`, `nibe_datenpunkte_log`.`wert` AS `wert`, `nibe_datenpunkte_log`.`zeitstempel` AS `zeitstempel`, `nibe_datenpunkte_log`.`cwna` AS `cwna`, `nibe_datenpunkte_log`.`deviceId` AS `deviceId` FROM (`nibe_datenpunkte` join `nibe_datenpunkte_log` on(`nibe_datenpunkte_log`.`nibe_datenpunkte_id` = `nibe_datenpunkte`.`id`)) ORDER BY `nibe_datenpunkte`.`modbus_id` ASC, `nibe_datenpunkte_log`.`zeitstempel` ASC ;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `nibe_datenpunkte_log`
--
ALTER TABLE `nibe_datenpunkte_log`
  ADD CONSTRAINT `nibe_datenpunkte_log_ibfk_1` FOREIGN KEY (`nibe_datenpunkte_id`) REFERENCES `nibe_datenpunkte` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `nibe_datenpunkte_log_ibfk_2` FOREIGN KEY (`deviceId`) REFERENCES `nibe_device` (`deviceId`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints der Tabelle `nibe_menuepunkte`
--
ALTER TABLE `nibe_menuepunkte`
  ADD CONSTRAINT `nibe_menuepunkte_ibfk_1` FOREIGN KEY (`api_id`) REFERENCES `nibe_datenpunkte` (`api_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints der Tabelle `nibe_notifications`
--
ALTER TABLE `nibe_notifications`
  ADD CONSTRAINT `fk_notification_device` FOREIGN KEY (`deviceId`) REFERENCES `nibe_device` (`deviceId`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
