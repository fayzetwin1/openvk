ALTER TABLE `apps`
  ADD `runner` TINYINT(1) NOT NULL DEFAULT 0 AFTER `type`,
  ADD `swf_hash` CHAR(8) DEFAULT NULL AFTER `avatar_hash`,
  ADD `secret` VARCHAR(32) NOT NULL DEFAULT '' AFTER `address`;
-- runner: 0 = html iframe, 1 = flash (Ruffle)
