-- Pages (builder-ready), revisions, section templates, menus.
-- Phase 2 pages use `body`; phase 3 fills `sections` (JSON list of
-- {type, settings, style}) and page_revisions for drafts/publish/restore.
CREATE TABLE pages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  slug VARCHAR(200) NOT NULL,
  summary TEXT NULL,
  body MEDIUMTEXT NULL COMMENT 'simple rich-text body (used when sections is NULL)',
  sections LONGTEXT NULL COMMENT 'published sections JSON (phase 3)',
  cover_media_id INT UNSIGNED NULL,
  status ENUM('draft','published','scheduled') NOT NULL DEFAULT 'draft',
  publish_at DATETIME NULL,
  visibility ENUM('public','members','hidden') NOT NULL DEFAULT 'public',
  seo_title VARCHAR(200) NULL,
  seo_description VARCHAR(300) NULL,
  template VARCHAR(40) NULL COMMENT 'starter template key it was created from',
  revision INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'optimistic lock counter',
  draft_revision_id INT UNSIGNED NULL,
  published_revision_id INT UNSIGNED NULL,
  author_id INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pages_slug (slug),
  KEY ix_pages_pub (status, publish_at),
  FULLTEXT KEY ft_pages (title, summary, body),
  CONSTRAINT fk_pages_cover FOREIGN KEY (cover_media_id) REFERENCES media(id) ON DELETE SET NULL,
  CONSTRAINT fk_pages_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pages_updated_by FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE page_revisions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  page_id INT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  sections LONGTEXT NULL,
  body MEDIUMTEXT NULL,
  author_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_revisions_page (page_id, revision),
  CONSTRAINT fk_revisions_page FOREIGN KEY (page_id) REFERENCES pages(id) ON DELETE CASCADE,
  CONSTRAINT fk_revisions_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE section_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  kind ENUM('section','page') NOT NULL DEFAULT 'section',
  sections LONGTEXT NOT NULL,
  is_starter TINYINT(1) NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_templates_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menus (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(80) NOT NULL,
  location VARCHAR(40) NULL COMMENT 'main, topbar, footer_1..footer_4 or NULL (unassigned)',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_menus_location (location)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE menu_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  menu_id INT UNSIGNED NOT NULL,
  parent_id INT UNSIGNED NULL,
  label VARCHAR(120) NOT NULL,
  target_type ENUM('page','list','item','url') NOT NULL,
  target_ref VARCHAR(40) NULL COMMENT 'list key (news, events...) or item type (post, event...)',
  target_id INT UNSIGNED NULL,
  url VARCHAR(500) NULL,
  open_new_tab TINYINT(1) NOT NULL DEFAULT 0,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  is_broken TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'set when the linked page/item is deleted',
  sort INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_menu_items (menu_id, parent_id, sort),
  KEY ix_menu_targets (target_type, target_ref, target_id),
  CONSTRAINT fk_items_menu FOREIGN KEY (menu_id) REFERENCES menus(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_parent FOREIGN KEY (parent_id) REFERENCES menu_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
