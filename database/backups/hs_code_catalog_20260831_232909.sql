SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
DROP TABLE IF EXISTS `hs_code_catalog`;
CREATE TABLE `hs_code_catalog` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ex` varchar(20) DEFAULT NULL,
  `tariff_code` varchar(32) DEFAULT NULL,
  `name` text DEFAULT NULL,
  `section` text DEFAULT NULL,
  `chapter` text DEFAULT NULL,
  `previous_tariff_code` varchar(32) DEFAULT NULL,
  `full_name` longtext DEFAULT NULL,
  `full_name_en` longtext DEFAULT NULL,
  `full_name_de` longtext DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `hs_code_catalog_tariff_code_index` (`tariff_code`),
  KEY `hs_code_catalog_previous_tariff_code_index` (`previous_tariff_code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SET FOREIGN_KEY_CHECKS=1;
