-- Addendum C3 security review fixes.

-- Messages sent before someone joined a group stay hidden from them:
-- they see messages with id >= first_visible_id (0 = everything, which is
-- what creators, first participants and every existing row get).
ALTER TABLE conversation_participants
  ADD COLUMN first_visible_id BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER joined_at;

-- An announcement group whose last admin left is closed (read-only).
ALTER TABLE conversations
  ADD COLUMN archived_at DATETIME NULL AFTER team_notified_message_id;

-- "Others can find me in messages" (members and groups; staff are always findable).
ALTER TABLE users
  ADD COLUMN message_findable TINYINT(1) NOT NULL DEFAULT 1 AFTER message_emails;
