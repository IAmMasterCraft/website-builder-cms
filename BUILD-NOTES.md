# Build notes

## Install and run locally

See README.md. Short version: `php -S 127.0.0.1:8080 -t public public/index.php`
and open `/install`, or copy `config.example.php` to `config.php`, then
`php bin/migrate`, `php bin/make-admin`, `php bin/demo`. Run `bin/check` for
lint + tests + smoke test (needs `tests/config.test.php` pointing at an empty
database).

Local development database (`gather_dev`, http://localhost:8080): the super
admin is **admin@example.org** with the password **Harbourside-admin-2026**
(local demo only; set with `GATHER_ADMIN_PASSWORD=… php bin/make-admin
admin@example.org`). The demo member amara.okafor@example.org has the
password member-demo-2026 (used by `dev-tools/audit.mjs`). Re-seeding: drop
the tables, `php bin/migrate`, `bin/make-admin`, `php bin/demo
admin@example.org`, then pick a theme on the Design screen (the dev site
uses Bold).

## Phases done

**Phase 1: skeleton**
- `public/index.php` front controller, `.htaccess` (clean URLs, deny rules, no
  PHP in `public/uploads/`), `Require all denied` in every non-public folder.
- `src/bootstrap.php` (errors to log, strict session cookie, CSP and security
  headers), pattern router, PDO helpers (prepared statements only, UTC session).
- Views with `e()`, layouts, flash/old-input, PRG; CSRF on every POST (419).
- Session auth for one `users` table with roles super_admin/admin/member/group;
  admin permission checks (8 permission keys, super admin has all); Argon2id;
  lockout after 10 failures per IP+email (also 50 per IP, 30 per email) for
  15 min; session regeneration; session version per account (see "Code
  review fixes"); login, logout (POST), forgot password, single-use 1-hour
  reset tokens.
- Migrations (`migrations/001..004`) + `bin/migrate`; `bin/make-admin`;
  `bin/cron` dispatcher (publish scheduled items, cleanup; slots for later jobs).
- `/install` wizard (needs a one-time install token, see "Code review
  fixes"): DB check → writes `config.php` (`installed => false`) →
  migrations → super admin → theme → optional demo content → sets
  `installed => true` (all `/install` routes then 404). Later steps only work
  for the browser session that wrote the config.
- Admin shell: collapsible sidebar filtered by permission, top bar (site name,
  View site, content search, notification count, light/dark toggle, profile),
  overview with real counts, today's birthdays, recent activity (audit log),
  quick actions, cron health.
- Design tokens + "Civic" theme (served at `/theme.css`), public layout with
  top bar (phone, email, charity number, social, login/join) and footer,
  designed 404/403/419/429 pages with search.
- Mail abstraction: `Core\Mail\Mailer` interface, `FileMailer` writes
  `storage/mail/*.eml`; `send_template_mail()` with templates in
  `templates/email/`. Add an `SmtpMailer` (vendored PHPMailer) in phase 5 and
  switch on `config['mail']['driver']`.

**Phase 2: content**
- Media library: MIME sniffing + extension allow-list + per-kind size limits;
  images decoded and re-encoded with GD (EXIF/GPS stripped, orientation
  applied), WebP variants 400/800/1600 (plus capped full size), originals in
  `storage/originals/`; members-only files and private variants are served by
  PHP after an access check (with byte-range support). Folders, search, alt
  text, "used on…", replace file, delete blocked while in use, JS picker.
- All §3.3 content types with admin CRUD driven by one registry
  (`src/lib/content.php`): posts (categories with news/blog/projects bases,
  tags, archive filters today/month/year/older, share buttons, likes/comments
  toggles stored), events (timezone-aware UTC storage, venue/map/online link,
  capacity, guest/member registration, tickets, deadline, countdown,
  today/upcoming/past, month/week/agenda calendar, per-event `.ics` and
  `/events.ics`), registrations (confirmation email, admin list, CSV export,
  check-in), albums + photos (upload many, captions/alt, reorder, lightbox,
  paginated), downloads (counter, members-only), leaders + leader groups (past
  leaders archive, per-field show/hide, social links), awards (by year), FAQs
  (accordion by category), branches, groups.
- Draft / published / scheduled with publish date on every type that has it;
  cron publishes and a once-a-minute lazy check covers a stopped cron.
- Pages with a simple rich-text body, visibility public/members/hidden, home
  page flag, optimistic-lock revision counter; `pages.sections`,
  `page_revisions` and `section_templates` exist for phase 3.
- Menu manager: menus assigned to locations (main, top bar, footer 1–4), two
  levels, targets page / content list / content item / URL, hide without
  deleting, reorder, broken-link flag when the target is deleted; drives the
  header, top bar and footer (default main menu until one is assigned).
- Site search (FULLTEXT with LIKE fallback; members see members-only results),
  RSS `/feed.xml`, `/sitemap.xml`, `/robots.txt`.
- Minimal "Site settings" page for the super admin (identity, contact, social,
  header/top bar toggles, logo, theme) so the header placeholder is editable.
- Demo content: a fictional "Harbourside Community Association" with abstract
  artwork generated by GD at install time (no third-party images).

**Phase 3: page builder, themes, design**
- 21 section types in `src/Sections/` (one class each: schema, optional `data()`
  query, template in `templates/sections/`): hero (image or slider), rich text
  (+ sidebar), text + image, feature columns, stats/counters (typed or counted
  live), call to action, latest posts (grid/list/carousel), upcoming events,
  event calendar, gallery (album or latest; grid/masonry/strip), leaders,
  awards, FAQ, downloads, branches/groups directory, radio player (off-air
  placeholder until the radio tables exist), birthday celebrants (opted-in
  members, first name + initial, never the year), donate, contact form + map +
  details, embed (allow-list: YouTube, Vimeo, Google Maps, Facebook page; X shows
  a link card), spacer/divider. Per-section style: background none/soft/brand/
  image, spacing compact/normal/roomy, width contained/full, optional anchor.
- `src/lib/builder.php`: schema-driven validation (types text, textarea,
  richtext, url, number, select, checkbox, media, repeater; `show_if`), section
  list repair (unknown types dropped, ids regenerated), rendering with a
  per-section error guard, revisions, templates, starter templates.
- Editor (`/admin/pages/{id}/builder`, `builder.js`): real page in an iframe
  (click a section to edit it; links disabled), section list with drag and
  drop plus arrow buttons and arrow keys on the handle (live-region
  announcements), thumbnail picker (SVG sprite `sections.svg`), duplicate,
  hide, delete, settings forms generated from each schema (rich text, media
  picker, repeaters), Content/Style tabs, desktop/tablet/mobile preview widths.
- Drafts: autosave (JSON POST with the CSRF header) updates one draft
  revision per editing session (new revision after a publish, a restore,
  another editor or a 30-minute pause). Publish copies the draft to
  `pages.sections`, stores search text in `pages.body` and publishes draft
  pages. History tab: last 20 revisions (live and draft never pruned) with
  preview and restore (restore = new draft). Optimistic lock on
  `pages.revision`: a stale save gets 409 "edited by X at hh:mm" and a reload
  banner; publish/restore/the page settings form check it too.
- Templates: save a section or a whole page; seven starter page templates
  (Home, About, Contact, Leaders, Gallery, Events, Membership) offered on
  "New page" ("Start from"); `/admin/templates` lists and deletes saved ones.
  Demo home, contact and membership pages are builder pages.
- Header and footer screen (`/admin/layout`): top bar, log in/join buttons,
  sticky header, "Listen live" (shown once the radio is on air, phase 6); up
  to four footer columns from widgets (about, menu, contact, latest news,
  social, donate, join button). Site settings gained donation details (bank
  text + up to three links) used by the Donate section and widget.
- Contact form (`POST /contact`): honeypot + signed time token + 5/hour per
  IP; stored in `contact_messages` and emailed to the organisation; the
  sender gets a short confirmation (without the message) only when they tick
  the box, at most 2 a day per address.
- Themes: Heritage, Harvest, Monochrome added (tokens, overrides, preview.png
  rendered from the demo site). Design screen (`/admin/design`, super admin):
  theme cards, brand and accent colours, heading/body fonts from a curated
  list (seven self-hosted OFL variable fonts + three system stacks, no
  third-party requests), corner radius, button style, live preview (CSS
  custom properties set on the preview iframe), WCAG contrast table that
  updates as you type and a warning flash when saving failing pairs. Only
  values that differ from the theme defaults are stored. Colours in
  `site.css` are now tokens (link, accent-strong/-text, inks on brand and
  dark bands), so custom colours keep readable text.

**Phase 4: membership**
- `/join`: all §4.1 fields, custom fields visible to members, square photo
  crop in the browser (`photo-crop.js`, drag/keys/zoom; server centre-crops
  when JavaScript is off), password, consent, newsletter opt-in; honeypot,
  signed time check (3 s to 2 h) and 10 sign-ups/hour per IP; optional
  "free for the first N members" banner. `/join/group` for associations
  (logo optional; group listed only after verification).
- Verification (D7): email link opens a confirm page; confirming activates
  the account, assigns the number, card code and "valid until", records the
  fee status, sends a welcome email and logs the user in. Unverified users
  can log in, see "check your email" (with resend) and edit their profile and
  password only. `bin/cron` purges unverified accounts (and photo files)
  after 14 days.
- Numbers: format `{PREFIX}-{YY}-{0000}` (also `{YYYY}`, 2-8 digit sequence),
  member and group prefixes, per-year sequence rows locked with `FOR UPDATE`,
  every issued number kept in `issued_numbers` and hand-typed numbers skipped,
  so numbers are never reused.
- `/account`: overview (card preview, coming events, news, birthdays today,
  birthday greeting, notices placeholder), profile + photo, password, ID card,
  my events with cancel, member downloads, notices (placeholder until phase
  5), email preferences (newsletters, birthday greeting, celebrants opt-in),
  data export (JSON) and deletion request (password-confirmed, emails super
  admins). Group accounts get a group profile and their group ID.
- ID card: front (logo, photo, name, number, type, valid until, up to two
  "show on card" custom fields) and back (contact, charity number, signature
  image or line, QR to `/verify/{number}/{code}`), theme colours, optional
  background image. PNG drawn on a canvas in the browser (`card.js`), CR80
  print page (named `@page`), admin A4 sheet of 8 (backs mirrored for
  duplex). QR codes from vendored qrcode-generator (MIT,
  `public/assets/vendor/qrcode-generator/`).
- `/verify/{number}/{code}`: valid / expired / not valid with name and photo
  only (photo through a code-checked route), rate limited, not cached.
- Admin Members (`members` permission): search, filters (status incl.
  expired/deletion requested, type, region, custom field value), edit all
  fields incl. admin-only custom fields, photo replace, suspend/reactivate,
  mark verified, remove (typed DELETE; registrations anonymised, comments kept
  as "former member", number retired), password link email, regenerate card
  code, expiry date, notes, CSV export (formula-safe) and CSV import (create,
  optional update, reserved numbers, row report, optional welcome email),
  bulk card printing. Custom fields screen (super admin): text/select/date/
  checkbox, required, shown on card, visible to members, member or group.
  Membership settings (super admin): prefixes, format (validated, live
  example), validity months, free-first-N, terms link, regions, gender
  options, membership types, card background and signature uploads.
- Group status in the Groups screen now suspends/restores the group login.
**Phase 5: communications and member extras**
- Mail: `Core\Mail\SmtpMailer` wraps vendored PHPMailer 6.9.3 (`vendor/phpmailer`,
  LGPL 2.1, unmodified, no Composer). Email settings (super admin,
  `/admin/settings/email`): file/SMTP driver, sender, host/port/encryption/user,
  password stored encrypted (libsodium secretbox keyed from `app_key`), emails
  per minute, birthday time, "send a test email". `FileMailer` stays the
  default for development and tests. A transactional email that fails is
  queued and retried by cron.
- Queue (`email_queue` + `campaign_recipients`): `bin/cron` sends batches up to
  the per-minute limit (counted over the last 60 s across both tables, so a
  second cron run in the same minute sends nothing extra). A row is claimed
  (queued -> sending) before sending; failures retry with back-off (5 min,
  30 min, 2 h, 6 h) and become `failed` after 5 attempts; an unreachable
  SMTP server pauses the queue instead (no attempts used); rows a crashed
  run left in `sending` are marked failed and flagged `interrupted`, never
  re-sent (not even by "Retry failed"). Bulk rows are re-checked against
  unsubscribes/preferences/account status when sent (`suppressed`). Unique (campaign, email) means a
  recipient can never get a campaign twice; queued rows resume.
- Campaigns (`/admin/campaigns`): block editor (heading, text, image, button,
  divider, latest posts, upcoming events) as a server-rendered form (add,
  move, duplicate, remove work without JS); `campaigns.js` autosaves (JSON +
  CSRF header), refreshes the live preview (iframe, desktop/mobile widths)
  and the recipient count. Audience picker per §5; test send; send now or
  schedule; HTML snapshot taken when queued; `{first_name}` and a signed
  per-address unsubscribe link filled per recipient; `List-Unsubscribe` +
  one-click headers; delivery log per campaign plus a global log for other
  queued mail. Emails use a responsive table layout in the active theme's
  colours (readable inks from the design tokens).
- Unsubscribe: `/unsubscribe/{b64(email)}.{hmac}`; GET shows a confirm button
  (mail scanners do not unsubscribe people), POST unsubscribes (also RFC 8058
  one-click). Effect: `email_suppressions` row + member `newsletters` off;
  switching newsletters on again lifts the suppression.
- Transactional templates (`/admin/email-templates`): 17 templates (verify,
  welcome with member number, group welcome, import welcome, password reset,
  event registration, job approved/rejected/pending, comment reply, birthday
  greeting, birthday digest, contact copy/message/reply, deletion request).
  Subject, plain-text body (blank line = paragraph) and button text are
  editable; placeholders are listed per template and unknown ones are
  rejected; preview with sample values, send me a test, reset to default.
  All existing flows (verify, welcome, reset, admin password link, event
  registration, contact, deletion) now use them; `templates/email/*.php`
  except the layout were removed.
- Notices (`/admin/notices`): rich-text announcement, same audiences, publish
  / take down, optional email copy queued once on first publish (honours
  preferences), read tracking (`notice_reads`; read counts in the admin list).
  Members see them on `/account/notices` and the dashboard widget with an
  unread badge; "mark as read" buttons, and `community.js` marks a notice read
  after it has been on screen for 1.5 s.
- Inbox (`/admin/inbox`): all/unread/replied, search, message page (marked read
  by a POST from JS, button without JS), reply by email (template "Reply from
  the inbox", Reply-To the organisation address), replies kept in
  `contact_replies`, delete.
- Birthdays (`birthday_run()`, cron `daily_at` = setting, default 07:00 site
  time): opted-in celebrants get the greeting (`birthday_sends` member+year
  insert is the idempotency check), admins with the members permission get a
  digest of today + next 7 days (once per local date via an `INSERT IGNORE`
  marker); 29 Feb -> 28 Feb in non-leap years. Admin overview shows today and
  the next 7 days; the member dashboard shows today plus "coming up this week"
  (opted-in celebrants only). `cron_is_due()` gained `daily_at` support.
- Jobs (D8): `/jobs` (members only unless the "public" setting is on, filters
  type/remote/search), `/jobs/{id}` (apply link/email, report once),
  `/account/jobs` (my posts with status, post, edit, remove; 5 posts a day).
  Posts wait for approval unless posted by staff or by a trusted member with
  the auto-approve setting on (trusted = at least one approved job and no
  rejected one). Owner edits of a live job go back to approval unless trusted.
  Expiry = end of the closing date (site time) or 60 days, whichever first:
  cron flips status, list queries also filter by `expires_at`. Admin queue
  (`/admin/jobs`): awaiting approval / reported / live / expired / rejected,
  approve, reject with reason (emailed), keep and close reports, delete,
  settings, post a job. Moderators get a "job awaiting approval" email.
- Likes and comments (D11; moderation changed by Addendum A1, see below):
  likes on posts and downloads (toggle, unique key,
  JSON or form post), comments on posts: plain text, members only,
  pre-moderated by default (setting: post-moderated; staff comments always
  published), one level of replies (a reply to a reply joins the thread),
  author can edit within 15 minutes (edits of approved comments go back to
  the queue when pre-moderated) or delete, report once per member, author
  emailed once when a reply is published, "Former member" after deletion.
  Moderation queue `/admin/comments` (awaiting / reported / published /
  rejected; approve, keep, reject, delete) under the content permission.
  Post cards show like and comment counts (batched with `engagement_preload`).
- Admin sidebar: Members (jobs, comments), Communications (campaigns,
  notices, inbox, email templates, delivery log), Radio; the active link is
  now the most specific match. Overview quick actions: new campaign, new
  notice, upload to radio; "needs attention" cards link to the queues.

**Phase 6: online radio**
- Tables `radio_categories`, `radio_media` (points at a `media` row; title,
  artist, description, cover, category, duration, on-demand flag, publish
  date), `radio_playlists`, `radio_playlist_items`, `radio_slots` (start UTC,
  duration, repeat none/daily/weekly + days mask, optional repeat-until,
  media or playlist).
- Admin (`/admin/radio`, radio permission): upload audio (mp3/m4a/ogg) or
  video (mp4/webm); `radio-admin.js` measures the duration in the browser
  (`URL.createObjectURL` + metadata) before the upload and posts it with the
  form; without JS (or an unreadable file) the duration stays unknown and the
  edit page offers "Measure duration" from the stored file. Unknown-duration
  media cannot be scheduled or used in a scheduled/loop playlist. Media used
  in a playlist or slot cannot be deleted (and the media library shows radio
  usage and refuses to replace radio files). Categories, station on/off,
  fallback loop playlist. Playlists: running order with move/remove/add (no
  JS needed); a change that would make a scheduled slot overlap is refused
  and rolled back. Schedule: week calendar (items placed by time with JS;
  a list per day without it), now/next strip, all slots, add/edit/delete with
  overlap validation (also a repeat against its own next repeat).
- `GET /api/radio/now-playing`: exactly `{status, server_time, media{id,
  title, artist, description, url, media_type, mime_type, duration,
  cover_image}, offset, remaining, next{type, title, artist, starts_in},
  next_check_in}`, computed from server time in UTC (injectable clock),
  `Cache-Control: public, max-age=1`. No duration-reporting endpoint exists.
- Player (`radio.js`, partial `partials/radio_player.php`, full on `/radio`,
  full or mini in the radio section): server-rendered current state, big
  "Tune in" button (autoplay rules), seeks to `offset + half the round trip`,
  corrects once more when playback actually starts, re-syncs every 30 s and
  seeks when drift > 2 s, reloads at item boundaries (`next_check_in`), swaps
  audio/video elements by media type, cover, progress, elapsed/total, up next
  with live countdown, volume (remembered), play/pause (resume = rejoin live),
  off-air state with the next show. Playing something from the library pauses
  the live player. Media are served by PHP with byte ranges (206).
- `/radio`: live player on top, on-demand library below (newest first,
  category chips, search, pagination, native audio/video controls,
  `preload="none"`). "Listen live" header button shows when the station is
  switched on and something is on air. CSP gained `media-src 'self' blob:`.
- Demo: six original radio items in `demo-assets/radio` (synthesised music,
  a talk segment read by the macOS speech synthesiser from text written for
  this project, a generated 15 s VP8 video), an all-day loop playlist, a daily
  "Midday magazine" slot and a weekly evening slot, station on; plus a notice,
  a draft campaign and two jobs. `demo-assets/make-radio-demo.php` rebuilds
  the files (macOS + an ffmpeg with libvpx).

**Phase 7: super admin, hardening, accessibility, deployment**
- Users and roles (`/admin/users`, super admin): list with search/status
  filter, create admins (email invitation with a 72-hour set-password link,
  or a password set now), edit name/email/role/status and the 8 permission
  keys, quick suspend/reactivate (a suspended admin is logged out on their
  next request), send a password link, recent actions per admin. Rule (§2):
  there is always at least one active super admin. `staff_apply_change()`
  locks the active super admin rows (`FOR UPDATE`) and refuses any change
  that would leave none (`LastSuperAdminException`); `bin/make-admin` goes
  through the same function. The screen also never lets anyone change their
  own role or status. No 2FA (D10).
- Audit log (`/admin/audit`): filters by person (or "System"), action, item
  type, date range (site time zone) and summary text, paging, CSV export
  (formula-safe). Every admin POST handler writes an entry; a unit test
  reads every admin POST route's handler source and fails if one does not
  (two documented exemptions: builder autosave, which logs once per
  editing session from `builder_save_draft()`, and "mark message read").
  Gaps found by the test were filled (menu move/hide, album captions/
  reorder/remove, member notes, campaign save/test/cancel/copy, template
  test, radio categories, playlist edits). Member-side changes (email
  change, deletion request, export) are logged too.
- Backups (`/admin/backups`): "Download database" streams a gzip SQL dump
  written in pure PHP (own unbuffered connection, consistent InnoDB
  snapshot, DROP/CREATE + batched INSERTs, binary columns as hex, one
  statement per line); "Download uploads" streams a ZIP (stored entries,
  CRC computed first, Zip64 records when needed) of `public/uploads`,
  `storage/originals` and `storage/variants`. Both are POSTs (CSRF, audited).
  Daily backup via cron (setting: on/off, local time, keep 1-60, with or
  without uploads) into `storage/backups` (mode 640, atomic `.part` rename,
  pruned to the newest N per kind); "Back up to the server now"; download
  and delete stored files (name pattern + realpath check). CLI:
  `bin/backup [--uploads]`, `bin/restore <file> [--yes]` (asks you to type
  the database name).
- System health (`/admin/health`): PHP version and SAPI, required/optional
  extensions, GD WebP, debug/display_errors, shell functions (not needed);
  database server and version, pending migrations, size; cron last run and
  every job's last run with staleness (3 intervals + 5 min, daily jobs 26 h),
  overdue scheduled items (§12 warning), emails stuck in the queue; disk
  space, writable folders, config.php permissions, upload totals;
  upload_max_filesize/post_max_size/largest upload vs the radio limits,
  memory_limit, max_execution_time; mail transport, failed emails, https
  site address, scheduled backups. Links to "Send a test email" and Backups.
- §12 lazy publishing was already in place (once a minute on public page
  views); it is now covered by a test, and health warns when items are
  overdue.
- Members change their own email (`/account/password`, now "Email and
  password"): current password required, 5 requests an hour; verified
  accounts keep the old login until the link sent to the new address is
  confirmed (button page, single use, 2 days; the old address gets a notice
  with the new one masked; outstanding reset links are dropped; a race for
  the address cancels the change); unverified accounts switch at once and
  get a new verification link. Three new editable templates: confirm new
  email, email change notice, admin invitation.
- Orphaned group logos: deleting an unverified group deletes its logo;
  the hourly purge job also sweeps sign-up logos no group uses (only files
  with no uploader or a group uploader, older than a day).
- Builder touch reordering: the drag handle uses pointer events for touch
  and pen (pointer capture, drop marker, auto-scroll near the edges, live
  region announcement, autosave); mouse keeps HTML5 drag and drop; arrow
  buttons/keys unchanged. Checked in headless Chrome with real touch events
  (`dev-tools/touch-reorder.mjs`).
- Analytics and cookie banner (§7, §11): Site settings offer an allow-list
  (Plausible, Fathom, Google Analytics 4) with a per-provider site ID
  pattern; no pasted scripts. `analytics.js` loads the provider; the CSP is
  widened for that provider's hosts on public pages only (admin keeps the
  strict CSP; builder/design previews never load analytics). GA4 waits for
  consent in a cookie banner (editable text; "Cookie settings" in the footer
  to change the choice; shown only with JavaScript, so nothing tracks
  without it). No analytics = no banner.
- Hardening: `X-Powered-By` removed, `Cross-Origin-Opener-Policy`, HSTS on
  HTTPS, explicit `connect-src`; `.htaccess` adds compression, nosniff and
  referrer headers for static files and denies dumps/archives in `public/`.
  Current-password checks (password change, email change, deletion request)
  allow 10 wrong guesses an hour per account. `bin/check` gained a secrets
  step (no config, dumps, keys or hard-coded passwords tracked) and lints the
  bash scripts. Reviewed against the php-plain-security checklist (raw
  input, SQL with variables, unescaped output, innerHTML greps: every hit is
  code-derived or read-only).
- Deployment kit (superseded by `docs/`, see Addendum A3/A4): `DEPLOY.md` (aaPanel, Apache, PHP-FPM, MySQL; see the
  file) and `bin/package` (archive of a commit for upload, private files
  excluded and verified). Not deployed anywhere.


**Addendum A (A1, A2, A5), 2026-10-24**
- A1 comments without approval (migration `013`): comments from active
  members are published at once; the pre/post moderation setting, its
  route and the "edits go back to the queue" rule are gone (waiting
  comments were published, rejected ones became hidden). Statuses are now
  `approved`/`hidden` with `hidden_reason` (`moderator` or `reports`).
  `/admin/comments` is a Reports / Hidden / Published queue with keep,
  hide, show again and delete; a comment reported by 3 different members
  (`COMMENT_AUTO_HIDE_REPORTS`) is hidden until an admin keeps it ("keep"
  shows a report-hidden comment again; one hidden by hand stays hidden).
  Authors see their own hidden comments with a badge; authors cannot
  report themselves. Overview card and bell count reported comments.
- A2 newsletter opt-in (migration `014`): dashboard card for members with
  news emails off ("Yes, send me updates" = POST + CSRF, audited as
  `consent`; "No thanks" sets `members.optin_prompt_dismissed_at`, the card
  returns after 90 days; never shown after a complaint or bounce). Every
  email, transactional too, has "Manage email preferences" in the HTML
  footer and the text part (`email_prefs_text()`); `/account/preferences`
  asks visitors to log in and returns there. Campaigns can be marked
  "Essential membership notice" (`campaigns.essential`; checkbox + warning
  in the editor; audit entries when toggled and in the send/schedule
  entries): audience and send-time checks ignore the newsletter
  preference but skip every suppressed address; the footer explains why
  it was received. Membership settings: sign-up box ticked by default
  (off; note on UK GDPR/PECR). Opting in again lifts the person's own
  unsubscribe or complaint, never a bounce.
- A5 bounces and complaints (migration `015`):
  - Tags: every queued message has a signed tag (`c<row>-<12 hex>` for
    campaign recipients, `q<row>-…` for `email_queue`; HMAC from
    `app_sign()`), used in the VERP envelope sender
    `bounces+TAG@domain` (Email settings: bounce address), the Message-ID
    and `X-Gather-Message-Id`; plus the provider's metadata header when
    the SMTP host is Mailgun/SendGrid/Postmark/Brevo/SES. Campaigns add
    `Feedback-ID`; bulk mail has `Precedence: bulk` and
    `Auto-Submitted: auto-generated`. Transactional mail sent directly is
    now logged as an `email_queue` row first (subject only, body dropped
    once sent) so its bounces map too; these rows count towards the
    per-minute limit.
  - SMTP time: `SmtpMailer` reads PHPMailer's reply code and throws
    `RecipientRejectedException`; 5xx -> hard bounce (or block for 5.7.x),
    failed at once, never retried; 4xx -> soft, retried with back-off and
    recorded as a soft bounce when the attempts run out.
  - Classification `bounce_classify()` (RFC 3463 status first, then SMTP
    code and text): hard (5.1.x, 5.2.1, 5.4.1, 5.4.4, 550/551/553, "user
    unknown"), soft (4.x.x, 5.2.2/5.2.3, mailbox full, timeouts), block
    (5.7.x, spam/policy text), complaint (ARF, webhooks).
  - `bounce_record()`: `email_events` row (provider/report id as `ext_id`,
    so replays are no-ops), outcome on the queue row (`bounce` column),
    rules: hard -> `hard_bounce` suppression; soft -> `soft_bounce` after 3
    within 30 days; complaint -> `complaint`; block -> campaign `paused`
    (with reason, Resume button, health warning), address not suppressed.
    Stronger reasons replace weaker ones (hard > complaint > soft >
    unsubscribe). Bulk mail skips every suppression at claim time;
    transactional mail skips hard/soft bounces except mail the person just
    asked for (verify, password reset, email change, invitation, event
    registration, contact copy/reply).
  - Parsing: `Core\Mail\MimeMessage` + `BounceParser` (RFC 3464 DSNs,
    RFC 5965 ARF, legacy plain-text Exim bounces, auto-replies and delay
    notices ignored); tag from the envelope address or returned headers
    (since the second review untagged mailbox reports only go to review;
    the 30-day address match is kept for SMTP-time and webhook reports).
  - Bounce mailbox: pure-PHP `ImapClient` / `Pop3Client` over
    `MailboxSocket` (implicit TLS or STARTTLS/STLS, certificate always
    verified, optional CA file; no ext-imap). `bounce_mailbox_poll()` (cron
    every 5 min, "Check now" button) reads up to 200 messages, records,
    deletes processed ones, moves unreadable ones to an IMAP folder (POP3:
    `storage/bounces/`), prunes that folder by count/age, stores status in
    `mail.bounce_state` (health).
  - Webhooks `POST /webhooks/email/{ses|mailgun|postmark|sendgrid|brevo}`
    (`src/lib/webhooks.php`, controller `Site\EmailWebhooks`): off until
    configured; SES/SNS signature v1/v2 with the certificate fetched only
    from `https://sns.<region>.amazonaws.com(.cn)/…pem` and a topic ARN
    allow-list (subscription confirmed automatically); Mailgun HMAC +
    15-minute timestamp; Postmark basic auth or `X-Gather-Webhook-Token`;
    SendGrid ECDSA P-256 signature of timestamp+body; Brevo bearer token.
    CSRF-exempt like the RFC 8058 unsubscribe: no `csrf_check()`,
    documented in the handler and listed in the smoke test's
    `$csrfExempt`. Failed authentications are rate limited per IP.
  - List hygiene `src/lib/dns.php`: MX (null MX = no mail) then A/AAAA via
    `dns_get_record` with warnings caught, cached in `email_domains`
    (ok 7 days, none 24 h, errors not cached), 4 s lookup budget per
    request (then "unknown" = accepted), big providers and RFC 6761 names
    never looked up. Used at sign-up (plus typo suggestions such as
    gmial.com -> gmail.com, asked once), CSV import (row skipped) and
    campaign/notice queueing (row stored as "not sent"). Tests inject a
    resolver; `GATHER_DNS=off` disables lookups (smoke server).
  - Per-domain throttling: Email settings "emails per minute to one
    domain" (default 30, 0 = off), counted over the last 60 s across both
    tables; throttled rows wait without using the batch budget.
  - Screens: Email settings bounce section (bounce address, per-domain
    pace, mailbox, webhooks with their URLs; secrets sealed and never
    printed), Suppression list `/admin/email-suppressions`
    (communications permission; rates, filters, latest events; admins can
    clear bounce suppressions only), campaign delivery log with delivered /
    soft / hard / complaint / suppressed cards, filters and a bounce
    column, global log columns, paused-campaign banner + Resume, health
    group "Bounces and complaints" (7/30-day rates with warnings above 2 %
    and 0.1 %, blocks/paused campaigns, mailbox status, set-aside count,
    bounce address), member banner on every account page when their
    address bounced.

**Addendum A6: design system overhaul, 2026-10-25**
- Assets (all self-hosted, no runtime third-party requests; see CREDITS.md):
  26 Unsplash photos in `demo-assets/photos/`; Lucide 1.61.0 (ISC) compiled
  into `public/assets/img/icons.svg` by `dev-tools/build-icons.php` (the map
  keeps the old short names: pin, help, trash, close…; brand glyphs stay our
  own drawings because Lucide has no X/WhatsApp marks); three more OFL
  fonts from Fontsource 5.3.0: Bricolage Grotesque (display grotesk),
  Instrument Sans (text and admin UI), Newsreader (editorial serif, upright
  + italic). `design_fonts()` entries carry their weight range; the active
  heading and body fonts are preloaded (`design_preload_fonts()`).
- Tokens: `themes/base.css` (loaded into /theme.css before each theme)
  holds the shared scales: fluid type `--step--2 … --step-6` (display up to
  5.25rem, tight tracking tokens), eyebrow style tokens, spacing and section
  rhythm (`--section-y*`), container widths, a layered shadow scale
  (`--shadow-1…4`, ambient + key, tinted by `--shadow-rgb`), neumorphic
  (`--shadow-neu*`) and glass materials (with solid fallbacks), overlay
  gradients, motion durations/easings, dark-band colours (`--c-dark*`,
  `--c-dark-accent-text` for accent text on dark). Themes: **Bold** (new:
  near-black, white, crimson; Bricolage + Instrument; square uppercase
  buttons), **Civic** (navy/teal/gold; Manrope + Instrument; pills, dot
  markers), **Heritage** (earth tones on cream; Fraunces + Newsreader;
  italic eyebrows with a rule, round tiles, paper grain), **Harvest**
  (green/gold; Nunito + Instrument; pill eyebrows, blobs), **Monochrome**
  (black/white/burnt orange; Newsreader + Instrument; hairline and heavy
  rules, offset hard shadows). All pass the AA defaults test; preview.png
  regenerated from the demo. `site.css` lives in `@layer site` so each
  theme's (unlayered) overrides.css wins although it loads first.
- Public CSS rewritten (`site.css`): eyebrows with markers, buttons (accent,
  highlight, ghost, on-dark, light, glass), animated underline links, icon
  tiles, cards with hover lift and image zoom, post cards with category
  chips, event cards with date badges, person cards (portrait, name on a
  gradient, social links on hover), album tiles, page heroes with a fine
  grid and glow, event heroes over the cover photo, article layout, auth
  split screen, account header, form cards, FAQ with animated plus/minus.
- Header and footer: transparent header over a full-height hero (only with
  JS: `page_flag('overlay_header')` set by the hero, `.is-overlay`), glass
  bar once scrolled (`.is-scrolled`, solid fallback without
  backdrop-filter), dropdowns open on hover/click (more than 5 children =
  two-column "mega" panel), full-screen mobile menu with staggered links,
  focus kept inside, Escape closes; without JS the menu is a plain list and
  the header is not sticky on phones. Rich footer: call-to-action strip
  (heading/text and an outlined wordmark editable under Header and footer:
  `footer.cta`, `footer.cta_heading`, `footer.cta_text`, `footer.wordmark`),
  widget columns, bottom bar with back-to-top.
- Motion (`motion.js`, `hero.js`, `carousel.js`, `accordion.js`,
  `header.js`): scroll reveal (`data-reveal`, stagger via
  `data-reveal-group`; nothing is hidden until JS adds `.reveal-ready`),
  count-up of any number in `[data-count]` text (prefix/suffix and grouping
  kept), parallax-lite on the hero photo, timeline progress, hero
  crossfade with Ken Burns, smooth FAQ height animation (WAAPI), showcase
  index highlight. Everything is skipped under `prefers-reduced-motion`
  (CSS also neutralises transitions/animations; the marquee becomes a
  wrapped row).
- Focal points (migration `016_focal_point`): `media.focal_x/focal_y`
  (percent). Media edit screen: click the photo or type values (no JS
  needed), live square/banner/phone previews (`focal.js`). `img_html()`
  adds `fx-NN fy-NN` classes (5 % steps; CSP allows no style attributes) and
  every img uses `object-position: var(--fx) var(--fy)`, so crops follow
  the focal point at every breakpoint (mobile art direction = same photo
  cropped differently around its focal point; heroes go portrait on
  phones).
- Builder: 29 section types. New (group "Story and highlights"):
  `split_metrics` (photo + heading/text/button + 2-4 numbers with accent
  rules, optional floating glass chip), `about_band` (dark: heading and
  story side by side, wide rounded photo with overlaid counters),
  `process` (steps on a vertical timeline that fills on scroll, optional
  sticky photo; or cards in a row), `points` (heading/text beside soft rows;
  dots, ticks or numbers), `showcase` (sticky heading + index beside large
  numbered photo cards that stack while scrolling), `testimonials` (quote,
  name, role, photo, 3-5 stars; slider with neumorphic arrows and dots, or
  grid), `tiers` (2-4 price cards, benefits one per line, featured dark
  gradient card with a badge), `logos` (partners: logo or icon + name,
  looping marquee that pauses on hover/focus, or still grid). Upgraded:
  `hero` (layout `fullscreen` with height full/tall/auto, overlay
  strength, crossfade slider with dots/arrows/pause/swipe, Ken Burns,
  optional muted background video from the media library - played only
  without reduced motion, avatar stack + social-proof line, floating glass
  card), `stats` (cards / band with icon tiles / big numbers with rules, an
  icon per number), `features` (cards / tall photo-reveal cards / minimal,
  photo per card), `cta` (adds dark and photo-with-glass-panel tones and an
  eyebrow), `upcoming_events` (photo cards with date badges), `latest_posts`
  (magazine), `gallery` (mosaic), `faq` (heading beside the questions).
  New section background "Dark". Schema additions: `head_layout`, media
  fields with `'kind' => 'video'` (validated server-side as a public video),
  `icon_options_none`. All values are validated by the existing
  schema-driven `builder_clean_field()`; `_wrap.php` adds `hero-full h-* ov-*`
  classes; preview labels are a `.sec-label` span. Starter templates Home,
  About and Membership use the new sections.
- Demo (`src/lib/demo.php`): photos imported with `media_store()` (WebP
  400/800/1600, focal points from `DEMO_PHOTOS`; GD artwork only if a file
  is missing). Home: full-screen hero slider → stats band → partners
  marquee → image with numbers → photo-reveal programme cards → dark story
  band → event cards → joining timeline → numbered project showcase → dark
  testimonials slider → magazine news → mosaic gallery → radio → photo CTA.
  About, Contact and Membership (tiers, highlights, steps, testimonials
  grid, FAQ, CTA) are builder pages too; leaders have portraits and social
  links.
- Public pages restyled: news list (first post featured), news detail
  (article header, 21:9 cover, related posts), events (cards), event detail
  (photo hero with register/calendar buttons), gallery (album tiles, first
  one large; masonry albums), leaders (each group with person cards),
  leader profile, radio (dark hero, glass player), join (photo aside),
  login (split screen with the home hero photo, `site_feature_image()`),
  FAQ, account shell (dark header, new icons).
- Admin polish: Instrument Sans, layered shadows, larger radii, graphite
  sidebar with distinct Lucide icons per screen, stat-card icon tiles,
  section picker thumbnails redrawn for the upgraded sections and added for
  the eight new ones (`sections.svg`), focal point UI.
- Performance: hero photo preloaded with `imagesrcset`; later hero photos
  ship in `<template>`s and are added after `load`, so they never compete
  with the first; fonts preloaded; the dev server (`php -S`) now gzips text
  assets and HTML and caches versioned assets like `.htaccess` does on
  Apache (only for the built-in server).

**Addendum A3/A4: hosting-neutral deployment and documentation, 2026-10-25**
- No control-panel assumptions in code or messages (a unit test greps
  `src/`, `templates/`, `bin/`, `public/`, `themes/`, `migrations/` for
  `/www/server`, `/www/wwwroot`, `aapanel`, `www:www`). Backups screen and
  `backup.php` wording made generic.
- System health group **Server and paths** (`health_path_items()`): web
  server and SAPI, install folder, document root mode
  (`health_docroot_mode()`: public / project = root `.htaccess` fallback /
  other / unknown), config file, php.ini (+ per-folder ini name), open_basedir,
  the user PHP runs as, the PHP CLI for cron (`health_php_cli_candidates()`:
  PHP_BINARY when CLI, PHP_BINDIR/phpX.Y, PHP_BINDIR/php, sbin/../bin/php,
  sibling php; first executable wins, otherwise unconfirmed) and the exact
  cron line (`health_cron_line()`, appends to `storage/logs/cron.log`, quotes
  paths with spaces). Overview, Email settings and the Cron health item show
  that line (computed in the controllers). Paths and the line render as
  copyable code.
- Root `.htaccess` (fallback when the document root is the project folder):
  `.well-known/` is served as is (AutoSSL / Let's Encrypt HTTP checks), dot
  paths such as `.git/` are refused by a rewrite rule and by `RedirectMatch`
  (no `<If>`, for wider server support), top-level notes/config files denied
  even without mod_rewrite. `public/.htaccess` copies the `Authorization`
  header into the environment: under PHP-FPM/CGI Apache dropped it, so the
  Postmark (basic auth) and Brevo (bearer) webhooks always got 401.
- `dev-tools/htaccess-check.sh`: throwaway Apache (macOS `/usr/sbin/httpd`,
  mod_rewrite, mod_proxy_fcgi, mod_alias) + Homebrew php-fpm against a temp
  copy of the checkout (httpd cannot read ~/Desktop); checks public pages,
  PHP-made `.css/.png/.xml` routes, uploads, ACME/DCV files, 20+ private
  paths and the Authorization header in three layouts (document root
  `public/`, project folder, project folder without mod_rewrite). All pass.
- `nginx.conf.example` (root `public/`, only `index.php` runs, uploads WebP
  only, static caching with fallback to PHP for `/theme.css` etc., 330 MB
  body, ACME location). Not run through `nginx -t` (no nginx here).
- `bin/package --zip` (matching zip) and `--zip --flat` (no top folder, for
  extracting into `public_html`); `docs/` no longer shipped (shipped again
  since Addendum B3: the help centre reads it). Test builds all
  three and compares their file lists.
- Install token page explains the file-manager route (no terminal).
- UI fixes found while documenting: admin `.fields`/`.field`/`.media-field`
  grids use `minmax(0, 1fr)` (side-panel controls overflowed at 1280 px);
  "New FAQ" instead of "New faq" (`content_label_lower()`); stale texts (radio
  "arrives in a later update", group ID "(phase 4)", profile "contact us to
  change your email").
- `docs/`: index, getting the code, requirements, cPanel / VPS / aaPanel /
  Plesk / local guides, email deliverability, website manager guide (29 pages
  + How do I…?), member guide; 84 WebP screenshots (4.3 MB) from
  `dev-tools/doc-shots.mjs` (light theme, 1280 px, some 375 px; the health and
  email-settings shots from a neutral copy at `/Users/Shared/gather` so no home
  path shows; the OS user name is replaced in shots). DEPLOY.md is now a
  pointer to `docs/`.
- Known gap (fixed since: `/admin/profile`, see the second review): admins had no profile page to change their own password; the
  docs point to "Forgotten your password?" or a super admin's "Send password
  link".

**Addendum B: admin navigation, log in as member, docs on the web, 2026-10-26**
- B1 sidebar groups (`admin_nav()` now returns groups with key, label, icon
  and links; `admin_nav_active()` picks the most specific link and its
  group): Site, Content, Members (members, groups, custom fields,
  membership settings), Community (jobs, comments), Communications (now
  also email settings), Radio, System; Help centre pinned below. Without
  JS each group is a plain label over an always-open list; with JS
  (`html.js`, set before paint) the label is hidden and a
  `<button aria-expanded aria-controls>` toggles the list. The current
  page's group is the active parent (bold, accent bar, "(current
  section)" for screen readers) and always opens; other groups follow
  `localStorage['gather-admin-nav']` (`{key: bool}`, try/catch), applied
  before paint through `data-nav-open` on `<html>` + one CSS rule per
  group key, so there is no flash. Expand all / Collapse all (JS only).
  Icon mode (folded sidebar, builder, design at >= 64rem): one icon per
  group, the active one highlighted; hover, focus or click shows a
  flyout of the group's links (labelled by `data-label`, kept inside the
  window, Escape closes and returns focus; `aria-expanded` follows the
  flyout). Groups without permitted links disappear. 375 px: the same
  groups in the off-canvas sidebar.
- B2 log in as a member (`src/lib/impersonation.php`, migration
  `018_impersonation`): permission key `impersonate` (10 keys; super
  admins always; existing admins are not granted it). Entry points:
  member page, "Log in as" row action in the members list, group edit
  screen ("Log in as this group" when the group has a login). GET
  `/admin/impersonate/{userId}` = confirmation (reason, "allow changes",
  "I understand" tick required for suspended and unverified accounts);
  staff accounts get a 422 refusal page without a form. POST starts:
  `impersonations` row (admin, user, reason, allow_changes, IP, start,
  expiry), audit `impersonate_start` (written as the admin before the
  switch), `session_regenerate_id(true)`, `$_SESSION['imp']` holds the
  admin id, the admin's session version, expiry (start + 1800 s, hard,
  never extended), return path and display name/number; the session user
  becomes the member (with the member's session version), CSRF token
  renewed, optional notice email (template `impersonation_notice`,
  setting `impersonation.notify`, default on). `current_user()` accepts
  a suspended member only while it is the impersonated account.
  `impersonation_guard()` runs in `public/index.php` before routing for
  every request: admin row missing / not staff / suspended / session
  version changed -> both sessions end (logged out, `/login`);
  expired -> back to the admin (flash); permission removed -> back to
  `/admin`; member gone or their own session version changed -> back to
  the admin. Then the rules: `/admin*`, data export, job post/edit
  forms, email-change confirmation and join/reset pages are 403 even for
  GET; every POST is 403 (default deny: password, email, deletion,
  preferences/consent/unsubscribe, likes, comments, reports, jobs,
  contact form, notice read marks, login) except `/impersonation/stop`,
  `/logout` and, with "allow changes", `/account/profile`,
  `/account/photo`, event register and registration cancel. The 403
  pages say why and to use "Return to admin". `audit()` records the
  admin as actor and the member in the new `audit_log.on_behalf_of`
  while impersonating (audit screen shows "as Name", CSV column "On
  behalf of"); member profile/photo saves now always write an audit
  entry, event registrations do while impersonating. Stop (POST + CSRF)
  regenerates the id, restores the admin's `user_id`/`sv`, audits
  `impersonate_end` with minutes and reason, and returns to the member's
  admin page (or `/admin` without the members permission). Logging out
  while impersonating ends both. Banner partial
  (`partials/impersonation_banner.php`) in the site and print layouts:
  fixed bottom bar, near-black with yellow (theme-independent),
  read-only/changes allowed and end time, hidden in print.
- B3 help centre: `Core\Markdown` (small CommonMark-like renderer:
  ATX headings with GitHub-style unique ids, paragraphs, nested tight/loose
  lists, fenced code, tables with alignment, blockquotes and `[!NOTE]`
  notes, rules, inline code, links, autolinks, images, emphasis; no raw
  HTML: every text fragment is escaped, links only http(s)/mailto/relative/
  #anchor (anything else, or a resolver refusal, renders as text), images
  only via the caller's resolver),
  `src/lib/help.php` (manifest of every guide in reading order with
  sections, link/image rewriting per mode admin/public/static, cache in
  `storage/cache/help/` keyed by file mtime/size + `HELP_RENDER_VERSION`,
  stale copies removed; search over h1-h3 sections, every term must
  match, heading hits weigh more, snippets returned as text parts and
  escaped in the template; contextual help map from admin paths to guide
  pages/anchors; static export). Routes: `/admin/help`,
  `/admin/help/search`, `/admin/help/images/{name}`,
  `/admin/help/{slug}` (staff only), `/help` and `/help/images/{name}`
  (setting `help.member_guide`: public (default) or members). Image names
  are allow-listed (`[a-z0-9][a-z0-9_-]*.(webp|png|jpe?g|gif)`, no SVG)
  and realpath-checked inside `docs/images`; served with `send_file()`.
  `help_link_insert()` (called by `render()` for admin pages with status
  < 400) puts a "Help" pill after the first `<h1>` of a `.page-head`; a
  unit test checks every admin GET route maps to an existing guide and
  anchor. Doc headings never take ids the layouts use (`main`,
  `sidebar`...). `/help` and Help links in the footer and account menu;
  `help` and `impersonation` are reserved page slugs. Site settings
  panel "Help and member support" (only saved when the form carries
  `support_panel`, so older forms leave the values alone).
- `bin/docs-html [folder]` -> `dist/docs-site/` (refuses folders outside
  `dist/`, empties it first): one HTML page per guide from
  `templates/help/static_page.php` with relative links, `docs.css`
  (light/dark), copied images, `.nojekyll`. `docs/docs-website.md`
  explains GitHub Pages via a `gh-pages` branch (worktree) or an Actions
  workflow (shown in the doc, not shipped active).
- Packaging: `docs/` ships again (help centre needs it) with
  `docs/.htaccess` (`Require all denied`); package test expects the docs
  and `bin/docs-html`; `dev-tools/htaccess-check.sh` probes
  `/docs/README.md`, a docs image, `templates/help/docs.css` and
  `bin/docs-html` as private paths.
- Docs: new `manager-guide/help-centre.md` and `docs-website.md`; first
  login (menu groups, folding, flyouts, own profile section), members
  ("Log in as a member"), users and roles (10 permissions), settings,
  audit log, email templates, how do I, member guide, both indexes and
  README. All screenshots that show the admin sidebar regenerated (89
  WebP, 5.1 MB; health and email-settings shots again from a neutral
  copy at `/Users/Shared/gather`, removed afterwards); new
  `admin-help`, `admin-impersonate`, `admin-sidebar-icons`,
  `member-impersonation`. `doc-shots.mjs` gained `hover`, `ctx` and an
  `imp` viewer.

## Tests

`bin/check`: lint of every PHP file, `tests/run.php` (299 unit/DB tests after
the second security review) and `tests/smoke.php` (492 checks through
`php -S`). The second review added 21 unit/DB tests (most in
`tests/bounces_test.php`, plus `tests/admin_profile_test.php`, comment and
essential-notice tests) and 10 smoke checks.
Addendum A6 added `tests/design_test.php` (6 tests) and
`tests/smoke_design.php` (45 checks); builder/smoke counts updated for 29
section types and 5 themes.

Addendum B (2026-10-26): `bin/check` = lint + **329 unit/DB tests** +
**548 smoke checks**, all green; after the Addendum B security review
fixes **337 unit/DB tests** and **555 smoke checks**, all green. New: `tests/impersonation_test.php` (16:
10th permission key, staff refused, session switch with the admin kept
apart, audit entries and the impersonations row, notice email on/off,
nested refused, confirmation for suspended/unverified, every restricted
route 403 read-only and the allow-changes set, on-behalf-of audit,
return, 30-minute expiry, revoked/suspended/demoted admin logs everyone
out, permission or member removed, logout ends both, banner), 
`tests/help_test.php` (14: Markdown escaping, unsafe link schemes,
resolver-only images, unique GitHub ids and reserved ids, lists/tables/
notes/emphasis, manifest = docs files, every link/anchor/image in docs/
resolves, per-mode link rewriting, image name allow-list, cache reuse and
rebuild, search, a help page for every admin GET route, static export
with every relative link checked, B1 nav groups by permission and active
group), `tests/smoke_addendum_b.php` (54 checks through php -S: sidebar
markup and permission filtering, help pages/search/escaping/images/
refused paths/contextual links, member vs visitor access and the
members-only setting, impersonation start with session-id change, banner,
notice email, audit, 14 restricted routes 403 with nothing changed,
stop 419 without token then 303 with another new session id, allow-changes
profile edit logged on behalf, revoked admin session, logout, group
entry point). The permission matrix (now 10 keys + none) and the CSRF
enumeration cover the new routes automatically; the audit-coverage test
exempts `Admin\Impersonation::start` (its audit entry is written by
`impersonation_start()`). Package test: `docs/` and `bin/docs-html` must
ship. `dev-tools/htaccess-check.sh`: all Apache checks pass, incl. the
docs paths.

Addendum B visual and accessibility checks (headless Chrome, Bold theme on
the dev site): 1440 and 375 px screenshots of the members list, member
page, confirmation screen, help centre page and search, all groups
expanded / collapsed, icon mode with hover, keyboard focus (Tab into the
flyout, Escape closes it, focus back on the icon, aria-expanded false) and
the builder flyout, the off-canvas sidebar, impersonated dashboard, home
(with the open mobile menu, banner stays on top), card, 403 page and
"Return to admin", `/help` and its search; no horizontal overflow, no
console errors; without JavaScript every group list is open and no toggle
shows. axe-core 4.11 (WCAG 2.0/2.1/2.2 A+AA + best practice):
`dev-tools/audit.mjs` on 52 page runs (public `/`, `/help`, `/help?q=`,
`/events`, member pages, 17 admin screens incl. members, member page,
both confirmation states, help index/guides/search with and without
results, settings, audit, users, group edit, radio, builder, design; 1366
and 375 px, admin dark mode) plus 31 runs of interactive states (all
groups expanded/collapsed, icon mode, hover and keyboard flyouts, off-
canvas, six pages while impersonating at 1440 light/dark and 375 px):
**0 violations of any impact**.

A6 visual, accessibility and performance checks (2026-10-25, Bold theme
on the dev site, headless Chrome via the suite's playwright-core):
- Screenshots (`dev-tools/shots.mjs`, 1440 and 375 px, one image per
  screenful plus full-page): home, about, membership, news detail, events,
  event detail, gallery, leaders, radio, join, login, member dashboard, ID
  card, admin dashboard, builder and section picker, open mobile menu,
  each theme's home page; also checked with JavaScript disabled and with
  reduced motion.
- No horizontal overflow on 16 public pages at 360, 390, 768, 1024, 1280
  and 1920 px (a 21:9 + min-height image overflowed at 768 px; fixed).
- axe-core 4.11 (WCAG 2.0/2.1/2.2 A+AA + best practice, after scrolling so
  revealed content is tested): 82 page runs (26 public pages, member pages,
  10 admin screens incl. builder and design, 1366 and 375 px, admin dark
  mode): **0 violations of any impact**; plus 29 runs per theme for Civic,
  Heritage, Harvest, Monochrome and Bold: 0 serious/critical (fixed on the
  way: crimson accent text on dark bands, partner names on soft tints).
- Lighthouse 12.8 mobile (dev server with gzip, see Performance): home
  95-98 performance (3 of 4 runs 98; LCP 2.3-2.9 s, CLS 0, TBT 0), news
  detail 99, event detail 99, events 99, membership 99, join 99, gallery
  98; accessibility, best practices and SEO 100 on all of them. Desktop
  home 100/100/100/100 (CLS 0.002).
Addendum A added `tests/optin_test.php` (A2), `tests/bounces_test.php`
(A5: classification table, tags, every DSN/ARF fixture in
`tests/fixtures/bounces/`, suppression rules, SMTP-time rejections through
`tests/fixtures/fake_smtp_server.php`, IMAP (implicit TLS and STARTTLS) and
POP3 (STLS) polling against `tests/fixtures/fake_mail_server.php` with a
certificate generated per run, webhooks with real HMAC/RSA/ECDSA
signatures and the fixtures in `tests/fixtures/webhooks/`, hostile SNS
certificate URLs, DNS with an injected resolver, throttling, health,
suppression clearing) and `tests/smoke_addendum.php`; the A1 comment
tests were rewritten.
The review fixes added 57 unit/DB tests and 11 smoke checks (install token,
site address and hidden DB errors in the installer, sign-up without login,
identical answer for an existing address, opt-in contact confirmation and
its per-address limit, manual backup naming); counts per phase below are
as they were when each phase was finished. Phases 1-2 coverage as
before (router, escaping, CSRF, sanitiser, auth, lockout, permissions, uploads,
content types, events, menus, search, feeds, installer, cron). Phase 3 adds:
every section type rendering with defaults, hidden sections, schema validation
(required, max, select, number range, repeater min/max, media, unsafe URLs,
rich text sanitising), section list repair, embed allow-list, autosave/draft
session reuse, publish, restore, pruning to 20, optimistic lock ("edited by X
at hh:mm"), starter and saved templates, page form keeping search text, WCAG
contrast maths, theme defaults passing AA, design tokens and CSS, celebrants
opt-in + 29 Feb, footer widgets, form guard. Phase 4 adds: join validation,
custom field rules, number building/format checks, sign-up → verify → active
+ number, sequential/per-year/never-reused numbers (also after deletion and a
counter reset, and skipping hand-typed numbers), group sign-up/verify/suspend,
purge after 14 days, card valid/expired/invalid after regenerate/suspended,
private photo access, deletion anonymising, export, free-first-N, CSV import
and export, rate limit, router quantifiers. The smoke test exercises the
builder JSON API (save, 409 conflict, 419 without token), preview, publish,
restore, templates, new page from a starter, Design save with contrast
warning and reset, header/footer, the contact form (too fast, honeypot,
stored + 2 emails), all four theme stylesheets, the full join → email link →
verify → dashboard → card → print → public verify flow, regenerate/expiry on
the verify page, photo access (visitor 404, owner/staff 200), admin members
list/filters/edit/notes/password link/CSV export/import/bulk sheet, custom
fields, settings validation, account pages, cancel registration (own only),
data export, deletion request and admin removal, group registration, and
403s for a limited admin and a member.

Phases 5-6 add 25 unit/DB tests (`tests/comms_test.php`, `tests/radio_test.php`;
146 in total) and 95 smoke checks (`tests/smoke_comms.php`; 325 in total):
SMTP through a local test SMTP server (PHPMailer conversation incl. AUTH and
custom headers), sealed SMTP password, template placeholders (all §5 ones),
overrides and unknown-placeholder rejection, retry queue for failed
transactional mail, unsubscribe token forgery, suppression and opt-in again,
audience resolution for every audience type with preferences/suppression/
dedupe, block validation/sanitising and rendering, queue batching with the
per-minute limit, crash recovery and no duplicates, retries then failure,
scheduled campaigns, notices (audience, read tracking, single email copy),
birthday idempotency (same day, next year), digest window, 29 Feb in leap and
non-leap years, `daily_at` cron, job validation/expiry/lifecycle/visibility/
trust, likes uniqueness, comment moderation, reply depth, edit window,
reports and reply email, repeat expansion across DST, overlap validation
(repeats, self-overlap, back-to-back), playlist resync refusal, now-playing at
slot starts/ends, inside playlists, loop alignment and wrap, loop cut short by
a show, offline with next show, station off, repeat rules in local time. The
smoke test drives every new screen through `php -S` (settings + test email,
template edit/reset, campaign create/autosave/blocks/preview/test/send/batch/
log, unsubscribe GET/POST/one-click, notice publish/read, inbox reply, job
post/approve/report/expire/public, likes JSON + CSRF, comment pre-moderation/
approve/reply email/edit window, radio page/API shape/byte ranges, upload with
and without duration, schedule overlap, station off) and 403s for a limited
admin and a member.

Visual checks for phases 5-6 (headless Chrome via the suite's playwright-core,
1440 and 375 px, no horizontal overflow on any of them): campaign list and
editor, email render (campaign and transactional, desktop and phone width),
template editor, admin jobs/comments/inbox/overview, radio library and
schedule, job board and detail, account home/notices/jobs, post with
comments, news cards with counts, downloads with likes, /radio before and
after "Tune in", home mini player, video programme in the player. Two
separate browser contexts tuned in 1.7 s apart stayed 0.16 s apart over 12 s
(each within 0.2 s of the computed live position); an audio-to-video slot
boundary swapped elements and played within 10 ms of the live position.

Visual checks (headless Chrome, 1440 px and 375 px): home, contact and
membership builder pages, builder editor (list, settings form, picker),
Design screen with live theme switch, /join, account overview, ID card
(screen, PNG render, CR80 print, A4 sheet), verify page, admin members list
and edit. No horizontal overflow at 375 px on the public and account pages.

Phase 7 adds 28 unit/DB tests (`tests/admin_test.php`, `tests/system_test.php`,
`tests/profile_test.php`; 174 in total) and 67 smoke checks
(`tests/smoke_admin.php`; 392 in total): last-super-admin rule (demote,
suspend, suspended super admins do not count, promote back), staff
validation, invitation link lifetime, suspended session dropped, permission
keys, audit fields/filters, static audit coverage of every admin POST route,
SQL dump -> drop every table -> restore with identical `SHOW CREATE TABLE`,
row counts and `CHECKSUM TABLE` (gzip and plain, with quotes, backslashes,
semicolons, newlines and emoji in the data), SQL splitter, ZIP validity
(ZipArchive consistency check and `unzip -t`, normal and forced Zip64),
uploads zip contents, retention, the backups cron job, health checks, stale
cron, overdue items, lazy publishing, analytics allow-list/CSP, email
change flows, logo purge, password-confirm limit. Smoke: Users & roles
through HTTP (invite email -> set password -> scoped access, permission
change, suspend logs out, own role/status ignored, second super admin
demotes and promotes), audit screen/filters/CSV and entries for every area
the smoke test touched, backups (419 without token, gzip dump downloaded
over HTTP then restored into gather_test with identical checksums, uploads
zip, store/download/delete, schedule validation), health page, **permission
matrix over every admin route** (each of the 8 keys plus an admin with
none: every admin GET answers 403 exactly outside that key's area and every
denied admin POST answers 403 with a valid token; members get 403 and
visitors are sent to /login on every admin page), **CSRF enumeration**
(every POST route of the app answers 419 without a token, logged in or
not, and with wrong form/header tokens; the only exemption is the signed
RFC 8058 unsubscribe, which still refuses a forged token), email change over
HTTP, analytics/cookie banner and security headers.

Accessibility and performance (phase 7, headless Chrome 141 via the suite's
playwright-core; tools in `dev-tools/`, never deployed):
- axe-core 4.11 with WCAG 2.0/2.1/2.2 A+AA and best-practice rules: 78 page
  runs (home, news list and detail, event list/calendar/detail, join, radio,
  gallery, leaders, downloads, FAQ, login, jobs, member dashboard, ID card,
  email and password; 20 admin screens incl. users, audit, backups, health,
  members, member edit, settings, posts, post form, builder, design, menu,
  media, campaigns, radio library and schedule, inbox, events) at 1366 px
  and 375 px plus admin dark mode: **0 violations of any impact** after the
  fixes (admin "View site" and profile menu unnamed on phones; collapsed
  sidebar hid link names; accent button contrast in system dark mode;
  scrolling tables not keyboard-reachable; `.sr-only` text in tables caused
  horizontal page overflow on phones; top bar outside landmarks; nested
  complementary landmarks). No horizontal overflow, no console errors.
- Lighthouse 12.8 (local `php -S`, so no gzip and no cache headers; Apache
  adds both through `.htaccess`): mobile home 96, news detail 96, event
  detail 97, join 97, dashboard 97, ID card 95 performance; accessibility,
  best practices 100 everywhere; SEO 100 on public pages (66 on account
  pages, which are `noindex` on purpose). Desktop: 100/100/100/100 on home,
  news and event. Remaining hints: render-blocking theme.css + site.css
  (13 KB gzip for site.css), ~60 KB unused CSS per page (one shared
  stylesheet), compression/caching (server config). Images: WebP 400/800/
  1600 with srcset/sizes, below-the-fold images `loading="lazy"`.

## Interpretations of the spec

- "All content types have title, slug, summary, body…": applied in full to
  posts, events, albums, downloads and pages. Leaders, awards, FAQs, branches
  and groups use their natural fields (name/recipient/question as the title,
  bio/citation/answer/description as the body) plus status and order.
- Post categories: each category belongs to one of the three public lists
  (news/blog/projects); its posts appear there and use that URL prefix
  (`/blog/{slug}`); extra categories filter the list with `?category=`.
- Awards have an "award name" field (e.g. Volunteer of the Year) in addition
  to year, recipient, photo, citation, order.
- Page visibility "hidden" = only staff can open it (not listed, not in search
  or the sitemap); "members" shows a log-in prompt (403) to visitors.
- Event "today" includes multi-day events that span today; "past" uses the end
  time. Calendar weeks start on Monday.
- Members-only downloads are listed for visitors with a lock and a log-in link.
- Admin users and permissions: Users & roles screen (phase 7) or `bin/make-admin`.
- Theme previews: `themes/<name>/preview.png` (spec layout) served by a small
  route (`/theme-preview/{key}.png`) because `themes/` is not web-reachable.
- Builder: Publish also publishes a draft page (status draft -> published);
  scheduled pages stay scheduled. A page created from a template goes live with
  its sections immediately (according to its status). Autosave keeps one
  revision per editing session so "last 20 revisions" are meaningful versions,
  not keystrokes. The builder needs JavaScript (a noscript message points to
  page settings and the history list); classic text pages work without it.
- "Feature/columns (2-4 cards)": read as 2-4 cards per row; up to 8 cards.
- Embeds: X/Twitter timelines need X's own script, which the strict CSP does
  not allow, so they render as a link card. Facebook page timelines use the
  iframe plugin (`www.facebook.com` added to `frame-src`).
- Radio player section: off-air state until phase 6 creates the radio tables;
  the "Listen live" header button follows `radio.enabled` (phase 6).
- Fonts: "curated Google Fonts list or self-hosted" -> seven OFL Google fonts
  self-hosted from the Fontsource packages (no external requests, CSP stays
  `font-src 'self'`) plus three system stacks.
- Design: the admin adjusts brand and accent; the other shades (strong/soft,
  readable inks, link colour) are derived and pushed to AA where possible. The
  contrast warning does not block saving (spec: "warn").
- Join: "full name" is collected as first + last name (the members table has
  both). Required: name, birthday day+month, country of residence, photo,
  email, password, consent; the rest is optional. Region and gender options
  are settings. After sign-up the visitor is sent to the log-in page with a
  "check your email" message; they are logged in when they confirm the
  link (or can log in with their password to see "check your email" and
  resend). An address that already has an account gets the same answer on
  screen and an "already have an account" email instead (review fixes S4/S7).
- Verification links work for 14 days (the purge window) and need a click on a
  confirm button, so mail scanners that prefetch links do not verify accounts.
- Custom field "visible to members" = asked at sign-up and editable by the
  member; otherwise the field is admin-only (edited in the Members screen).
- The number sequence restarts each year when the format contains a year
  token; without one it is a single running sequence. The year is the year of
  verification (site time zone). Imported numbers are reserved too.
- "Free for the first N": fee status `free` for the first N verified members,
  then `due`; admins can set paid/waived.
- Groups get a group ID and a dashboard but no QR card (§4.3 describes member
  cards). Member "valid until" defaults to 12 months (setting).
- Member photos are private media (owner + staff); the public verify page
  shows the photo through a route that re-checks the card code. Card
  background and signature are members-visible media.
- Deletion: members request it (password confirmed); an admin confirms by
  removing the member. Imported members get a random password and use "Forgot
  password" (the optional welcome email says so).

- Bulk email honours preferences strictly: members get campaigns and notice
  copies only with "News and event updates" on (it is opt-in at sign-up);
  unsubscribed addresses are skipped for everyone (groups, admins, event
  guests have no preferences, only the suppression list). Transactional
  emails ignore both. The editor shows how many are left out.
- Audience "a region/branch": members have a region but no branch field, so
  the audience is by region; "birthdays this month" uses the month at
  queue time (site time zone).
- Campaign content is rendered once when queued, so every recipient gets the
  same latest posts/events; only `{first_name}` and the unsubscribe link vary.
- The email-preview responses get their own CSP (`style-src 'unsafe-inline'`,
  no scripts) because emails need inline styles; the rest of the site keeps
  the strict CSP.
- Comments (names of members) are shown to logged-in active members only;
  visitors see the counts and a log-in prompt. Likes on downloads are
  labelled "Useful". Members may delete their own comments.
- Notice email copies are sent once, at first publish. Viewing a message or a
  notice marks it read through a POST from JavaScript (GET never changes data).
- Job "trusted member" is not defined in the spec: here it means at least one
  approved job and no rejected one.
- Radio repeats keep the local wall-clock time (site time zone) across DST;
  boundaries are compared in UTC. Slots are at most 24 h and need a known
  duration; slot length is the media or playlist length rounded up to whole
  seconds. The loop is aligned to the Unix epoch so every listener hears the
  same position; it is cut short when a show starts. `next.type` is
  `scheduled` (next show or next item of the current playlist) or `loop`.
  `server_time` is ISO 8601 UTC with milliseconds; `media`, `offset` and
  `remaining` are null when off air.
- "Cacheable for 1 s" = `Cache-Control: public, max-age=1`; the player fetches
  with `cache: 'no-store'` so its latency compensation stays exact.
- Radio media are served by PHP (`/media/{id}/{name}`) with byte-range support
  rather than as static Apache files, because originals live outside
  `public/`.

- Users & roles: the screen manages staff only (super admins and admins);
  members/groups stay in Members. An admin login cannot reuse a member's
  address (one users table, one role per account). Super admins hold no
  permission rows. "Demote" = super admin -> admin; there is no account
  deletion for staff (suspend instead), so the audit trail keeps its names.
- Audit log: summaries are short text (255 chars), not before/after diffs;
  exports and backup downloads are logged too (GET downloads write a log
  row, the only GET side effect). Builder autosaves log once per editing
  session; campaign autosaves (JSON) are not logged, form saves are.
- Backups: "download DB dump + uploads zip" = two downloads (SQL gzip, ZIP);
  restore is a CLI step (`bin/restore`), not a web button, so a wrong click
  cannot overwrite the live site. Scheduled backups use the same cron
  dispatcher (`daily_at`).
- Cookie banner: shown only when the chosen provider sets cookies (GA4);
  cookieless providers (Plausible, Fathom) load without a banner. The
  allow-list is fixed in code (`src/lib/analytics.php`).
- Email change: members and groups on /account/password; staff on their own
  /admin/profile (added after the docs were written), or by a super admin in
  Users & roles.

- Addendum A1: comments waiting for approval at upgrade time are published
  (the new rule); the 3-report auto-hide counts open reports from distinct
  active member/group accounts at least 7 days old and never hides staff
  comments (second security review; they wait in the Reports queue).
- Addendum A2: "unsubscribed-from-all" = clicked the unsubscribe link (an
  `unsubscribe` suppression row); switching news emails off in the
  preferences does not stop essential notices. Essential is a flag on the
  campaign, not an audience; any audience can be used. Duplicating a
  campaign does not copy the flag. The opt-in card is not shown to groups
  (no members row).
- Addendum A5: "delivered" in the logs = handed to our relay with no
  bounce reported since (a complaint still counts as delivered). Soft
  bounces at SMTP time count towards the 3-in-30-days rule only once the
  retries are exhausted; asynchronous DSNs with `Action: failed` and a 4.x.x
  status count at once; `Action: delayed` never. A block pauses the whole
  campaign (spec), the blocked recipient can be retried after resuming
  ("Retry failed"); bounced recipients never. Since the second security
  review a block reported through the bounce mailbox pauses only when 3
  recipient domains report blocks for the campaign. Admins may clear bounce
  suppressions and complaints that came by email (arf/dsn/review);
  unsubscribes and provider-webhook complaints are lifted only by the person
  opting in again. SES requires a topic ARN allow-list before the endpoint
  is enabled.

- Addendum B1: "use the current grouping as the base" -> the six old
  groups plus Community (jobs, comments, split from Members); Groups,
  Custom fields and Membership settings moved to Members, Email settings
  to Communications, so each topic is in one place. The active group
  always opens on page load, even if the user folded it earlier (spec:
  "expanded automatically"); remembered states apply to the other groups.
- Addendum B2: `impersonate` stands alone (the spec names only that key);
  the docs recommend giving it with Members so the admin can find people.
  Event *registration* is treated like registration cancel (allowed only
  with "allow changes"), and the contact form and notice read marks are
  blocked too (read-only community actions). The notice email is sent at
  the start. "Never extends the admin's own session lifetime": Gather has
  no absolute session lifetime (idle limit 8 h via the session GC); the
  30-minute limit is hard (from the start, never refreshed), the admin's
  session version is checked on every request, and the admin's session
  continues unchanged afterwards. Unverified accounts need the same
  confirmation tick as suspended ones (the spec allows them "for support"
  but also says "unless the admin confirms").
- Addendum B3: `/help` shows the member guide only (manager and install
  guides stay staff-only in the app and public on GitHub/the static
  site). Links from a guide to files outside `docs/` render as text.

## Code review fixes (2026-10-24)

Two reviews (security-reviewer, plain-php-reviewer) after phase 7. Every
finding was checked against the code; all were confirmed and fixed (one
sub-item turned out not to be a bug, noted below). Regression tests:
`tests/security_review_test.php`, `tests/mail_cron_review_test.php`,
`tests/integrity_review_test.php` plus new smoke checks. Migrations
`010_sessions`, `011_mail_queue`, `012_integrity`.

Security
- S1/S2/S11 media: member photos (and any private file) can never be
  attached to content, picked, or served as a download; `Downloads::file`
  checks `media_can_view`. Media follow the items that use them
  (`media_references()` -> `media_sync_visibility()` after every content
  save and in `bin/migrate`): used by any public item -> public; only by
  members-only/hidden items or card artwork -> members (WebP variants move
  from `public/uploads` to `storage/variants`); uploads for members-only
  items are written members-only from the start. Builder sections and
  campaigns still require public images (so an image used there stays
  public); images embedded in rich text are "pinned" (never moved, the
  link would break). `img_html()` prints nothing for images the viewer
  cannot open. Member photos: owner, super admin, or the members
  permission (other staff no longer).
- S3 app_key: refused when empty, `change-me` or < 32 characters (the site
  answers 500 with a neutral message, `bin/cron` refuses, health shows an
  error); no fallback key. `app_subkey()` derives separate HKDF keys for
  signing and secret storage. SMTP passwords sealed with the old key
  (`sb1:`) are read and re-encrypted (`sb2:`) on first use and by
  `bin/migrate`; unsubscribe links in emails sent before the change still
  verify.
- S4/R13 sessions: `users.session_version` is stored in the session at
  login and compared on every request; it is bumped on password reset and
  change, suspension (members, groups, staff), email verification and email
  change (members, admin edits, staff). The browser making the change keeps
  its session. Sign-up no longer logs in (pre-hijack).
- S5 abuse of outgoing mail: per-address daily limits for emails visitors
  can aim at an address (contact confirmation 2, guest event confirmation
  3, "already have an account" 2) and a global cap of 60 an hour; the
  contact confirmation is opt-in and never repeats the message.
- S6 login: also locked after 50 failures from one IP (any emails) or 30
  for one email (any IPs) in 15 minutes; the dummy hash for unknown emails
  uses `hash_password()` (Argon2id).
- S7 sign-up enumeration: same answer for new and existing addresses; the
  owner of an existing address gets the new "Sign-up with an existing
  address" template.
- S8 `safe_url()` refuses `/\host`, `/<tab>/host`, `//host`, whitespace,
  control characters and backslashes in relative URLs.
- S9 installer: one-time install token (`GATHER_INSTALL_TOKEN` or
  `storage/install-token`, deleted when the install finishes; without one
  `/install` shows how to create it), the site address is a validated form
  field (pre-filled, not trusted from Host), database errors are logged,
  not shown.
- S10 overview: birthdays need the members permission; the activity panel
  shows everyone's actions to super admins and "your recent activity" to
  other admins.
- S12 deployment: code owned by root, only `storage/` and `public/uploads/`
  writable by `www`, `config.php` root:www 640 (DEPLOY.md); optional
  `trusted_proxies` (off by default) for X-Forwarded-For/Proto; HSTS adds
  `includeSubDomains` (no preload).

Mail, cron and backups
- R4 bulk mail is re-checked when it is sent: unsubscribed, newsletters
  off, or account not active -> `suppressed` (shown as "Not sent (opted
  out)" in the logs); deleting an account removes its queued emails.
- R6 SMTP outages: connection/TLS/login failures (`TransportException`)
  put the row back without using an attempt and pause the whole queue
  (5 min doubling to 1 h, `mail.pause`), shown in the delivery logs and
  health; "Retry N failed" on a campaign's delivery log re-queues failed
  rows that were never handed over (interrupted rows are excluded).
- R9 notices: copies use `notice:<id>:<sha256(email)>` as dedupe key,
  `emailed_at` is set after the loop (a crash half-way is completed by
  publishing again), taking a notice down is audited.
- R10 campaigns: saves (form and autosave) run under a row lock and get
  409 once the campaign is queued; the subject is frozen in
  `subject_snapshot` with the HTML/text snapshot.
- R14 notices: one query for the notices plus one per distinct audience,
  cached for the request (badge, widget and list share it).
- R5/R12 cron: each job's result is saved immediately (row-locked JSON in
  `cron.jobs`); a daily job that throws is not marked done but noted in
  `cron.failed` and retried every 15 minutes (health shows it red); backups
  run after the main cron lock is released, under their own
  `storage/cache/backup.lock`, so a long backup never delays the mail queue.
- R11 backups: every write is checked (short writes, `ZLIB_FINISH`,
  `fflush`, `fclose`); stored dumps are verified against the gzip trailer
  (CRC-32 + length) or the end marker, zips against the bytes written;
  stale `.part` files are removed when the next backup starts; "Back up
  now" and `bin/backup` write `gather-…-manual-…` files, pruned separately
  from the daily ones.
- Low: sent/suppressed rows in `email_queue` are purged after 90 days.

Data integrity
- R1 CSV import (update mode) only changes columns present in the file; one
  leading `'` added by `csv_safe()` is removed on import (`csv_unsafe()`).
- R2 `media_usage()` also covers live builder sections (incl. background
  images and repeaters), page revisions, saved templates, campaign blocks
  and the card background/signature settings, so deleting is refused.
- R3 a comment with replies is blanked and shown as "[deleted]" (author
  removed) when its author or a moderator deletes it; replies stay. FK
  changed to `ON DELETE SET NULL`.
- R7 `users.job_rejections` (backfilled from rejected posts) keeps a poster
  untrusted after deleting a rejected post.
- R8 the unverified purge waits until the account's latest verification
  link has expired (a resent link gives 14 more days) and re-checks the
  status under a row lock (`account_delete($id, true)`).
- Low: card "valid until" clamps to month end (`card_valid_until()`);
  account deletion also anonymises guest registrations made with the same
  address; every job approval sets a fresh `published_at`; replies under a
  rejected (or re-queued) comment are not counted; a time in the
  daylight-saving gap gets "There is no 01:30 on 29 Mar 2026…" (content,
  radio slots, campaign schedule); radio slot overlap check + write and
  playlist resyncs run under one schedule lock row; changing the site time
  zone warns when radio repeats would clash.
- Not a bug: "a trusted owner editing an expired job relists it". The code
  keeps an expired job expired when a trusted owner edits it (covered by a
  test); the real issue was the stale `published_at` on re-approval, fixed
  above.

## Second security review fixes (2026-10-25)

A fresh-context review of the A1/A2/A5 code. Every finding was checked in
the code first; all were confirmed (M4 partly: the per-IP sign-up limit
already ran before any DNS lookup). Migration `017_review_fixes`
(`email_events.review`). Regression tests in `tests/bounces_test.php`
(H1/M1/M2/M3/M4/M5/LOW), `tests/comms_test.php`, `tests/optin_test.php`,
`tests/admin_test.php`, `tests/admin_profile_test.php`, smoke checks in
`tests/smoke_addendum.php` and `tests/smoke_admin.php`.

- H1 forged bounces/complaints: tags are now
  `HMAC('verp2|<kind><id>|<address>')`, verified against the row (so a tag
  cannot be moved to another row or address; tags issued before this change
  no longer verify and their reports go to review). Mailbox reports (DSN,
  ARF, legacy text) act only with a valid tag for a row that was actually
  sent, record one event for that row, and use the row's address, never the
  report's. Untagged/forged reports, and authenticated SMTP/webhook reports
  for an address with no message sent in 30 days, are stored with
  `review = 'pending'`: no suppression, no row change, not counted in the
  rates. Suppression list "Reports to review" (apply/dismiss, CSRF,
  communications permission, audited `review_apply`/`review_dismiss`),
  health item "Bounce reports to review". At most 5 events per report
  (`BOUNCE_REPORT_MAX_RECIPIENTS`); the parser keeps at most 20 recipients
  (text bounces were uncapped). Admins may clear complaints whose source is
  arf/dsn/review; provider-webhook complaints stay non-clearable.
- M1 campaign pause: SMTP-time and provider-webhook blocks pause at once;
  mailbox blocks are recorded on the row and pause only when blocks for the
  campaign come from 3 different recipient domains.
- M2 poison messages: `BounceMailbox::list()` returns sizes (IMAP
  `UID FETCH … (RFC822.SIZE)`, POP3 `LIST`); messages over 2 MB are moved
  aside (IMAP) or deleted (POP3) without download; `fetch($id, $maxBytes)`
  uses an IMAP partial fetch `BODY.PEEK[]<0.n+1>` and capped POP3 reading
  (bytes and 100,000 lines; a lying server is drained up to 16 MB or cut
  off); IMAP literals other than bodies capped at 64 KB and responses at
  8 MB; `MessageUnreadableException`/`MessageTooLargeException` move the
  message aside so it can never stay at the head; MIME parsing capped at 100
  parts across all levels and splits boundaries without exploding the body.
  Counters `oversized` in `mail.bounce_state` and on health.
- M3 SNS: topic allow-list checked before anything else; the signing
  certificate URL must be `https://sns.<region>.amazonaws.com(.cn)/SimpleNotificationService-<hex>.pem`
  with no query or fragment; cache keyed on the normalised URL, 7-day TTL,
  20 files at most; at most 10 certificate fetches an hour (`rate_hits`).
- M4 DNS at sign-up: MX only (no A/AAAA fallback, empty MX = unknown), 2 s
  budget; A/AAAA skipped after a slow (>1 s) MX lookup everywhere; uncached
  lookups capped at 60 per 10 minutes across all requests; the daily cleanup
  prunes expired `email_domains` rows and keeps at most 20,000.
- M5 webhook types: all provider fields go through `webhook_field()`;
  arrays/objects where text is expected answer 400; the controller counts
  every non-2xx towards the failure limit and turns unexpected errors into a
  counted 500.
- LOW: Mailgun tokens are accepted once within the replay window
  (`rate_hits`, pruned daily). New permission key `essential_notices`
  (with Communications: mark/unmark essential, send/schedule an essential
  campaign; super admins always; **existing admins are not granted it**).
  Comment reports: 10 per member per day; auto-hide counts only active
  member/group accounts at least 7 days old; staff comments never
  auto-hide. Bounce mailbox host must resolve to a public address
  (loopback/private/link-local/reserved refused on save and before
  connecting; the client connects to the checked IP while TLS still checks
  the name) unless `config.php` `mail.allow_internal_mailbox_host` is true.
- Feature: `/admin/profile` (top-bar profile menu → My profile) for every
  staff user: name, login email (current password, confirmation link to the
  new address, notice to the old one, session version bumped on confirm,
  staff return to /admin/profile), password (current password with the
  existing 10-an-hour re-check limit, `password_problem()`, reset links
  dropped, other sessions ended). Audited (`profile_name`,
  `email_change_request`, `email_change_cancel`, `password_change`). Docs:
  first-login, users-roles, how-do-i; screenshot `docs/images/admin-profile.webp`.

## Addendum B security review fixes (2026-10-26)

A fresh-context review of Addendum B found no critical/high issues. Each
finding was confirmed in the code and fixed with regression tests
(`tests/impersonation_test.php`, `tests/help_test.php`,
`tests/smoke_addendum_b.php`). Migration `019_impersonation_evidence`.

- MEDIUM public docs images: `/help/images/{name}` served every docs image,
  including admin screenshots (members list, audit log, backups, health
  paths, email settings). Now only names referenced by the public guides
  (`help_public_images()`, parsed from member-guide.md, cached per file
  mtime) are served there; everything else is 404. `/admin/help/images`
  (staff) is unchanged.
- LOW confirm page disclosure: refusals (staff, unknown) now use one
  generic message (`impersonation_refusal()`) and a separate template
  (`admin/impersonate_refused`, 422) that shows nothing about the account;
  unknown ids are 404. The email address on the confirmation screen only
  shows to admins who also have the members permission (which also covers
  groups).
- LOW notice email: `impersonation_notify()` runs before the session switch,
  never throws (try/catch, logged), and returns sent / off / skipped (no
  valid address) / failed; stored in `impersonations.notified` and the
  `impersonate_start` summary ("member notice: …").
  `impersonation_notice` joined `MAIL_BOUNCE_BYPASS_TEMPLATES`. Changes to
  the support settings (member guide access, the notice switch) are named
  in the settings audit entry.
- LOW audit consistency and evidence: impersonate_start/end use
  `member` + members.id or `group` + groups_orgs.id (`account` + user id
  when there is neither), like other member/group entries. The foreign
  keys on `audit_log.on_behalf_of` and `impersonations.admin_id/user_id`
  were dropped so the ids survive account deletion, and impersonation rows
  store snapshots `admin_name/admin_email/user_name/user_email` (kept on
  purpose as evidence after a member is removed).
- LOW return path: `impersonation_return_path()` sends the admin to the
  member/group page only while they can open it (members permission),
  otherwise to `/admin`; used for every restoring end (return, expiry,
  permission removed, member gone).
- VERY LOW `bin/docs-html`: refuses an output folder that is a symbolic
  link (before emptying anything).

## Known gaps / next steps

- Not run here: PHP 8.2 and MySQL 8 (local runs use PHP 8.5 and MariaDB);
  the code was scanned for post-8.2 syntax/functions and none are used.
  Do one install on the server's PHP version (DEPLOY.md step 8 lints with it).
- `plain-php-reviewer` and `security-reviewer` were run by the lead after
  phase 7 and again on Addendum A; their findings were fixed (see "Code
  review fixes" and "Second security review fixes").
- Second review leftovers: reports carrying a tag issued before 2026-10-25
  (old signature) go to review instead of acting; the review list has no
  bulk apply/dismiss; the 3-domain block threshold is a fixed constant; the
  SNS fetch limit is global (an attacker with an allow-listed topic ARN but
  no valid signature can use up the 10 fetches an hour, delaying genuine
  notifications whose certificate is not cached yet).
- Deployment itself is not done (by instruction); DEPLOY.md and
  `bin/package` are ready.
- Addendum A gaps: the docs/ folder (A3/A4, incl. SPF/DKIM/DMARC and
  bounce-mailbox set-up) is not written yet; the settings page has a short
  SPF/DKIM/DMARC note. Mailbox clients send ASCII credentials as quoted
  strings and others as IMAP literals; no OAuth2/XOAUTH2 (needed by some
  hosted mailboxes such as Microsoft 365). Real providers and real DSNs
  from live servers were not exercised (fixtures are hand-written from
  the formats). Members whose address bounced can change it but cannot
  self-clear the suppression (an admin clears it).
- Phases 5-6 gaps: no open/click tracking;
  campaign/notice images must be public media; the campaign editor
  has no drag and drop (up/down buttons); notice email copies are not re-sent
  when a notice is edited. The radio has no persistent mini player across page
  loads (D9), no per-programme on-demand detail pages, and the overlap check
  looks at an 18-day window around the later slot start (a clash that only
  appears on a DST change night is not detected). Two-browser sync was
  measured on one machine.
- PHP's built-in server needs `PHP_CLI_SERVER_WORKERS` > 1 to stream radio to
  several browsers at once (Apache has no such limit).
- The rich-text editor is a small built-in toolbar (contenteditable +
  server-side sanitiser); D5 allows swapping in a vendored editor later.
- Replacing an image changes its file name, so images embedded inside rich
  text keep pointing at the old (deleted) file; covers/photos are unaffected.
- Keyboard paths were checked with axe and by design; not audited with a
  real screen reader.
- Backups: very large upload folders take as long to zip as to download (no
  background job); restoring needs the CLI. Zip64 output was verified with
  forced Zip64 records, not with a real >4 GiB archive.
- The audit log has no retention limit or purge yet.
- Tests write uploaded/generated files into `storage/` and `public/uploads/`
  (git-ignored; ~250 MB after many runs here): clear them by hand. The unit
  tests and the smoke test share one test database (each run starts by
  dropping it).

- Addendum A6 gaps: the hero video was checked with the validation and
  markup tests only (the demo uses the photo slider; the only demo video is
  a 15 s generated studio clip, not a good hero). Variants stay
  400/800/1600, so small avatars download the 400 px file. "Mega menu" =
  dropdowns with more than five links become a two-column panel (menu
  items have no descriptions or images). Art direction is done with focal
  points (one photo cropped per breakpoint), not separate mobile uploads.
  Lucide has no X or WhatsApp marks, so the six social glyphs are our own.
  Reveal-card photos appear on hover and keyboard focus inside the card
  (cards without a link show the photo only on hover, and always on touch
  screens). The demo post published "today" can be scheduled an hour
  ahead when the demo is loaded during the autumn clock change (existing
  local-time behaviour). Lighthouse was run on the PHP built-in server
  (now gzipping like Apache), not on a real host.

- Addendum B gaps: expiry is enforced on the next request (no client-side
  countdown or automatic redirect at minute 30; the banner shows the end
  time). While impersonating, a blocked POST answers 403 before its CSRF
  check (still refused either way). The Markdown renderer covers what the
  docs use (no setext headings, reference-style links, footnotes or raw
  HTML by design); help search is plain substring matching with
  heading weighting (no stemming or fuzzy matching); the static docs site
  has no search box. Sidebar group state is per browser (localStorage), not
  per account. Not checked with a real screen reader.

## Acceptance check (spec §14), final status

| # | Check | Status | Evidence / reason |
|---|---|---|---|
| 1 | Fresh install via wizard on PHP 8.2 + MySQL; demo content loads | Partial | Wizard + demo content pass in every smoke run (PHP 8.5, MariaDB 13); the packaged archive starts the wizard. Not run on PHP 8.2/MySQL 8 locally (no 8.2-incompatible code found) |
| 2 | Home page from sections, reorder, publish, restore revision | Met | builder tests + smoke; touch reorder added |
| 3 | Menus edited; deleting a linked page flags the item | Met | menus tests + smoke |
| 4 | Each content type: create, schedule, publish, filters, detail | Met | content/archive/events tests + smoke; lazy publish tested |
| 5 | Event registration (guest + member), email, CSV, .ics | Met | events tests + smoke (file mail catcher) |
| 6 | Sign-up -> verify -> number -> card PNG/print -> QR valid; regenerate -> invalid | Met | members tests + smoke + visual checks |
| 7 | Birthday cron once per year; 29 Feb; digest | Met | comms tests |
| 8 | Job post -> approval -> members -> auto-expire | Met | jobs tests + smoke |
| 9 | Campaign to a segment queued, batched; unsubscribe | Met | comms tests + smoke |
| 10 | Radio slot + loop; two browsers within 2 s; off air | Met | radio tests + smoke; measured 0.16 s apart (phase 6) |
| 11 | Admin without "members" gets 403; member cannot open /admin | Met | full permission matrix over every admin route (smoke) + unit matrix |
| 12 | CSRF rejection, PHP upload rejected, XSS neutralised, lockout | Met | CSRF enumeration of every POST route; upload/sanitiser/lockout tests |
| 13 | Lighthouse >= 90 and axe no serious issues on home, news, event, join, dashboard | Met | axe 0 violations (78 runs); Lighthouse mobile 95-97 perf, 100 a11y/best practices (local php -S) |
| 14 | Lint, tests green, plain-php-reviewer + security-reviewer no high findings | Met | two review rounds; every HIGH/MEDIUM/LOW finding fixed with a regression test (see "Code review fixes" and "Second security review fixes"); lint + 299 tests + 492 smoke checks green |
