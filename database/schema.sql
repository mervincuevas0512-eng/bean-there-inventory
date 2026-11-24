CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(120) NOT NULL,
 first_name VARCHAR(60) NULL,
 middle_name VARCHAR(60) NULL,
 last_name VARCHAR(60) NULL,
 suffix VARCHAR(15) NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('owner','staff') NOT NULL DEFAULT 'staff',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 email VARCHAR(190) NOT NULL,
 ip VARCHAR(45) NOT NULL,
 attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY login_lookup (email, ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categories (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(90) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS items (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sku VARCHAR(40) NOT NULL UNIQUE,
 name VARCHAR(150) NOT NULL,
 category_id INT UNSIGNED NOT NULL,
 unit VARCHAR(24) NOT NULL,
 min_level DECIMAL(12,3) NOT NULL DEFAULT 0,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_item_cat FOREIGN KEY (category_id) REFERENCES categories(id),
 INDEX item_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lots (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id INT UNSIGNED NOT NULL,
 batch_code VARCHAR(80) NOT NULL,
 received_on DATE NOT NULL,
 expiry_date DATE NULL,
 qty_on_hand DECIMAL(12,3) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_lots_item FOREIGN KEY (item_id) REFERENCES items(id),
 UNIQUE KEY unique_batch (item_id,batch_code),
 INDEX lots_pick (item_id,expiry_date,received_on),
 CONSTRAINT chk_non_negative CHECK (qty_on_hand >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS movements (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_id INT UNSIGNED NOT NULL,
 lot_id BIGINT UNSIGNED NULL,
 user_id INT UNSIGNED NOT NULL,
 type ENUM('IN','OUT','ADJUST_IN','ADJUST_OUT') NOT NULL,
 quantity DECIMAL(12,3) NOT NULL,
 reason VARCHAR(180) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_mov_item FOREIGN KEY (item_id) REFERENCES items(id),
 CONSTRAINT fk_mov_lot FOREIGN KEY (lot_id) REFERENCES lots(id),
 CONSTRAINT fk_mov_user FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX move_date (created_at),
 INDEX move_item (item_id),
 CONSTRAINT chk_quantity_positive CHECK (quantity > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
 setting_key VARCHAR(70) NOT NULL PRIMARY KEY,
 setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO settings (setting_key,setting_value) VALUES
 ('expiry_warning_days','7'),('shop_label','Bean There Cafe');
