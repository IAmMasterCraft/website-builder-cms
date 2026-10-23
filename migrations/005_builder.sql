-- Page builder (phase 3): revision bookkeeping and saved templates.
-- pages.sections holds the live sections; pages.draft_revision_id points at
-- the revision being edited; page_revisions keeps the last 20 per page.
ALTER TABLE page_revisions
  ADD COLUMN updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at,
  ADD COLUMN published_at DATETIME NULL AFTER updated_at,
  ADD COLUMN note VARCHAR(120) NOT NULL DEFAULT '' AFTER published_at;

ALTER TABLE section_templates
  ADD COLUMN description VARCHAR(255) NOT NULL DEFAULT '' AFTER name;

-- Messages sent with the contact-form section (inbox screen arrives in phase 5).
ALTER TABLE contact_messages
  ADD COLUMN phone VARCHAR(40) NOT NULL DEFAULT '' AFTER email,
  ADD COLUMN page_id INT UNSIGNED NULL AFTER body,
  ADD COLUMN ip VARCHAR(45) NOT NULL DEFAULT '' AFTER page_id;
