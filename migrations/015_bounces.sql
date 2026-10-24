-- Addendum A5: bounce and complaint handling.

-- Suppression list: why, where it came from, the last diagnostic.
-- reason: unsubscribe | complaint | hard_bounce | soft_bounce
ALTER TABLE email_suppressions
  ADD COLUMN detail VARCHAR(500) NOT NULL DEFAULT '' AFTER reason,
  ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT '' AFTER detail,
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD KEY ix_suppressions_reason (reason, created_at);

-- Every bounce or complaint we learn about (SMTP rejection, DSN, ARF report,
-- provider webhook). ext_id makes reprocessing the same report a no-op.
CREATE TABLE email_events (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  type ENUM('hard','soft','block','complaint') NOT NULL,
  status_code VARCHAR(12) NOT NULL DEFAULT '' COMMENT 'enhanced status, e.g. 5.1.1',
  smtp_code SMALLINT UNSIGNED NULL,
  detail VARCHAR(500) NOT NULL DEFAULT '',
  source VARCHAR(20) NOT NULL COMMENT 'smtp, dsn, arf, ses, mailgun, postmark, sendgrid, brevo',
  queue_table ENUM('email_queue','campaign_recipients') NULL,
  queue_id BIGINT UNSIGNED NULL,
  campaign_id INT UNSIGNED NULL,
  ext_id VARCHAR(190) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_events_ext (ext_id),
  KEY ix_email_events_email (email, type, created_at),
  KEY ix_email_events_time (created_at, type),
  KEY ix_email_events_campaign (campaign_id, type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Outcome after delivery to our relay (shown in the delivery logs).
ALTER TABLE campaign_recipients
  ADD COLUMN bounce ENUM('soft','hard','block','complaint') NULL AFTER interrupted,
  ADD COLUMN bounced_at DATETIME NULL AFTER bounce,
  ADD KEY ix_cr_email (email, sent_at);
ALTER TABLE email_queue
  ADD COLUMN bounce ENUM('soft','hard','block','complaint') NULL AFTER interrupted,
  ADD COLUMN bounced_at DATETIME NULL AFTER bounce,
  ADD KEY ix_eq_email (to_email, sent_at);

-- A policy/spam block pauses the campaign until an admin resumes it.
ALTER TABLE campaigns
  MODIFY status ENUM('draft','scheduled','sending','paused','sent','cancelled') NOT NULL DEFAULT 'draft',
  ADD COLUMN pause_reason VARCHAR(500) NOT NULL DEFAULT '' AFTER status;

-- MX/A lookup cache for list hygiene (sign-up, import, campaign queueing).
-- status: ok | none (the domain cannot receive mail)
CREATE TABLE email_domains (
  domain VARCHAR(190) NOT NULL PRIMARY KEY,
  status ENUM('ok','none') NOT NULL,
  checked_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
