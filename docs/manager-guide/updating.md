# Updating the site

New versions of Gather fix problems and add features. Updating replaces the
program files only. Your content, members, settings, uploads and design are
kept.

This page is for the website manager who coordinates an update. The exact
commands depend on the hosting and are in
[Getting the code → Updating later](../getting-the-code.md#updating-later)
and in each install guide's "Updating" section.

## Before

1. **Read what changed** in the new version (the release notes on GitHub).
2. **Pick a quiet time**. An update takes a few minutes, and the site may
   briefly show errors while files are replaced.
3. **Make a backup**: **Admin → Backups**, tick **Include uploaded files**,
   then **Back up to the server now**. Download it too. See
   [Backups](backups.md).
4. Check that no campaign is sending right now (**Admin → Campaigns**).

## During

Your technical person (or you, following the install guide):

1. Puts the new files in place: extracts the new package over the old one,
   or runs `git pull` / cPanel **Update from Remote**.
2. Runs the database update: `php bin/migrate`.

Three things must never be overwritten or deleted: `config.php`, the
`storage/` folder and `public/uploads/`. The update packages do not contain
them, so a normal update is safe. Never delete the whole site folder and
upload a fresh copy.

## After

1. **Admin → System health**: **Migrations** must say *none pending*, and
   nothing new should be marked **Problem**. Check the upload limits too.
2. Open the public site on a computer and a phone: home page, an event, the
   join page.
3. Log in as a member (or ask one) and check the dashboard.
4. Send a test email (**Email settings → Send a test email**).

If something is wrong, look at [Troubleshooting](troubleshooting.md). As a
last resort, put the previous version's files back and restore the backup
you made.
