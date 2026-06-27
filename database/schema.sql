-- ============================================================
--  CODEKATHAX — full schema (import-ready for Hostinger)
--
--  On Hostinger the database is already created for you in hPanel
--  (prefixed name like u123456_codekathax), so this file does NOT run
--  CREATE DATABASE / USE. Select your database in phpMyAdmin and import.
--
--  For an EXISTING database already running the older schema, run the
--  migration in database/migrations/ instead (it only adds the new bits).
-- ============================================================

-- Referral agents / partners who bring in clients.
CREATE TABLE IF NOT EXISTS `agents` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(120)    NOT NULL,
  `email`         VARCHAR(160)    NOT NULL,
  `phone`         VARCHAR(60)     DEFAULT NULL,
  `password_hash` VARCHAR(255)    NOT NULL,
  `payout_method` VARCHAR(40)     DEFAULT NULL,   -- e.g. GCash, Maya, Bank
  `payout_number` VARCHAR(120)    DEFAULT NULL,   -- account / number for the 30%
  `ref_token`     VARCHAR(20)     NOT NULL,        -- referral code in their link
  `status`        ENUM('pending','approved','suspended') NOT NULL DEFAULT 'pending',
  `ip_hash`       CHAR(64)        DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at`   TIMESTAMP       NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_agent_email` (`email`),
  UNIQUE KEY `uq_ref_token` (`ref_token`),
  KEY `idx_agent_ip` (`ip_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Project requests submitted through the website form.
--   agent_id / ref_token : set when the client came from an agent's link
--   deal_amount          : final agreed price (you set it when a deal closes)
--   deal_status          : lead (new) / won (closed) / lost
CREATE TABLE IF NOT EXISTS `project_requests` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference`     VARCHAR(20)     NOT NULL,
  `path`          ENUM('capstone','business') NOT NULL,
  `system_type`   VARCHAR(40)     DEFAULT NULL,
  `service`       VARCHAR(40)     DEFAULT NULL,
  `project_title` VARCHAR(160)    DEFAULT NULL,
  `business_name` VARCHAR(160)    DEFAULT NULL,
  `industry`      VARCHAR(120)    DEFAULT NULL,
  `has_existing`  VARCHAR(10)     DEFAULT NULL,
  `description`   TEXT            NOT NULL,
  `deadline`      VARCHAR(120)    DEFAULT NULL,
  `budget`        VARCHAR(40)     DEFAULT NULL,
  `custom_budget` VARCHAR(120)    DEFAULT NULL,
  `name`          VARCHAR(120)    NOT NULL,
  `email`         VARCHAR(160)    NOT NULL,
  `phone`         VARCHAR(60)     DEFAULT NULL,
  `org`           VARCHAR(160)    DEFAULT NULL,
  `agent_id`      BIGINT UNSIGNED DEFAULT NULL,
  `ref_token`     VARCHAR(20)     DEFAULT NULL,
  `deal_amount`   DECIMAL(10,2)   DEFAULT NULL,
  `deal_status`   ENUM('lead','won','lost') NOT NULL DEFAULT 'lead',
  `commission_pct` TINYINT UNSIGNED NOT NULL DEFAULT 15,
  `status`        ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  `ip_hash`       CHAR(64)        DEFAULT NULL,
  `user_agent`    VARCHAR(255)    DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_ip_created` (`ip_hash`, `created_at`),
  KEY `idx_agent` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login sessions for agents (and the admin) — opaque bearer tokens.
CREATE TABLE IF NOT EXISTS `sessions` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('agent','admin') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,        -- agents.id, or 0 for the single admin
  `token_hash` CHAR(64)        NOT NULL,         -- sha256 of the bearer token
  `expires_at` TIMESTAMP       NOT NULL,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_token` (`token_hash`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login throttle: one row per attempt, used to rate-limit by IP.
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`    CHAR(64)        NOT NULL,
  `success`    TINYINT(1)      NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Editable key/value settings (e.g. agent_limit). Admin-configurable.
CREATE TABLE IF NOT EXISTS `app_settings` (
  `name`  VARCHAR(64)  NOT NULL,
  `value` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `app_settings` (`name`, `value`) VALUES ('agent_limit', '0')
  ON DUPLICATE KEY UPDATE `name` = `name`;

-- No seed/dummy data. Tables start empty and fill from real activity.
