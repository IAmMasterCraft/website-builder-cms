-- Membership (phase 4): sign-up verification, member numbers that are never
-- reused, admin notes, deletion requests.
ALTER TABLE members
  ADD COLUMN consent_at DATETIME NULL AFTER prefs,
  ADD COLUMN deletion_requested_at DATETIME NULL AFTER verified_at,
  ADD KEY ix_members_type (membership_type),
  ADD KEY ix_members_region (region);

ALTER TABLE groups_orgs
  ADD COLUMN verified_at DATETIME NULL AFTER status;

-- Single-use links sent by email (email verification).
CREATE TABLE user_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  purpose VARCHAR(20) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_tokens_hash (token_hash),
  KEY ix_user_tokens_user (user_id, purpose),
  CONSTRAINT fk_user_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Counters per scope (member/group) and period (year, or "all" when the
-- format has no year). Only ever incremented.
CREATE TABLE number_sequences (
  scope VARCHAR(10) NOT NULL,
  period VARCHAR(10) NOT NULL,
  `last_value` INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (scope, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every number ever issued, kept after the member is deleted, so a number
-- is never given to anyone else (§4.3, §12).
CREATE TABLE issued_numbers (
  number VARCHAR(40) NOT NULL PRIMARY KEY,
  scope VARCHAR(10) NOT NULL,
  issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE member_notes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  member_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_member_notes (member_id, created_at),
  CONSTRAINT fk_member_notes_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
  CONSTRAINT fk_member_notes_author FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Group accounts can have custom field values too.
CREATE TABLE group_field_values (
  group_id INT UNSIGNED NOT NULL,
  field_id INT UNSIGNED NOT NULL,
  value TEXT NULL,
  PRIMARY KEY (group_id, field_id),
  CONSTRAINT fk_gfv_group FOREIGN KEY (group_id) REFERENCES groups_orgs(id) ON DELETE CASCADE,
  CONSTRAINT fk_gfv_field FOREIGN KEY (field_id) REFERENCES custom_fields(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
