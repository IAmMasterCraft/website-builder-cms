-- Phase 7: super admin tools (§7) and members changing their own email.
-- A member's new address waits here until the link sent to it is confirmed.
ALTER TABLE users ADD COLUMN pending_email VARCHAR(190) NULL AFTER email;

-- Audit log screen filters (who, what kind of item, which action, when).
ALTER TABLE audit_log
  ADD KEY ix_audit_user (user_id, created_at),
  ADD KEY ix_audit_item (item_type, created_at),
  ADD KEY ix_audit_action (action, created_at);
