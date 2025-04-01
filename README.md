# Years-in-pixels

Рабочий пример: https://t.me/PixelYearsBot/myYear

---

Залей на хостинг, создай базу или выбери нужную и создай в ней таблицу:
 
```sql
CREATE TABLE `calendar_entries` (
  `id` INT UNSIGNED NOT AUTO_INCREMENT PRIMARY KEY,
  `user_id` BIGINT NOT NULL, -- Telegram User ID (может быть большим)
  `entry_date` DATE NOT NULL,
  `mood_key` VARCHAR(25) NOT NULL, -- Ключ настроения (e.g., 'good', 'sad')
  `description` TEXT NULL, -- Описание дня (макс ~65k символов, NULL разрешен)
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `user_day_unique` (`user_id`, `entry_date`), -- Гарантирует одну запись на пользователя в день
  INDEX `idx_user_year` (`user_id`, `entry_date`) -- Индекс для быстрого поиска по пользователю и году/дате
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

---
  [![fhnb16](https://img.shields.io/badge/Made_by_fhnb16-april_2025-gray.svg?style=plastic&labelColor=FF0000)](https://fhnb.ru/)
