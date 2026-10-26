-- Addendum B2: "Log in as this member" (impersonation).

-- Actions taken while an admin views the site as a member are recorded with
-- the admin as the actor (user_id) and the member in on_behalf_of.
ALTER TABLE audit_log
  ADD COLUMN on_behalf_of INT UNSIGNED NULL AFTER user_id,
  ADD CONSTRAINT fk_audit_on_behalf FOREIGN KEY (on_behalf_of) REFERENCES users(id) ON DELETE SET NULL;

-- One row per impersonation session: who, whom, why, from where and how long.
-- end_reason: returned | expired | logout | admin_session_ended |
-- permission_removed | member_unavailable
CREATE TABLE impersonations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  reason VARCHAR(255) NOT NULL DEFAULT '',
  allow_changes TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NOT NULL DEFAULT '',
  started_at DATETIME NOT NULL,
  expires_at DATETIME NOT NULL,
  ended_at DATETIME NULL,
  end_reason VARCHAR(30) NOT NULL DEFAULT '',
  KEY ix_impersonations_admin (admin_id, started_at),
  KEY ix_impersonations_user (user_id, started_at),
  CONSTRAINT fk_impersonations_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_impersonations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
