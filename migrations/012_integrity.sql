-- Review fix R3: deleting a comment that has replies blanks it ("[deleted]")
-- instead of removing other members' replies with it; replies of a comment
-- that is removed after all become top-level rather than disappearing.
ALTER TABLE comments ADD COLUMN deleted_at DATETIME NULL AFTER status;
ALTER TABLE comments DROP FOREIGN KEY fk_comments_parent;
ALTER TABLE comments ADD CONSTRAINT fk_comments_parent_keep FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE SET NULL;

-- Review fix R7: job rejections are counted on the account, so deleting a
-- rejected post does not make the poster "trusted" again.
ALTER TABLE users ADD COLUMN job_rejections SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER session_version;
UPDATE users u SET job_rejections = (SELECT COUNT(*) FROM jobs j WHERE j.user_id = u.id AND j.status = 'rejected');
