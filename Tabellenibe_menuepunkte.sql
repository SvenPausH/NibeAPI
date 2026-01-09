-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Erstellungszeit: 09. Jan 2026 um 14:26
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
-- Tabellenstruktur für Tabelle `nibe_menuepunkte`
--

CREATE TABLE `nibe_menuepunkte` (
  `api_id` int(10) NOT NULL,
  `menuepunkt` varchar(15) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Daten für Tabelle `nibe_menuepunkte`
--

INSERT INTO `nibe_menuepunkte` (`api_id`, `menuepunkt`) VALUES
(3667, '1.30.1'),
(3671, '1.30.1'),
(3675, '1.30.4'),
(3679, '1.30.6'),
(3680, '1.30.7'),
(3681, '1.30.7'),
(3682, '1.30.7'),
(3683, '1.30.7'),
(3684, '1.30.7'),
(3685, '1.30.7'),
(3686, '1.30.7'),
(3687, '1.30.8'),
(3688, '1.30.8'),
(3697, '2.2'),
(3706, '2.4'),
(3707, '2.4'),
(3830, '1.2.1'),
(3831, '1.2.5'),
(3832, '1.2.5'),
(3833, '1.2.5'),
(3834, '1.2.5'),
(3841, '1.2.5'),
(3842, '1.2.5'),
(3843, '1.2.5'),
(3844, '1.2.5'),
(3845, '1.2.6'),
(4564, '2.1'),
(4787, '7.1.2.2'),
(4813, '7.1.2.2'),
(5088, '7.1.2.2'),
(7206, '1.2.2'),
(8034, '7.1.2.2'),
(10591, '7.1.2.2');

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `nibe_menuepunkte`
--
ALTER TABLE `nibe_menuepunkte`
  ADD PRIMARY KEY (`api_id`);

--
-- Constraints der exportierten Tabellen
--

--
-- Constraints der Tabelle `nibe_menuepunkte`
--
ALTER TABLE `nibe_menuepunkte`
  ADD CONSTRAINT `nibe_menuepunkte_ibfk_1` FOREIGN KEY (`api_id`) REFERENCES `nibe_datenpunkte` (`api_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
