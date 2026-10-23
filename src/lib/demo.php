<?php
declare(strict_types=1);

/*
 * Demo content (D12): a fictional community organisation, written for this
 * build. Images are abstract artwork generated with GD at install time, so
 * no third-party pictures are shipped.
 */

const DEMO_PALETTES = [
    [[18, 52, 86], [20, 140, 140], [242, 178, 70]],
    [[30, 64, 120], [86, 180, 170], [250, 240, 220]],
    [[14, 90, 100], [240, 120, 90], [250, 220, 160]],
    [[40, 40, 80], [120, 160, 220], [255, 200, 120]],
    [[10, 110, 90], [250, 200, 80], [230, 245, 240]],
    [[90, 40, 80], [240, 150, 120], [250, 235, 210]],
];

/** Draw an abstract image (gradient, soft circles, a wave band) to a JPEG file. */
function demo_image(string $file, int $w, int $h, int $seed): void
{
    mt_srand($seed);
    [$a, $b, $c] = DEMO_PALETTES[$seed % count(DEMO_PALETTES)];
    $img = imagecreatetruecolor($w, $h);
    for ($y = 0; $y < $h; $y++) {
        $t = $y / max(1, $h - 1);
        $col = imagecolorallocate($img, (int) ($a[0] + ($b[0] - $a[0]) * $t), (int) ($a[1] + ($b[1] - $a[1]) * $t), (int) ($a[2] + ($b[2] - $a[2]) * $t));
        imageline($img, 0, $y, $w, $y, $col);
    }
    imagealphablending($img, true);
    for ($i = 0; $i < 7; $i++) {
        $base = [$a, $b, $c][$i % 3];
        $col = imagecolorallocatealpha($img, $base[0], $base[1], $base[2], mt_rand(70, 105));
        $r = mt_rand((int) ($w * 0.12), (int) ($w * 0.45));
        imagefilledellipse($img, mt_rand(0, $w), mt_rand(0, $h), $r, $r, $col);
    }
    $wave = imagecolorallocatealpha($img, $c[0], $c[1], $c[2], 60);
    $phase = mt_rand(0, 628) / 100;
    $amp = $h * 0.06;
    $mid = $h * (0.62 + mt_rand(0, 20) / 100);
    for ($x = 0; $x < $w; $x++) {
        $y1 = (int) ($mid + sin($x / $w * 6.283 * 1.5 + $phase) * $amp);
        imageline($img, $x, $y1, $x, $h, $wave);
    }
    imagejpeg($img, $file, 88);
}

/** Minimal one-page PDF containing a title line. */
function demo_pdf(string $file, string $title): void
{
    $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $title);
    $stream = "BT /F1 20 Tf 72 720 Td ($text) Tj ET";
    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream",
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
    ];
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objects as $i => $o) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $o . "\nendobj\n";
    }
    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $off) {
        $pdf .= sprintf("%010d 00000 n \n", $off);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
    file_put_contents($file, $pdf);
}

function demo_media_image(int $seed, string $title, string $alt, int $userId, int $w = 1600, int $h = 1000, string $folder = 'demo'): int
{
    $tmp = tempnam(sys_get_temp_dir(), 'gdemo');
    $file = $tmp . '.jpg';
    rename($tmp, $file);
    demo_image($file, $w, $h, $seed);
    $id = media_store($file, slugify($title) . '.jpg', (int) filesize($file), $userId, false, ['alt' => $alt, 'title' => $title, 'folder' => $folder]);
    unlink($file);
    return $id;
}

function demo_media_pdf(string $title, int $userId, string $visibility = 'public'): int
{
    $tmp = tempnam(sys_get_temp_dir(), 'gdemo');
    $file = $tmp . '.pdf';
    rename($tmp, $file);
    demo_pdf($file, $title);
    $id = media_store($file, slugify($title) . '.pdf', (int) filesize($file), $userId, false, ['title' => $title, 'folder' => 'documents', 'visibility' => $visibility]);
    unlink($file);
    return $id;
}

/** p-wrapped paragraphs. */
function demo_html(string ...$paragraphs): string
{
    return implode('', array_map(static fn (string $p): string => str_starts_with($p, '<') ? $p : '<p>' . e($p) . '</p>', $paragraphs));
}

/** Insert one content row through the model (validation + hooks). */
function demo_save(string $typeKey, array $input, int $userId): int
{
    $type = content_type($typeKey);
    [$data, $errors] = content_validate($type, $input);
    if ($errors) {
        throw new RuntimeException("Demo $typeKey invalid: " . implode(' ', $errors));
    }
    return content_save($type, $data, $input, null, $userId);
}

function demo_local(string $modify, string $time = '10:00'): string
{
    return (new DateTimeImmutable('now', site_tz()))->modify($modify)->format('Y-m-d') . 'T' . $time;
}

/** Load the full demo site. Safe to run once on an empty install. */
function demo_seed(int $userId): void
{
    setting_set('site.name', 'Harbourside Community Association');
    setting_set('site.tagline', 'Neighbours helping neighbours, by the water and beyond');
    setting_set('site.footer_about', 'A volunteer-run association bringing people together through events, learning and mutual support. (Demo content: replace it with your own.)');
    setting_set('contact.email', 'hello@example.org');
    setting_set('contact.phone', '020 7946 0321');
    setting_set('contact.address', '12 Quay Street, Harbourside, HS1 2AB');
    setting_set('org.charity_number', '1000000 (demo)');
    setting_set('social', ['facebook' => 'https://www.facebook.com/', 'x' => 'https://x.com/', 'instagram' => 'https://www.instagram.com/', 'youtube' => '', 'linkedin' => '']);

    $img = [];
    $titles = [
        'Harbour festival', 'Community garden', 'Youth workshop', 'Winter supper', 'Volunteer day', 'Annual meeting',
        'Coastal walk', 'Music evening', 'Reading club', 'Market stalls', 'Family picnic', 'Skills fair',
    ];
    foreach ($titles as $i => $t) {
        $img[$i] = demo_media_image(101 + $i, $t, 'Abstract artwork for "' . $t . '"', $userId);
    }

    // Posts: spread across today, this month, this year and last year so every archive filter has results.
    $catId = static fn (string $slug): string => (string) q_val('SELECT id FROM categories WHERE slug = ?', [$slug]);
    $posts = [
        ['news', 'Harbour festival returns this summer', 'Stalls, music and food from every corner of our neighbourhood.', 'today', 0, 'festival, summer'],
        ['news', 'New volunteers welcomed at spring induction', 'Twenty-two new helpers joined us this month.', '-3 days', 4, 'volunteering'],
        ['news', 'Community garden wins a local green award', 'The raised beds behind the hall have become a favourite meeting spot.', '-40 days', 1, 'garden, awards'],
        ['news', 'Annual meeting: what we agreed', 'A summary of the decisions, budget and plans for the year ahead.', '-5 months', 5, 'governance'],
        ['news', 'Looking back on our winter supper', 'Over two hundred plates served and a hall full of laughter.', '-14 months', 3, 'food, winter'],
        ['blog', 'Why we walk the coast path every month', 'A volunteer reflects on friendship, fresh air and fixing stiles.', '-6 days', 6, 'walks, wellbeing'],
        ['blog', 'Five things I learned running a reading club', 'Biscuits matter more than you would think.', '-2 months', 8, 'reading'],
        ['blog', 'A beginner’s guide to our skills fair', 'How to share what you know, even if you think it is nothing special.', '-16 months', 11, 'skills'],
        ['projects', 'Youth workshop programme', 'Weekly creative sessions for 11–18 year olds, run with local schools.', '-20 days', 2, 'youth, education'],
        ['projects', 'Harbourside history archive', 'Collecting photos and memories from long-time residents.', '-7 months', 9, 'history'],
        ['projects', 'Warm spaces this winter', 'Keeping the hall open, heated and welcoming on cold evenings.', '-13 months', 3, 'winter, support'],
    ];
    foreach ($posts as $i => [$cat, $title, $summary, $when, $imgIdx, $tags]) {
        demo_save('posts', [
            'title' => $title, 'summary' => $summary, 'category_id' => $catId($cat), 'tags' => $tags,
            'body' => demo_html(
                $summary,
                'This is demo content written for the Gather community website builder. It shows how an article looks with a cover image, a summary, tags and share buttons.',
                '<h2>What happens next</h2>',
                'Members can read more in the newsletter, and everyone is welcome to come along and say hello at our next open evening.',
                '<ul><li>Open to everyone</li><li>Run by volunteers</li><li>Free to attend</li></ul>'
            ),
            'cover_media_id' => (string) $img[$imgIdx], 'status' => 'published',
            'publish_at' => $when === 'today' ? (new DateTimeImmutable('now', site_tz()))->modify('-1 minute')->format('Y-m-d\TH:i') : demo_local($when),
            'visibility' => $i === 6 ? 'members' : 'public', 'allow_likes' => '1', 'allow_comments' => '1',
        ], $userId);
    }
    demo_save('posts', [
        'title' => 'Scheduled: autumn programme announced', 'summary' => 'This post publishes itself next week.',
        'category_id' => $catId('news'), 'body' => demo_html('Scheduled demo post.'), 'status' => 'scheduled',
        'publish_at' => demo_local('+7 days'), 'visibility' => 'public',
    ], $userId);

    // Events.
    $events = [
        ['Harbour festival', '+12 days', '11:00', '+12 days', '18:00', 'Quayside Green', 0, true, true, 300, [['Adult', 'Free'], ['Family (up to 4)', 'Free']]],
        ['Community garden planting morning', '+5 days', '09:30', '+5 days', '12:30', 'Hall Garden', 1, true, true, 25, []],
        ['Members’ annual meeting', '+30 days', '19:00', '+30 days', '21:00', 'Harbourside Hall', 5, true, false, null, [['Member', 'No charge']]],
        ['Coffee morning', '+0 days', '10:00', '+0 days', '23:30', 'Harbourside Hall', 7, false, true, null, []],
        ['Winter supper', '-60 days', '18:30', '-60 days', '22:00', 'Harbourside Hall', 3, false, true, 120, [['Supper', '£8 on the door']]],
        ['Skills fair', '-120 days', '10:00', '-120 days', '16:00', 'Quay Street School', 11, false, true, null, []],
    ];
    foreach ($events as [$title, $sd, $st, $ed, $et, $venue, $imgIdx, $regOpen, $guests, $cap, $tickets]) {
        demo_save('events', [
            'title' => $title, 'summary' => 'Join neighbours for ' . lcfirst($title) . '. Everyone is welcome.',
            'body' => demo_html('Come along, bring a friend and meet the people who make Harbourside special.', 'Step-free access is available. Please tell us about any access needs when you register.'),
            'start_at' => demo_local($sd, $st), 'end_at' => demo_local($ed, $et), 'timezone' => site_tz()->getName(),
            'venue_name' => $venue, 'venue_address' => '12 Quay Street, Harbourside', 'map_url' => 'https://www.openstreetmap.org/',
            'registration_open' => $regOpen ? '1' : '', 'guest_registration' => $guests ? '1' : '',
            'capacity' => $cap === null ? '' : (string) $cap,
            'ticket_name' => array_column($tickets, 0), 'ticket_price' => array_column($tickets, 1),
            'cover_media_id' => (string) $img[$imgIdx], 'status' => 'published', 'publish_at' => demo_local('-90 days'), 'visibility' => 'public',
        ], $userId);
    }

    // Albums with photos.
    $albums = [['Harbour festival', 0, '-300 days'], ['Community garden through the seasons', 1, '-100 days'], ['Winter supper', 3, '-60 days']];
    foreach ($albums as $ai => [$title, $coverIdx, $date]) {
        $albumId = demo_save('albums', [
            'title' => $title, 'summary' => 'Photos from ' . lcfirst($title) . '.', 'body' => demo_html('A selection of moments shared by volunteers.'),
            'album_date' => (new DateTimeImmutable('now', site_tz()))->modify($date)->format('Y-m-d'),
            'cover_media_id' => (string) $img[$coverIdx], 'status' => 'published', 'publish_at' => demo_local($date), 'visibility' => 'public',
        ], $userId);
        for ($p = 0; $p < 8; $p++) {
            $mid = demo_media_image(500 + $ai * 10 + $p, $title . ' photo ' . ($p + 1), 'Abstract artwork, ' . strtolower($title) . ' set, image ' . ($p + 1), $userId, $p % 3 === 0 ? 1000 : 1600, $p % 3 === 0 ? 1300 : 1050, 'gallery');
            db_insert('album_photos', ['album_id' => $albumId, 'media_id' => $mid, 'caption' => 'Moment ' . ($p + 1) . ' from ' . lcfirst($title), 'alt' => 'Abstract artwork, image ' . ($p + 1), 'sort' => $p]);
        }
    }

    // Downloads.
    foreach ([
        ['Constitution', 'Governance', 'public'], ['Membership form', 'Membership', 'public'],
        ['Annual report', 'Governance', 'public'], ['Volunteer handbook', 'Volunteering', 'members'],
    ] as [$title, $cat, $vis]) {
        demo_save('downloads', [
            'title' => $title, 'summary' => 'Demo ' . strtolower($title) . ' (PDF).', 'category' => $cat,
            'file_media_id' => (string) demo_media_pdf($title, $userId, $vis === 'members' ? 'members' : 'public'),
            'status' => 'published', 'publish_at' => demo_local('-30 days'), 'visibility' => $vis,
        ], $userId);
    }

    // Leaders.
    $exec = demo_save('leader-groups', ['name' => 'Executive committee', 'description' => 'Elected each year at the annual meeting.', 'sort' => '1'], $userId);
    $trustees = demo_save('leader-groups', ['name' => 'Trustees', 'description' => 'Responsible for governance and finances.', 'sort' => '2'], $userId);
    $people = [
        [$exec, 'Amara Okafor', 'Chairperson', 1, '', ''], [$exec, 'Tomasz Nowak', 'Secretary', 2, '', ''],
        [$exec, 'Priya Raman', 'Treasurer', 3, '', ''], [$exec, 'Daniel Mensah', 'Events lead', 4, '', ''],
        [$trustees, 'Helen Carter', 'Trustee', 1, '', ''], [$trustees, 'Yusuf Ali', 'Trustee', 2, '', ''],
        [$exec, 'Grace Liu', 'Former chairperson', 9, '-4 years', '-1 year'],
    ];
    foreach ($people as $i => [$group, $name, $role, $sort, $start, $end]) {
        demo_save('leaders', [
            'name' => $name, 'role_title' => $role, 'group_id' => (string) $group, 'sort' => (string) $sort,
            'bio' => demo_html($name . ' has volunteered with the association for several years. (Fictional person for demo content.)'),
            'email' => 'committee@example.org', 'show_email' => $i === 0 ? '1' : '', 'show_bio' => '1',
            'photo_media_id' => (string) $img[($i + 6) % 12], 'status' => 'published',
            'term_start' => (new DateTimeImmutable('now', site_tz()))->modify($start !== '' ? $start : '-1 year')->format('Y-m-d'),
            'term_end' => $end !== '' ? (new DateTimeImmutable('now', site_tz()))->modify($end)->format('Y-m-d') : '',
        ], $userId);
    }

    // Awards, FAQs, branches, groups.
    $y = current_year();
    foreach ([
        [$y - 1, 'Volunteer of the Year', 'Samira Haddad', 4], [$y - 1, 'Young Achiever', 'Leo Brennan', 2],
        [$y - 2, 'Volunteer of the Year', 'Marcus Reid', 6], [$y - 2, 'Lifetime Contribution', 'Joan Whitfield', 9],
    ] as $i => [$year, $award, $who, $imgIdx]) {
        demo_save('awards', ['year' => (string) $year, 'title' => $award, 'recipient' => $who, 'sort' => (string) $i,
            'citation' => demo_html('For outstanding commitment to the community. (Fictional recipient.)'), 'photo_media_id' => (string) $img[$imgIdx], 'status' => 'published'], $userId);
    }
    foreach ([
        ['Membership', 'Who can join?', 'Anyone who lives, works or studies in the area, or feels part of the community.'],
        ['Membership', 'Is there a membership fee?', 'Membership is free in this demo. Your organisation can set its own rules.'],
        ['Membership', 'How do I get my member ID card?', 'Once your email is verified your digital card appears in your account.'],
        ['Events', 'Do I need to register for events?', 'Some events have limited places; the event page tells you if registration is needed.'],
        ['Events', 'Are events accessible?', 'Our hall has step-free access and a hearing loop. Tell us about any other needs.'],
        ['Volunteering', 'How can I volunteer?', 'Come to an open evening or send us a message through the contact page.'],
    ] as $i => [$cat, $q, $a]) {
        demo_save('faqs', ['question' => $q, 'answer' => demo_html($a), 'category' => $cat, 'sort' => (string) $i, 'status' => 'published', 'visibility' => 'public'], $userId);
    }
    foreach ([
        ['Harbourside Central', 'Harbourside', 'Nadia Petrova', 'First Tuesday of the month, Harbourside Hall'],
        ['North Shore', 'North Shore', 'Kwame Asante', 'Second Thursday, North Shore Library'],
        ['Riverside', 'Riverside', 'Fiona Doyle', 'Last Saturday, Riverside Café'],
    ] as $i => [$name, $region, $contact, $venue]) {
        demo_save('branches', ['name' => $name, 'region' => $region, 'contact_person' => $contact, 'email' => 'branches@example.org',
            'meeting_venue' => $venue, 'description' => demo_html('A friendly local branch.'), 'sort' => (string) $i, 'status' => 'published'], $userId);
    }
    foreach ([
        ['Quayside Residents’ Group', 'GRP-26-0001', 'Ola Bakare'], ['Harbourside Youth Club', 'GRP-26-0002', 'Chris Evans'],
        ['Old Town Heritage Society', 'GRP-26-0003', 'Maria Santos'],
    ] as $i => [$name, $num, $contact]) {
        demo_save('groups', ['name' => $name, 'group_number' => $num, 'contact_person' => $contact, 'email' => 'groups@example.org',
            'country' => 'United Kingdom', 'description' => demo_html('An affiliated local group. (Demo.)'), 'sort' => (string) $i, 'status' => 'active'], $userId);
    }

    // Pages.
    $about = demo_save('pages', ['title' => 'About us', 'summary' => 'Who we are and what we do.', 'body' => demo_html(
        'Harbourside Community Association is a fictional organisation used to demonstrate the Gather website builder.',
        '<h2>Our mission</h2>', 'To bring neighbours together, support one another and celebrate the place we share.',
        '<h2>How we work</h2>', 'We are run by volunteers and governed by trustees elected by our members.'
    ), 'status' => 'published', 'visibility' => 'public', 'cover_media_id' => (string) $img[4]], $userId);
    $involved = demo_save('pages', ['title' => 'Get involved', 'summary' => 'Volunteer, join or support us.', 'body' => demo_html(
        'There are lots of ways to help: volunteer at an event, join a branch, or share your skills at the next skills fair.',
        '<ul><li>Volunteer a few hours a month</li><li>Become a member</li><li>Donate to our winter appeal</li></ul>'
    ), 'status' => 'published', 'visibility' => 'public'], $userId);
    demo_save('pages', ['title' => 'Privacy policy', 'summary' => 'How we look after your information.', 'body' => demo_html(
        'This demo privacy page is a placeholder. Replace it with your organisation’s own policy.'
    ), 'status' => 'published', 'visibility' => 'public'], $userId);
    demo_save('pages', ['title' => 'Members’ noticeboard', 'summary' => 'Updates for members.', 'body' => demo_html('Only logged-in members can read this page.'), 'status' => 'published', 'visibility' => 'members'], $userId);

    // Builder pages (§3.1): the home page, the contact page and a membership page.
    setting_set('donate.bank_text', "Harbourside Community Association (demo)\nSort code 00-00-00 · Account 00000000\nReference: DONATION");
    setting_set('donate.links', [['label' => 'Give online', 'url' => 'https://example.org/donate']]);
    $home = demo_save('pages', ['title' => 'Home', 'summary' => 'Neighbours helping neighbours, by the water and beyond.', 'status' => 'published', 'visibility' => 'public', 'is_home' => '1'], $userId);
    builder_initialise($home, [
        builder_make('hero', ['eyebrow' => 'Welcome to Harbourside', 'heading' => 'Neighbours helping neighbours',
            'text' => 'Events, learning and support for everyone who lives, works or grew up by the harbour.',
            'slides' => [['media_id' => $img[0], 'caption' => 'Harbour festival'], ['media_id' => $img[1], 'caption' => 'Community garden'], ['media_id' => $img[9], 'caption' => 'Market stalls']]]),
        builder_make('stats'),
        builder_make('features', ['eyebrow' => 'What we do', 'heading' => 'Three ways we bring people together']),
        builder_make('upcoming_events', ['eyebrow' => 'Get together'], ['background' => 'soft']),
        builder_make('latest_posts', ['eyebrow' => 'Latest']),
        builder_make('gallery', ['eyebrow' => 'Moments', 'count' => 8], ['background' => 'soft']),
        builder_make('birthdays', ['hide_when_empty' => true]),
        builder_make('cta'),
    ], $userId);
    $contact = demo_save('pages', ['title' => 'Contact', 'summary' => 'Get in touch.', 'status' => 'published', 'visibility' => 'public'], $userId);
    builder_initialise($contact, [
        builder_make('contact', ['show_map' => false]),
        builder_make('faq', ['heading' => 'Before you write', 'count' => 4], ['background' => 'soft']),
        builder_make('donate'),
    ], $userId);
    $membership = demo_save('pages', ['title' => 'Membership', 'summary' => 'Join our community.', 'status' => 'published', 'visibility' => 'public'], $userId);
    builder_initialise($membership, builder_template_sections('starter:membership') ?? [], $userId);

    // Menus.
    $main = db_insert('menus', ['name' => 'Main navigation', 'location' => 'main']);
    $add = static function (int $menu, string $label, string $type, ?string $ref = null, ?int $id = null, ?string $url = null, ?int $parent = null): int {
        return db_insert('menu_items', ['menu_id' => $menu, 'parent_id' => $parent, 'label' => $label, 'target_type' => $type,
            'target_ref' => $ref, 'target_id' => $id, 'url' => $url, 'sort' => menu_next_sort($menu, $parent)]);
    };
    $add($main, 'Home', 'list', 'home');
    $aboutItem = $add($main, 'About', 'page', null, $about);
    $add($main, 'About us', 'page', null, $about, null, $aboutItem);
    $add($main, 'Leaders', 'list', 'leaders', null, null, $aboutItem);
    $add($main, 'Branches', 'list', 'branches', null, null, $aboutItem);
    $add($main, 'Awards', 'list', 'awards', null, null, $aboutItem);
    $newsItem = $add($main, 'News', 'list', 'news');
    $add($main, 'Latest news', 'list', 'news', null, null, $newsItem);
    $add($main, 'Blog', 'list', 'blog', null, null, $newsItem);
    $add($main, 'Projects', 'list', 'projects', null, null, $newsItem);
    $add($main, 'Events', 'list', 'events');
    $add($main, 'Gallery', 'list', 'gallery');
    $inv = $add($main, 'Get involved', 'page', null, $involved);
    $add($main, 'Get involved', 'page', null, $involved, null, $inv);
    $add($main, 'Downloads', 'list', 'downloads', null, null, $inv);
    $add($main, 'Groups', 'list', 'groups', null, null, $inv);
    $add($main, 'FAQ', 'list', 'faq', null, null, $inv);

    $top = db_insert('menus', ['name' => 'Top bar', 'location' => 'topbar']);
    $add($top, 'FAQ', 'list', 'faq');
    $add($top, 'Contact', 'page', null, $contact);

    $f1 = db_insert('menus', ['name' => 'Explore', 'location' => 'footer_1']);
    foreach (['news', 'events', 'gallery', 'downloads'] as $k) {
        $add($f1, menu_list_targets()[$k][0], 'list', $k);
    }
    $f2 = db_insert('menus', ['name' => 'Organisation', 'location' => 'footer_2']);
    $add($f2, 'About us', 'page', null, $about);
    $add($f2, 'Leaders', 'list', 'leaders');
    $add($f2, 'Branches', 'list', 'branches');
    $add($f2, 'Contact', 'page', null, $contact);
}
