-- Addendum A2: newsletter opt-in can be changed later.
-- When a member dismissed the "Get news and event updates" dashboard card
-- (it comes back at most every 90 days while they have not opted in).
ALTER TABLE members ADD COLUMN optin_prompt_dismissed_at DATETIME NULL AFTER prefs;

-- "Essential membership notice": sent to every active member in the audience
-- regardless of the newsletter preference (never to suppressed addresses).
ALTER TABLE campaigns ADD COLUMN essential TINYINT(1) NOT NULL DEFAULT 0 AFTER audience;
