CREATE DATABASE IF NOT EXISTS autocontrol CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE autocontrol;

CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  full_name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('user','inspector','officer','admin') NOT NULL DEFAULT 'user',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_login_at DATETIME NULL,
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  plate VARCHAR(12) NOT NULL,
  vin VARCHAR(17) NOT NULL,
  make VARCHAR(80) NOT NULL,
  model VARCHAR(100) NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  color VARCHAR(60) NULL,
  owner_name VARCHAR(160) NOT NULL,
  owner_email VARCHAR(190) NOT NULL,
  owner_user_id BIGINT UNSIGNED NULL,
  status ENUM('active','suspended','deregistered') NOT NULL DEFAULT 'active',
  registered_at DATE NULL,
  registered_by BIGINT UNSIGNED NULL,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_vehicles_plate (plate),
  UNIQUE KEY uq_vehicles_vin (vin),
  KEY idx_vehicles_owner_email (owner_email),
  KEY idx_vehicles_owner_user (owner_user_id),
  KEY idx_vehicles_status (status),
  CONSTRAINT fk_vehicles_owner_user FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_vehicles_registered_by FOREIGN KEY (registered_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_photos (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  caption VARCHAR(180) NULL,
  uploaded_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vehicle_photos_vehicle (vehicle_id),
  CONSTRAINT fk_vehicle_photos_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_photos_user FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicle_history (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  event_type VARCHAR(40) NOT NULL,
  title VARCHAR(180) NOT NULL,
  description TEXT NULL,
  event_date DATE NOT NULL,
  created_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_vehicle_history_vehicle (vehicle_id,event_date),
  CONSTRAINT fk_vehicle_history_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_vehicle_history_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fines (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  vehicle_id BIGINT UNSIGNED NOT NULL,
  external_ref VARCHAR(80) NOT NULL,
  title VARCHAR(180) NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  details TEXT NULL,
  due_date DATE NOT NULL,
  status ENUM('issued','paid','cancelled') NOT NULL DEFAULT 'issued',
  bank_status ENUM('pending','sent','error','not_configured') NOT NULL DEFAULT 'pending',
  bank_last_error VARCHAR(500) NULL,
  bank_sent_at DATETIME NULL,
  issued_by BIGINT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fines_external_ref (external_ref),
  KEY idx_fines_vehicle (vehicle_id),
  KEY idx_fines_status (status,bank_status),
  CONSTRAINT fk_fines_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
  CONSTRAINT fk_fines_issuer FOREIGN KEY (issued_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS bank_api_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  fine_id BIGINT UNSIGNED NOT NULL,
  outcome VARCHAR(32) NOT NULL,
  http_code SMALLINT NOT NULL DEFAULT 0,
  response_excerpt TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_bank_api_logs_fine (fine_id),
  CONSTRAINT fk_bank_api_logs_fine FOREIGN KEY (fine_id) REFERENCES fines(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
