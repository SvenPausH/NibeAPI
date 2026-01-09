-- phpMyAdmin SQL Dump
-- Nibe API Dashboard Datenbank-Schema
-- Version: 3.4.00

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
CREATE DATABASE IF NOT EXISTS `nibeapi` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `nibeapi`;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_device`
--

DROP TABLE IF EXISTS `nibe_device`;
CREATE TABLE `nibe_device` (
  `deviceId` int(10) NOT NULL,
  `aidMode` varchar(10) NOT NULL,
  `smartMode` varchar(10) NOT NULL,
  `serialNumber` varchar(15) NOT NULL,
  `name` varchar(50) NOT NULL,
  `manufacturer` varchar(50) NOT NULL,
  `firmwareId` varchar(15) NOT NULL,
  `last_updated` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`deviceId`),
  UNIQUE KEY `serialNumber` (`serialNumber`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_notifications`
--

DROP TABLE IF EXISTS `nibe_notifications`;
CREATE TABLE `nibe_notifications` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `deviceId` int(10) NOT NULL,
  `alarmId` int(10) NOT NULL,
  `description` varchar(255) NOT NULL,
  `header` varchar(255) NOT NULL,
  `severity` int(10) NOT NULL,
  `time` datetime NOT NULL,
  `equipName` varchar(50) NOT NULL,
  `resetNotifications` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_notification` (`deviceId`, `alarmId`, `time`),
  KEY `idx_device_time` (`deviceId`, `time` DESC),
  CONSTRAINT `fk_notification_device` FOREIGN KEY (`deviceId`) REFERENCES `nibe_device` (`deviceId`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte`
--

DROP TABLE IF EXISTS `nibe_datenpunkte`;
CREATE TABLE `nibe_datenpunkte` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `deviceId` int(10) DEFAULT NULL,
  `api_id` int(10) NOT NULL,
  `modbus_id` int(10) NOT NULL,
  `title` varchar(150) NOT NULL,
  `modbus_register_type` varchar(30) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `api_id_unique` (`api_id`),
  KEY `idx_device` (`deviceId`),
  CONSTRAINT `fk_datenpunkt_device` FOREIGN KEY (`deviceId`) REFERENCES `nibe_device` (`deviceId`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte_log`
--

DROP TABLE IF EXISTS `nibe_datenpunkte_log`;
CREATE TABLE `nibe_datenpunkte_log` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `deviceId` int(10) DEFAULT NULL,
  `nibe_datenpunkte_id` int(10) NOT NULL,
  `wert` int(10) NOT NULL,
  `zeitstempel` datetime NOT NULL,
  `cwna` varchar(1) NOT NULL DEFAULT '' COMMENT 'Changed by NibeApi: X=Manual, I=Import, ''=Auto',
  PRIMARY KEY (`id`),
  KEY `idx_datenpunkt_zeit` (`nibe_datenpunkte_id`,`zeitstempel` DESC),
  KEY `idx_device` (`deviceId`),
  CONSTRAINT `nibe_datenpunkte_log_ibfk_1` FOREIGN KEY (`nibe_datenpunkte_id`) REFERENCES `nibe_datenpunkte` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_log_device` FOREIGN KEY (`deviceId`) REFERENCES `nibe_device` (`deviceId`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_menuepunkte`
--

DROP TABLE IF EXISTS `nibe_menuepunkte`;
CREATE TABLE `nibe_menuepunkte` (
  `api_id` int(10) NOT NULL,
  `menuepunkt` varchar(15) NOT NULL,
  PRIMARY KEY (`api_id`),
  CONSTRAINT `nibe_menuepunkte_ibfk_1` FOREIGN KEY (`api_id`) REFERENCES `nibe_datenpunkte` (`api_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Struktur des Views `nibe_daten_view`
--

DROP VIEW IF EXISTS `nibe_daten_view`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `nibe_daten_view` AS 
SELECT 
    `nibe_datenpunkte`.`api_id` AS `api_id`, 
    `nibe_datenpunkte`.`modbus_id` AS `modbus_id`, 
    `nibe_datenpunkte`.`title` AS `title`, 
    `nibe_datenpunkte_log`.`wert` AS `wert`, 
    `nibe_datenpunkte_log`.`zeitstempel` AS `zeitstempel`, 
    `nibe_datenpunkte_log`.`cwna` AS `cwna`,
    `nibe_datenpunkte_log`.`deviceId` AS `deviceId`
FROM 
    `nibe_datenpunkte` 
    JOIN `nibe_datenpunkte_log` ON `nibe_datenpunkte_log`.`nibe_datenpunkte_id` = `nibe_datenpunkte`.`id` 
ORDER BY 
    `nibe_datenpunkte`.`modbus_id` ASC, 
    `nibe_datenpunkte_log`.`zeitstempel` ASC;

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
