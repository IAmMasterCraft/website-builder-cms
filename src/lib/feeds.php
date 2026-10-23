<?php
declare(strict_types=1);

// ---- iCalendar (RFC 5545) ---------------------------------------------------

function ics_escape(string $text): string
{
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\\,', '\\n'], $text);
}

/** Fold a content line at 75 octets without splitting UTF-8 characters. */
function ics_fold(string $line): string
{
    $out = '';
    $current = '';
    foreach (mb_str_split($line) as $char) {
        $limit = $out === '' ? 75 : 74; // continuation lines start with a space
        if (strlen($current) + strlen($char) > $limit) {
            $out .= ($out === '' ? '' : "\r\n ") . $current;
            $current = '';
        }
        $current .= $char;
    }
    return $out . ($out === '' ? '' : "\r\n ") . $current;
}

function ics_utc(string $utc): string
{
    return str_replace([' ', '-', ':'], ['T', '', ''], $utc) . 'Z';
}

/** @return list<string> VEVENT lines for one event row */
function ics_event_lines(array $event, string $host, string $baseUrl): array
{
    $end = $event['end_at'] ?: gmdate('Y-m-d H:i:s', strtotime($event['start_at'] . ' UTC') + 3600);
    $location = trim(implode(', ', array_filter([(string) $event['venue_name'], (string) $event['venue_address']])));
    if ($location === '' && !empty($event['online_url'])) {
        $location = (string) $event['online_url'];
    }
    $lines = [
        'BEGIN:VEVENT',
        'UID:event-' . (int) $event['id'] . '@' . $host,
        'DTSTAMP:' . ics_utc(gmdate('Y-m-d H:i:s', strtotime((string) $event['updated_at'] . ' UTC'))),
        'DTSTART:' . ics_utc((string) $event['start_at']),
        'DTEND:' . ics_utc((string) $end),
        'SUMMARY:' . ics_escape((string) $event['title']),
        'URL:' . $baseUrl . event_url($event),
    ];
    $desc = excerpt((string) ($event['summary'] ?: $event['body']), 1000);
    if ($desc !== '') {
        $lines[] = 'DESCRIPTION:' . ics_escape($desc);
    }
    if ($location !== '') {
        $lines[] = 'LOCATION:' . ics_escape($location);
    }
    $lines[] = 'END:VEVENT';
    return $lines;
}

/** A whole VCALENDAR document for the given events. */
function ics_calendar(array $events, string $calendarName): string
{
    $baseUrl = base_url();
    $host = (string) (parse_url($baseUrl, PHP_URL_HOST) ?: 'localhost');
    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Gather//Community CMS//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-CALNAME:' . ics_escape($calendarName),
    ];
    foreach ($events as $event) {
        array_push($lines, ...ics_event_lines($event, $host, $baseUrl));
    }
    $lines[] = 'END:VCALENDAR';
    return implode("\r\n", array_map('ics_fold', $lines)) . "\r\n";
}

// ---- RSS 2.0 ----------------------------------------------------------------

function xml_e(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rss_feed(array $posts, string $title, string $description): string
{
    $base = base_url();
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . xml_e($title) . '</title>'
        . '<link>' . xml_e($base . '/') . '</link>'
        . '<description>' . xml_e($description) . '</description>'
        . '<language>en</language>'
        . '<atom:link href="' . xml_e($base . '/feed.xml') . '" rel="self" type="application/rss+xml"/>';
    foreach ($posts as $p) {
        $url = $base . post_url($p);
        $x .= '<item><title>' . xml_e((string) $p['title']) . '</title>'
            . '<link>' . xml_e($url) . '</link><guid isPermaLink="true">' . xml_e($url) . '</guid>'
            . '<pubDate>' . gmdate(DATE_RSS, strtotime((string) $p['publish_at'] . ' UTC')) . '</pubDate>'
            . '<category>' . xml_e((string) ($p['category_name'] ?? '')) . '</category>'
            . '<description>' . xml_e(excerpt((string) ($p['summary'] ?: $p['body']), 400)) . '</description></item>';
    }
    return $x . '</channel></rss>' . "\n";
}

// ---- Sitemap ----------------------------------------------------------------

/** @return list<array{loc:string, lastmod:?string}> public URLs only */
function sitemap_urls(): array
{
    $urls = [['loc' => '/', 'lastmod' => null]];
    foreach (['/news', '/blog', '/projects', '/events', '/events/calendar', '/gallery', '/downloads', '/leaders', '/awards', '/faq', '/branches', '/groups'] as $p) {
        $urls[] = ['loc' => $p, 'lastmod' => null];
    }
    $pub = published_sql();
    foreach (q_all("SELECT id, slug, updated_at FROM pages WHERE $pub AND visibility = 'public'") as $r) {
        $urls[] = ['loc' => content_public_url('page', $r), 'lastmod' => $r['updated_at']];
    }
    foreach (q_all("SELECT p.slug, p.updated_at, c.base FROM posts p JOIN categories c ON c.id = p.category_id WHERE " . published_sql('p') . " AND p.visibility = 'public'") as $r) {
        $urls[] = ['loc' => post_url($r), 'lastmod' => $r['updated_at']];
    }
    foreach (q_all("SELECT slug, updated_at FROM events WHERE $pub AND visibility = 'public'") as $r) {
        $urls[] = ['loc' => event_url($r), 'lastmod' => $r['updated_at']];
    }
    foreach (q_all("SELECT slug, updated_at FROM albums WHERE $pub AND visibility = 'public'") as $r) {
        $urls[] = ['loc' => '/gallery/' . $r['slug'], 'lastmod' => $r['updated_at']];
    }
    foreach (q_all('SELECT slug FROM leader_groups') as $r) {
        $urls[] = ['loc' => '/leaders/' . $r['slug'], 'lastmod' => null];
    }
    foreach (q_all("SELECT l.slug, l.updated_at, g.slug AS gslug FROM leaders l JOIN leader_groups g ON g.id = l.group_id WHERE " . published_sql('l')) as $r) {
        $urls[] = ['loc' => '/leaders/' . $r['gslug'] . '/' . $r['slug'], 'lastmod' => $r['updated_at']];
    }
    return $urls;
}

function sitemap_xml(array $urls): string
{
    $base = base_url();
    $x = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as $u) {
        $x .= '<url><loc>' . xml_e($base . $u['loc']) . '</loc>'
            . ($u['lastmod'] ? '<lastmod>' . gmdate('Y-m-d', strtotime($u['lastmod'] . ' UTC')) . '</lastmod>' : '') . '</url>';
    }
    return $x . '</urlset>' . "\n";
}
