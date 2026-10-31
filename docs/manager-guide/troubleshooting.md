# Troubleshooting

Start with **Admin → System health**. It catches most problems and says
what to do. The tables below cover what managers and members report most
often. "Technical" means: pass it to whoever manages the hosting.

Where to look for details (technical): `storage/logs/php-error.log` in the
Gather folder, the web server's error log (control panel → Errors or Logs),
and `storage/logs/cron.log`.

## The website

| Problem | Likely cause and fix |
|---|---|
| "Something went wrong on our side" | A server error. Technical: `storage/logs/php-error.log`. |
| Every page except the home page says "not found" | Technical: the document root or `.htaccess` (see the install guide's troubleshooting). |
| "This site is not configured correctly: the secret key…" | `app_key` in `config.php` is missing or too short. Technical: generate one (see [Install on a VPS](../install-vps.md#10-install)). |
| The installer says "creating the tables failed" or "unfinished Gather installation" | Technical: read `storage/logs/php-error.log`, fix the cause, then submit the database step again with **Remove the unfinished installation** ticked. Gather needs MySQL 8.0+ or MariaDB 10.6+. |
| The site keeps sending you to `/install` | `config.php` is missing or the installation was not finished. Technical. |
| A page shows old content | You saved but did not **Publish** in the page builder; or your browser shows an old copy (reload). |
| A page says you must log in | Its **Visibility** is **Members only** (or **Hidden**). |
| A post or event did not appear at its scheduled time | Cron is not running (System health → Cron). It appears on the next visit anyway. |
| Photos look cut off | Set the [focal point](media-library.md#focal-points). |
| Colours hard to read | Design → **Contrast check** (see [Design](design.md)). |
| The "Listen live" button is missing | The station is switched off, or nothing is on air (see [Radio](radio.md)). |

## Logging in

| Problem | Fix |
|---|---|
| Forgotten password | **Forgotten your password?** on the login page. The link works for one hour. |
| "Too many failed attempts" | Wait 15 minutes, or use **Forgotten your password?**. |
| An admin cannot see a menu item | They lack that permission: **Users and roles** (super admin). |
| A member "cannot log in" after joining | They can log in, but most features wait until they verify their email. Members → the member → **Mark email as verified** if the email never arrived. |
| A suspended member or admin | Members → **Reactivate**, or Users and roles → status **Active**. |

## Email

| Problem | Fix |
|---|---|
| No emails are sent at all | **Email settings**: **Send emails with** must be **SMTP server**, not "Save to files". Send a test email. |
| The test email fails | Check the server, port, encryption, user name and password with your email provider. The error message helps. |
| Campaign stuck on "Sending" | Cron must run every minute. The **Delivery log** shows progress. A banner says if the mail server is unreachable (sending resumes by itself). |
| Campaign "Paused" | Receiving servers refused it as spam. See [Campaigns → delivery log](campaigns.md#the-delivery-log) and [Email deliverability](../email-deliverability.md). |
| One member gets no emails | See [When someone says "I don't get your emails"](email-settings.md#when-someone-says-i-dont-get-your-emails). |
| Emails land in spam | SPF, DKIM and DMARC records: see [Email deliverability](../email-deliverability.md#2-spf-dkim-and-dmarc). |

## Payments and birthday notices

| Problem | Fix |
|---|---|
| The currency cannot be changed | It locks once the first payment item exists, so amounts keep their meaning. Choose it before creating any item. |
| "This report changed since you opened it" | The member edited the report while you had it open. Look at it again, then approve. |
| A member says they cannot see an item in "What I owe" | The item is still a **draft** or **closed**, it does not apply to them (membership type or location), it was opened before they joined without **Also apply to people who join later**, or their charge was waived. Add a charge on their **Payments** page. |
| An item says "Creating charges…" for a long time | Charges for large memberships are made 500 at a time by cron every minute: check **System health → Cron**. |
| A payment was approved by mistake | Open it and **Reverse this payment** with a reason. Approved payments are never edited or deleted. |
| "Separation of duties is on" and nobody else can approve | Ask another admin with the payments permission, or a super admin can switch it off in **Payment settings**. |
| No "new payment report" emails | Your **Email me about new payment reports** box on **Payments → Pending** is off, your address is on the suppression list, or cron is not running. At most one email per 15 minutes is sent. |
| Members did not get a reminder | The item needs a due date and **Email reminders** on; only members with a balance get them, each at most once before and once after the due date; suppressed addresses are skipped. |
| System health: "Payment counters … difference" | Technical: run `php bin/payments-reconcile` to list the differences and `php bin/payments-reconcile --fix` to correct them. |
| A receipt's QR code says "Not a valid receipt" | The payment was reversed, or the code was typed wrongly. |
| No birthday emails | **Birthday notices** settings (which emails, recipients), **Skip the email when there are no birthdays**, the birthday hour in **Email settings**, and cron. |

## Messages

| Problem | Fix |
|---|---|
| A member cannot find another member to message | Check **Message settings**: members may be stopped from messaging each other. New members can only message the team for their first 24 hours. The other person may have blocked them (they are not told). |
| A member cannot find an admin by name | Members only see the admins ticked under **Admins members can message by name** in Message settings; otherwise they use **Message the team**. |
| Nobody sees the Team inbox | It needs the **Messages** permission (Users and roles); super admins always have it. |
| "You have sent a lot of messages in a short time" | Members can send 30 messages in 10 minutes and start 10 conversations a day. Wait, or continue an existing conversation. |
| Message emails do not arrive | They are sent 15 minutes after a message arrives, only if it is still unread, through cron (it must run every minute). Check the person's **Message notifications** preference, whether the conversation is muted, and the [suppression list](email-settings.md#suppression-list). |
| New messages only appear after reloading | The page checks every 10 seconds when JavaScript works; in a background tab it checks less often. A browser extension blocking scripts stops it (the page still works with reloads). |
| An attachment is refused | Only photos (JPG, PNG, WebP, GIF) and PDFs up to 5 MB. |

## Uploads and media

| Problem | Fix |
|---|---|
| Upload fails for big files | The hosting's upload limit: System health → **Uploads**. Technical: raise `upload_max_filesize` and `post_max_size`. |
| "Cannot be deleted" for a file | It is still used somewhere: open it in the media library and look at **Used on**. |
| Radio programme cannot be scheduled | Its length is unknown: open it and click **Measure duration**. |
| Images show but no small versions | Technical: GD without WebP (System health → GD WebP support). |

## Members and cards

| Problem | Fix |
|---|---|
| A member lost their card | Members → the member → **Regenerate card code** (the old QR stops working). The member downloads the card again. |
| Card says expired | Set a new **Card valid until** date on the member's page. |
| Duplicate member | Remove the extra account (type DELETE). Its member number is retired. |
| Import skipped rows | The import report gives the reason per row (missing email, existing email without "update", domain that cannot receive mail). |

## Still stuck?

Collect: what you did, what you expected, what happened, the time, and a
screenshot. Then pass it to your technical person with the latest lines
of `storage/logs/php-error.log`.
