-- Phase 5: communications (§5), birthdays (§4.5), jobs (§4.6), likes and
-- comments (§4.7).

-- Editable transactional templates. Defaults live in code
-- (src/lib/email_templates.php); a row here overrides subject/body/button.
CREATE TABLE email_templates (
  template_key VARCHAR(40) NOT NULL PRIMARY KEY,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  button_label VARCHAR(60) NOT NULL DEFAULT '',
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_email_templates_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ready-to-send messages (notice copies, birthday emails, transactional mail
-- that failed on the first try). Campaign recipients have their own table.
CREATE TABLE email_queue (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind VARCHAR(20) NOT NULL DEFAULT 'transactional',
  dedupe_key VARCHAR(190) NULL COMMENT 'set for messages that must never be queued twice',
  to_email VARCHAR(190) NOT NULL,
  to_name VARCHAR(120) NOT NULL DEFAULT '',
  subject VARCHAR(250) NOT NULL,
  text_body MEDIUMTEXT NOT NULL,
  html_body MEDIUMTEXT NULL,
  headers TEXT NULL COMMENT 'JSON',
  reply_to VARCHAR(190) NULL,
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  last_error VARCHAR(500) NOT NULL DEFAULT '',
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_queue_dedupe (dedupe_key),
  KEY ix_email_queue_due (status, next_attempt_at),
  KEY ix_email_queue_sent (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Addresses that unsubscribed (also covers groups, admins and guests, who
-- have no member preferences).
CREATE TABLE email_suppressions (
  email VARCHAR(190) NOT NULL PRIMARY KEY,
  reason VARCHAR(40) NOT NULL DEFAULT 'unsubscribe',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE campaigns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  subject VARCHAR(200) NOT NULL,
  preheader VARCHAR(200) NOT NULL DEFAULT '',
  blocks MEDIUMTEXT NULL COMMENT 'JSON list of email blocks',
  audience TEXT NULL COMMENT 'JSON audience definition',
  status ENUM('draft','scheduled','sending','sent','cancelled') NOT NULL DEFAULT 'draft',
  send_at DATETIME NULL,
  queued_at DATETIME NULL,
  completed_at DATETIME NULL,
  html_snapshot MEDIUMTEXT NULL COMMENT 'rendered once when queued, so every recipient gets the same content',
  text_snapshot MEDIUMTEXT NULL,
  recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_campaigns_status (status, send_at),
  CONSTRAINT fk_campaigns_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One row per recipient; the unique key means a recipient can never get
-- the same campaign twice, even if queuing runs again (§12).
CREATE TABLE campaign_recipients (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  campaign_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT '',
  first_name VARCHAR(80) NOT NULL DEFAULT '',
  status ENUM('queued','sending','sent','failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error VARCHAR(500) NOT NULL DEFAULT '',
  next_attempt_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  claimed_at DATETIME NULL,
  sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_campaign_recipient (campaign_id, email),
  KEY ix_campaign_recipients_due (status, next_attempt_at),
  KEY ix_campaign_recipients_sent (sent_at),
  CONSTRAINT fk_cr_campaign FOREIGN KEY (campaign_id) REFERENCES campaigns(id) ON DELETE CASCADE,
  CONSTRAINT fk_cr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notices (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(200) NOT NULL,
  body MEDIUMTEXT NULL,
  audience TEXT NULL COMMENT 'JSON audience definition',
  email_copy TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  emailed_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY ix_notices_pub (status, published_at),
  CONSTRAINT fk_notices_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notice_reads (
  notice_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (notice_id, user_id),
  CONSTRAINT fk_nr_notice FOREIGN KEY (notice_id) REFERENCES notices(id) ON DELETE CASCADE,
  CONSTRAINT fk_nr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_replies (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  subject VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  sent_ok TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_contact_replies (message_id),
  CONSTRAINT fk_contact_replies_msg FOREIGN KEY (message_id) REFERENCES contact_messages(id) ON DELETE CASCADE,
  CONSTRAINT fk_contact_replies_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One greeting per member per year (§4.5), even if cron runs twice.
CREATE TABLE birthday_sends (
  member_id INT UNSIGNED NOT NULL,
  year SMALLINT UNSIGNED NOT NULL,
  sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (member_id, year),
  CONSTRAINT fk_birthday_sends_member FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Jobs: expiry, review trail.
ALTER TABLE jobs
  ADD COLUMN expires_at DATETIME NULL AFTER published_at,
  ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER expires_at,
  ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
  ADD COLUMN reject_reason VARCHAR(500) NOT NULL DEFAULT '' AFTER reviewed_at,
  ADD KEY ix_jobs_user (user_id),
  ADD KEY ix_jobs_expiry (status, expires_at);

ALTER TABLE job_reports
  ADD COLUMN resolved_at DATETIME NULL AFTER reason,
  ADD UNIQUE KEY uq_job_reports (job_id, user_id);

-- Comments: edit window and reply notification.
ALTER TABLE comments
  ADD COLUMN edited_at DATETIME NULL AFTER status,
  ADD COLUMN reply_notified TINYINT(1) NOT NULL DEFAULT 0 AFTER edited_at,
  ADD COLUMN reviewed_by INT UNSIGNED NULL AFTER reply_notified,
  ADD KEY ix_comments_parent (parent_id);

CREATE TABLE comment_reports (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  comment_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_comment_reports (comment_id, user_id),
  CONSTRAINT fk_comment_reports_comment FOREIGN KEY (comment_id) REFERENCES comments(id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
