-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Hôte : localhost
-- Généré le : dim. 08 fév. 2026 à 11:14
-- Version du serveur : 10.3.32-MariaDB
-- Version de PHP : 8.0.23

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Base de données : `carbure`
--

-- --------------------------------------------------------

--
-- Structure de la table `bank_account`
--

CREATE TABLE `bank_account` (
  `id` int(11) NOT NULL,
  `bank_name` varchar(50) NOT NULL,
  `account_number` varchar(100) NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `last_sync_at` datetime DEFAULT NULL,
  `last_sync_status` enum('OK','ERROR') DEFAULT NULL,
  `last_sync_message` varchar(500) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `bank_transaction`
--

CREATE TABLE `bank_transaction` (
  `id` bigint(20) UNSIGNED NOT NULL COMMENT 'Unique ID for a transaction',
  `uuid` varchar(32) NOT NULL COMMENT 'Unique identifier for a transaction',
  `imported` timestamp NOT NULL DEFAULT current_timestamp() COMMENT 'Importation date from the bank',
  `date` date NOT NULL COMMENT 'Transaction date',
  `rdate` date NOT NULL COMMENT 'Transaction real date',
  `type` tinyint(3) UNSIGNED NOT NULL COMMENT 'Type of transaction : Cheque, virement ...',
  `label` varchar(300) NOT NULL COMMENT 'Label for the transaction',
  `category` tinyint(3) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Category of the transaction',
  `amount` decimal(10,2) NOT NULL COMMENT 'Amount in currency for this transaction',
  `card` varchar(19) DEFAULT NULL COMMENT 'Card number used for this transaction',
  `pointed` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Pointed status for thjs transaction',
  `user` int(10) UNSIGNED NOT NULL COMMENT 'The user of this transaction'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `bank_transaction_category`
--

CREATE TABLE `bank_transaction_category` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `parent_category` tinyint(3) UNSIGNED DEFAULT 0,
  `type` enum('DEBIT','CREDIT','HORS-BUDGET') NOT NULL,
  `icon` varchar(50) NOT NULL,
  `color` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `bank_transaction_category_keyword`
--

CREATE TABLE `bank_transaction_category_keyword` (
  `id` int(10) UNSIGNED NOT NULL,
  `keyword` varchar(60) NOT NULL,
  `category` tinyint(3) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `budget`
--

CREATE TABLE `budget` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `category` tinyint(3) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `date` date NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `budget_insight`
--

CREATE TABLE `budget_insight` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(20) NOT NULL,
  `color` varchar(20) NOT NULL,
  `icon` varchar(50) DEFAULT NULL COMMENT 'Material icon (default: chosen from the name)',
  `sql` varchar(500) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `settings`
--

CREATE TABLE `settings` (
  `name` varchar(50) NOT NULL,
  `value` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `api_tokens`
--

CREATE TABLE `api_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `token_hash` char(64) NOT NULL COMMENT 'SHA-256 of the token, the token itself is never stored',
  `token_hint` varchar(16) NOT NULL COMMENT 'Start of the token, to recognize it',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `devices`
--

CREATE TABLE `devices` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL,
  `token` varchar(200) NOT NULL,
  `lastLogin` timestamp NOT NULL DEFAULT current_timestamp(),
  `user_id` int(10) UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Structure de la table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `username` varchar(20) NOT NULL,
  `password` varchar(100) NOT NULL,
  `firstname` varchar(50) DEFAULT NULL,
  `lastname` varchar(50) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `language` varchar(5) NOT NULL DEFAULT 'fr',
  `alert_threshold` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Index pour les tables déchargées
--

--
-- Index pour la table `bank_account`
--
ALTER TABLE `bank_account`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_account_user` (`account_number`,`bank_name`,`user_id`),
  ADD KEY `FK_USER_ID` (`user_id`) USING BTREE;

--
-- Index pour la table `bank_transaction`
--
ALTER TABLE `bank_transaction`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_uuid` (`uuid`),
  ADD KEY `transaction_category` (`category`),
  ADD KEY `transaction_user` (`user`),
  ADD KEY `transaction_date` (`date`);

--
-- Index pour la table `bank_transaction_category`
--
ALTER TABLE `bank_transaction_category`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`),
  ADD KEY `parent_category` (`parent_category`);

--
-- Index pour la table `bank_transaction_category_keyword`
--
ALTER TABLE `bank_transaction_category_keyword`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `keyword` (`keyword`),
  ADD KEY `keyword_category` (`category`);

--
-- Index pour la table `budget`
--
ALTER TABLE `budget`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category` (`category`,`date`);

--
-- Index pour la table `budget_insight`
--
ALTER TABLE `budget_insight`
  ADD PRIMARY KEY (`id`);

--
-- Index pour la table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`name`);

--
-- Index pour la table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `fk_api_token_user` (`user_id`);

--
-- Index pour la table `devices`
--
ALTER TABLE `devices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `fk_device_user` (`user_id`);

--
-- Index pour la table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT pour les tables déchargées
--

--
-- AUTO_INCREMENT pour la table `bank_account`
--
ALTER TABLE `bank_account`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `bank_transaction`
--
ALTER TABLE `bank_transaction`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'Unique ID for a transaction';

--
-- AUTO_INCREMENT pour la table `bank_transaction_category`
--
ALTER TABLE `bank_transaction_category`
  MODIFY `id` tinyint(3) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `bank_transaction_category_keyword`
--
ALTER TABLE `bank_transaction_category_keyword`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `budget`
--
ALTER TABLE `budget`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `budget_insight`
--
ALTER TABLE `budget_insight`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `api_tokens`
--
ALTER TABLE `api_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `devices`
--
ALTER TABLE `devices`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT pour la table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Contraintes pour les tables déchargées
--

--
-- Contraintes pour la table `bank_account`
--
ALTER TABLE `bank_account`
  ADD CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `bank_transaction`
--
ALTER TABLE `bank_transaction`
  ADD CONSTRAINT `fk_transaction_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`),
  ADD CONSTRAINT `fk_transaction_user` FOREIGN KEY (`user`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Contraintes pour la table `bank_transaction_category`
--
ALTER TABLE `bank_transaction_category`
  ADD CONSTRAINT `fk_parent_category` FOREIGN KEY (`parent_category`) REFERENCES `bank_transaction_category` (`id`);

--
-- Contraintes pour la table `bank_transaction_category_keyword`
--
ALTER TABLE `bank_transaction_category_keyword`
  ADD CONSTRAINT `fk_keyword_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `budget`
--
ALTER TABLE `budget`
  ADD CONSTRAINT `fk_budget_category` FOREIGN KEY (`category`) REFERENCES `bank_transaction_category` (`id`);

--
-- Contraintes pour la table `api_tokens`
--
ALTER TABLE `api_tokens`
  ADD CONSTRAINT `fk_api_token_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Contraintes pour la table `devices`
--
ALTER TABLE `devices`
  ADD CONSTRAINT `fk_device_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
