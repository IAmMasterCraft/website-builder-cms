-- Addendum B2 review: impersonation records keep their evidence when an
-- account is deleted. The ids stay (no ON DELETE SET NULL), and the names
-- and addresses at the time are stored with each impersonation.
-- notified: sent | off | skipped | failed (the member notice email).
ALTER TABLE audit_log DROP FOREIGN KEY fk_audit_on_behalf;

ALTER TABLE impersonations
  DROP FOREIGN KEY fk_impersonations_admin,
  DROP FOREIGN KEY fk_impersonations_user,
  ADD COLUMN admin_name VARCHAR(120) NOT NULL DEFAULT '' AFTER admin_id,
  ADD COLUMN admin_email VARCHAR(190) NOT NULL DEFAULT '' AFTER admin_name,
  ADD COLUMN user_name VARCHAR(200) NOT NULL DEFAULT '' AFTER user_id,
  ADD COLUMN user_email VARCHAR(190) NOT NULL DEFAULT '' AFTER user_name,
  ADD COLUMN notified VARCHAR(10) NOT NULL DEFAULT '' AFTER allow_changes;
