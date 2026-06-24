-- ============================================================
--  CODEKATHAX — project requests schema (import-ready for Hostinger)
--
--  On Hostinger the database is already created for you in hPanel
--  (prefixed name like u123456_codekathax), so this file does NOT run
--  CREATE DATABASE / USE. Select your database in phpMyAdmin and import
--  this file to create the table.
--
--    reference : public reference shown to the client (e.g. CKX-7G2K9Q)
--    path      : 'capstone' or 'business' (which form was used)
--    ip_hash   : salted SHA-256 of the submitter IP, used for rate limiting
--    status    : workflow flag for you (new / read / archived)
-- ============================================================

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
  `status`        ENUM('new','read','archived') NOT NULL DEFAULT 'new',
  `ip_hash`       CHAR(64)        DEFAULT NULL,
  `user_agent`    VARCHAR(255)    DEFAULT NULL,
  `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reference` (`reference`),
  KEY `idx_status_created` (`status`, `created_at`),
  KEY `idx_ip_created` (`ip_hash`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No seed/dummy data. The table starts empty and is filled only by real
-- submissions through the API.
