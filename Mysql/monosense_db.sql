-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Värd: localhost:3306
-- Tid vid skapande: 05 nov 2025 kl 14:22
-- Serverversion: 10.6.23-MariaDB
-- PHP-version: 8.1.33

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Databas: `s66550_mono`
--

-- --------------------------------------------------------

--
-- Tabellstruktur `alarm_log`
--

CREATE TABLE `alarm_log` (
  `sensor_id` int(11) NOT NULL,
  `last_alert` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `sensors`
--

CREATE TABLE `sensors` (
  `sensor_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `sensor_name` varchar(100) NOT NULL,
  `sensor_givenname` varchar(100) DEFAULT NULL,
  `sensor_type` varchar(50) DEFAULT NULL,
  `sensor_mac` varchar(100) DEFAULT NULL,
  `sensor_update` int(5) NOT NULL,
  `sensor_location` varchar(10) NOT NULL,
  `cfg_interval` decimal(10,0) NOT NULL DEFAULT 60,
  `sensor_pin` int(4) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `firmware_version` varchar(20) DEFAULT NULL,
  `firmware_updated` datetime DEFAULT NULL,
  `cfg_niu` varchar(10) DEFAULT NULL,
  `lora_deveui` varchar(20) DEFAULT NULL,
  `lora_deviceid` varchar(100) DEFAULT NULL,
  `temp_calibration` decimal(4,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `sensor_data`
--

CREATE TABLE `sensor_data` (
  `data_id` int(11) NOT NULL,
  `sensor_id` int(11) NOT NULL,
  `value1` decimal(10,0) DEFAULT NULL COMMENT 'Temp',
  `value2` decimal(10,0) DEFAULT NULL COMMENT 'Humi',
  `value3` decimal(5,0) DEFAULT NULL COMMENT 'Level',
  `value4` varchar(25) DEFAULT NULL COMMENT 'Power',
  `value5` decimal(5,0) DEFAULT NULL COMMENT 'Wifi/signal',
  `value6` decimal(10,0) DEFAULT NULL,
  `value7` decimal(10,0) DEFAULT NULL COMMENT 'hPa',
  `value8` decimal(10,0) DEFAULT NULL COMMENT 'mV',
  `value9` decimal(5,0) DEFAULT NULL COMMENT 'lx',
  `value10` decimal(20,0) DEFAULT NULL COMMENT 'SoilMoist',
  `reading_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Tabellstruktur `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `start_page` varchar(255) NOT NULL DEFAULT '/index.php',
  `city` varchar(50) DEFAULT NULL,
  `state` varchar(50) DEFAULT NULL,
  `niu` varchar(50) DEFAULT NULL,
  `niu2` varchar(50) DEFAULT NULL,
  `lon` varchar(10) NOT NULL,
  `lat` varchar(10) NOT NULL,
  `niu3` varchar(255) DEFAULT NULL,
  `login_pin` int(5) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `login_token` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Index för dumpade tabeller
--

--
-- Index för tabell `alarm_log`
--
ALTER TABLE `alarm_log`
  ADD PRIMARY KEY (`sensor_id`);

--
-- Index för tabell `sensors`
--
ALTER TABLE `sensors`
  ADD PRIMARY KEY (`sensor_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Index för tabell `sensor_data`
--
ALTER TABLE `sensor_data`
  ADD PRIMARY KEY (`data_id`),
  ADD KEY `sensor_id` (`sensor_id`);

--
-- Index för tabell `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT för dumpade tabeller
--

--
-- AUTO_INCREMENT för tabell `sensors`
--
ALTER TABLE `sensors`
  MODIFY `sensor_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `sensor_data`
--
ALTER TABLE `sensor_data`
  MODIFY `data_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT för tabell `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Restriktioner för dumpade tabeller
--

--
-- Restriktioner för tabell `sensors`
--
ALTER TABLE `sensors`
  ADD CONSTRAINT `sensors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Restriktioner för tabell `sensor_data`
--
ALTER TABLE `sensor_data`
  ADD CONSTRAINT `sensor_data_ibfk_1` FOREIGN KEY (`sensor_id`) REFERENCES `sensors` (`sensor_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
