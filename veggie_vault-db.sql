-- phpMyAdmin SQL Dump
-- version 5.2.3
-- https://www.phpmyadmin.net/
--
-- Host: prchlik.iad1-mysql-e2-13b.dreamhost.com
-- Generation Time: Oct 15, 2026 at 06:11 AM
-- Server version: 8.4.10-0ubuntu0.26.04.1
-- PHP Version: 8.5.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `veggie_vault`
--

-- --------------------------------------------------------

--
-- Table structure for table `comment_votes`
--

CREATE TABLE `comment_votes` (
  `id` int NOT NULL,
  `comment_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci NOT NULL,
  `vote_type` enum('like','dislike') COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ingredients`
--

CREATE TABLE `ingredients` (
  `id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `quantity` decimal(8,2) NOT NULL,
  `unit` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL,
  `ingredient_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `notes` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `instructions`
--

CREATE TABLE `instructions` (
  `id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `step_number` int NOT NULL,
  `instruction_text` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invite_codes`
--

CREATE TABLE `invite_codes` (
  `id` int NOT NULL,
  `code` varchar(50) COLLATE utf8mb4_general_ci NOT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invite_codes`
--

INSERT INTO `invite_codes` (`id`, `code`, `is_active`, `created_at`) VALUES
(1, 'Boobers1983', 1, '2026-09-20 12:25:48');

-- --------------------------------------------------------

--
-- Table structure for table `macros`
--

CREATE TABLE `macros` (
  `recipe_id` int NOT NULL,
  `calories` decimal(8,2) NOT NULL,
  `protein_g` decimal(8,2) NOT NULL,
  `carbs_g` decimal(8,2) NOT NULL,
  `fat_g` decimal(8,2) NOT NULL,
  `fiber_g` decimal(8,2) NOT NULL DEFAULT '0.00',
  `math_calculations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `micros`
--

CREATE TABLE `micros` (
  `id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `nutrient_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `amount` decimal(8,2) NOT NULL,
  `unit` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `daily_value_percentage` decimal(5,2) NOT NULL,
  `math_calculations` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipes`
--

CREATE TABLE `recipes` (
  `id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `title` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `image_url` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `yields` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `prep_time_mins` int DEFAULT NULL,
  `cook_time_mins` int DEFAULT NULL,
  `is_wfpb` tinyint(1) DEFAULT '1',
  `is_oil_free` tinyint(1) DEFAULT '1',
  `oil_rationale` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `is_public` tinyint(1) DEFAULT '0',
  `image_source` enum('ai','user','none') CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci DEFAULT 'none',
  `notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci,
  `average_rating` decimal(3,2) DEFAULT '0.00',
  `rating_count` int DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipe_comments`
--

CREATE TABLE `recipe_comments` (
  `id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `author_name` varchar(100) COLLATE utf8mb4_general_ci NOT NULL DEFAULT 'Vault Guest',
  `body` text COLLATE utf8mb4_general_ci NOT NULL,
  `image_url` varchar(255) COLLATE utf8mb4_general_ci DEFAULT NULL,
  `likes` int DEFAULT '0',
  `dislikes` int DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipe_favorites`
--

CREATE TABLE `recipe_favorites` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `recipe_ratings`
--

CREATE TABLE `recipe_ratings` (
  `id` int NOT NULL,
  `recipe_id` int NOT NULL,
  `user_id` int DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_general_ci NOT NULL,
  `rating` tinyint NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT '0',
  `verification_token` varchar(255) DEFAULT NULL,
  `is_verified` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `account_tier` enum('basic','premium') DEFAULT 'basic',
  `generation_tokens` decimal(10,2) DEFAULT '0.00',
  `monthly_full_gens` int DEFAULT '0',
  `last_gen_reset_date` date DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_token_expires` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `is_admin`, `verification_token`, `is_verified`, `created_at`, `account_tier`, `generation_tokens`, `monthly_full_gens`, `last_gen_reset_date`, `reset_token`, `reset_token_expires`) VALUES
(1, 'ChrisAndEmma', 'chris@chrisandemmashow.com', '$2y$10$placeholderhashfortesting123', 1, NULL, 1, '2025-09-08 05:52:13', 'basic', 15.00, 0, NULL, NULL, NULL),
(2, 'Chris83', 'chris@chrisandinga.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 1, NULL, 1, '2025-09-08 06:49:12', 'premium', 999896.60, 0, NULL, NULL, NULL),
(3, 'chrisp', 'chris@balls.com', '$2y$10$EQh4XjzlUQCHCRPvt01O6.ksVeTYUbNFFi3qtQ4o7zSLXBfTr18gK', 0, NULL, 0, '2025-12-29 06:03:50', 'basic', 15.00, 0, NULL, NULL, NULL),
(4, 'sandeejames2005@yahoo.com', 'sandeejames2005@yahoo.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 0, NULL, 0, '2025-12-29 06:05:14', 'premium', 17.00, 0, NULL, NULL, NULL),
(6, 'ingamariesmith', 'ingamariesmith@gmail.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 0, NULL, 0, '2025-12-29 06:03:50', 'premium', 18.00, 0, NULL, NULL, NULL),
(7, 'savannah', 'banana@chrisandemmashow.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 0, NULL, 0, '2025-12-29 06:03:50', 'premium', 20.00, 0, NULL, NULL, NULL),
(8, 'dylan', 'dylan@chrisandemmashow.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 0, NULL, 0, '2025-12-29 06:03:50', 'premium', 20.00, 0, NULL, NULL, NULL),
(9, 'mailyah', 'mailyah@chrisandemmashow.com', '$2y$10$7PQHUDbFT3iP7isMSE4m0.0PCF8B6KiMGkmDMm3Tx0dM9nBYprHLe', 0, NULL, 0, '2025-12-29 06:03:50', 'premium', 20.00, 0, NULL, NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `comment_votes`
--
ALTER TABLE `comment_votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_vote` (`comment_id`,`ip_address`);

--
-- Indexes for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `instructions`
--
ALTER TABLE `instructions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `invite_codes`
--
ALTER TABLE `invite_codes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `macros`
--
ALTER TABLE `macros`
  ADD PRIMARY KEY (`recipe_id`);

--
-- Indexes for table `micros`
--
ALTER TABLE `micros`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `recipes`
--
ALTER TABLE `recipes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- Indexes for table `recipe_comments`
--
ALTER TABLE `recipe_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `recipe_id` (`recipe_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `recipe_favorites`
--
ALTER TABLE `recipe_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_favorite` (`user_id`,`recipe_id`),
  ADD KEY `recipe_id` (`recipe_id`);

--
-- Indexes for table `recipe_ratings`
--
ALTER TABLE `recipe_ratings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_recipe_user` (`recipe_id`,`ip_address`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `comment_votes`
--
ALTER TABLE `comment_votes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ingredients`
--
ALTER TABLE `ingredients`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `instructions`
--
ALTER TABLE `instructions`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invite_codes`
--
ALTER TABLE `invite_codes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `micros`
--
ALTER TABLE `micros`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recipes`
--
ALTER TABLE `recipes`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recipe_comments`
--
ALTER TABLE `recipe_comments`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recipe_favorites`
--
ALTER TABLE `recipe_favorites`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `recipe_ratings`
--
ALTER TABLE `recipe_ratings`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `comment_votes`
--
ALTER TABLE `comment_votes`
  ADD CONSTRAINT `comment_votes_ibfk_1` FOREIGN KEY (`comment_id`) REFERENCES `recipe_comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ingredients`
--
ALTER TABLE `ingredients`
  ADD CONSTRAINT `ingredients_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `instructions`
--
ALTER TABLE `instructions`
  ADD CONSTRAINT `instructions_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `macros`
--
ALTER TABLE `macros`
  ADD CONSTRAINT `macros_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `micros`
--
ALTER TABLE `micros`
  ADD CONSTRAINT `micros_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recipe_comments`
--
ALTER TABLE `recipe_comments`
  ADD CONSTRAINT `recipe_comments_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `recipe_comments_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `recipe_comments` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recipe_favorites`
--
ALTER TABLE `recipe_favorites`
  ADD CONSTRAINT `recipe_favorites_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `recipe_ratings`
--
ALTER TABLE `recipe_ratings`
  ADD CONSTRAINT `recipe_ratings_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `recipes` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
