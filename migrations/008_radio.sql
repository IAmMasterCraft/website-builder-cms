-- Phase 6: online radio (§6). Audio/video files live in the media table
-- (kind audio/video, duration measured in the admin's browser at upload).
CREATE TABLE radio_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_radio_categories_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO radio_categories (name, slug, sort) VALUES ('News', 'news', 1), ('Music', 'music', 2), ('Talk', 'talk', 3);

CREATE TABLE radio_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  media_id INT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  artist VARCHAR(160) NOT NULL DEFAULT '',
  description TEXT NULL,
  cover_media_id INT UNSIGNED NULL,
  category_id INT UNSIGNED NULL,
  duration DECIMAL(10,3) NULL COMMENT 'seconds, measured in the browser; NULL = unknown (cannot be scheduled)',
  on_demand TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'listed in the public library',
  publish_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_radio_media_pub (on_demand, publish_at),
  FULLTEXT KEY ft_radio_media (title, artist, description),
  CONSTRAINT fk_radio_media_media FOREIGN KEY (media_id) REFERENCES media(id),
  CONSTRAINT fk_radio_media_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_radio_media_category FOREIGN KEY (category_id) REFERENCES radio_categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_radio_media_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE radio_playlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  description VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE radio_playlist_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  playlist_id INT UNSIGNED NOT NULL,
  radio_media_id INT UNSIGNED NOT NULL,
  sort INT NOT NULL DEFAULT 0,
  KEY ix_rpi_playlist (playlist_id, sort),
  CONSTRAINT fk_rpi_playlist FOREIGN KEY (playlist_id) REFERENCES radio_playlists(id) ON DELETE CASCADE,
  CONSTRAINT fk_rpi_media FOREIGN KEY (radio_media_id) REFERENCES radio_media(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A slot plays one media item or one playlist from start_at (UTC) for
-- duration seconds. Repeats keep the same local wall-clock time in the
-- site time zone; every occurrence is compared in UTC.
CREATE TABLE radio_slots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(160) NOT NULL DEFAULT '',
  start_at DATETIME NOT NULL,
  duration INT UNSIGNED NOT NULL,
  repeat_rule ENUM('none','daily','weekly') NOT NULL DEFAULT 'none',
  days_mask TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'weekly: bit 0 = Monday ... bit 6 = Sunday',
  repeat_until DATE NULL,
  media_id INT UNSIGNED NULL COMMENT 'radio_media.id',
  playlist_id INT UNSIGNED NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_radio_slots_start (start_at),
  CONSTRAINT fk_radio_slots_media FOREIGN KEY (media_id) REFERENCES radio_media(id),
  CONSTRAINT fk_radio_slots_playlist FOREIGN KEY (playlist_id) REFERENCES radio_playlists(id),
  CONSTRAINT fk_radio_slots_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
