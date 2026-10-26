# Audit log *(super admin)*

**Admin → Audit log** records every change made in the admin area: who did
what, to which item, and when (in the site's time zone). It also records
important member actions, such as email changes, data exports and deletion
requests, and system jobs.

![Audit log](../images/admin-audit.webp)

## Finding something

Filters across the top:

- **Search the summaries**: free text, for example a member's name or a page
  title.
- **Who**: one admin, **Everyone**, or **System / visitors** (automatic jobs
  and public actions).
- **Action**: for example create, update, delete, publish, send, export.
- **Item type**: page, post, member, campaign, design, settings…
- **From … to**: a date range.

Click **Filter** (or **Clear** to start again). Each row shows when, who,
the action, the item, details, and the network (IP) address.

**Export CSV** downloads the filtered list as a spreadsheet.

## Viewing the site as a member

When an admin uses [Log in as this member](members.md#log-in-as-a-member),
the log shows:

- **impersonate_start**: who started, which member or group (with their
  number), the reason typed, whether changes were allowed, and whether the
  member notice email was sent;
- every change made during the visit (for example **profile_update**,
  **photo**, **register**, **cancel**), under the admin's name with a
  yellow **as** *member's name* label (the CSV export has an **On behalf
  of** column);
- **impersonate_end**: how many minutes it lasted and how it ended
  (returned to admin, ended automatically after 30 minutes, logged out, or
  ended because the admin's session or permission was removed).

Filter **Action** by *impersonate_start* to see every such visit. These
records stay complete even after the member's account is removed.

## What it is for

- Finding out who changed a page, or when a member was removed.
- Showing your committee or a regulator how personal data is handled (for
  example, who exported the member list and when).
- Spotting mistakes or misuse early.

The log cannot be edited or deleted from the admin area.
