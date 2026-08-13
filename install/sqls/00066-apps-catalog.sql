ALTER TABLE `apps`
  ADD `type` TINYINT(1) NOT NULL DEFAULT 1 AFTER `enabled`;
-- 0 = app (soft), 1 = game

CREATE TABLE IF NOT EXISTS `app_activity` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user` BIGINT UNSIGNED NOT NULL,
  `app` BIGINT UNSIGNED NOT NULL,
  `kind` TINYINT UNSIGNED NOT NULL,
  `created` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_created` (`user`, `created`),
  KEY `app` (`app`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci;
