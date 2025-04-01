# Years-in-pixels

Рабочий пример: https://t.me/PixelYearsBot/myYear

---

Залей на хостинг, создай базу или выбери нужную и создай в ней таблицу:
 
```sql
CREATE TABLE IF NOT EXISTS `calendar_entries` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) NOT NULL,
  `entry_date` date NOT NULL,
  `mood_key` varchar(25) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `alcohol` tinyint(1) DEFAULT 0,
  `sport` tinyint(1) DEFAULT 0,
  `sex` tinyint(1) DEFAULT 0,
  `friends` tinyint(1) DEFAULT 0,
  `romantic` tinyint(1) DEFAULT 0,
  `crying` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_day_unique` (`user_id`,`entry_date`),
  KEY `idx_user_year` (`user_id`,`entry_date`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---
  [![fhnb16](https://img.shields.io/badge/Made_by_fhnb16-april_2025-gray.svg?style=plastic&labelColor=FF0000)](https://fhnb.ru/)
