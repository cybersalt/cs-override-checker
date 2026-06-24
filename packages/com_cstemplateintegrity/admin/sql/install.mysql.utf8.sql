--
-- Cybersalt Override Checker -- install schema
-- Three tables created on first install. The component reads
-- Joomla's #__template_overrides table for its primary data; these
-- tables capture our own session log, action log, and pre-change
-- file backups.
--

CREATE TABLE IF NOT EXISTS `#__cstemplateintegrity_sessions` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(64)  NOT NULL DEFAULT '',
    `summary`         VARCHAR(500) NOT NULL DEFAULT '',
    `source`          VARCHAR(32)  NOT NULL DEFAULT 'paste',
    `report_markdown` LONGTEXT     NULL,
    `messages`        LONGTEXT     NULL,
    `state`           TINYINT      NOT NULL DEFAULT 1,
    `created_by`      INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`      DATETIME     NOT NULL,
    `modified_at`     DATETIME     NULL,
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`),
    KEY `idx_state` (`state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__cstemplateintegrity_actions` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id` INT UNSIGNED NULL,
    `action`     VARCHAR(64)  NOT NULL DEFAULT '',
    `details`    TEXT         NULL,
    `user_id`    INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_session_id` (`session_id`),
    KEY `idx_action` (`action`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__cstemplateintegrity_backups` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`   INT UNSIGNED NULL,
    `file_path`    VARCHAR(500) NOT NULL DEFAULT '',
    `file_hash`    CHAR(64)     NOT NULL DEFAULT '',
    `file_size`    INT UNSIGNED NOT NULL DEFAULT 0,
    `contents_b64` LONGTEXT     NULL,
    `created_by`   INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`   DATETIME     NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_session_id` (`session_id`),
    KEY `idx_file_path` (`file_path`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
