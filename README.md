# Years-in-pixels

Рабочий пример: https://t.me/PixelYearsBot/Diary

---

Залей на хостинг, создай таблицы бд:
 
```sql
CREATE TABLE `calendar_entries` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`user_id` BIGINT(20) NOT NULL,
	`entry_date` DATE NOT NULL,
	`mood_key` VARCHAR(25) NOT NULL COLLATE 'utf8mb4_unicode_ci',
	`description` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`created_at` TIMESTAMP NULL DEFAULT current_timestamp(),
	`updated_at` TIMESTAMP NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
	`alcohol` TINYINT(1) NULL DEFAULT '0',
	`sport` TINYINT(1) NULL DEFAULT '0',
	`sex` TINYINT(1) NULL DEFAULT '0',
	`friends` TINYINT(1) NULL DEFAULT '0',
	`romantic` TINYINT(1) NULL DEFAULT '0',
	`crying` TINYINT(1) NULL DEFAULT '0',
	`WomanDay` TINYINT(1) NULL DEFAULT '0',
	PRIMARY KEY (`id`) USING BTREE,
	UNIQUE INDEX `user_day_unique` (`user_id`, `entry_date`) USING BTREE,
	INDEX `idx_user_year` (`user_id`, `entry_date`) USING BTREE,
	CONSTRAINT `fk_user_id` FOREIGN KEY (`user_id`) REFERENCES `calendar_users` (`user_id`) ON UPDATE RESTRICT ON DELETE RESTRICT
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
AUTO_INCREMENT=482
;
```
 
```sql
CREATE TABLE `calendar_users` (
	`id` INT(11) NOT NULL AUTO_INCREMENT,
	`user_id` BIGINT(20) NULL DEFAULT NULL,
	`user_firstname` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_lastname` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_nickname` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_allowmessages` TINYINT(1) NOT NULL DEFAULT '1',
	`user_metainfo` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_timezone` VARCHAR(50) NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_lastmessage` DATE NULL DEFAULT NULL,
	`user_lastentry` DATE NULL DEFAULT NULL,
	`user_entriestotal` INT(11) NOT NULL DEFAULT '0',
	`user_latestip` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_menu` TEXT NOT NULL DEFAULT '0' COLLATE 'utf8mb4_unicode_ci',
	`user_private` TINYINT(1) NOT NULL DEFAULT '1',
	`user_sharehash` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_shareimage` TEXT NULL DEFAULT NULL COLLATE 'utf8mb4_unicode_ci',
	`user_registerdate` TIMESTAMP NULL DEFAULT current_timestamp(),
	PRIMARY KEY (`id`) USING BTREE,
	UNIQUE INDEX `user_id` (`user_id`) USING BTREE
)
COLLATE='utf8mb4_unicode_ci'
ENGINE=InnoDB
AUTO_INCREMENT=299
;
```

---
  [![fhnb16](https://img.shields.io/badge/Made_by_fhnb16-april_2025-gray.svg?style=plastic&labelColor=FF0000)](https://fhnb.ru/)
