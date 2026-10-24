-- Review fix S4/R13: every login stores the account's session_version in the
-- session; bumping it (password reset or change, suspension, email
-- verification, email change) ends every other session of that account.
ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER status;

-- Review fix S6: login throttling also counts failures per email across IPs.
ALTER TABLE login_attempts ADD KEY ix_login_attempts_email (email, attempted_at);
