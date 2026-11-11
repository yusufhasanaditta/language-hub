CREATE TABLE IF NOT EXISTS `testimonials` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `batch` varchar(255) DEFAULT NULL,
  `avatar` varchar(10) DEFAULT NULL,
  `color` varchar(20) NOT NULL DEFAULT '#5A3CF0',
  `text` varchar(500) NOT NULL,
  `rating` tinyint(4) NOT NULL DEFAULT 5,
  `sort_order` smallint(6) NOT NULL DEFAULT 0,
  `is_approved` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_approved` (`is_approved`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
