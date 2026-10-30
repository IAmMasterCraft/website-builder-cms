<?php
declare(strict_types=1);

/*
 * Transactional email templates (§5). Defaults are defined here; admins
 * with the communications permission can override the subject, body and
 * button label (stored in email_templates). Bodies are plain text with
 * {placeholders}: a blank line starts a new paragraph. Placeholder values
 * are escaped when the HTML version is built.
 */

/** Placeholders every template can use. */
function email_common_placeholders(): array
{
    return ['site_name' => 'Organisation name', 'site_url' => 'Website address'];
}

/** @return array<string,array<string,mixed>> */
function email_template_defs(): array
{
    return [
        'verify_email' => [
            'label' => 'Verify email address', 'group' => 'Membership',
            'description' => 'Sent after sign-up (members and groups) and when someone asks for a new link.',
            'placeholders' => ['first_name' => 'First name (or contact person)', 'verify_url' => 'Verification link', 'days' => 'Days the link works', 'account_label' => '“your membership” or “the account for <group>”'],
            'subject' => 'Please verify your email',
            'body' => "Hello {first_name},\n\nThank you for signing up with {site_name}. Please confirm this email address to activate {account_label}.\n\nThe link works for {days} days; accounts that are not verified are removed after that. If you did not sign up, you can ignore this email.",
            'button' => ['label' => 'Verify my email', 'url' => 'verify_url'],
        ],
        'welcome_member' => [
            'label' => 'Welcome (with member number)', 'group' => 'Membership',
            'description' => 'Sent when a member verifies their email: the account is active and the member number assigned (D7).',
            'placeholders' => ['first_name' => 'First name', 'member_number' => 'Member number', 'card_url' => 'Link to the digital member card'],
            'subject' => 'Welcome! Your member number is {member_number}',
            'body' => "Hello {first_name},\n\nWelcome to {site_name}! Your email is verified and your membership is active.\n\nYour member number is {member_number}. Your digital member card, with a QR code anyone can check, is in your account.",
            'highlight' => ['label' => 'Your member number', 'value' => 'member_number'],
            'button' => ['label' => 'See my member card', 'url' => 'card_url'],
        ],
        'welcome_group' => [
            'label' => 'Welcome (group account)', 'group' => 'Membership',
            'description' => 'Sent when a group verifies its email and is listed in the directory.',
            'placeholders' => ['first_name' => 'Contact person', 'group_name' => 'Group name', 'group_number' => 'Group ID'],
            'subject' => 'Your group is registered ({group_number})',
            'body' => "Hello {first_name},\n\n{group_name} is now registered with {site_name} and listed in our groups directory.\n\nYour group ID is {group_number}.",
            'highlight' => ['label' => 'Your group ID', 'value' => 'group_number'],
        ],
        'welcome_import' => [
            'label' => 'Welcome (imported member)', 'group' => 'Membership',
            'description' => 'Optional email for members created by a CSV import.',
            'placeholders' => ['first_name' => 'First name', 'member_number' => 'Member number', 'login_url' => 'Link to choose a password'],
            'subject' => 'Your member account is ready',
            'body' => "Hello {first_name},\n\nYou now have an online account with {site_name}. Your member number is {member_number}.\n\nTo log in for the first time, choose a password with “Forgot password” and this email address.",
            'button' => ['label' => 'Choose my password', 'url' => 'login_url'],
        ],
        'password_reset' => [
            'label' => 'Password reset', 'group' => 'Membership',
            'description' => 'Sent from “Forgot password” and when an admin sends a password link.',
            'placeholders' => ['first_name' => 'First name', 'reset_url' => 'Reset link (one hour)'],
            'subject' => 'Reset your password',
            'body' => "Hello {first_name},\n\nWe received a request to set a new password for your {site_name} account. The link works for one hour.\n\nIf you did not ask for this, you can ignore this email; your password will not change.",
            'button' => ['label' => 'Choose a new password', 'url' => 'reset_url'],
        ],
        'event_registration' => [
            'label' => 'Event registration', 'group' => 'Events',
            'description' => 'Confirmation for guests and members who register for an event.',
            'placeholders' => ['first_name' => 'Name given at registration', 'event_title' => 'Event title', 'event_when' => 'Date and time', 'event_where' => 'Venue (or “Online”)', 'ticket' => 'Ticket option', 'quantity' => 'Number of places', 'event_url' => 'Event page', 'ics_url' => 'Calendar file'],
            'subject' => 'You are registered: {event_title}',
            'body' => "Hello {first_name},\n\nYou are registered for {event_title}.\n\nWhen: {event_when}\nWhere: {event_where}\nTicket: {ticket}\nPlaces: {quantity}\n\nAdd it to your calendar: {ics_url}\n\nSee you there,\n{site_name}",
            'button' => ['label' => 'View the event', 'url' => 'event_url'],
        ],
        'job_approved' => [
            'label' => 'Job approved', 'group' => 'Jobs',
            'description' => 'Sent to the member who posted a job when an admin approves it.',
            'placeholders' => ['first_name' => 'First name', 'job_title' => 'Job title', 'job_url' => 'Job page', 'expires' => 'Date the post expires'],
            'subject' => 'Your job post is live: {job_title}',
            'body' => "Hello {first_name},\n\nGood news: your job post “{job_title}” has been approved and members can now see it on the job board.\n\nIt will be shown until {expires}.",
            'button' => ['label' => 'View the job', 'url' => 'job_url'],
        ],
        'job_rejected' => [
            'label' => 'Job not approved', 'group' => 'Jobs',
            'description' => 'Sent when an admin rejects a job post.',
            'placeholders' => ['first_name' => 'First name', 'job_title' => 'Job title', 'reason' => 'Reason given by the admin', 'jobs_url' => 'Your job posts'],
            'subject' => 'Your job post was not approved',
            'body' => "Hello {first_name},\n\nThank you for posting “{job_title}”. We are not able to publish it on the job board.\n\nReason: {reason}\n\nYou can post a new job at any time.",
            'button' => ['label' => 'My job posts', 'url' => 'jobs_url'],
        ],
        'comment_reply' => [
            'label' => 'Reply to a comment', 'group' => 'Community',
            'description' => 'Sent when someone replies to a member’s comment (once the reply is published).',
            'placeholders' => ['first_name' => 'First name', 'replier' => 'Name of the person who replied', 'item_title' => 'Title of the post', 'reply' => 'The reply', 'item_url' => 'Link to the conversation'],
            'subject' => '{replier} replied to your comment',
            'body' => "Hello {first_name},\n\n{replier} replied to your comment on “{item_title}”:\n\n{reply}",
            'button' => ['label' => 'Join the conversation', 'url' => 'item_url'],
        ],
        'message_digest' => [
            'label' => 'Unread messages', 'group' => 'Community',
            'description' => 'Sent 15 minutes after a message arrives if it is still unread (one email per conversation; not sent to people who switched “Message notifications” off or muted the conversation).',
            'placeholders' => ['first_name' => 'First name', 'sender' => 'Who sent the newest message', 'conversation' => 'Conversation name', 'count' => 'Number of unread messages',
                'messages_label' => '“1 unread message” or “3 unread messages”', 'preview' => 'Start of the newest message', 'thread_url' => 'Link to the conversation', 'preferences_url' => 'Where to switch these emails off'],
            'subject' => '{messages_label} from {sender}',
            'body' => "Hello {first_name},\n\nYou have {messages_label} in “{conversation}”. The newest, from {sender}:\n\n{preview}\n\nTo stop these emails, switch off “Message notifications” in your email preferences: {preferences_url}",
            'button' => ['label' => 'Read and reply', 'url' => 'thread_url'],
        ],
        'message_digest_new' => [
            'label' => 'Unread messages (from someone new)', 'group' => 'Community',
            'description' => 'Used instead of “Unread messages” when a member or group started the conversation and the recipient has not replied yet: no sender name and no message text, so messages cannot be used to send unwanted content by email.',
            'placeholders' => ['first_name' => 'First name', 'count' => 'Number of unread messages', 'messages_label' => '“1 unread message” or “3 unread messages”',
                'thread_url' => 'Link to the conversation', 'preferences_url' => 'Where to switch these emails off'],
            'subject' => 'You have a new message on {site_name}',
            'body' => "Hello {first_name},\n\nYou have {messages_label} on {site_name}. Log in to read it.\n\nTo stop these emails, switch off “Message notifications” in your email preferences: {preferences_url}",
            'button' => ['label' => 'Read the message', 'url' => 'thread_url'],
        ],
        'birthday_greeting' => [
            'label' => 'Birthday greeting', 'group' => 'Community',
            'description' => 'Sent by the daily birthday job to members who keep birthday greetings switched on.',
            'placeholders' => ['first_name' => 'First name', 'preferences_url' => 'Email preferences'],
            'subject' => 'Happy birthday, {first_name}!',
            'body' => "Dear {first_name},\n\nEveryone at {site_name} wishes you a very happy birthday. Thank you for being part of our community; we hope your day is full of joy.\n\nWith warm wishes,\n{site_name}",
            'button' => ['label' => 'Visit your account', 'url' => 'account_url'],
            'extra_vars' => ['account_url'],
        ],
        'contact_copy' => [
            'label' => 'Contact form copy', 'group' => 'Contact',
            'description' => 'Confirmation sent to the person who used the contact form, only when they tick “Email me a confirmation”. It never repeats their message, so the form cannot be used to send text to someone else.',
            'placeholders' => ['first_name' => 'Name given in the form', 'subject' => 'Subject'],
            'subject' => 'We received your message',
            'body' => "Hello {first_name},\n\nThank you for getting in touch with {site_name}. We have received your message and a volunteer will reply by email.\n\nIf you did not use our contact form, you can ignore this email.",
        ],
        'contact_reply' => [
            'label' => 'Reply from the inbox', 'group' => 'Contact',
            'description' => 'Wraps a reply written in the admin inbox.',
            'placeholders' => ['first_name' => 'Name given in the form', 'reply' => 'The reply text', 'original' => 'Their original message', 'sender' => 'Name of the admin replying'],
            'subject' => 'Re: {subject}',
            'body' => "Hello {first_name},\n\n{reply}\n\n{sender}\n{site_name}\n\nYour original message:\n{original}",
            'extra_vars' => ['subject'],
        ],
        'contact_message' => [
            'label' => 'Contact form message (to the organisation)', 'group' => 'Admin',
            'description' => 'Sent to the organisation’s contact address for every contact-form message.',
            'placeholders' => ['name' => 'Sender name', 'email' => 'Sender email', 'phone' => 'Sender phone', 'subject' => 'Subject', 'message' => 'Message', 'inbox_url' => 'Message in the admin inbox'],
            'subject' => 'Website message: {subject}',
            'body' => "New message from the website contact form.\n\nFrom: {name} ({email})\nPhone: {phone}\nSubject: {subject}\n\n{message}",
            'button' => ['label' => 'Open in the inbox', 'url' => 'inbox_url'],
            'reply_to' => 'email',
        ],
        'email_change' => [
            'label' => 'Confirm a new email address', 'group' => 'Membership',
            'description' => 'Sent to the new address when a member changes their email in their account.',
            'placeholders' => ['first_name' => 'First name', 'new_email' => 'New email address', 'confirm_url' => 'Confirmation link', 'days' => 'Days the link works'],
            'subject' => 'Confirm your new email address',
            'body' => "Hello {first_name},\n\nYou asked to use {new_email} for your {site_name} account. Please confirm this address; until you do, we keep using your old one.\n\nThe link works for {days} days. If you did not ask for this, you can ignore this email.",
            'button' => ['label' => 'Confirm my new email', 'url' => 'confirm_url'],
        ],
        'signup_existing' => [
            'label' => 'Sign-up with an existing address', 'group' => 'Membership',
            'description' => 'Sent instead of a verification link when someone signs up with an address that already has an account, so the join form does not reveal who is registered.',
            'placeholders' => ['first_name' => 'First name', 'login_url' => 'Log-in page', 'reset_url' => 'Forgot password page'],
            'subject' => 'You already have an account',
            'body' => "Hello {first_name},\n\nSomeone (hopefully you) tried to sign up to {site_name} with this email address, but it already has an account.\n\nIf it was you, log in instead. If you have forgotten your password, use “Forgot password”: {reset_url}\n\nIf it was not you, you can ignore this email; nothing has changed.",
            'button' => ['label' => 'Log in', 'url' => 'login_url'],
        ],
        'email_change_notice' => [
            'label' => 'Email change notice (old address)', 'group' => 'Membership',
            'description' => 'Sent to the old address when a member asks to change their email, so a stolen login is noticed.',
            'placeholders' => ['first_name' => 'First name', 'new_email' => 'New email address (partly hidden)'],
            'subject' => 'Your email address is being changed',
            'body' => "Hello {first_name},\n\nSomeone signed in to your {site_name} account asked to change its email address to {new_email}. The change only happens once the new address is confirmed.\n\nIf this was not you, change your password now and contact us.",
        ],
        'admin_invite' => [
            'label' => 'Admin invitation', 'group' => 'Admin',
            'description' => 'Sent when a super admin creates an admin account without setting a password.',
            'placeholders' => ['first_name' => 'First name', 'role_label' => 'Role (Admin or Super admin)', 'reset_url' => 'Link to choose a password', 'hours' => 'Hours the link works'],
            'subject' => 'You have been given admin access to {site_name}',
            'body' => "Hello {first_name},\n\nYou now have an {role_label} account for the {site_name} website. Choose your password with the link below; it works for {hours} hours.\n\nAfterwards, log in with this email address. If the link has expired, use “Forgot password” on the login page.",
            'button' => ['label' => 'Choose my password', 'url' => 'reset_url'],
        ],
        'deletion_request' => [
            'label' => 'Deletion request (to super admins)', 'group' => 'Admin',
            'description' => 'Sent when a member asks for their account to be deleted.',
            'placeholders' => ['name' => 'Member name', 'email' => 'Member email', 'member_number' => 'Member number', 'admin_url' => 'Member in the admin area'],
            'subject' => 'Account deletion request',
            'body' => "{name} ({email}, {member_number}) has asked for their account to be deleted.\n\nPlease review and confirm the deletion in the admin area.",
            'button' => ['label' => 'Review the request', 'url' => 'admin_url'],
        ],
        'birthday_digest' => [
            'label' => 'Birthday notice: daily (to admins)', 'group' => 'Admin',
            'description' => 'Sent at the birthday hour when there are birthdays today (Settings → Birthdays: on/off, recipients, empty days). Lists today’s birthdays with links, then the next 7 days. Never the birth year or age.',
            'placeholders' => ['first_name' => 'Admin first name', 'today_list' => 'Birthdays today (name, member number, day, links)', 'week_list' => 'Birthdays in the next 7 days', 'date' => 'Today’s date', 'count' => 'Number of birthdays today'],
            'subject' => 'Birthdays for {date}',
            'body' => "Hello {first_name},\n\nToday:\n{today_list}\n\nNext 7 days:\n{week_list}\n\nOpted-in members received a birthday greeting.",
            'button' => ['label' => 'Open the birthday list', 'url' => 'admin_url'],
            'extra_vars' => ['admin_url'],
        ],
        'birthday_digest_weekly' => [
            'label' => 'Birthday notice: weekly (to admins)', 'group' => 'Admin',
            'description' => 'Sent on Mondays at the birthday hour: every birthday from Monday to Sunday (Settings → Birthdays). Never the birth year or age.',
            'placeholders' => ['first_name' => 'Admin first name', 'week' => 'The week, e.g. 2 Mar – 8 Mar 2026', 'count' => 'Number of birthdays', 'birthday_list' => 'Birthdays this week (name, member number, day, links)'],
            'subject' => 'Birthdays this week ({week})',
            'body' => "Hello {first_name},\n\nBirthdays this week ({week}):\n\n{birthday_list}",
            'button' => ['label' => 'Open the birthday list', 'url' => 'admin_url'],
            'extra_vars' => ['admin_url'],
        ],
        'birthday_digest_monthly' => [
            'label' => 'Birthday notice: monthly (to admins)', 'group' => 'Admin',
            'description' => 'Sent on the 1st of the month at the birthday hour: every birthday in the month, grouped by week (Settings → Birthdays). Never the birth year or age.',
            'placeholders' => ['first_name' => 'Admin first name', 'month' => 'Month and year', 'count' => 'Number of birthdays', 'birthday_list' => 'Birthdays this month, grouped by week'],
            'subject' => 'Birthdays in {month}',
            'body' => "Hello {first_name},\n\n{count} birthdays in {month}:\n\n{birthday_list}",
            'button' => ['label' => 'Open the birthday list', 'url' => 'admin_url'],
            'extra_vars' => ['admin_url'],
        ],
        'impersonation_notice' => [
            'label' => 'An administrator viewed your account', 'group' => 'Membership',
            'description' => 'Sent to a member or group when an admin uses “Log in as this member” (only while the site setting “Email the member when an admin views their account” is on).',
            'placeholders' => ['first_name' => 'First name (or contact person)', 'date' => 'Date and time the admin started', 'admin_name' => 'Name of the admin', 'account_url' => 'Link to the account'],
            'subject' => 'An administrator viewed your {site_name} account',
            'body' => "Hello {first_name},\n\nThis is a short notice that an administrator of {site_name} viewed your account on {date}, usually to help with a question or check that something works.\n\nThey could see your account pages as you see them. They could not see or change your password.\n\nIf you were not expecting this, please contact us.",
            'button' => ['label' => 'Go to my account', 'url' => 'account_url'],
        ],
        'payment_received' => [
            'label' => 'Payment report received', 'group' => 'Payments',
            'description' => 'Optional (Settings → Payments, off by default): sent to a member when they report a payment.',
            'placeholders' => ['first_name' => 'First name', 'item' => 'What the payment was for', 'amount' => 'Amount', 'date_paid' => 'Date paid', 'payment_url' => 'The record in the member’s account'],
            'subject' => 'We received your payment report',
            'body' => "Hello {first_name},\n\nThank you. We received your report of {amount} for {item}, paid on {date_paid}. We will check it and let you know when it is confirmed.",
            'button' => ['label' => 'See my payments', 'url' => 'payment_url'],
        ],
        'payment_approved' => [
            'label' => 'Payment confirmed (with receipt)', 'group' => 'Payments',
            'description' => 'Sent when an admin approves a payment report or records a payment for the member.',
            'placeholders' => ['first_name' => 'First name', 'item' => 'What the payment was for', 'amount' => 'Amount', 'date_paid' => 'Date paid', 'receipt_number' => 'Receipt number', 'receipt_url' => 'Printable receipt'],
            'subject' => 'Payment confirmed: {item}',
            'body' => "Hello {first_name},\n\nYour payment of {amount} for {item}, paid on {date_paid}, is confirmed. Thank you!\n\nYour receipt number is {receipt_number}. You can view and print the receipt in your account.",
            'button' => ['label' => 'View my receipt', 'url' => 'receipt_url'],
        ],
        'payment_rejected' => [
            'label' => 'Payment report not confirmed', 'group' => 'Payments',
            'description' => 'Sent when an admin rejects a payment report, with the reason.',
            'placeholders' => ['first_name' => 'First name', 'item' => 'What the payment was for', 'amount' => 'Amount', 'date_paid' => 'Date paid', 'reason' => 'Reason given', 'payment_url' => 'The record in the member’s account'],
            'subject' => 'We could not confirm your payment',
            'body' => "Hello {first_name},\n\nWe could not confirm your report of {amount} for {item}, paid on {date_paid}.\n\nReason: {reason}\n\nIf you think this is a mistake, please report the payment again with the details or contact us.",
            'button' => ['label' => 'See my payments', 'url' => 'payment_url'],
        ],
        'payment_needs_info' => [
            'label' => 'Payment report: more information needed', 'group' => 'Payments',
            'description' => 'Sent when an admin asks a question about a payment report; the member answers by editing the record.',
            'placeholders' => ['first_name' => 'First name', 'item' => 'What the payment was for', 'amount' => 'Amount', 'date_paid' => 'Date paid', 'question' => 'The admin’s question', 'reviewer' => 'Who asks (Settings → Payments)', 'payment_url' => 'The record to edit'],
            'subject' => 'A question about your payment for {item}',
            'body' => "Hello {first_name},\n\nThe {reviewer} has a question about your report of {amount} for {item}, paid on {date_paid}:\n\n{question}\n\nPlease answer by editing the payment record in your account (for example add the reference or a clearer receipt).",
            'button' => ['label' => 'Answer now', 'url' => 'payment_url'],
        ],
        'payment_reminder' => [
            'label' => 'Payment reminder (due date)', 'group' => 'Payments',
            'description' => 'Sent automatically before and after an item’s due date to members with a balance (per item; defaults 7 days before and 14 days after). Not sent to suppressed addresses.',
            'placeholders' => ['first_name' => 'First name', 'item' => 'Item name', 'amount' => 'Amount due', 'balance' => 'Balance still to pay', 'due_date' => 'Due date', 'pay_url' => 'Payments page in the account', 'bank_details' => 'Bank details (Settings → Payments)'],
            'subject' => 'Reminder: {item} ({balance} to pay)',
            'body' => "Hello {first_name},\n\nThis is a friendly reminder that {item} is due on {due_date}. You still have {balance} to pay.\n\n{bank_details}\n\nOnce you have paid, please report the payment in your account so we can confirm it. If you have already paid, thank you, and please ignore this email.",
            'button' => ['label' => 'Report a payment', 'url' => 'pay_url'],
        ],
        'payment_remind_unpaid' => [
            'label' => 'Payment reminder (sent by an admin)', 'group' => 'Payments',
            'description' => 'Sent with “Remind unpaid” on an item’s page to members who have not paid in full (at most once a day each).',
            'placeholders' => ['first_name' => 'First name', 'item' => 'Item name', 'amount' => 'Amount due', 'balance' => 'Balance still to pay', 'due_date' => 'Due date', 'pay_url' => 'Payments page in the account', 'bank_details' => 'Bank details (Settings → Payments)'],
            'subject' => 'Reminder: {item}',
            'body' => "Hello {first_name},\n\nOur records show {balance} still to pay for {item} (due {due_date}).\n\n{bank_details}\n\nOnce you have paid, please report the payment in your account so we can confirm it. If you have already paid, thank you, and please ignore this email.",
            'button' => ['label' => 'Report a payment', 'url' => 'pay_url'],
        ],
        'payment_reported_admin' => [
            'label' => 'New payment reports (to admins)', 'group' => 'Payments',
            'description' => 'Sent to admins with the Payments permission when members report or edit payments: at most one email per admin every 15 minutes, listing the new reports. Each admin can switch these emails off on the Payments page.',
            'placeholders' => ['first_name' => 'Admin first name', 'count' => 'Number of reports', 'report_list' => 'The reports (member, item, amount, date, method, reference, review link)', 'queue_url' => 'Pending payments', 'preferences_url' => 'Where to switch these emails off'],
            'subject' => 'Payment reports to review ({count})',
            'body' => "Hello {first_name},\n\nNew or changed payment reports are waiting for review:\n\n{report_list}\n\nTo stop these emails, untick “Email me about new payment reports” on the Payments page: {preferences_url}",
            'button' => ['label' => 'Review payments', 'url' => 'queue_url'],
        ],
        'job_pending' => [
            'label' => 'Job awaiting approval (to admins)', 'group' => 'Admin',
            'description' => 'Sent to job moderators when a member posts a job that needs approval.',
            'placeholders' => ['first_name' => 'Admin first name', 'job_title' => 'Job title', 'poster' => 'Member who posted it', 'queue_url' => 'Jobs queue'],
            'subject' => 'Job awaiting approval: {job_title}',
            'body' => "Hello {first_name},\n\n{poster} posted “{job_title}”. It is waiting for approval on the job board queue.",
            'button' => ['label' => 'Review jobs', 'url' => 'queue_url'],
        ],
    ];
}

function email_template_def(string $key): array
{
    $defs = email_template_defs();
    if (!isset($defs[$key])) {
        throw new InvalidArgumentException('Unknown email template ' . $key);
    }
    return $defs[$key];
}

/** Default merged with any saved override. */
function email_template(string $key): array
{
    $def = email_template_def($key);
    $row = null;
    try {
        $row = q_one('SELECT subject, body, button_label, updated_at FROM email_templates WHERE template_key = ?', [$key]);
    } catch (PDOException) {
        // Before migration 007: defaults only.
    }
    return [
        'key' => $key,
        'subject' => $row['subject'] ?? $def['subject'],
        'body' => $row['body'] ?? $def['body'],
        'button_label' => $row !== null && $row['button_label'] !== '' ? $row['button_label'] : ($def['button']['label'] ?? ''),
        'customised' => $row !== null,
        'updated_at' => $row['updated_at'] ?? null,
    ] + $def;
}

/** Placeholder names a template may use. */
function email_template_allowed(array $def): array
{
    return array_merge(array_keys($def['placeholders']), array_keys(email_common_placeholders()), $def['extra_vars'] ?? []);
}

/** {names} used in a text. */
function email_placeholders_in(string $text): array
{
    preg_match_all('/\{([a-z_][a-z0-9_]*)\}/', $text, $m);
    return array_values(array_unique($m[1]));
}

/**
 * Validate an edited template.
 * @return array<string,string> field => error
 */
function email_template_validate(string $key, string $subject, string $body, string $buttonLabel): array
{
    $def = email_template_def($key);
    $allowed = email_template_allowed($def);
    $errors = [];
    if ($subject === '' || mb_strlen($subject) > 200 || preg_match('/[\r\n]/', $subject)) {
        $errors['subject'] = 'Enter a subject line (one line, up to 200 characters).';
    }
    if ($body === '' || mb_strlen($body) > 8000) {
        $errors['body'] = 'Write the email text (up to 8,000 characters).';
    }
    if (mb_strlen($buttonLabel) > 60) {
        $errors['button_label'] = 'Keep the button text under 60 characters.';
    }
    foreach (['subject' => $subject, 'body' => $body] as $field => $text) {
        $unknown = array_diff(email_placeholders_in($text), $allowed);
        if ($unknown !== [] && !isset($errors[$field])) {
            $errors[$field] = 'Unknown placeholder' . (count($unknown) > 1 ? 's' : '') . ': {' . implode('}, {', $unknown) . '}. Use only the placeholders listed.';
        }
    }
    return $errors;
}

/** Replace {placeholders} in plain text. Unknown or missing ones become empty. */
function email_fill(string $text, array $vars): string
{
    return (string) preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}/', static fn (array $m): string => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : '', $text);
}

/** Plain-text body (with placeholders) to email HTML paragraphs, values escaped. */
function email_text_to_html(string $text, array $vars, array $colours): string
{
    $out = '';
    foreach (preg_split('/\R{2,}/', trim($text)) ?: [] as $para) {
        $html = (string) preg_replace_callback('/\{([a-z_][a-z0-9_]*)\}|([^{]+|\{)/', static function (array $m) use ($vars): string {
            if (($m[1] ?? '') !== '') {
                return nl2br(e((string) ($vars[$m[1]] ?? '')), false);
            }
            return nl2br(e($m[2] ?? ''), false);
        }, $para);
        $out .= '<p style="margin:0 0 16px">' . $html . '</p>';
    }
    return $out;
}

/** Colours for emails, taken from the active theme (with readable inks). */
function email_colours(): array
{
    $d = design_effective();
    $t = design_tokens($d);
    return [
        'brand' => $d['brand'],
        'brand_ink' => $t['--c-brand-ink'],
        'accent' => $d['accent'],
        'accent_ink' => $t['--c-accent-ink'],
        'link' => $t['--c-link'],
        'soft' => $t['--c-brand-soft'],
        'button_radius' => $t['--button-radius'],
    ];
}

/** Bulletproof (table) button. */
function email_button(string $label, string $url, array $c): string
{
    if ($label === '' || safe_url($url) === '') {
        return '';
    }
    $radius = $c['button_radius'] === '999px' ? '999px' : $c['button_radius'];
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 20px"><tr><td style="background:' . e($c['accent']) . ';border-radius:' . e($radius) . '">'
        . '<a href="' . e($url) . '" style="display:inline-block;padding:13px 24px;color:' . e($c['accent_ink']) . ';font-weight:700;text-decoration:none;font-size:16px;border-radius:' . e($radius) . '">' . e($label) . '</a></td></tr></table>';
}

/**
 * Wrap body HTML in the responsive email layout.
 * @param array{preheader?:string, unsubscribe_url?:string, footer_note?:string, colours?:array} $opts
 */
function email_layout(string $bodyHtml, array $opts = []): string
{
    return view('email/_layout', [
        'body' => $bodyHtml,
        'site_name' => (string) setting('site.name'),
        'site_url' => base_url('/'),
        'address' => (string) setting('contact.address', ''),
        'charity_number' => (string) setting('org.charity_number', ''),
        'logo' => media_find((int) setting('site.logo_media_id', 0) ?: null),
        'c' => $opts['colours'] ?? email_colours(),
        'preheader' => (string) ($opts['preheader'] ?? ''),
        'unsubscribe_url' => (string) ($opts['unsubscribe_url'] ?? ''),
        'footer_note' => (string) ($opts['footer_note'] ?? 'You received this email because of an action on the ' . setting('site.name') . ' website.'),
    ]);
}

/** Sample values used for previews and test sends. */
function email_template_samples(array $def): array
{
    $out = [];
    foreach ($def['placeholders'] as $name => $label) {
        $out[$name] = match (true) {
            $name === 'first_name' || $name === 'name' => 'Amara',
            str_ends_with($name, '_url') => base_url('/'),
            $name === 'member_number' => 'ORG-26-0042',
            $name === 'group_number' => 'GRP-26-0007',
            $name === 'days' => (string) VERIFY_DAYS,
            $name === 'quantity' => '2',
            $name === 'email' => 'amara@example.org',
            str_ends_with($name, '_list') => "Amara O. (14 Mar)\nTomasz K. (16 Mar)",
            default => '[' . $label . ']',
        };
    }
    foreach ($def['extra_vars'] ?? [] as $name) {
        $out[$name] ??= str_ends_with($name, '_url') ? base_url('/') : '[' . $name . ']';
    }
    return $out;
}

/**
 * Render a template to subject/text/html.
 * @return array{subject:string, text:string, html:string, reply_to:?string}
 */
function email_template_render(string $key, array $vars, ?array $override = null): array
{
    $t = email_template($key);
    if ($override !== null) {
        $t = array_merge($t, $override);
    }
    $vars += ['site_name' => (string) setting('site.name'), 'site_url' => base_url('/')];
    $vars = array_map(static fn ($v): string => is_scalar($v) || $v === null ? (string) $v : '', $vars);
    $c = email_colours();
    $subject = trim((string) preg_replace('/\s+/', ' ', email_fill($t['subject'], $vars)));
    $text = email_fill($t['body'], $vars);
    $html = '';
    if (isset($t['highlight']) && ($vars[$t['highlight']['value']] ?? '') !== '') {
        $html .= '<p style="margin:0 0 4px;font-size:13px;letter-spacing:.06em;text-transform:uppercase;color:#5a6779">' . e($t['highlight']['label']) . '</p>'
            . '<p style="margin:0 0 20px;font-size:28px;font-weight:800;letter-spacing:.03em;color:' . e($c['brand']) . '">' . e($vars[$t['highlight']['value']]) . '</p>';
    }
    $body = email_text_to_html($t['body'], $vars, $c);
    $html = $body . $html;
    if (isset($t['button'])) {
        $url = $vars[$t['button']['url']] ?? '';
        $label = $t['button_label'] !== '' ? $t['button_label'] : $t['button']['label'];
        $html .= email_button($label, $url, $c);
        if ($url !== '') {
            $text .= "\n\n" . $label . ': ' . $url;
        }
    }
    $replyTo = isset($t['reply_to']) && filter_var($vars[$t['reply_to']] ?? '', FILTER_VALIDATE_EMAIL) ? $vars[$t['reply_to']] : null;
    $text = trim($text) . "\n\n--\n" . email_prefs_text();
    return ['subject' => $subject !== '' ? $subject : (string) setting('site.name'), 'text' => trim($text) . "\n", 'html' => email_layout($html, ['colours' => $c]), 'reply_to' => $replyTo];
}

/**
 * Send rendered email HTML for an admin preview iframe. Emails need inline
 * styles, so this response alone gets a CSP that allows them (and nothing
 * else: no scripts, no forms).
 */
function email_preview_out(string $html): never
{
    header("Content-Security-Policy: default-src 'none'; img-src 'self' data: https: http:; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Frame-Options: SAMEORIGIN');
    // Links open outside the preview frame.
    echo str_replace('<head>', '<head><base target="_blank">', $html);
    exit;
}
