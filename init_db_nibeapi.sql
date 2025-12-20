-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Erstellungszeit: 20. Dez 2025 um 16:39
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
CREATE DATABASE IF NOT EXISTS `nibeapi` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `nibeapi`;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte`
--

DROP TABLE IF EXISTS `nibe_datenpunkte`;
CREATE TABLE `nibe_datenpunkte` (
  `id` int(10) NOT NULL,
  `api_id` int(10) NOT NULL,
  `modbus_id` int(10) NOT NULL,
  `title` varchar(150) NOT NULL,
  `modbus_register_type` varchar(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellenstruktur für Tabelle `nibe_datenpunkte_log`
--

DROP TABLE IF EXISTS `nibe_datenpunkte_log`;
CREATE TABLE `nibe_datenpunkte_log` (
  `id` int(11) NOT NULL,
  `nibe_datenpunkte_id` int(10) NOT NULL,
  `wert` int(10) NOT NULL,
  `zeitstempel` timestamp NOT NULL DEFAULT current_timestamp(),
  `cwna` varchar(1) NOT NULL COMMENT 'Changed by NibeApi'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stellvertreter-Struktur des Views `nibe_daten_view`
-- (Siehe unten für die tatsächliche Ansicht)
--
DROP VIEW IF EXISTS `nibe_daten_view`;
CREATE TABLE `nibe_daten_view` (
`api_id` int(10)
,`modbus_id` int(10)
,`title` varchar(150)
,`wert` int(10)
,`zeitstempel` timestamp
,`cwna` varchar(1)
);

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `nibe_datenpunkte`
--
ALTER TABLE `nibe_datenpunkte`
  ADD PRIMARY KEY (`id`);

--
-- Indizes für die Tabelle `nibe_datenpunkte_log`
--
ALTER TABLE `nibe_datenpunkte_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fq_datenpunkte_id` (`nibe_datenpunkte_id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `nibe_datenpunkte`
--
ALTER TABLE `nibe_datenpunkte`
  MODIFY `id` int(10) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT für Tabelle `nibe_datenpunkte_log`
--
ALTER TABLE `nibe_datenpunkte_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Struktur des Views `nibe_daten_view`
--
DROP TABLE IF EXISTS `nibe_daten_view`;

DROP VIEW IF EXISTS `nibe_daten_view`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`%` SQL SECURITY DEFINER VIEW `nibe_daten_view`  AS SELECT `nibe_datenpunkte`.`api_id` AS `api_id`, `nibe_datenpunkte`.`modbus_id` AS `modbus_id`, `nibe_datenpunkte`.`title` AS `title`, `nibe_datenpunkte_log`.`wert` AS `wert`, `nibe_datenpunkte_log`.`zeitstempel` AS `zeitstempel`, `nibe_datenpunkte_log`.`cwna` AS `cwna` FROM (`nibe_datenpunkte` join `nibe_datenpunkte_log`) WHERE `nibe_datenpunkte_log`.`nibe_datenpunkte_id` = `nibe_datenpunkte`.`id` ORDER BY `nibe_datenpunkte`.`modbus_id` ASC, `nibe_datenpunkte_log`.`zeitstempel` ASC ;

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `nibe_datenpunkte_log`
--
ALTER TABLE `nibe_datenpunkte_log`
  ADD CONSTRAINT `nibe_datenpunkte_log_ibfk_1` FOREIGN KEY (`nibe_datenpunkte_id`) REFERENCES `nibe_datenpunkte` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
