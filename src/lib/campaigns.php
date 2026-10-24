<?php
declare(strict_types=1);

use Core\Mail\Message;

/*
 * Email campaigns (§5): a list of blocks (heading, text, image, button,
 * divider, latest posts, upcoming events) rendered into the responsive
 * email layout. When a campaign is queued its HTML is rendered once (so
 * every recipient gets the same content) and one campaign_recipients row
 * is written per address; {first_name} and the unsubscribe link are filled
 * in per recipient at send time.
 */

const CAMPAIGN_MAX_BLOCKS = 40;
const CAMPAIGN_UNSUB_TOKEN = '%%UNSUBSCRIBE_URL%%';

function campaign_block_types(): array
{
    return [
        'heading' => ['label' => 'Heading', 'defaults' => ['text' => 'A heading', 'size' => 'large']],
        'text' => ['label' => 'Text', 'defaults' => ['html' => '<p>Write your message here.</p>']],
        'image' => ['label' => 'Image', 'defaults' => ['media_id' => null, 'alt' => '', 'link' => '']],
        'button' => ['label' => 'Button', 'defaults' => ['label' => 'Read more', 'url' => '/']],
        'divider' => ['label' => 'Divider', 'defaults' => []],
        'latest_posts' => ['label' => 'Latest posts', 'defaults' => ['count' => 3, 'base' => '']],
        'upcoming_events' => ['label' => 'Upcoming events', 'defaults' => ['count' => 3]],
    ];
}

function campaign_new_block(string $type): array
{
    $types = campaign_block_types();
    if (!isset($types[$type])) {
        throw new InvalidArgumentException('Unknown block type');
    }
    return ['type' => $type] + $types[$type]['defaults'];
}

/** Starter content for a new campaign. */
function campaign_starter_blocks(): array
{
    return [
        ['type' => 'heading', 'text' => 'News from ' . setting('site.name'), 'size' => 'large'],
        ['type' => 'text', 'html' => '<p>Hello {first_name},</p><p>Here is what has been happening in our community, and what is coming up next.</p>'],
        ['type' => 'latest_posts', 'count' => 3, 'base' => ''],
        ['type' => 'divider'],
        ['type' => 'upcoming_events', 'count' => 3],
        ['type' => 'button', 'label' => 'Visit our website', 'url' => '/'],
    ];
}

/**
 * Validate blocks from the editor form (blocks[i][field]).
 * @return array{0:list<array>,1:array<string,string>}
 */
function campaign_blocks_validate(mixed $raw): array
{
    $blocks = [];
    $errors = [];
    if (!is_array($raw)) {
        return [[], []];
    }
    foreach (array_values(array_slice($raw, 0, CAMPAIGN_MAX_BLOCKS)) as $i => $b) {
        if (!is_array($b) || !isset(campaign_block_types()[(string) ($b['type'] ?? '')])) {
            continue;
        }
        $type = (string) $b['type'];
        $str = static fn (string $k, int $max = 300): string => mb_substr(trim(is_string($b[$k] ?? null) ? $b[$k] : ''), 0, $max);
        $n = count($blocks);
        switch ($type) {
            case 'heading':
                $text = $str('text', 200);
                if ($text === '') {
                    $errors["b$n.text"] = 'Enter the heading text.';
                }
                $blocks[] = ['type' => $type, 'text' => $text, 'size' => ($b['size'] ?? '') === 'medium' ? 'medium' : 'large'];
                break;
            case 'text':
                $html = \Core\Sanitizer::clean(is_string($b['html'] ?? null) ? mb_substr($b['html'], 0, 20000) : '');
                if (trim(strip_tags($html)) === '') {
                    $errors["b$n.html"] = 'Write some text or remove this block.';
                }
                $blocks[] = ['type' => $type, 'html' => $html];
                break;
            case 'image':
                $id = (int) ($b['media_id'] ?? 0);
                $m = $id > 0 ? media_find($id) : null;
                if ($m === null || $m['kind'] !== 'image' || $m['visibility'] !== 'public') {
                    $errors["b$n.media_id"] = 'Choose a public image from the media library.';
                    $id = 0;
                }
                $link = $str('link', 500);
                if ($link !== '' && safe_url($link) === '') {
                    $errors["b$n.link"] = 'Use a full https:// link or a path starting with /.';
                }
                $blocks[] = ['type' => $type, 'media_id' => $id ?: null, 'alt' => $str('alt', 300), 'link' => $link];
                break;
            case 'button':
                $label = $str('label', 60);
                $url = $str('url', 500);
                if ($label === '') {
                    $errors["b$n.label"] = 'Enter the button text.';
                }
                if (safe_url($url) === '') {
                    $errors["b$n.url"] = 'Use a full https:// link or a path starting with /.';
                }
                $blocks[] = ['type' => $type, 'label' => $label, 'url' => $url];
                break;
            case 'divider':
                $blocks[] = ['type' => $type];
                break;
            case 'latest_posts':
                $base = in_array($b['base'] ?? '', ['news', 'blog', 'projects'], true) ? (string) $b['base'] : '';
                $blocks[] = ['type' => $type, 'count' => max(1, min(6, (int) ($b['count'] ?? 3))), 'base' => $base];
                break;
            case 'upcoming_events':
                $blocks[] = ['type' => $type, 'count' => max(1, min(6, (int) ($b['count'] ?? 3)))];
                break;
        }
    }
    return [$blocks, $errors];
}

function campaign_blocks_decode(?string $json): array
{
    $b = json_decode((string) $json, true);
    if (!is_array($b)) {
        return [];
    }
    [$blocks] = campaign_blocks_validate($b);
    return $blocks;
}

/** Absolute URL for links in emails (relative paths become site links). */
function email_abs_url(string $url): string
{
    $url = safe_url($url);
    if ($url === '') {
        return '';
    }
    return str_starts_with($url, '/') ? base_url($url) : $url;
}

/** Add inline styles to sanitised rich text for mail clients. */
function email_style_rich(string $html, array $c): string
{
    $map = [
        '<p>' => '<p style="margin:0 0 16px">',
        '<h2>' => '<h2 style="margin:24px 0 10px;font-size:22px;line-height:1.3;color:#14202e">',
        '<h3>' => '<h3 style="margin:20px 0 8px;font-size:18px;line-height:1.3;color:#14202e">',
        '<h4>' => '<h4 style="margin:18px 0 6px;font-size:16px;color:#14202e">',
        '<ul>' => '<ul style="margin:0 0 16px;padding-left:22px">',
        '<ol>' => '<ol style="margin:0 0 16px;padding-left:22px">',
        '<li>' => '<li style="margin:0 0 6px">',
        '<blockquote>' => '<blockquote style="margin:0 0 16px;padding-left:14px;border-left:4px solid ' . e($c['accent']) . ';color:#3a4656">',
    ];
    $html = strtr($html, $map);
    $html = (string) preg_replace('/<a /', '<a style="color:' . e($c['link']) . ';text-decoration:underline" ', $html);
    return (string) preg_replace('/<img /', '<img style="max-width:100%;height:auto;border:0" ', $html);
}

/** Rough plain-text version of email HTML. */
function html_to_text(string $html): string
{
    $html = (string) preg_replace('/<a [^>]*href="([^"]+)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html);
    $html = (string) preg_replace('/<(br|\/p|\/h[1-6]|\/li|\/tr|\/blockquote)[^>]*>/i', "\n", $html);
    $html = (string) preg_replace('/<li[^>]*>/i', '- ', $html);
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = (string) preg_replace("/[ \t]+/", ' ', $text);
    $text = (string) preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text);
    return trim(implode("\n", array_map('trim', explode("\n", $text))));
}

/**
 * Body HTML and text for a list of blocks. Dynamic blocks query live content.
 * @return array{html:string, text:string}
 */
function campaign_render_blocks(array $blocks, array $c, ?DateTimeImmutable $now = null): array
{
    $html = '';
    $text = [];
    foreach ($blocks as $b) {
        switch ($b['type']) {
            case 'heading':
                $size = $b['size'] === 'medium' ? '20px' : '26px';
                $html .= '<h2 class="email-h1" style="margin:0 0 14px;font-size:' . $size . ';line-height:1.25;letter-spacing:-.01em;color:#14202e">' . e($b['text']) . '</h2>';
                $text[] = mb_strtoupper($b['text']);
                break;
            case 'text':
                $rich = email_style_rich($b['html'], $c);
                $html .= '<div>' . $rich . '</div>';
                $text[] = html_to_text($b['html']);
                break;
            case 'image':
                $m = media_find($b['media_id'] ? (int) $b['media_id'] : null);
                if ($m === null) {
                    break;
                }
                $img = '<img src="' . e(base_url(media_url($m, 800))) . '" alt="' . e($b['alt'] !== '' ? $b['alt'] : $m['alt']) . '" width="536" style="display:block;width:100%;max-width:536px;height:auto;border:0;border-radius:10px">';
                $link = email_abs_url($b['link']);
                $html .= '<div style="margin:0 0 20px">' . ($link !== '' ? '<a href="' . e($link) . '">' . $img . '</a>' : $img) . '</div>';
                if ($b['alt'] !== '') {
                    $text[] = '[' . $b['alt'] . ']' . ($link !== '' ? ' ' . $link : '');
                }
                break;
            case 'button':
                $url = email_abs_url($b['url']);
                $html .= email_button($b['label'], $url, $c);
                $text[] = $b['label'] . ': ' . $url;
                break;
            case 'divider':
                $html .= '<hr style="border:0;border-top:1px solid #dde3ea;margin:24px 0">';
                $text[] = '----';
                break;
            case 'latest_posts':
                $params = [];
                $where = published_sql('p') . " AND p.visibility = 'public'";
                if ($b['base'] !== '') {
                    $where .= ' AND c.base = ?';
                    $params[] = $b['base'];
                }
                $posts = q_all('SELECT p.*, c.base, c.name AS category_name FROM posts p JOIN categories c ON c.id = p.category_id WHERE ' . $where . ' ORDER BY p.publish_at DESC LIMIT ' . (int) $b['count'], $params);
                if ($posts === []) {
                    break;
                }
                $html .= '<h3 style="margin:8px 0 12px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#5a6779">Latest news</h3>';
                foreach ($posts as $p) {
                    $url = base_url(post_url($p));
                    $cover = media_find($p['cover_media_id'] ? (int) $p['cover_media_id'] : null);
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 18px"><tr>';
                    if ($cover !== null && $cover['visibility'] === 'public') {
                        $html .= '<td class="email-col" width="150" valign="top" style="padding:0 16px 8px 0"><a href="' . e($url) . '"><img src="' . e(base_url(media_url($cover, 400))) . '" alt="" width="150" style="display:block;width:150px;max-width:100%;height:auto;border:0;border-radius:8px"></a></td>';
                    }
                    $html .= '<td class="email-col" valign="top"><p style="margin:0 0 2px;font-size:13px;color:#5a6779">' . e(fmt_date($p['publish_at'], 'j F Y')) . ' · ' . e($p['category_name']) . '</p>'
                        . '<p style="margin:0 0 4px;font-size:18px;font-weight:700;line-height:1.3"><a href="' . e($url) . '" style="color:#14202e;text-decoration:none">' . e($p['title']) . '</a></p>'
                        . '<p style="margin:0;font-size:15px;color:#3a4656">' . e(excerpt($p['summary'] ?: $p['body'], 140)) . '</p></td></tr></table>';
                    $text[] = '* ' . $p['title'] . "\n  " . $url;
                }
                break;
            case 'upcoming_events':
                $nowSql = ($now ?? new DateTimeImmutable('now', utc()))->setTimezone(utc())->format('Y-m-d H:i:s');
                $events = q_all('SELECT e.* FROM events e WHERE ' . published_sql('e') . " AND e.visibility = 'public' AND e.start_at >= ? ORDER BY e.start_at LIMIT " . (int) $b['count'], [$nowSql]);
                if ($events === []) {
                    break;
                }
                $html .= '<h3 style="margin:8px 0 12px;font-size:13px;letter-spacing:.08em;text-transform:uppercase;color:#5a6779">Coming up</h3>';
                foreach ($events as $ev) {
                    $url = base_url(event_url($ev));
                    $local = utc_to_local($ev['start_at']);
                    $where = trim((string) ($ev['venue_name'] ?? '')) !== '' ? (string) $ev['venue_name'] : ((string) ($ev['online_url'] ?? '') !== '' ? 'Online' : '');
                    $html .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 14px"><tr>'
                        . '<td width="58" valign="top" style="padding-right:14px"><div style="width:56px;border-radius:10px;background:' . e($c['soft']) . ';text-align:center;padding:6px 0">'
                        . '<div style="font-size:12px;font-weight:700;text-transform:uppercase;color:' . e($c['brand']) . '">' . e($local?->format('M') ?? '') . '</div>'
                        . '<div style="font-size:22px;font-weight:800;line-height:1.1;color:#14202e">' . e($local?->format('j') ?? '') . '</div></div></td>'
                        . '<td valign="top"><p style="margin:0 0 2px;font-size:17px;font-weight:700;line-height:1.3"><a href="' . e($url) . '" style="color:#14202e;text-decoration:none">' . e($ev['title']) . '</a></p>'
                        . '<p style="margin:0;font-size:14px;color:#5a6779">' . e(fmt_datetime($ev['start_at'])) . ($where !== '' ? ' · ' . e($where) : '') . '</p></td></tr></table>';
                    $text[] = '* ' . $ev['title'] . ' (' . fmt_datetime($ev['start_at']) . ")\n  " . $url;
                }
                break;
        }
    }
    return ['html' => $html, 'text' => implode("\n\n", array_filter($text, static fn (string $t): bool => $t !== ''))];
}

/**
 * Full email for a campaign row. With $recipient null the unsubscribe link
 * and first name stay as placeholders (used for the snapshot).
 * @return array{subject:string, html:string, text:string}
 */
function campaign_render(array $campaign, ?array $recipient = null, ?DateTimeImmutable $now = null): array
{
    $c = email_colours();
    $body = campaign_render_blocks(campaign_blocks_decode($campaign['blocks'] ?? null), $c, $now);
    $html = email_layout($body['html'], [
        'colours' => $c,
        'preheader' => (string) ($campaign['preheader'] ?? ''),
        'unsubscribe_url' => CAMPAIGN_UNSUB_TOKEN,
        'footer_note' => 'You are receiving this because you are on the mailing list of ' . setting('site.name') . '.',
    ]);
    $text = $body['text'] . "\n\n--\n" . setting('site.name') . "\nUnsubscribe: " . CAMPAIGN_UNSUB_TOKEN . "\n";
    $out = ['subject' => (string) $campaign['subject'], 'html' => $html, 'text' => $text];
    return $recipient === null ? $out : campaign_personalise($out, $recipient);
}

/** Fill {first_name} and the unsubscribe link for one recipient. */
function campaign_personalise(array $parts, array $recipient): array
{
    $first = trim((string) ($recipient['first_name'] ?? '')) ?: 'friend';
    $unsub = unsubscribe_url((string) $recipient['email']);
    return [
        'subject' => str_replace('{first_name}', $first, $parts['subject']),
        'html' => str_replace(['{first_name}', CAMPAIGN_UNSUB_TOKEN], [e($first), e($unsub)], $parts['html']),
        'text' => str_replace(['{first_name}', CAMPAIGN_UNSUB_TOKEN], [$first, $unsub], $parts['text']),
    ];
}

/** The Message for one queued recipient (uses the snapshot taken when queued). */
function campaign_message_for(array $campaign, array $recipient): Message
{
    // Subject, HTML and text are all frozen when the campaign is queued.
    $parts = ['subject' => (string) ($campaign['subject_snapshot'] ?? $campaign['subject']), 'html' => (string) $campaign['html_snapshot'], 'text' => (string) $campaign['text_snapshot']];
    if ($parts['html'] === '') {
        $parts = campaign_render($campaign);
    }
    $p = campaign_personalise($parts, $recipient);
    return new Message((string) $recipient['email'], (string) $recipient['name'], $p['subject'], $p['text'], $p['html'], null, bulk_headers((string) $recipient['email']));
}

/**
 * Queue a draft or scheduled campaign: snapshot + one row per recipient.
 * Safe to call twice: the status check under a row lock and the unique
 * (campaign, email) key stop duplicates. Returns the number of recipients queued.
 */
function campaign_queue(int $id, ?int $now = null): int
{
    $now ??= time();
    return db_tx(static function () use ($id, $now): int {
        $c = q_one('SELECT * FROM campaigns WHERE id = ? FOR UPDATE', [$id]);
        if ($c === null || !in_array($c['status'], ['draft', 'scheduled'], true)) {
            return 0;
        }
        $nowDt = new DateTimeImmutable('@' . $now);
        $snap = campaign_render($c, null, $nowDt);
        $aud = audience_resolve(audience_decode($c['audience']), true, $nowDt);
        $n = 0;
        foreach ($aud['recipients'] as $r) {
            $n += q('INSERT IGNORE INTO campaign_recipients (campaign_id, user_id, email, name, first_name, next_attempt_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$id, $r['user_id'], $r['email'], mb_substr($r['name'], 0, 120), mb_substr($r['first_name'], 0, 80), gmdate('Y-m-d H:i:s', $now)])->rowCount();
        }
        $total = (int) q_val('SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ?', [$id]);
        q("UPDATE campaigns SET status = ?, queued_at = ?, html_snapshot = ?, subject_snapshot = ?, text_snapshot = ?, recipient_count = ?, completed_at = ? WHERE id = ?",
            [$total > 0 ? 'sending' : 'sent', gmdate('Y-m-d H:i:s', $now), $snap['html'], $snap['subject'], $snap['text'], $total, $total > 0 ? null : gmdate('Y-m-d H:i:s', $now), $id]);
        return $n;
    });
}

/** Cron: queue scheduled campaigns whose time has come. */
function campaigns_due(?int $now = null): int
{
    $now ??= time();
    $n = 0;
    foreach (q_all("SELECT id FROM campaigns WHERE status = 'scheduled' AND send_at <= ?", [gmdate('Y-m-d H:i:s', $now)]) as $r) {
        campaign_queue((int) $r['id'], $now);
        $n++;
    }
    return $n;
}

/** Mark campaigns done when no recipient is left to send. */
function campaigns_finish(int $now): void
{
    q("UPDATE campaigns c SET c.status = 'sent', c.completed_at = ?
        WHERE c.status = 'sending' AND NOT EXISTS (SELECT 1 FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status IN ('queued','sending'))",
        [gmdate('Y-m-d H:i:s', $now)]);
}

/** Delivery counts per status for one campaign. */
function campaign_counts(int $id): array
{
    $out = ['queued' => 0, 'sending' => 0, 'sent' => 0, 'failed' => 0, 'suppressed' => 0, 'retryable' => 0];
    $out['retryable'] = (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE campaign_id = ? AND status = 'failed' AND interrupted = 0", [$id]);
    foreach (q_all('SELECT status, COUNT(*) AS n FROM campaign_recipients WHERE campaign_id = ? GROUP BY status', [$id]) as $r) {
        $out[$r['status']] = (int) $r['n'];
    }
    return $out;
}

/**
 * Save the editor's fields, but only while the campaign is still a draft or
 * scheduled: the status check and the update happen under a row lock, so an
 * autosave can never change a campaign that was queued in the meantime.
 * Returns false when the campaign can no longer be edited.
 */
function campaign_save_draft(int $id, array $fields): bool
{
    return db_tx(static function () use ($id, $fields): bool {
        $status = q_val('SELECT status FROM campaigns WHERE id = ? FOR UPDATE', [$id]);
        if (!in_array($status, ['draft', 'scheduled'], true)) {
            return false;
        }
        db_update('campaigns', $id, $fields);
        return true;
    });
}

/** Send a test copy now (not queued, not logged as a recipient). */
function campaign_test_send(array $campaign, string $email, string $firstName): bool
{
    $p = campaign_render($campaign, ['email' => $email, 'first_name' => $firstName]);
    $m = new Message($email, $firstName, '[Test] ' . $p['subject'], $p['text'], $p['html'], null, bulk_headers($email));
    return mail_send_now($m, 'test', false);
}
