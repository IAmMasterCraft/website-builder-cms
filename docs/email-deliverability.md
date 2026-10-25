# Email deliverability

"Deliverability" means your emails reach the inbox instead of the spam
folder, or instead of not arriving at all. This guide is for whoever sets up
the site's email: the installer, or a website manager with some patience for
DNS settings.

All settings mentioned here are under **Admin → Email settings** (super
admins only).

![Email settings](images/admin-email-settings.webp)

## How Gather sends email

| Kind | Examples | Sent |
|---|---|---|
| **Account emails** (transactional) | email verification, password reset, event registration, contact form copy | straight away |
| **Bulk emails** | campaigns, notice copies, birthday greetings | by cron, in batches |

Bulk emails go through a queue that cron empties every minute, up to
**Emails per minute** (default 60) and at most **Emails per minute to one
domain** (default 30 per minute to, say, `gmail.com`). Failed sends are
retried with growing gaps (5 min, 30 min, 2 h, 6 h). Permanent failures are
never retried. If the mail server is unreachable, sending pauses (5 minutes
up to an hour) and picks up again by itself. Nothing is lost.

Every bulk email carries a one-click unsubscribe link and the matching
`List-Unsubscribe` headers that Gmail and Yahoo require from bulk senders.
Every email, account emails included, links to the member's email
preferences.

## 1. Choose how to send (SMTP)

Until you choose, Gather only writes emails to files (`storage/mail/`).
Under **Delivery**, choose **Send emails with: SMTP server** and fill in the
details from your provider. Then **Save email settings** and **Send a test
email**.

| Option | Good for | Typical settings |
|---|---|---|
| **Your hosting's mailbox** (cPanel, Plesk) | Small organisations, a few hundred members | `mail.example.org`, port 465 **SSL/TLS** or 587 **STARTTLS**, the full address as user name |
| **Amazon SES** | Large lists, lowest cost | `email-smtp.<region>.amazonaws.com`, 587, STARTTLS, *SMTP credentials* created in the SES console (not your AWS keys) |
| **Mailgun** | Transactional and bulk | `smtp.mailgun.org` (EU: `smtp.eu.mailgun.org`), 587, STARTTLS |
| **Postmark** | Very reliable account emails | `smtp.postmarkapp.com`, 587, STARTTLS; user name and password are both a Server API token. Postmark keeps transactional and bulk ("broadcast") mail apart: check its rules before sending newsletters |
| **SendGrid** | Transactional and bulk | `smtp.sendgrid.net`, 587, STARTTLS, user name `apikey`, password = an API key |
| **Brevo** | Transactional and bulk, generous free tier | `smtp-relay.brevo.com`, 587, STARTTLS, your SMTP login and SMTP key |
| Google Workspace / Gmail | Small volumes only | `smtp.gmail.com`, 587, STARTTLS, an app password; daily sending caps make it unsuitable for newsletters |

Providers change their settings now and then. Always copy the current values
from your provider's dashboard. Set **Emails per minute** to your provider's
limit (or a little below it).

The **Sender address** should be on your own domain (for example
`news@example.org`), and that domain needs the DNS records in the next
section. Never use a Gmail or Hotmail address as the sender: those providers
tell receivers to reject such mail.

## 2. SPF, DKIM and DMARC

These are DNS records (text entries in your domain's settings) that prove
your emails really come from you. Large mailbox providers (Gmail, Yahoo,
Microsoft) expect all three from anyone sending to many people. Your email
provider tells you the exact values. The examples below show what the records
look like.

Where to add them: your domain's DNS. That may be cPanel → **Zone Editor**,
Plesk → **DNS Settings**, Cloudflare, or the company where you bought the
domain.

### 1. SPF: which servers may send for your domain

One `TXT` record on the domain itself (`example.org`):

```
v=spf1 +a +mx include:_spf.yourprovider.example ~all
```

- Your hosting's own mail server: cPanel's **Email Deliverability** shows the
  right record and can install it for you.
- A provider: add its `include:` (for example `include:mailgun.org`,
  `include:sendgrid.net`, `include:amazonses.com`, `include:_spf.google.com`).
- **Only one SPF record per domain.** If you use two services, put both
  `include:`s in the same record.

### 2. DKIM: a signature on every email

Your provider gives you one or more records named like
`selector._domainkey.example.org` (a `TXT` with a long key, or a `CNAME`).
Add them exactly as given. cPanel creates DKIM for its own mail server
automatically (**Email Deliverability** shows whether it is valid).

### 3. DMARC: what receivers should do with fakes

One `TXT` record named `_dmarc.example.org`. Start in monitoring mode:

```
v=DMARC1; p=none; rua=mailto:dmarc-reports@example.org
```

After a few weeks of reports showing that your real mail passes SPF or DKIM,
make it stricter:

```
v=DMARC1; p=quarantine; rua=mailto:dmarc-reports@example.org
```

and finally `p=reject`. DMARC passes when the domain in the **From** address
matches the domain that DKIM signed (or the SPF domain). Sending as
`news@example.org` through a provider that signs with DKIM for `example.org`
is exactly right.

To check your setup, send a test email to a Gmail address, open it, choose
**Show original**, and look for `SPF: PASS`, `DKIM: PASS` and `DMARC: PASS`.

## 3. Bounces: the bounce address and mailbox

A **bounce** is the "could not deliver" message that comes back when an
address does not exist or a mailbox is full. Gather records bounces and
**suppresses** addresses that keep failing, so you stop mailing them. Mailbox
providers judge you by how often you send to dead addresses.

Bounces reach Gather in up to three ways:

1. **Straight away from your SMTP server**: when the server refuses an address
   while sending ("user unknown"), Gather records it immediately. You need
   nothing extra for this.
2. **Later, by email**, to a **bounce mailbox** that Gather reads every 5
   minutes. Use this when you send through your hosting's mailbox or any SMTP
   server that sends bounces back to you.
3. **From your provider's webhooks** (next section). Use this with SES,
   Mailgun, Postmark, SendGrid or Brevo. Those providers catch the bounces
   themselves, so they never reach a mailbox of yours.

### Setting up a bounce mailbox

1. Create a mailbox used **only** for this, for example
   `bounces@example.org` (cPanel: **Email Accounts → Create**).
2. In **Email settings → Bounces and complaints**, set **Bounce address
   (Return-Path)** to `bounces@example.org`. Gather sends each email with its
   own tagged return address, such as `bounces+c123-…@example.org`, so every
   bounce matches exactly one email. The mailbox must accept these `+`
   addresses. Most do (cPanel, Google Workspace, Fastmail and Zoho
   included). Test it by sending a normal email to `bounces+test@example.org`.
3. Under **Bounce mailbox**, set **Read the bounce mailbox** to **IMAP**
   (recommended) or **POP3**, then fill in:

   | Provider | Mail server | Port / Encryption |
   |---|---|---|
   | cPanel / Plesk hosting | `mail.example.org` | 993 SSL/TLS (POP3: 995) |
   | Google Workspace | `imap.gmail.com` | 993 SSL/TLS, with an app password |
   | Fastmail | `imap.fastmail.com` | 993 SSL/TLS, with an app password |
   | Zoho Mail | `imap.zoho.com` (EU: `imap.zoho.eu`) | 993 SSL/TLS |

   Microsoft 365 / Outlook.com no longer accept plain passwords for IMAP, so
   they cannot be used as a bounce mailbox.
4. **Save bounce settings**, then **Check the bounce mailbox now**. The boxes
   underneath show the last check, how many messages were processed, and how
   many were set aside.

Gather **deletes** every message it has processed, so the mailbox never
fills up. Messages it cannot understand (rare) go to an IMAP folder (default
`Gather-Unprocessed`) or, with POP3, to `storage/bounces/` on the server. It
keeps at most the number and age you set and deletes older ones. Out-of-office
replies and "delayed" notices are ignored. Messages larger than 2 MB (real
bounce reports are a few kilobytes) are never downloaded: they go straight to
the unprocessed folder (IMAP) or are deleted (POP3), so one huge message can
never hold up the reports behind it.

**Only tagged reports change anything.** Anyone can send email to the
bounce address, so a report counts only when it carries the tag of an email
Gather actually sent (in the return address or the returned headers), and it
then applies to the address that email went to, never to an address written
in the report. Reports without a valid tag (for example from a server that
drops the return address and the original headers) change nothing: they wait
under **Suppression list → Reports to review**, where an admin can **Apply**
or **Dismiss** each one. System health shows how many are waiting. A single
recipient's server refusing a message as spam is recorded but only pauses a
campaign when blocks come from three or more different receiving domains
(refusals from your own SMTP server or a provider webhook pause at once).

## 4. Provider webhooks

If you send through one of these providers, let it tell Gather about bounces
and complaints directly. Each provider's webhook address is listed under
**Email settings → Provider webhooks** and looks like:

```
https://community.example.org/webhooks/email/<provider>
```

A webhook does nothing until you fill in its secret in Gather. Every request
is checked for a valid signature or password, and failed attempts are
rate-limited.

| Provider | In the provider's dashboard | In Gather |
|---|---|---|
| **Amazon SES** (via SNS) | Create an SNS topic; in SES, send the identity's **Bounce** and **Complaint** notifications to it; add an **HTTPS** subscription with Gather's address | Tick **Accept Amazon SES notifications through SNS** and paste the topic ARN into **Allowed SNS topic ARNs**. Gather checks Amazon's signature and confirms the subscription by itself |
| **Mailgun** | **Sending → Webhooks**: add Gather's address for *Permanent failure* and *Spam complaints* | Paste the **HTTP webhook signing key** into **Mailgun webhook signing key** |
| **Postmark** | Server → message stream → **Webhooks → Add webhook**: Gather's address, events *Bounce* and *Spam complaint*, and either basic-auth credentials or a custom header `X-Gather-Webhook-Token` | The same user name and password (**Postmark basic-auth user/password**), or the token |
| **SendGrid** | **Settings → Mail Settings → Event Webhook**: Gather's address, events *Bounced*, *Dropped* and *Spam Reports*; switch on **Signed Event Webhook** | Paste the verification key (a public key, not a secret) into **SendGrid signed event webhook verification key** |
| **Brevo** | **Transactional → Settings → Webhook** (and the marketing webhooks if you use them): Gather's address, events *Hard bounce*, *Soft bounce*, *Invalid email*, *Spam/complaint*; authentication by bearer token (or basic auth with the token as the password) | Paste the token into **Brevo webhook token** |

Dashboard menu names change over time. Look for "webhooks" or "event
notifications" in your provider's settings.

The webhook address must be reachable over HTTPS from the internet. A site on
`localhost` cannot receive webhooks.

## 5. The suppression list

**Admin → Suppression list** (admins with the communications permission)
shows every address Gather will not send bulk email to, and why:

| Reason | Happens when | Effect |
|---|---|---|
| **Unsubscribed** | The person clicked unsubscribe | No bulk email |
| **Complaint** | The person marked an email as spam | No bulk email, straight away |
| **Hard bounce** | The address does not exist (or the domain has no mail server) | No email at all except what the person asks for (password reset, verification…), straight away |
| **Repeated soft bounces** | 3 temporary failures (mailbox full, server busy) within 30 days | The same as a hard bounce |

A **block** (the receiving server refused the email as spam or by policy) is
different: it says more about your sending reputation or content than about
the address. Gather **pauses the campaign** and warns on System health. Check
your SPF/DKIM/DMARC records and the content, then press **Resume sending** on
the campaign.

Admins can **Clear** a bounce suppression once the address has been
corrected or the mailbox works again. They can also clear a complaint that
arrived as an emailed feedback report (shown "via arf", "via dsn" or "via
review"), because such reports can be forged. Unsubscribes, and complaints
reported by your sending provider's signed webhook, are the **person's
choice**: only the person can undo them, by switching "News and event
updates" back on in their email preferences.

Provider webhooks are authenticated, but a provider report about an address
Gather has not emailed in the last 30 days also goes to **Reports to
review** instead of suppressing it. Essential membership
notices (see [Campaigns](manager-guide/campaigns.md)) still skip every
suppressed address.

Members whose address bounced see a banner on their account pages asking
them to update it.

![Suppression list](images/admin-suppressions.webp)

### Keeping the list clean

Gather also checks addresses **before** they cause bounces. At sign-up, on
CSV import and when a campaign is queued, it looks up whether the domain can
receive mail at all, and skips the address if it cannot. The sign-up form
suggests fixes for common typos ("gmial.com → gmail.com?").

## 6. Reading System health

**Admin → System health → Bounces and complaints** shows:

| Line | Healthy | When it warns |
|---|---|---|
| **Bounce rate** (7 and 30 days) | below 2 % | Above 2 %: check recent imports and old lists, and look at the suppression list |
| **Complaint rate** (7 and 30 days) | below 0.1 % | Above 0.1 %: mail only people who opted in, make your emails recognisable, and send less often |
| **Blocked as spam or by policy** | none | Refusals or paused campaigns: fix DNS records and content, then resume |
| **Bounce mailbox** | checked within the last 30 minutes | Not set up, failing (wrong password or server), or not checked (cron not running) |
| **Unprocessed bounce messages** | none | Some messages were set aside; look at them in the mailbox folder or `storage/bounces/` |
| **Bounce address** | set | Not set: bounces go to the sender address and can only be matched by recipient |

The **Delivery log** (and each campaign's own log) shows, per email:
delivered, soft bounce, hard bounce, complaint, suppressed, or failed.

## Quick checklist

- [ ] SMTP configured and a test email received
- [ ] Sender address on your own domain
- [ ] SPF, DKIM and DMARC records added; a Gmail "Show original" shows three passes
- [ ] Bounce address and mailbox, **or** provider webhooks, set up
- [ ] Cron running (System health → Cron)
- [ ] Campaigns go only to people who opted in (essential notices excepted)
- [ ] Bounce rate below 2 % and complaint rate below 0.1 % on System health
