-- Addendum C4: cascading location fields. One row per member and "slot"
-- (residence, nationality, origin, cf<custom field id>): ISO country code,
-- GeoNames first/second-level codes and a snapshot of the display names.
-- A code is NULL when the level was typed as free text (no list for it).
CREATE TABLE member_locations (
  member_id INT UNSIGNED NOT NULL,
  slot VARCHAR(40) NOT NULL,
  country_code CHAR(2) NULL,
  country_name VARCHAR(80) NULL,
  admin1_code VARCHAR(20) NULL,
  admin1_name VARCHAR(120) NULL,
  admin2_code VARCHAR(30) NULL,
  admin2_name VARCHAR(120) NULL,
  locality VARCHAR(80) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id, slot),
  KEY ix_member_locations_place (slot, country_code, admin1_code, admin2_code),
  CONSTRAINT fk_member_locations_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Local areas the admins add where the bundled list is missing one (Location lists screen).
CREATE TABLE geo_custom_areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  country_code CHAR(2) NOT NULL,
  admin1_code VARCHAR(20) NOT NULL,
  name VARCHAR(120) NOT NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_geo_custom_areas (country_code, admin1_code, name),
  CONSTRAINT fk_geo_custom_areas_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Custom fields gain the "location" type; its settings (levels, fixed country, labels) are JSON.
ALTER TABLE custom_fields
  MODIFY type ENUM('text','select','date','checkbox','location') NOT NULL DEFAULT 'text',
  ADD COLUMN field_config LONGTEXT NULL COMMENT 'JSON' AFTER options;

-- What members had typed before the upgrade (nationality, country, region, city), kept so nothing is lost.
ALTER TABLE members
  ADD COLUMN location_legacy TEXT NULL COMMENT 'JSON' AFTER city;
