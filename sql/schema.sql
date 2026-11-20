-- ============================================================
-- AR License Manager v1.0 - Central License Server Schema
-- Copyright (c) ARNIT CREATION. All rights reserved.
-- Target: MySQL 5.7+ / MariaDB 10.4+, InnoDB, utf8mb4
-- {ARLM_PREFIX} is replaced by the installer with the chosen
-- table prefix. This script never touches existing SMF tables.
-- ============================================================

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}settings` (
  `name`       VARCHAR(64)  NOT NULL,
  `value`      TEXT         NULL,
  `updated_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}products` (
  `id`               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `product_uid`      VARCHAR(32)   NOT NULL,
  `name`             VARCHAR(191)  NOT NULL,
  `slug`             VARCHAR(191)  NOT NULL,
  `description`      TEXT          NULL,
  `version`          VARCHAR(32)   NOT NULL DEFAULT '1.0',
  `category`         VARCHAR(64)   NOT NULL DEFAULT 'general',
  `default_license_type` VARCHAR(20) NOT NULL DEFAULT 'single_domain',
  `default_activation_limit` INT UNSIGNED NOT NULL DEFAULT 1,
  `default_duration_days` INT UNSIGNED NULL DEFAULT NULL,
  `status`           ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_product_uid` (`product_uid`),
  UNIQUE KEY `uk_product_slug` (`slug`),
  KEY `idx_product_status` (`status`),
  KEY `idx_product_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}customers` (
  `id`            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `smf_member_id` INT UNSIGNED  NULL DEFAULT NULL,
  `email`         VARCHAR(191)  NOT NULL,
  `name`          VARCHAR(191)  NULL DEFAULT NULL,
  `source`        ENUM('smf','external') NOT NULL DEFAULT 'external',
  `status`        ENUM('active','suspended','blocked') NOT NULL DEFAULT 'active',
  `notes`         TEXT          NULL,
  `created_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customer_email` (`email`),
  UNIQUE KEY `uk_customer_smf_member` (`smf_member_id`),
  KEY `idx_customer_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}licenses` (
  `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key_hash`            CHAR(64)     NOT NULL,
  `key_last4`           CHAR(4)      NOT NULL DEFAULT '',
  `product_id`          INT UNSIGNED NOT NULL,
  `customer_id`         INT UNSIGNED NOT NULL,
  `license_type`        VARCHAR(20)  NOT NULL DEFAULT 'single_domain',
  `max_activations`     INT UNSIGNED NOT NULL DEFAULT 1,
  `current_activations` INT UNSIGNED NOT NULL DEFAULT 0,
  `status`              ENUM('active','expired','suspended','revoked','pending') NOT NULL DEFAULT 'active',
  `issued_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`          DATETIME     NULL DEFAULT NULL,
  `notes`               TEXT         NULL,
  `created_by`          VARCHAR(32)  NOT NULL DEFAULT 'admin',
  `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_license_key_hash` (`key_hash`),
  KEY `idx_license_product` (`product_id`),
  KEY `idx_license_customer` (`customer_id`),
  KEY `idx_license_status` (`status`),
  KEY `idx_license_expires` (`expires_at`),
  CONSTRAINT `fk_license_product`  FOREIGN KEY (`product_id`)  REFERENCES `{ARLM_PREFIX}products`  (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_license_customer` FOREIGN KEY (`customer_id`) REFERENCES `{ARLM_PREFIX}customers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}activations` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `license_id`     BIGINT UNSIGNED NOT NULL,
  `domain`         VARCHAR(255) NOT NULL,
  `domain_hash`    CHAR(64)     NOT NULL,
  `status`         ENUM('active','deactivated') NOT NULL DEFAULT 'active',
  `ip`             VARCHAR(45)  NULL DEFAULT NULL,
  `user_agent`     VARCHAR(255) NULL DEFAULT NULL,
  `activated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_seen_at`   DATETIME     NULL DEFAULT NULL,
  `deactivated_at` DATETIME     NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_activation_license_domain` (`license_id`, `domain_hash`),
  KEY `idx_activation_domain` (`domain_hash`),
  KEY `idx_activation_status` (`status`),
  CONSTRAINT `fk_activation_license` FOREIGN KEY (`license_id`) REFERENCES `{ARLM_PREFIX}licenses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}orders` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `order_ref`    VARCHAR(64)  NOT NULL,
  `txn_id`       VARCHAR(128) NULL DEFAULT NULL,
  `store_name`   VARCHAR(64)  NOT NULL DEFAULT 'arnit_store',
  `product_id`   INT UNSIGNED NULL DEFAULT NULL,
  `customer_id`  INT UNSIGNED NULL DEFAULT NULL,
  `license_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `amount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency`     CHAR(3)      NOT NULL DEFAULT 'INR',
  `status`       ENUM('pending','paid','failed','fulfilled','refunded','blocked') NOT NULL DEFAULT 'pending',
  `paid_at`      DATETIME     NULL DEFAULT NULL,
  `fulfilled_at` DATETIME     NULL DEFAULT NULL,
  `payload`      MEDIUMTEXT   NULL,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_order_ref` (`order_ref`),
  KEY `idx_order_txn` (`txn_id`),
  KEY `idx_order_status` (`status`),
  KEY `idx_order_customer` (`customer_id`),
  KEY `idx_order_product` (`product_id`),
  CONSTRAINT `fk_order_product`  FOREIGN KEY (`product_id`)  REFERENCES `{ARLM_PREFIX}products`  (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `{ARLM_PREFIX}customers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_license`  FOREIGN KEY (`license_id`)  REFERENCES `{ARLM_PREFIX}licenses`  (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}api_clients` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`            VARCHAR(191) NOT NULL,
  `client_id`       CHAR(32)     NOT NULL,
  `secret_hash`     CHAR(64)     NOT NULL,
  `permissions`     VARCHAR(255) NOT NULL DEFAULT 'activate,validate,deactivate,check,products',
  `rate_limit_m`    INT UNSIGNED NOT NULL DEFAULT 60,
  `status`          ENUM('active','disabled','revoked') NOT NULL DEFAULT 'active',
  `last_used_at`    DATETIME     NULL DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_client_id` (`client_id`),
  KEY `idx_api_client_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}api_logs` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `api_client_id` INT UNSIGNED NULL DEFAULT NULL,
  `endpoint`      VARCHAR(64)  NOT NULL,
  `method`        VARCHAR(8)   NOT NULL DEFAULT 'POST',
  `request_id`    CHAR(64)     NULL DEFAULT NULL,
  `license_ref`   VARCHAR(8)   NULL DEFAULT NULL,
  `ip`            VARCHAR(45)  NULL DEFAULT NULL,
  `status_code`   SMALLINT UNSIGNED NOT NULL DEFAULT 200,
  `error_code`    VARCHAR(32)  NULL DEFAULT NULL,
  `duration_ms`   INT UNSIGNED NOT NULL DEFAULT 0,
  `response_json` MEDIUMTEXT   NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_log_request` (`api_client_id`, `request_id`),
  KEY `idx_api_log_client_time` (`api_client_id`, `created_at`),
  KEY `idx_api_log_endpoint` (`endpoint`),
  CONSTRAINT `fk_api_log_client` FOREIGN KEY (`api_client_id`) REFERENCES `{ARLM_PREFIX}api_clients` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}security_logs` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `event`      VARCHAR(64)  NOT NULL,
  `severity`   ENUM('info','warning','critical') NOT NULL DEFAULT 'info',
  `actor`      VARCHAR(64)  NULL DEFAULT NULL,
  `ip`         VARCHAR(45)  NULL DEFAULT NULL,
  `details`    TEXT         NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_seclog_event` (`event`),
  KEY `idx_seclog_time` (`created_at`),
  KEY `idx_seclog_severity` (`severity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_type`  ENUM('admin','customer','api','system') NOT NULL DEFAULT 'system',
  `actor_id`    BIGINT UNSIGNED NULL DEFAULT NULL,
  `action`      VARCHAR(64)  NOT NULL,
  `object_type` VARCHAR(32)  NULL DEFAULT NULL,
  `object_id`   BIGINT UNSIGNED NULL DEFAULT NULL,
  `details`     TEXT         NULL,
  `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_audit_action` (`action`),
  KEY `idx_audit_object` (`object_type`, `object_id`),
  KEY `idx_audit_time` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `{ARLM_PREFIX}admins` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(64)  NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `last_login_at` DATETIME     NULL DEFAULT NULL,
  `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_admin_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
