-- Addendum C3: direct messages between members and admins (1-to-1, groups,
-- admin announcement groups and the shared "Team inbox").
--
-- kind: direct (two people), group (group, up to 20 people), announcement
-- (admins post, everyone else reads), team (a member writing to the
-- organisation; answered by any admin with the messages permission).
-- pair_key keeps one conversation per pair of people ("d:<low id>:<high id>")
-- and one Team inbox thread per member ("t:<member user id>").
CREATE TABLE conversations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('direct','group','announcement','team') NOT NULL DEFAULT 'direct',
  title VARCHAR(120) NULL,
  pair_key VARCHAR(40) NULL,
  created_by INT UNSIGNED NULL,
  last_message_id BIGINT UNSIGNED NULL,
  last_message_at DATETIME NULL,
  -- Team inbox: shared read marker and digest marker for the admins.
  team_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  team_notified_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_conversations_pair (pair_key),
  KEY ix_conversations_last (kind, last_message_at),
  CONSTRAINT fk_conversations_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- part_role: owner (created the group; may add and remove people) or member.
-- last_read_message_id: everything up to this id has been read.
-- notified_message_id: the email digest covered messages up to this id.
CREATE TABLE conversation_participants (
  conversation_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  part_role ENUM('owner','member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_read_at DATETIME NULL,
  notified_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  muted TINYINT(1) NOT NULL DEFAULT 0,
  left_at DATETIME NULL,
  PRIMARY KEY (conversation_id, user_id),
  KEY ix_participants_user (user_id, left_at),
  CONSTRAINT fk_participants_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_participants_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Plain text (escaped and auto-linked when shown). note_kind marks the short
-- lines Gather writes itself ("Ada added Ben"); deleted messages keep their
-- row with an empty body ("message deleted"). sender_id becomes NULL when
-- the account is deleted (shown as "Former member").
CREATE TABLE messages (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  conversation_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NULL,
  body TEXT NOT NULL,
  note_kind VARCHAR(20) NULL,
  attachment_media_id INT UNSIGNED NULL,
  edited_at DATETIME NULL,
  deleted_at DATETIME NULL,
  deleted_by VARCHAR(20) NULL COMMENT 'sender | moderator | account | retention',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_messages_conversation (conversation_id, id),
  KEY ix_messages_changed (conversation_id, updated_at),
  KEY ix_messages_sender (sender_id, created_at),
  KEY ix_messages_created (created_at),
  CONSTRAINT fk_messages_conversation FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
  CONSTRAINT fk_messages_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_messages_attachment FOREIGN KEY (attachment_media_id) REFERENCES media(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "Block": the blocked account cannot message the blocker, and the blocker
-- no longer sees their 1-to-1 conversation.
CREATE TABLE message_blocks (
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (blocker_id, blocked_id),
  KEY ix_message_blocks_blocked (blocked_id),
  CONSTRAINT fk_message_blocks_blocker FOREIGN KEY (blocker_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_blocks_blocked FOREIGN KEY (blocked_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reports go to the admin Reports queue (messages permission).
-- outcome: kept | removed (set when an admin resolves the report).
CREATE TABLE message_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id BIGINT UNSIGNED NOT NULL,
  reporter_id INT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at DATETIME NULL,
  resolved_by INT UNSIGNED NULL,
  outcome VARCHAR(20) NOT NULL DEFAULT '',
  UNIQUE KEY uq_message_reports (message_id, reporter_id),
  KEY ix_message_reports_open (resolved_at, created_at),
  CONSTRAINT fk_message_reports_message FOREIGN KEY (message_id) REFERENCES messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_message_reports_reporter FOREIGN KEY (reporter_id) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_message_reports_admin FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Email preference "Message notifications" (members, groups and staff; on by default).
ALTER TABLE users
  ADD COLUMN message_emails TINYINT(1) NOT NULL DEFAULT 1 AFTER display_name;
