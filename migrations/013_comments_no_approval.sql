-- Addendum A1: comments from active members are published straight away.
-- The pre/post moderation setting is removed; reported comments go to a
-- "Reports" queue where an admin can hide them, and a comment reported by
-- 3 different members is hidden automatically until an admin reviews it.
DELETE FROM settings WHERE `key` = 'comments.moderation';

-- Comments that were waiting for approval are published (under the new rules
-- they would have been); rejected comments become hidden.
ALTER TABLE comments MODIFY status ENUM('pending','approved','rejected','reported','hidden') NOT NULL DEFAULT 'approved';
UPDATE comments SET status = 'approved' WHERE status IN ('pending','reported');
UPDATE comments SET status = 'hidden' WHERE status = 'rejected';
ALTER TABLE comments MODIFY status ENUM('approved','hidden') NOT NULL DEFAULT 'approved';

-- Why a comment is hidden: 'moderator' (an admin hid it) or 'reports'
-- (hidden automatically after reports from 3 different members).
ALTER TABLE comments ADD COLUMN hidden_reason VARCHAR(20) NULL AFTER status;
UPDATE comments SET hidden_reason = 'moderator' WHERE status = 'hidden';
