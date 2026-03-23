-- Boarding House Finder - MySQL schema
-- Import this into phpMyAdmin, or run from CLI:
--   mysql -u root boarding_house_finder < schema.sql

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  full_name VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('owner','tenant','admin') NOT NULL DEFAULT 'tenant',
  phone VARCHAR(50) NULL,
  avatar VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boarding_houses (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id INT UNSIGNED NOT NULL,
  name VARCHAR(200) NOT NULL,
  location VARCHAR(255) NOT NULL,
  city VARCHAR(120) NOT NULL,
  description TEXT NULL,
  rules TEXT NULL,
  price_min DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  price_max DECIMAL(10,2) NULL DEFAULT NULL,
  accommodation_type ENUM('solo_room','shared_room','studio','apartment') NOT NULL DEFAULT 'solo_room',
  total_rooms INT UNSIGNED NOT NULL DEFAULT 1,
  available_rooms INT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('active','inactive','full') NOT NULL DEFAULT 'active',
  contact_phone VARCHAR(50) NULL,
  contact_email VARCHAR(190) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bh_owner (owner_id),
  KEY idx_bh_city (city),
  KEY idx_bh_status (status),
  KEY idx_bh_type (accommodation_type),
  CONSTRAINT fk_bh_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boarding_house_images (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT UNSIGNED NOT NULL,
  image_path VARCHAR(255) NOT NULL,
  is_cover TINYINT(1) NOT NULL DEFAULT 0,
  uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bhi_bh (boarding_house_id),
  KEY idx_bhi_cover (boarding_house_id, is_cover),
  CONSTRAINT fk_bhi_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS amenities (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_amenities_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS boarding_house_amenities (
  boarding_house_id INT UNSIGNED NOT NULL,
  amenity_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (boarding_house_id, amenity_id),
  KEY idx_bha_amenity (amenity_id),
  CONSTRAINT fk_bha_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE,
  CONSTRAINT fk_bha_amenity FOREIGN KEY (amenity_id) REFERENCES amenities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS contact_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NULL,
  sender_name VARCHAR(150) NOT NULL,
  sender_email VARCHAR(190) NOT NULL,
  sender_phone VARCHAR(50) NULL,
  message TEXT NOT NULL,\r\n  is_read TINYINT(1) NOT NULL DEFAULT 0,\r\n  owner_reply TEXT NULL,\r\n  replied_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_bh (boarding_house_id),
  KEY idx_cm_sender (sender_id),
  CONSTRAINT fk_cm_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

