-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Sep 09, 2023 at 05:19 AM
-- Server version: 10.4.27-MariaDB
-- PHP Version: 8.0.25

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `billing`
--

-- --------------------------------------------------------

--
-- Table structure for table `ms_user`
--

CREATE TABLE `ms_user` (
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `user_pass` varchar(100) NOT NULL,
  `user_role` int(11) NOT NULL,
  `is_active` bit(1) NOT NULL DEFAULT b'1',
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ms_user`
--

INSERT INTO `ms_user` (`user_id`, `user_name`, `user_pass`, `user_role`, `is_active`, `created_at`) VALUES
(1, 'owner', '827ccb0eea8a706c4c34a16891f84e7b', 1, b'1', '2023-09-08 17:39:49');

-- --------------------------------------------------------

--
-- Table structure for table `table_active`
--

CREATE TABLE `table_active` (
  `table_id` int(11) NOT NULL,
  `table_number` varchar(100) NOT NULL,
  `table_mode` enum('Reg','Book') DEFAULT NULL,
  `table_start_time` datetime DEFAULT NULL,
  `table_end_time` datetime DEFAULT NULL,
  `table_duration` time NOT NULL,
  `table_bill` int(11) NOT NULL DEFAULT 0,
  `table_active` bit(1) NOT NULL DEFAULT b'0',
  `is_active` bit(1) NOT NULL DEFAULT b'1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `table_active`
--

INSERT INTO `table_active` (`table_id`, `table_number`, `table_mode`, `table_start_time`, `table_end_time`, `table_duration`, `table_bill`, `table_active`, `is_active`) VALUES
(1, '01', 'Reg', '2023-09-09 09:55:06', '2023-09-09 11:25:06', '01:30:00', 45000, b'1', b'1'),
(2, '02', NULL, NULL, NULL, '02:12:00', 0, b'0', b'1'),
(3, '03', NULL, NULL, NULL, '00:00:00', 0, b'0', b'1'),
(4, '04', NULL, NULL, NULL, '00:00:00', 0, b'0', b'1'),
(5, '05', NULL, NULL, NULL, '00:00:00', 0, b'0', b'1'),
(6, '06\r\n', NULL, NULL, NULL, '00:00:00', 0, b'0', b'1'),
(7, '07', NULL, NULL, NULL, '00:00:00', 0, b'0', b'1');

-- --------------------------------------------------------

--
-- Table structure for table `transaction`
--

CREATE TABLE `transaction` (
  `transaction_id` int(11) NOT NULL,
  `transaction_inv` varchar(20) NOT NULL,
  `transaction_date` date NOT NULL,
  `transaction_start_time` time NOT NULL,
  `transaction_end_time` time NOT NULL,
  `transaction_duration` int(11) NOT NULL,
  `transaction_sub_total` int(11) NOT NULL,
  `transaction_discount` int(11) NOT NULL,
  `transaction_total_bill` int(11) NOT NULL,
  `transaction_table` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_by` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ms_user`
--
ALTER TABLE `ms_user`
  ADD PRIMARY KEY (`user_id`);

--
-- Indexes for table `table_active`
--
ALTER TABLE `table_active`
  ADD PRIMARY KEY (`table_id`);

--
-- Indexes for table `transaction`
--
ALTER TABLE `transaction`
  ADD PRIMARY KEY (`transaction_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ms_user`
--
ALTER TABLE `ms_user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `table_active`
--
ALTER TABLE `table_active`
  MODIFY `table_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `transaction`
--
ALTER TABLE `transaction`
  MODIFY `transaction_id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
