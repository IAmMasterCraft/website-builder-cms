-- Membership and community tables (§9). Created now so the admin overview
-- shows real counts; their features arrive in phases 4 and 5.
CREATE TABLE members (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  member_number VARCHAR(40) NULL,
  membership_type VARCHAR(60) NOT NULL DEFAULT 'standard',
  first_name VARCHAR(80) NOT NULL,
  last_name VARCHAR(80) NOT NULL,
  phone VARCHAR(40) NULL,
  birth_day TINYINT UNSIGNED NULL,
  birth_month TINYINT UNSIGNED NULL,
  birth_year SMALLINT UNSIGNED NULL,
  gender VARCHAR(40) NULL,
  address VARCHAR(300) NULL,
  nationality VARCHAR(80) NULL,
  country VARCHAR(80) NULL,
  region VARCHAR(80) NULL,
  city VARCHAR(80) NULL,
  photo_media_id INT UNSIGNED NULL,
  card_code VARCHAR(40) NULL,
  card_valid_until DATE NULL,
  membership_fee_status VARCHAR(40) NULL,
  prefs LONGTEXT NULL COMMENT 'JSON',
  verified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_members_user (user_id),
  UNIQUE KEY uq_members_number (member_number),
  KEY ix_members_birthday (birth_month, birth_day),
  CONSTRAINT fk_members_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_members_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE custom_fields (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  field_key VARCHAR(60) NOT NULL,
  label VARCHAR(120) NOT NULL,
  type ENUM('text','select','date','checkbox') NOT NULL DEFAULT 'text',
  options LONGTEXT NULL COMMENT 'JSON list for select',
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  show_on_card TINYINT(1) NOT NULL DEFAULT 0,
  visible_to_members TINYINT(1) NOT NULL DEFAULT 0,
  applies_to ENUM('member','group') NOT NULL DEFAULT 'member',
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_custom_fields_key (field_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_field_values (
  member_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  value TEXT NULL,
  PRIMARY KEY (member_id, field_id),
  CONSTRAINT fk_mfv_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_mfv_field FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE likes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  item_type VARCHAR(30) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_likes (user_id, item_type, item_id),
  KEY ix_likes_item (item_type, item_id),
  CONSTRAINT fk_likes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE comments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  item_type VARCHAR(30) NOT NULL,
  item_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  parent_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  status ENUM('pending','approved','rejected','reported') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_comments_item (item_type, item_id, status),
  KEY ix_comments_status (status),
  CONSTRAINT fk_comments_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_comments_parent FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jobs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL,
  title VARCHAR(200) NOT NULL,
  organisation VARCHAR(200) NOT NULL DEFAULT '',
  location VARCHAR(200) NOT NULL DEFAULT '',
  job_type ENUM('full_time','part_time','contract','volunteer','internship') NOT NULL DEFAULT 'full_time',
  is_remote TINYINT(1) NOT NULL DEFAULT 0,
  salary_text VARCHAR(120) NOT NULL DEFAULT '',
  description MEDIUMTEXT NULL,
  apply_url VARCHAR(500) NULL,
  apply_email VARCHAR(190) NULL,
  closing_date DATE NULL,
  status ENUM('pending','published','rejected','expired') NOT NULL DEFAULT 'pending',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_jobs_status (status),
  CONSTRAINT fk_jobs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE job_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  job_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_job_reports_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
  CONSTRAINT fk_job_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  subject VARCHAR(200) NOT NULL DEFAULT '',
  body TEXT NOT NULL,
  read_at DATETIME NULL,
  replied_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_contact_read (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
