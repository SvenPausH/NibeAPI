-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: mariadb
-- Erstellungszeit: 09. Jan 2026 um 15:59
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
(4, '3.1.2'),
(8, '3.1.2'),
(10, '3.1.2'),
(11, '3.1.2'),
(12, '3.1.2'),
(54, '3.1.3'),
(58, '3.1.2'),
(91, '3.1.3'),
(764, '3.1.11.20'),
(766, '3.1.11.20'),
(781, '3.1.2'),
(786, '3.1.11.20'),
(788, '3.1.11.20'),
(790, '3.1.11.20'),
(799, '3.1.11.20'),
(802, '3.1.11.20'),
(1708, '3.1.2'),
(1755, '3.1.12'),
(1865, '3.1.12'),
(2491, '3.1.3'),
(2494, '3.1.3'),
(2505, '3.1.12'),
(2506, '3.1.12'),
(2507, '3.1.12'),
(2792, '3.1.3'),
(3096, '3.1.3'),
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
(3699, '7.1.1.1'),
(3700, '7.1.1.1'),
(3701, '7.1.1.1'),
(3702, '7.1.1.1'),
(3703, '7.1.1.1'),
(3704, '7.1.1.1'),
(3705, '7.1.1.1'),
(3706, '2.4'),
(3707, '2.4'),
(3708, '2.4'),
(3823, '7.1.5.1'),
(3830, '1.2.1'),
(3831, '7.1.4.1'),
(3832, '7.1.4.1'),
(3833, '7.1.4.1'),
(3834, '7.1.4.1'),
(3835, '7.1.4.1'),
(3841, '1.2.5'),
(3842, '1.2.5'),
(3843, '1.2.5'),
(3844, '1.2.5'),
(3845, '1.2.6'),
(3846, '3.1.3'),
(4564, '2.1'),
(4787, '7.1.2.2'),
(4813, '7.1.2.2'),
(5088, '7.1.2.2'),
(6139, '3.1.12'),
(7206, '1.2.2'),
(8034, '7.1.2.2'),
(10591, '7.1.2.2'),
(10691, '4.4'),
(10894, '3.1.2'),
(27378, '3.1.12'),
(27379, '3.1.12'),
(27382, '3.1.12'),
(27383, '3.1.12'),
(30001, '7.1.2.2');

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
