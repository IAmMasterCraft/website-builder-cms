-- Review fixes R4/R6/R10: queued mail is re-checked when it is sent.
-- 'suppressed' = not sent because the address unsubscribed, opted out, or the
-- account was suspended/deleted after the email was queued.
ALTER TABLE email_queue MODIFY status ENUM('queued','sending','sent','failed','suppressed') NOT NULL DEFAULT 'queued';
ALTER TABLE campaign_recipients MODIFY status ENUM('queued','sending','sent','failed','suppressed') NOT NULL DEFAULT 'queued';

-- Rows a crashed run left in "sending" may or may not have been delivered:
-- they are flagged so "retry failed recipients" never sends them again.
ALTER TABLE email_queue ADD COLUMN interrupted TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
ALTER TABLE campaign_recipients ADD COLUMN interrupted TINYINT(1) NOT NULL DEFAULT 0 AFTER status;

-- The subject is frozen with the HTML/text snapshot when a campaign is queued.
ALTER TABLE campaigns ADD COLUMN subject_snapshot VARCHAR(200) NULL AFTER html_snapshot;
