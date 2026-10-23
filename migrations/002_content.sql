-- Content types (§3.3). Rich-text "full" types share the common columns:
-- title, slug, summary, body, cover_media_id, status, publish_at, author_id,
-- visibility, seo_title, seo_description, created_at, updated_at.
CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  base ENUM('news','blog','projects') NOT NULL COMMENT 'which public list (and URL prefix) its posts appear in',
  is_base TINYINT(1) NOT NULL DEFAULT 0,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO categories (name, slug, base, is_base, sort) VALUES
  ('News', 'news', 'news', 1, 1), ('Blog', 'blog', 'blog', 1, 2), ('Projects', 'projects', 'projects', 1, 3);

CREATE TABLE tags (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(60) NOT NULL,
  slug VARCHAR(60) NOT NULL,
  UNIQUE KEY uq_tags_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  body MEDIUMTEXT NULL,
  cover_media_id INT UNSIGNED NULL,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  author_id INT UNSIGNED NULL,
  visibility ENUM('public','members') NOT NULL DEFAULT 'public',
  seo_title VARCHAR(200) NULL,
  seo_description VARCHAR(300) NULL,
  category_id INT UNSIGNED NOT NULL,
  allow_likes TINYINT(1) NOT NULL DEFAULT 1,
  allow_comments TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_posts_slug (slug),
  KEY ix_posts_pub (status, publish_at),
  FULLTEXT KEY ft_posts (title, summary, body),
  CONSTRAINT fk_posts_category FOREIGN KEY (category_id) REFERENCES categories(id),
  CONSTRAINT fk_posts_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_posts_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_tags (
  post_id INT UNSIGNED NOT NULL,
  tag_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (post_id, tag_id),
  CONSTRAINT fk_pt_post FOREIGN KEY (post_id) REFERENCES posts(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_tag FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE events (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  body MEDIUMTEXT NULL,
  cover_media_id INT UNSIGNED NULL,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  author_id INT UNSIGNED NULL,
  visibility ENUM('public','members') NOT NULL DEFAULT 'public',
  seo_title VARCHAR(200) NULL,
  seo_description VARCHAR(300) NULL,
  start_at DATETIME NOT NULL COMMENT 'UTC',
  end_at DATETIME NULL COMMENT 'UTC',
  timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/London',
  venue_name VARCHAR(200) NULL,
  venue_address VARCHAR(300) NULL,
  map_url VARCHAR(500) NULL,
  online_url VARCHAR(500) NULL,
  capacity INT UNSIGNED NULL,
  registration_open TINYINT(1) NOT NULL DEFAULT 0,
  guest_registration TINYINT(1) NOT NULL DEFAULT 1,
  registration_deadline DATETIME NULL COMMENT 'UTC',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_events_slug (slug),
  KEY ix_events_start (start_at),
  KEY ix_events_pub (status, publish_at),
  FULLTEXT KEY ft_events (title, summary, body),
  CONSTRAINT fk_events_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_events_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  price_label VARCHAR(60) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  CONSTRAINT fk_tickets_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_registrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id INT UNSIGNED NOT NULL,
  ticket_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(40) NOT NULL DEFAULT '',
  quantity SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('registered','cancelled','attended') NOT NULL DEFAULT 'registered',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_reg_event (event_id, status),
  KEY ix_reg_created (created_at),
  CONSTRAINT fk_reg_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_reg_ticket FOREIGN KEY (ticket_id) REFERENCES event_tickets(id) ON DELETE SET NULL,
  CONSTRAINT fk_reg_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE albums (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  body MEDIUMTEXT NULL,
  cover_media_id INT UNSIGNED NULL,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  author_id INT UNSIGNED NULL,
  visibility ENUM('public','members') NOT NULL DEFAULT 'public',
  seo_title VARCHAR(200) NULL,
  seo_description VARCHAR(300) NULL,
  album_date DATE NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_albums_slug (slug),
  CONSTRAINT fk_albums_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_albums_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE album_photos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  album_id INT UNSIGNED NOT NULL,
  media_id INT UNSIGNED NOT NULL,
  caption VARCHAR(300) NOT NULL DEFAULT '',
  alt VARCHAR(300) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_photos_album (album_id, sort),
  CONSTRAINT fk_photos_album FOREIGN KEY (album_id) REFERENCES albums(id) ON DELETE CASCADE,
  CONSTRAINT fk_photos_media FOREIGN KEY (media_id) REFERENCES media(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE downloads (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  body MEDIUMTEXT NULL,
  cover_media_id INT UNSIGNED NULL,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  author_id INT UNSIGNED NULL,
  visibility ENUM('public','members') NOT NULL DEFAULT 'public',
  seo_title VARCHAR(200) NULL,
  seo_description VARCHAR(300) NULL,
  file_media_id INT UNSIGNED NOT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  download_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_downloads_slug (slug),
  FULLTEXT KEY ft_downloads (title, summary, body),
  CONSTRAINT fk_downloads_file FOREIGN KEY (file_media_id) REFERENCES media(id),
  CONSTRAINT fk_downloads_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_downloads_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leader_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  description TEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leader_groups_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leaders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  group_id INT UNSIGNED NOT NULL,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  role_title VARCHAR(160) NOT NULL DEFAULT '',
  photo_media_id INT UNSIGNED NULL,
  bio MEDIUMTEXT NULL,
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  show_email TINYINT(1) NOT NULL DEFAULT 0,
  show_phone TINYINT(1) NOT NULL DEFAULT 0,
  show_bio TINYINT(1) NOT NULL DEFAULT 1,
  social LONGTEXT NULL COMMENT 'JSON {network: url}',
  term_start DATE NULL,
  term_end DATE NULL,
  sort INT NOT NULL DEFAULT 0,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'published',
  publish_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_leaders_slug (slug),
  KEY ix_leaders_group (group_id, sort),
  CONSTRAINT fk_leaders_group FOREIGN KEY (group_id) REFERENCES leader_groups(id),
  CONSTRAINT fk_leaders_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE awards (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  year SMALLINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL COMMENT 'award name, e.g. Volunteer of the Year',
  recipient VARCHAR(160) NOT NULL,
  photo_media_id INT UNSIGNED NULL,
  citation MEDIUMTEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'published',
  publish_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_awards_year (year, sort),
  CONSTRAINT fk_awards_photo FOREIGN KEY (photo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  question VARCHAR(300) NOT NULL,
  answer MEDIUMTEXT NULL,
  category VARCHAR(80) NOT NULL DEFAULT '',
  sort INT NOT NULL DEFAULT 0,
  visibility ENUM('public','members') NOT NULL DEFAULT 'public',
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'published',
  publish_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_faqs_cat (category, sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE branches (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(160) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  region VARCHAR(120) NOT NULL DEFAULT '',
  contact_person VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  meeting_venue VARCHAR(300) NULL,
  description MEDIUMTEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'published',
  publish_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_branches_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE groups_orgs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NULL COMMENT 'linked group account (phase 4 sign-up)',
  group_number VARCHAR(40) NULL,
  name VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  contact_person VARCHAR(160) NOT NULL DEFAULT '',
  email VARCHAR(190) NULL,
  phone VARCHAR(40) NULL,
  address VARCHAR(300) NULL,
  country VARCHAR(80) NULL,
  website VARCHAR(500) NULL,
  logo_media_id INT UNSIGNED NULL,
  description MEDIUMTEXT NULL,
  sort INT NOT NULL DEFAULT 0,
  status ENUM('active','hidden','suspended') NOT NULL DEFAULT 'active',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_groups_slug (slug),
  UNIQUE KEY uq_groups_number (group_number),
  CONSTRAINT fk_groups_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_groups_logo FOREIGN KEY (logo_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
