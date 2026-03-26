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
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  owner_verified TINYINT(1) NOT NULL DEFAULT 0,
  owner_verified_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role (role),
  KEY idx_users_active (is_active),
  KEY idx_users_owner_verified (owner_verified)
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
  total_rooms INT UNSIGNED NOT NULL DEFAULT 0,
  available_rooms INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('active','inactive','full') NOT NULL DEFAULT 'active',
  contact_phone VARCHAR(50) NULL,
  contact_email VARCHAR(190) NULL,
  approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved',
  approved_by INT UNSIGNED NULL,
  approved_at TIMESTAMP NULL DEFAULT NULL,
  rejected_reason TEXT NULL,
  views INT UNSIGNED NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_bh_owner (owner_id),
  KEY idx_bh_city (city),
  KEY idx_bh_status (status),
  KEY idx_bh_type (accommodation_type),
  KEY idx_bh_approval (approval_status),
  KEY idx_bh_views (views),
  CONSTRAINT fk_bh_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_bh_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL
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
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  owner_reply TEXT NULL,
  replied_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_cm_bh (boarding_house_id),
  KEY idx_cm_sender (sender_id),
  CONSTRAINT fk_cm_bh FOREIGN KEY (boarding_house_id) REFERENCES boarding_houses(id) ON DELETE CASCADE,
  CONSTRAINT fk_cm_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT UNSIGNED NOT NULL,
  reporter_id INT UNSIGNED NULL,
  reason VARCHAR(120) NOT NULL,
  details TEXT NULL,
  status ENUM('open','resolved','dismissed') NOT NULL DEFAULT 'open',
  handled_by INT UNSIGNED NULL,
  handled_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reports_bh (boarding_house_id),
  KEY idx_reports_status (status),
  KEY idx_reports_reporter (reporter_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_pages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug VARCHAR(120) NOT NULL,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pages_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS announcements (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  posted_by INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ann_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS settings (
  `key` VARCHAR(120) NOT NULL,
  `value` MEDIUMTEXT NULL,
  updated_by INT UNSIGNED NULL,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS search_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  ip VARCHAR(64) NULL,
  channel ENUM('web','api') NOT NULL DEFAULT 'web',
  search VARCHAR(255) NULL,
  city VARCHAR(120) NULL,
  min_price DECIMAL(10,2) NULL,
  max_price DECIMAL(10,2) NULL,
  accommodation_type VARCHAR(60) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_search_created (created_at),
  KEY idx_search_city (city),
  KEY idx_search_type (accommodation_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id INT UNSIGNED NOT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id INT UNSIGNED NULL,
  meta_json MEDIUMTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin (admin_id),
  KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Favorites / Saved Listings
-- Note: foreign keys are intentionally omitted for import compatibility
-- with older schemas (signed vs unsigned IDs / non-InnoDB tables).
CREATE TABLE IF NOT EXISTS favorites (
  user_id INT NOT NULL,
  boarding_house_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, boarding_house_id),
  KEY idx_fav_bh (boarding_house_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Ratings / Reviews
CREATE TABLE IF NOT EXISTS reviews (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT NOT NULL,
  user_id INT NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  review TEXT NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_review_user_bh (user_id, boarding_house_id),
  KEY idx_review_bh (boarding_house_id),
  KEY idx_review_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- In-app Chat (Tenant <-> Owner per listing)
CREATE TABLE IF NOT EXISTS chat_threads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT NOT NULL,
  tenant_id INT NOT NULL,
  owner_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  last_message_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_thread_bh_tenant (boarding_house_id, tenant_id),
  KEY idx_thread_owner (owner_id),
  KEY idx_thread_tenant (tenant_id),
  KEY idx_thread_last (last_message_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS chat_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  thread_id INT NOT NULL,
  sender_id INT NOT NULL,
  message TEXT NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_msg_thread (thread_id),
  KEY idx_msg_created (created_at),
  KEY idx_msg_read (thread_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Room Management (Boarding House -> Rooms -> Tenant Requests)
CREATE TABLE IF NOT EXISTS rooms (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  boarding_house_id INT NOT NULL,
  room_name VARCHAR(120) NOT NULL,
  price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  capacity INT UNSIGNED NOT NULL DEFAULT 1,
  current_occupants INT UNSIGNED NOT NULL DEFAULT 0,
  status ENUM('available','occupied') NOT NULL DEFAULT 'available',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rooms_bh (boarding_house_id),
  KEY idx_rooms_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS room_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  room_id INT NOT NULL,
  tenant_id INT NOT NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  move_in_date DATE NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rr_room (room_id),
  KEY idx_rr_tenant (tenant_id),
  KEY idx_rr_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

