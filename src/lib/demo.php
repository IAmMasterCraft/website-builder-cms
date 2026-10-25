<?php
declare(strict_types=1);

/*
 * Demo content (D12): a fictional community organisation, written for this
 * build. Photos come from demo-assets/photos (Unsplash licence, credits in
 * CREDITS.md) and go through the normal media pipeline (re-encoded, WebP
 * variants, focal point) so they behave like real uploads. When the photos
 * are missing (a trimmed package), abstract GD artwork is generated instead.
 */

/** Demo photos: key => [file, alt text, focal x %, focal y %]. */
const DEMO_PHOTOS = [
    'wave' => ['hero-community-wave.jpg', 'Friends waving hello together in a sunny park', 55, 40],
    'selfie' => ['hero-friends-selfie.jpg', 'A group of friends taking a selfie outdoors', 55, 40],
    'porch' => ['family-group-porch.jpg', 'A large family group gathered on a porch', 50, 42],
    'family' => ['family-laughing.jpg', 'Parents laughing together with their baby', 50, 32],
    'sorting' => ['volunteers-sorting-food.jpg', 'Volunteers sorting donated tins at a food bank', 50, 45],
    'packing' => ['volunteers-packing-bags.jpg', 'Volunteers packing paper bags of food for families', 45, 45],
    'handing' => ['volunteer-food-distribution.jpg', 'A volunteer handing out bags of fresh food', 50, 35],
    'cleanup' => ['volunteers-cleanup.jpg', 'Volunteers in matching T-shirts with litter bags after a clean-up', 50, 40],
    'aprons' => ['volunteers-aprons.jpg', 'Two smiling volunteers in aprons', 50, 30],
    'helpdesk' => ['event-helpdesk.jpg', 'A volunteer helping visitors at an event information desk', 45, 40],
    'smiling' => ['event-volunteers-smiling.jpg', 'Two smiling volunteers with lanyards at an outdoor event', 48, 38],
    'meeting' => ['meeting-audience.jpg', 'An audience listening at a community meeting', 50, 55],
    'festival' => ['festival-celebration.jpg', 'A crowd celebrating at an outdoor music festival', 55, 40],
    'field' => ['festival-outdoor.jpg', 'A festival crowd on a green field on a summer day', 50, 55],
    'picnic' => ['picnic-gathering.jpg', 'People relaxing on the grass at a summer gathering', 40, 60],
    'elder1' => ['elder-portrait-1.jpg', 'Portrait of an older man in a blue shirt', 50, 32],
    'elder2' => ['elder-portrait-2.jpg', 'Portrait of a man in an embroidered tunic', 50, 25],
    'elder3' => ['elder-portrait-3.jpg', 'Portrait of an older man in glasses and a blue cap', 45, 28],
    'elder4' => ['elder-portrait-4.jpg', 'Portrait of an older man in a flat cap and scarf', 62, 35],
    'story' => ['youth-storytelling.jpg', 'A storyteller with children sitting on the grass in a park', 50, 50],
    'youth' => ['youth-park-session.jpg', 'Children listening to a storyteller under the trees', 50, 55],
    'presenter' => ['radio-presenter.jpg', 'A presenter at the desk of a radio studio', 35, 40],
    'mic' => ['radio-microphone.jpg', 'A studio microphone in front of a screen', 50, 40],
    'dining' => ['dining-together.jpg', 'Friends sharing dishes around a long wooden table', 50, 50],
    'supper' => ['community-dinner.jpg', 'A community supper at a long table in a timber hall', 50, 72],
    'street' => ['london-street.jpg', 'A city street leading to a cathedral dome', 50, 45],
];

/** Import every demo photo through the media pipeline. @return array<string,int> key => media id */
function demo_photos(int $userId): array
{
    $out = [];
    $seed = 101;
    foreach (DEMO_PHOTOS as $key => [$file, $alt, $fx, $fy]) {
        $src = BASE . '/demo-assets/photos/' . $file;
        if (!is_file($src)) {
            $out[$key] = demo_media_image($seed++, ucfirst(str_replace(['-', '.jpg'], [' ', ''], $file)), $alt, $userId);
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gphoto');
        $copy = $tmp . '.jpg';
        rename($tmp, $copy);
        copy($src, $copy);
        $out[$key] = media_store($copy, $file, (int) filesize($copy), $userId, false, [
            'alt' => $alt, 'title' => ucfirst(str_replace(['-', '.jpg'], [' ', ''], $file)), 'folder' => 'demo', 'focal_x' => $fx, 'focal_y' => $fy,
        ]);
        is_file($copy) && unlink($copy);
    }
    return $out;
}

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

    $ph = demo_photos($userId);

    // Posts: spread across today, this month, this year and last year so every archive filter has results.
    $catId = static fn (string $slug): string => (string) q_val('SELECT id FROM categories WHERE slug = ?', [$slug]);
    $posts = [
        ['news', 'Harbour festival returns this summer', 'Stalls, music and food from every corner of our neighbourhood.', 'today', 'field', 'festival, summer'],
        ['news', 'New volunteers welcomed at spring induction', 'Twenty-two new helpers joined us this month.', '-3 days', 'smiling', 'volunteering'],
        ['news', 'Community garden wins a local green award', 'The raised beds behind the hall have become a favourite meeting spot.', '-40 days', 'picnic', 'garden, awards'],
        ['news', 'Annual meeting: what we agreed', 'A summary of the decisions, budget and plans for the year ahead.', '-5 months', 'meeting', 'governance'],
        ['news', 'Looking back on our winter supper', 'Over two hundred plates served and a hall full of laughter.', '-14 months', 'supper', 'food, winter'],
        ['blog', 'Why our Saturday walking group keeps growing', 'A volunteer reflects on friendship, fresh air and the best café stops.', '-6 days', 'wave', 'walks, wellbeing'],
        ['blog', 'Five things I learned running a reading club', 'Biscuits matter more than you would think.', '-2 months', 'dining', 'reading'],
        ['blog', 'A beginner’s guide to our skills fair', 'How to share what you know, even if you think it is nothing special.', '-16 months', 'helpdesk', 'skills'],
        ['projects', 'Youth workshop programme', 'Weekly creative sessions for 11–18 year olds, run with local schools.', '-20 days', 'story', 'youth, education'],
        ['projects', 'Harbourside history archive', 'Collecting photos and memories from long-time residents.', '-7 months', 'porch', 'history'],
        ['projects', 'Warm spaces this winter', 'Keeping the hall open, heated and welcoming on cold evenings.', '-13 months', 'aprons', 'winter, support'],
    ];
    foreach ($posts as $i => [$cat, $title, $summary, $when, $photo, $tags]) {
        demo_save('posts', [
            'title' => $title, 'summary' => $summary, 'category_id' => $catId($cat), 'tags' => $tags,
            'body' => demo_html(
                $summary,
                'This is demo content written for the Gather community website builder. It shows how an article looks with a cover image, a summary, tags and share buttons.',
                '<h2>What happens next</h2>',
                'Members can read more in the newsletter, and everyone is welcome to come along and say hello at our next open evening.',
                '<ul><li>Open to everyone</li><li>Run by volunteers</li><li>Free to attend</li></ul>'
            ),
            'cover_media_id' => (string) $ph[$photo], 'status' => 'published',
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
        ['Harbour festival', '+12 days', '11:00', '+12 days', '18:00', 'Quayside Green', 'festival', true, true, 300, [['Adult', 'Free'], ['Family (up to 4)', 'Free']]],
        ['Park and beach clean-up morning', '+5 days', '09:30', '+5 days', '12:30', 'Hall Garden', 'cleanup', true, true, 25, []],
        ['Members’ annual meeting', '+30 days', '19:00', '+30 days', '21:00', 'Harbourside Hall', 'meeting', true, false, null, [['Member', 'No charge']]],
        ['Coffee morning', '+0 days', '10:00', '+0 days', '23:30', 'Harbourside Hall', 'dining', false, true, null, []],
        ['Winter supper', '-60 days', '18:30', '-60 days', '22:00', 'Harbourside Hall', 'supper', false, true, 120, [['Supper', '£8 on the door']]],
        ['Skills fair', '-120 days', '10:00', '-120 days', '16:00', 'Quay Street School', 'helpdesk', false, true, null, []],
    ];
    $eventIds = [];
    foreach ($events as [$title, $sd, $st, $ed, $et, $venue, $photo, $regOpen, $guests, $cap, $tickets]) {
        $eventIds[$title] = demo_save('events', [
            'title' => $title, 'summary' => 'Join neighbours for ' . lcfirst($title) . '. Everyone is welcome.',
            'body' => demo_html('Come along, bring a friend and meet the people who make Harbourside special.', 'Step-free access is available. Please tell us about any access needs when you register.'),
            'start_at' => demo_local($sd, $st), 'end_at' => demo_local($ed, $et), 'timezone' => site_tz()->getName(),
            'venue_name' => $venue, 'venue_address' => '12 Quay Street, Harbourside', 'map_url' => 'https://www.openstreetmap.org/',
            'registration_open' => $regOpen ? '1' : '', 'guest_registration' => $guests ? '1' : '',
            'capacity' => $cap === null ? '' : (string) $cap,
            'ticket_name' => array_column($tickets, 0), 'ticket_price' => array_column($tickets, 1),
            'cover_media_id' => (string) $ph[$photo], 'status' => 'published', 'publish_at' => demo_local('-90 days'), 'visibility' => 'public',
        ], $userId);
    }

    // Albums with photos.
    $albums = [
        ['Harbour festival', 'festival', '-300 days', ['festival', 'field', 'picnic', 'wave', 'selfie', 'smiling', 'helpdesk', 'family']],
        ['Volunteers in action', 'sorting', '-100 days', ['sorting', 'packing', 'handing', 'cleanup', 'aprons', 'helpdesk', 'smiling', 'meeting']],
        ['Suppers, stories and get-togethers', 'supper', '-60 days', ['supper', 'dining', 'porch', 'story', 'youth', 'picnic', 'family', 'wave']],
    ];
    foreach ($albums as [$title, $cover, $date, $keys]) {
        $albumId = demo_save('albums', [
            'title' => $title, 'summary' => 'Photos from ' . lcfirst($title) . '.', 'body' => demo_html('A selection of moments shared by volunteers.'),
            'album_date' => (new DateTimeImmutable('now', site_tz()))->modify($date)->format('Y-m-d'),
            'cover_media_id' => (string) $ph[$cover], 'status' => 'published', 'publish_at' => demo_local($date), 'visibility' => 'public',
        ], $userId);
        foreach ($keys as $p => $key) {
            db_insert('album_photos', ['album_id' => $albumId, 'media_id' => $ph[$key], 'caption' => DEMO_PHOTOS[$key][1], 'alt' => DEMO_PHOTOS[$key][1], 'sort' => $p]);
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
        [$exec, 'Samuel Mensah', 'Chairperson', 1, '', '', 'elder3', ['linkedin' => 'https://www.linkedin.com/', 'website' => 'https://example.org/']],
        [$exec, 'Joseph Adeyemi', 'Secretary', 2, '', '', 'elder2', ['facebook' => 'https://www.facebook.com/']],
        [$exec, 'Emmanuel Osei', 'Treasurer', 3, '', '', 'elder1', ['linkedin' => 'https://www.linkedin.com/']],
        [$exec, 'Kofi Boateng', 'Events lead', 4, '', '', 'elder4', ['instagram' => 'https://www.instagram.com/', 'x' => 'https://x.com/']],
        [$trustees, 'Helen Carter', 'Trustee', 1, '', '', null, ['linkedin' => 'https://www.linkedin.com/']],
        [$trustees, 'Ama Owusu', 'Trustee', 2, '', '', null, []],
        [$exec, 'Grace Liu', 'Former chairperson', 9, '-4 years', '-1 year', null, []],
    ];
    foreach ($people as $i => [$group, $name, $role, $sort, $start, $end, $photo, $social]) {
        demo_save('leaders', [
            'name' => $name, 'role_title' => $role, 'group_id' => (string) $group, 'sort' => (string) $sort,
            'bio' => demo_html($name . ' has volunteered with the association for several years. (Fictional person for demo content.)'),
            'email' => 'committee@example.org', 'show_email' => $i === 0 ? '1' : '', 'show_bio' => '1',
            ...array_combine(array_map(static fn (string $k): string => 'social_' . $k, array_keys($social)), array_values($social)),
            'photo_media_id' => $photo !== null ? (string) $ph[$photo] : '', 'status' => 'published',
            'term_start' => (new DateTimeImmutable('now', site_tz()))->modify($start !== '' ? $start : '-1 year')->format('Y-m-d'),
            'term_end' => $end !== '' ? (new DateTimeImmutable('now', site_tz()))->modify($end)->format('Y-m-d') : '',
        ], $userId);
    }

    // Awards, FAQs, branches, groups.
    $y = current_year();
    foreach ([
        [$y - 1, 'Volunteer of the Year', 'Samira Haddad', 'aprons'], [$y - 1, 'Young Achiever', 'Leo Brennan', 'youth'],
        [$y - 2, 'Volunteer of the Year', 'Marcus Reid', 'handing'], [$y - 2, 'Lifetime Contribution', 'Joan Whitfield', 'porch'],
    ] as $i => [$year, $award, $who, $photo]) {
        demo_save('awards', ['year' => (string) $year, 'title' => $award, 'recipient' => $who, 'sort' => (string) $i,
            'citation' => demo_html('For outstanding commitment to the community. (Fictional recipient.)'), 'photo_media_id' => (string) $ph[$photo], 'status' => 'published'], $userId);
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
        member_reserve_number($num, 'group');
        demo_save('groups', ['name' => $name, 'group_number' => $num, 'contact_person' => $contact, 'email' => 'groups@example.org',
            'country' => 'United Kingdom', 'description' => demo_html('An affiliated local group. (Demo.)'), 'sort' => (string) $i, 'status' => 'active'], $userId);
    }

    // Pages.
    $about = demo_save('pages', ['title' => 'About us', 'summary' => 'Who we are and what we do.', 'body' => demo_html(
        'Harbourside Community Association is a fictional organisation used to demonstrate the Gather website builder.',
        '<h2>Our mission</h2>', 'To bring neighbours together, support one another and celebrate the place we share.',
        '<h2>How we work</h2>', 'We are run by volunteers and governed by trustees elected by our members.'
    ), 'status' => 'published', 'visibility' => 'public', 'cover_media_id' => (string) $ph['porch']], $userId);
    $involved = demo_save('pages', ['title' => 'Get involved', 'summary' => 'Volunteer, join or support us.', 'body' => demo_html(
        'There are lots of ways to help: volunteer at an event, join a branch, or share your skills at the next skills fair.',
        '<ul><li>Volunteer a few hours a month</li><li>Become a member</li><li>Donate to our winter appeal</li></ul>'
    ), 'status' => 'published', 'visibility' => 'public'], $userId);
    demo_save('pages', ['title' => 'Privacy policy', 'summary' => 'How we look after your information.', 'body' => demo_html(
        'This demo privacy page is a placeholder. Replace it with your organisation’s own policy.'
    ), 'status' => 'published', 'visibility' => 'public'], $userId);
    demo_save('pages', ['title' => 'Members’ noticeboard', 'summary' => 'Updates for members.', 'body' => demo_html('Only logged-in members can read this page.'), 'status' => 'published', 'visibility' => 'members'], $userId);

    // Members (§4): a custom field and a few verified demo members with numbers.
    setting_set('members', ['prefix' => 'HCA'] + member_settings());
    $district = db_insert('custom_fields', ['field_key' => 'home_district', 'label' => 'Home district', 'type' => 'select',
        'options' => json_encode(['Quayside', 'Old Town', 'Hillcrest', 'Marina', 'Elsewhere']), 'is_required' => 0, 'show_on_card' => 1, 'visible_to_members' => 1, 'sort' => 1]);
    $today = new DateTimeImmutable('now', site_tz());
    foreach ([
        ['Amara', 'Okafor', 'Quayside', 'England', $today, true], ['Tom', 'Brennan', 'Old Town', 'Wales', $today->modify('+3 days'), true],
        ['Priya', 'Shah', 'Marina', 'England', $today->modify('+40 days'), false],
    ] as $i => [$first, $last, $dist, $region, $bday, $celebrate]) {
        $uid = create_user(strtolower($first) . '.' . strtolower($last) . '@example.org', bin2hex(random_bytes(12)), 'member', "$first $last", 'active');
        $base = tempnam(sys_get_temp_dir(), 'gdemo');
        $tmp = $base . '.jpg';
        demo_image($tmp, 600, 600, 201 + $i);
        $photo = member_photo_store($tmp, 'photo.jpg', (int) filesize($tmp), $uid, false);
        unlink($tmp);
        unlink($base);
        db_tx(static function () use ($uid, $first, $last, $dist, $region, $bday, $celebrate, $photo, $district, $today): void {
            $mid = db_insert('members', ['user_id' => $uid, 'member_number' => member_issue_number('member'), 'membership_type' => 'Standard',
                'first_name' => $first, 'last_name' => $last, 'birth_day' => (int) $bday->format('j'), 'birth_month' => (int) $bday->format('n'), 'country' => 'United Kingdom',
                'region' => $region, 'city' => 'Harbourside', 'photo_media_id' => $photo, 'card_code' => card_code_new(),
                'card_valid_until' => $today->modify('+1 year')->format('Y-m-d'), 'verified_at' => utc_now(), 'consent_at' => utc_now(),
                'prefs' => json_encode(['newsletters' => true, 'birthday_greeting' => true, 'celebrants' => $celebrate])]);
            custom_values_save('member_field_values', 'member_id', $mid, [$district => $dist]);
        });
    }

    // Builder pages (§3.1): home, about, contact and membership, built from the A6 sections.
    setting_set('donate.bank_text', "Harbourside Community Association (demo)\nSort code 00-00-00 · Account 00000000\nReference: DONATION");
    setting_set('donate.links', [['label' => 'Give online', 'url' => 'https://example.org/donate']]);
    $festival = q_one('SELECT slug FROM events WHERE id = ?', [$eventIds['Harbour festival']]);
    $home = demo_save('pages', ['title' => 'Home', 'summary' => 'Neighbours helping neighbours, by the water and beyond.', 'status' => 'published', 'visibility' => 'public', 'is_home' => '1'], $userId);
    builder_initialise($home, demo_home_sections($ph, $festival !== null ? event_url($festival) : '/events'), $userId);
    builder_initialise($about, demo_about_sections($ph), $userId);
    $contact = demo_save('pages', ['title' => 'Contact', 'summary' => 'Questions, ideas or offers of help: we would love to hear from you.', 'status' => 'published', 'visibility' => 'public'], $userId);
    builder_initialise($contact, [
        builder_make('contact', ['show_map' => false]),
        builder_make('faq', ['eyebrow' => 'Good to know', 'heading' => 'Before you write', 'intro' => 'Quick answers to the questions we hear most often.', 'count' => 4], ['background' => 'soft']),
        builder_make('donate'),
    ], $userId);
    $membership = demo_save('pages', ['title' => 'Membership', 'summary' => 'Join our community.', 'status' => 'published', 'visibility' => 'public'], $userId);
    builder_initialise($membership, demo_membership_sections($ph), $userId);

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
    $add($main, 'Radio', 'url', null, null, '/radio');
    $inv = $add($main, 'Get involved', 'page', null, $involved);
    $add($main, 'Get involved', 'page', null, $involved, null, $inv);
    $add($main, 'Downloads', 'list', 'downloads', null, null, $inv);
    $add($main, 'Groups', 'list', 'groups', null, null, $inv);
    $add($main, 'FAQ', 'list', 'faq', null, null, $inv);
    $add($main, 'Job board', 'url', null, null, '/jobs', $inv);

    demo_seed_community($userId);

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


/** Demo home page: every A6 pattern in one long, finished-looking page. */
function demo_home_sections(array $ph, string $festivalUrl): array
{
    return [
        builder_make('hero', [
            'layout' => 'fullscreen', 'height' => 'full', 'overlay' => 'medium', 'kenburns' => true, 'autoplay' => true,
            'eyebrow' => 'Harbourside Community Association', 'heading' => 'Neighbours helping neighbours',
            'text' => 'Festivals, food support, youth clubs and a warm welcome for everyone who lives, works or grew up by the harbour.',
            'button1_label' => 'See what’s on', 'button1_url' => '/events', 'button2_label' => 'Become a member', 'button2_url' => '/join',
            'slides' => [['media_id' => $ph['wave'], 'caption' => 'Saturday walking group'], ['media_id' => $ph['festival'], 'caption' => 'Harbour festival'], ['media_id' => $ph['cleanup'], 'caption' => 'Park clean-up']],
            'proof_avatars' => [['media_id' => $ph['elder3']], ['media_id' => $ph['smiling']], ['media_id' => $ph['family']], ['media_id' => $ph['elder4']]],
            'proof_count' => '+1k', 'proof_text' => 'Join 1,200 neighbours who already take part',
            'float_title' => 'Next up: Harbour festival', 'float_text' => 'Quayside Green · free for everyone', 'float_url' => $festivalUrl, 'float_icon' => 'calendar',
        ], ['background' => 'dark', 'width' => 'full']),
        builder_make('stats', ['eyebrow' => '', 'heading' => '', 'intro' => '', 'layout' => 'band', 'items' => [
            ['metric' => 'custom', 'value' => '1,200+', 'label' => 'Members and friends', 'icon' => 'users'],
            ['metric' => 'custom', 'value' => '600+', 'label' => 'Volunteer hours a month', 'icon' => 'hand-heart'],
            ['metric' => 'events', 'value' => '', 'label' => 'Events this year', 'icon' => 'calendar-check'],
            ['metric' => 'custom', 'value' => '18', 'label' => 'Community projects', 'icon' => 'sprout'],
        ]], ['background' => 'dark', 'spacing' => 'compact']),
        builder_make('logos', ['eyebrow' => 'Proudly supported by', 'items' => [
            ['name' => 'Harbour Town Council', 'media_id' => null, 'icon' => 'landmark', 'url' => ''],
            ['name' => 'Quayside Fund', 'media_id' => null, 'icon' => 'hand-heart', 'url' => ''],
            ['name' => 'North Shore Library', 'media_id' => null, 'icon' => 'book-open', 'url' => ''],
            ['name' => 'Marina Arts Trust', 'media_id' => null, 'icon' => 'palette', 'url' => ''],
            ['name' => 'Seaview School', 'media_id' => null, 'icon' => 'graduation-cap', 'url' => ''],
            ['name' => 'Riverside Health', 'media_id' => null, 'icon' => 'stethoscope', 'url' => ''],
            ['name' => 'Old Town Bakery', 'media_id' => null, 'icon' => 'coffee', 'url' => ''],
        ]]),
        builder_make('split_metrics', [
            'eyebrow' => 'Who we are', 'heading' => 'A neighbourhood that looks after its own',
            'text' => 'From the first welcome to a lifetime of friendships, our volunteers make sure nobody by the harbour is left on their own. We are independent, volunteer-run and open to everyone.',
            'button_label' => 'Our story', 'button_url' => '/about-us', 'media_id' => $ph['aprons'], 'image_position' => 'left',
            'chip_value' => '48+', 'chip_label' => 'volunteers on shift every week',
            'metrics' => [['value' => '30', 'label' => 'Years by the harbour'], ['value' => '1,200', 'label' => 'Members and friends']],
        ], ['background' => 'soft']),
        builder_make('features', ['eyebrow' => 'What we do', 'heading' => 'Four ways we bring people together', 'intro' => 'Hover over a card to see a little of each programme in action.', 'head_layout' => 'center', 'columns' => '4', 'style' => 'reveal', 'cards' => [
            ['icon' => 'calendar', 'title' => 'Festivals and events', 'text' => 'The Harbour festival, coffee mornings, suppers and more, all year round.', 'link_label' => 'See events', 'link_url' => '/events', 'media_id' => $ph['field']],
            ['icon' => 'utensils', 'title' => 'Food support', 'text' => 'A weekly food bank and hot meals, run with dignity and no questions asked.', 'link_label' => 'How it works', 'link_url' => '/get-involved', 'media_id' => $ph['sorting']],
            ['icon' => 'graduation-cap', 'title' => 'Youth programmes', 'text' => 'Storytelling, workshops and clubs with local schools for ages 5 to 18.', 'link_label' => 'Youth projects', 'link_url' => '/projects', 'media_id' => $ph['story']],
            ['icon' => 'hand-heart', 'title' => 'A warm welcome', 'text' => 'Friendly advice and a helping hand for newcomers and anyone going through a hard time.', 'link_label' => 'Get in touch', 'link_url' => '/contact', 'media_id' => $ph['helpdesk']],
        ]]),
        builder_make('about_band', [
            'eyebrow' => 'About us', 'heading' => 'Rooted by the harbour for thirty years',
            'text' => "We started as a handful of neighbours with a kettle and a borrowed hall. Today more than a thousand members run festivals, clubs, a food bank and a radio station.\n\nEverything we do is decided by members and delivered by volunteers, so every hour you give stays right here in Harbourside.",
            'button_label' => 'Meet the committee', 'button_url' => '/leaders', 'media_id' => $ph['porch'],
            'counters' => [['value' => '30', 'label' => 'Years of service'], ['value' => '120', 'label' => 'Events a year'], ['value' => '600+', 'label' => 'Volunteer hours a month']],
        ], ['background' => 'dark']),
        builder_make('upcoming_events', ['eyebrow' => 'Get together', 'heading' => 'What’s on next', 'intro' => 'Free, friendly and open to everyone. Some events ask you to book a place.', 'count' => 3, 'layout' => 'cards', 'link_label' => 'Open the calendar', 'link_url' => '/events/calendar'], ['background' => 'none']),
        builder_make('process', ['eyebrow' => 'Get involved', 'heading' => 'Joining takes five minutes', 'intro' => 'No forms to post and no fees in the first year. Here is what happens after you say hello.', 'button_label' => 'Join now', 'button_url' => '/join', 'media_id' => $ph['handing'], 'layout' => 'vertical'], ['background' => 'soft']),
        builder_make('showcase', ['eyebrow' => 'Our projects', 'heading' => 'Projects that changed our street', 'intro' => 'Ideas that started at a members’ meeting and grew into something the whole neighbourhood shares.', 'button_label' => 'All projects', 'button_url' => '/projects', 'items' => [
            ['title' => 'The community garden', 'text' => 'Raised beds, a seed library and a quiet bench behind the hall, looked after by forty growers.', 'media_id' => $ph['picnic'], 'icon' => 'sprout', 'link_label' => 'Read the story', 'link_url' => '/news'],
            ['title' => 'Warm spaces every winter', 'text' => 'A heated hall, hot soup and good company on the coldest evenings of the year.', 'media_id' => $ph['supper'], 'icon' => 'house-heart', 'link_label' => 'How to help', 'link_url' => '/get-involved'],
            ['title' => 'Youth storytelling', 'text' => 'Weekly sessions in the park where children tell, write and perform their own stories.', 'media_id' => $ph['youth'], 'icon' => 'book-open', 'link_label' => 'See the programme', 'link_url' => '/projects'],
            ['title' => 'The food bank', 'text' => 'Three hundred parcels a month, sorted and packed by volunteers every Thursday.', 'media_id' => $ph['packing'], 'icon' => 'utensils', 'link_label' => 'Donate food', 'link_url' => '/contact'],
        ]]),
        builder_make('testimonials', ['eyebrow' => 'In their words', 'heading' => 'What members say', 'intro' => 'Don’t take our word for it: here is what being part of Harbourside means to the people who make it happen.', 'head_layout' => 'split', 'items' => [
            ['quote' => 'I moved here knowing nobody. Within a month I had friends, a garden plot and a reason to get up on Saturday mornings.', 'name' => 'Ama Owusu', 'role' => 'Member and trustee', 'media_id' => $ph['family'], 'rating' => '5'],
            ['quote' => 'The food bank volunteers treated my family with such kindness when we needed it most. Now I help on Thursdays myself.', 'name' => 'Joseph Adeyemi', 'role' => 'Volunteer, then secretary', 'media_id' => $ph['elder2'], 'rating' => '5'],
            ['quote' => 'Our pupils’ storytelling sessions in the park are the highlight of their week. Professional, warm and always ready to help.', 'name' => 'Seaview School', 'role' => 'Partner school', 'media_id' => null, 'rating' => '5'],
            ['quote' => 'The festival is the best day of the year. Thirty stalls, a brass band and the whole street out together.', 'name' => 'Helen Carter', 'role' => 'Festival volunteer', 'media_id' => $ph['smiling'], 'rating' => '5'],
        ]], ['background' => 'dark']),
        builder_make('latest_posts', ['eyebrow' => 'Latest', 'heading' => 'News and stories', 'head_layout' => 'split', 'intro' => 'Updates from the committee, volunteers and projects.', 'count' => 4, 'layout' => 'magazine', 'link_label' => 'All news', 'link_url' => '/news']),
        builder_make('gallery', ['eyebrow' => 'Moments', 'heading' => 'Life by the harbour', 'count' => 6, 'layout' => 'mosaic', 'link_label' => 'Browse every album', 'link_url' => '/gallery'], ['background' => 'soft']),
        builder_make('radio_player', ['heading' => 'Harbourside Radio', 'size' => 'mini'], ['spacing' => 'compact']),
        builder_make('birthdays', ['hide_when_empty' => true], ['background' => 'soft', 'spacing' => 'compact']),
        builder_make('cta', ['eyebrow' => 'Membership', 'heading' => 'Your neighbourhood needs you', 'text' => 'Join free today: a digital member card, first news of every event and a real say in what we do next.', 'button1_label' => 'Join us today', 'button1_url' => '/join', 'button2_label' => 'Ways to give', 'button2_url' => '/contact', 'tone' => 'image', 'media_id' => $ph['selfie']]),
    ];
}

/** Demo about page. */
function demo_about_sections(array $ph): array
{
    return [
        builder_make('hero', ['layout' => 'fullscreen', 'height' => 'tall', 'overlay' => 'strong', 'kenburns' => true, 'eyebrow' => 'About us', 'heading' => 'Thirty years of neighbours helping neighbours',
            'text' => 'Independent, volunteer-run and open to everyone who calls Harbourside home.', 'button1_label' => 'Meet the committee', 'button1_url' => '/leaders', 'button2_label' => '', 'button2_url' => '',
            'slides' => [['media_id' => $ph['meeting'], 'caption' => '']]], ['background' => 'dark', 'width' => 'full']),
        builder_make('text_image', ['eyebrow' => 'Our story', 'heading' => 'It started with a kettle', 'image_position' => 'right', 'media_id' => $ph['porch'],
            'body' => demo_html('In 1996 a few neighbours borrowed the church hall to welcome families who had just moved to the quay. They brought a kettle, a tin of biscuits and a noticeboard.', 'Thirty years later the kettle is still here, along with a food bank, a youth programme, a community garden and a radio station, all run by members.'),
            'button_label' => 'Get involved', 'button_url' => '/get-involved']),
        builder_make('stats', ['eyebrow' => 'In numbers', 'heading' => 'What members made happen last year', 'layout' => 'rules', 'items' => [
            ['metric' => 'custom', 'value' => '3,600', 'label' => 'Food parcels', 'icon' => ''], ['metric' => 'custom', 'value' => '120', 'label' => 'Events', 'icon' => ''],
            ['metric' => 'custom', 'value' => '7,200', 'label' => 'Volunteer hours', 'icon' => ''], ['metric' => 'branches', 'value' => '', 'label' => 'Local branches', 'icon' => ''],
        ]], ['background' => 'soft']),
        builder_make('points', ['eyebrow' => 'What we stand for', 'heading' => 'Four promises we keep', 'text' => 'Written by members at our first annual meeting and still on the wall of the hall.', 'button_label' => 'Read our constitution', 'button_url' => '/downloads', 'style' => 'numbers', 'list_label' => 'Our values', 'points' => [
            ['title' => 'Everyone is welcome', 'text' => 'Whatever your age, background or how long you have lived here.'],
            ['title' => 'Members decide', 'text' => 'Plans and budgets are agreed at open general meetings.'],
            ['title' => 'Kindness first', 'text' => 'Support is given with dignity and in confidence.'],
            ['title' => 'Every penny stays local', 'text' => 'Donations fund work right here in Harbourside.'],
        ]]),
        builder_make('process', ['eyebrow' => 'Milestones', 'heading' => 'From a borrowed hall to a thousand members', 'intro' => 'A few of the moments that shaped us.', 'button_label' => '', 'button_url' => '', 'media_id' => $ph['supper'], 'steps' => [
            ['title' => '1996: The first welcome', 'text' => 'Coffee mornings for families new to the quay.'],
            ['title' => '2004: The first festival', 'text' => 'Twelve stalls and a brass band on Quayside Green.'],
            ['title' => '2015: Ground broken', 'text' => 'The community garden opens behind the hall.'],
            ['title' => 'Today', 'text' => '1,200 members, 18 projects and a radio station.'],
        ]], ['background' => 'dark']),
        builder_make('leaders', ['eyebrow' => 'People', 'heading' => 'Meet the committee', 'intro' => 'Elected by members every year at the annual meeting.', 'head_layout' => 'split', 'count' => 8, 'link_label' => 'All leaders', 'link_url' => '/leaders']),
        builder_make('logos', ['eyebrow' => 'Our partners', 'style' => 'grid', 'items' => [
            ['name' => 'Harbour Town Council', 'media_id' => null, 'icon' => 'landmark', 'url' => ''], ['name' => 'Quayside Fund', 'media_id' => null, 'icon' => 'hand-heart', 'url' => ''],
            ['name' => 'North Shore Library', 'media_id' => null, 'icon' => 'book-open', 'url' => ''], ['name' => 'Seaview School', 'media_id' => null, 'icon' => 'graduation-cap', 'url' => ''],
        ]], ['background' => 'soft']),
        builder_make('cta', ['heading' => 'Want to help?', 'text' => 'We are always looking for volunteers, trustees and new ideas.', 'button1_label' => 'Get involved', 'button1_url' => '/get-involved', 'button2_label' => 'Contact us', 'button2_url' => '/contact', 'tone' => 'dark']),
    ];
}

/** Demo membership page. */
function demo_membership_sections(array $ph): array
{
    return [
        builder_make('hero', ['layout' => 'fullscreen', 'height' => 'tall', 'overlay' => 'medium', 'eyebrow' => 'Membership', 'heading' => 'Join our community',
            'text' => 'A digital member card, early event news and a real voice in how we run things. Free in your first year.',
            'button1_label' => 'Join now', 'button1_url' => '/join', 'button2_label' => 'Register a group', 'button2_url' => '/join/group',
            'slides' => [['media_id' => $ph['selfie'], 'caption' => '']], 'proof_avatars' => [['media_id' => $ph['family']], ['media_id' => $ph['elder1']], ['media_id' => $ph['smiling']]], 'proof_text' => '86 people joined last month'], ['background' => 'dark', 'width' => 'full']),
        builder_make('tiers', ['eyebrow' => 'Ways to join', 'heading' => 'Choose how you take part', 'intro' => 'Every level gets a digital member card with a QR code anyone can check. Pay what you can: no one is turned away.', 'items' => [
            ['name' => 'Friend', 'icon' => 'heart', 'from_label' => 'Always', 'price' => 'Free', 'period' => '', 'description' => 'For everyone who wants to stay in touch and come along.', 'features' => "Digital member card\nNews and event updates\nCome to every open event", 'button_label' => 'Join free', 'button_url' => '/join', 'featured' => false, 'badge' => ''],
            ['name' => 'Supporter', 'icon' => 'hand-heart', 'from_label' => 'Suggested', 'price' => '£5', 'period' => '/ month', 'description' => 'Keeps the hall warm, open and welcoming all year.', 'features' => "Everything in Friend\nPriority booking for events\nVote at the annual meeting\nSupporter pin badge", 'button_label' => 'Become a supporter', 'button_url' => '/join', 'featured' => true, 'badge' => 'Most popular'],
            ['name' => 'Group', 'icon' => 'building', 'from_label' => 'Per year', 'price' => '£40', 'period' => '/ year', 'description' => 'For clubs, schools and associations working with us.', 'features' => "Listed in our directory\nShared events calendar\nA group member ID", 'button_label' => 'Register a group', 'button_url' => '/join/group', 'featured' => false, 'badge' => ''],
        ]], ['background' => 'soft']),
        builder_make('points', ['eyebrow' => 'Why join', 'heading' => 'More than a membership card', 'text' => 'Members shape what we do and are first to hear about everything that is happening.', 'button_label' => 'Join now', 'button_url' => '/join', 'style' => 'checks', 'points' => [
            ['title' => 'A real say', 'text' => 'Vote at general meetings and stand for the committee.'],
            ['title' => 'Events first', 'text' => 'Book places before they open to everyone else.'],
            ['title' => 'Your card, your number', 'text' => 'A digital ID card with a QR code anyone can check.'],
            ['title' => 'Friendly support', 'text' => 'Advice and a helping hand when life gets hard.'],
        ]]),
        builder_make('process', ['eyebrow' => 'How it works', 'heading' => 'Four steps to your member card', 'intro' => 'Have a photo ready for your card.', 'button_label' => 'Start now', 'button_url' => '/join', 'layout' => 'horizontal'], ['background' => 'soft']),
        builder_make('testimonials', ['eyebrow' => 'Members', 'heading' => 'Why people join', 'layout' => 'grid', 'head_layout' => 'center', 'items' => [
            ['quote' => 'Joining took five minutes and my card was on my phone before I had finished my tea.', 'name' => 'Emmanuel Osei', 'role' => 'Member since 2019', 'media_id' => $ph['elder1'], 'rating' => '5'],
            ['quote' => 'The best thing I did after moving here. I know half the street now.', 'name' => 'Ama Owusu', 'role' => 'Member and trustee', 'media_id' => $ph['family'], 'rating' => '5'],
            ['quote' => 'Our club found volunteers, a venue and a whole new audience through the association.', 'name' => 'Harbourside Youth Club', 'role' => 'Group member', 'media_id' => null, 'rating' => ''],
        ]]),
        builder_make('faq', ['eyebrow' => 'Questions', 'heading' => 'Membership questions', 'intro' => 'Anything else? Use the contact form and a volunteer will reply.', 'category' => 'Membership', 'link_label' => 'Contact us', 'link_url' => '/contact'], ['background' => 'soft']),
        builder_make('cta', ['eyebrow' => 'Ready?', 'heading' => 'It takes about five minutes', 'text' => 'Have a photo ready for your member card.', 'button1_label' => 'Start now', 'button1_url' => '/join', 'button2_label' => '', 'button2_url' => '', 'tone' => 'image', 'media_id' => $ph['field']]),
    ];
}


/** Exact duration of an .m4a/.mp4 file from its movie header (mvhd), or null. */
function demo_mp4_duration(string $file): ?float
{
    $fh = fopen($file, 'rb');
    if ($fh === false) {
        return null;
    }
    $size = (int) filesize($file);
    $find = static function (int $start, int $end, string $type) use ($fh): ?array {
        $pos = $start;
        while ($pos + 8 <= $end) {
            fseek($fh, $pos);
            $h = (string) fread($fh, 8);
            $len = unpack('N', substr($h, 0, 4))[1];
            if ($len < 8) {
                return null;
            }
            if (substr($h, 4, 4) === $type) {
                return [$pos + 8, $pos + $len];
            }
            $pos += $len;
        }
        return null;
    };
    $moov = $find(0, $size, 'moov');
    $mvhd = $moov ? $find($moov[0], $moov[1], 'mvhd') : null;
    if ($mvhd === null) {
        fclose($fh);
        return null;
    }
    fseek($fh, $mvhd[0]);
    $v = ord((string) fread($fh, 1));
    fseek($fh, $mvhd[0] + ($v === 1 ? 20 : 12));
    $scale = unpack('N', (string) fread($fh, 4))[1];
    $dur = $v === 1 ? unpack('J', (string) fread($fh, 8))[1] : unpack('N', (string) fread($fh, 4))[1];
    fclose($fh);
    return $scale > 0 ? round($dur / $scale, 3) : null;
}

/** Store one demo radio file (demo-assets/radio) with its programme row. */
function demo_radio_item(string $file, string $title, string $artist, string $description, string $category, int $userId, int|string|null $coverSeed = null): int
{
    $src = BASE . '/demo-assets/radio/' . $file;
    $tmp = tempnam(sys_get_temp_dir(), 'gradio');
    $copy = $tmp . '.' . pathinfo($file, PATHINFO_EXTENSION);
    rename($tmp, $copy);
    copy($src, $copy);
    $mediaId = media_store($copy, $file, (int) filesize($copy), $userId, false, ['folder' => 'radio', 'visibility' => 'public', 'title' => $title]);
    is_file($copy) && unlink($copy);
    // Durations are normally measured by the admin's browser at upload; the
    // demo files' lengths are read from their headers instead.
    $duration = str_ends_with($file, '.webm') ? 15.0 : demo_mp4_duration($src);
    q('UPDATE media SET duration = ? WHERE id = ?', [$duration, $mediaId]);
    $cover = is_int($coverSeed) ? demo_media_image($coverSeed, $title . ' cover', '', $userId, 800, 800, 'radio') : (is_string($coverSeed) ? (int) q_val('SELECT id FROM media WHERE folder = ? AND original_name = ? ORDER BY id LIMIT 1', ['demo', $coverSeed]) ?: null : null);
    return db_insert('radio_media', [
        'media_id' => $mediaId, 'title' => $title, 'artist' => $artist, 'description' => $description, 'cover_media_id' => $cover,
        'category_id' => q_val('SELECT id FROM radio_categories WHERE slug = ?', [$category]), 'duration' => $duration, 'created_by' => $userId,
    ]);
}

/** Phase 5-6 demo content: radio station, a notice, a draft campaign, jobs. */
function demo_seed_community(int $userId): void
{
    if (!is_dir(BASE . '/demo-assets/radio')) {
        return;
    }
    $morning = demo_radio_item('harbour-morning.m4a', 'Harbour Morning', 'The Quayside Ensemble', 'A bright tune to start the day, recorded for our station.', 'music', $userId, 'radio-microphone.jpg');
    $market = demo_radio_item('market-day.m4a', 'Market Day', 'The Quayside Ensemble', 'Upbeat music for Saturday mornings at the market.', 'music', $userId, 'festival-celebration.jpg');
    $evening = demo_radio_item('evening-calm.m4a', 'Evening Calm', 'Lantern Trio', 'Slow and gentle, for the end of a long day.', 'music', $userId, 'radio-microphone.jpg');
    $ident = demo_radio_item('station-ident.m4a', 'Station ident', 'Harbourside Radio', 'Our short station jingle.', 'music', $userId);
    $notices = demo_radio_item('community-notices.m4a', 'Community notices', 'Harbourside Radio', 'This week\'s volunteering, clubs and meetings.', 'news', $userId, 'radio-presenter.jpg');
    $studio = demo_radio_item('studio-session.webm', 'Studio session (video)', 'Harbourside Radio', 'A short video from the radio studio.', 'talk', $userId);

    $loop = db_insert('radio_playlists', ['name' => 'All-day music', 'description' => 'Plays whenever no show is scheduled.']);
    foreach ([$morning, $ident, $market, $evening, $ident] as $i => $m) {
        q('INSERT INTO radio_playlist_items (playlist_id, radio_media_id, sort) VALUES (?, ?, ?)', [$loop, $m, $i]);
    }
    $midday = db_insert('radio_playlists', ['name' => 'Midday magazine', 'description' => 'Notices and a studio video.']);
    foreach ([$ident, $notices, $studio] as $i => $m) {
        q('INSERT INTO radio_playlist_items (playlist_id, radio_media_id, sort) VALUES (?, ?, ?)', [$midday, $m, $i]);
    }
    $start = (new DateTimeImmutable('now', site_tz()))->modify('-1 day')->format('Y-m-d');
    $dur = radio_source_duration(null, $midday)[0] ?? 180;
    q('INSERT INTO radio_slots (title, start_at, duration, repeat_rule, days_mask, playlist_id, created_by) VALUES (?, ?, ?, ?, 0, ?, ?)',
        ['Midday magazine', local_to_utc($start . 'T12:00'), $dur, 'daily', $midday, $userId]);
    q('INSERT INTO radio_slots (title, start_at, duration, repeat_rule, days_mask, media_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)',
        ['Evening wind-down', local_to_utc($start . 'T21:00'), (int) ceil((float) q_val('SELECT duration FROM radio_media WHERE id = ?', [$evening])), 'weekly', radio_days_mask([5, 6, 7]), $evening, $userId]);
    setting_set('radio.loop_playlist_id', $loop);
    setting_set('radio.enabled', true);

    db_insert('notices', [
        'title' => 'Welcome to your new member dashboard',
        'body' => demo_html('Notices from the committee appear here. You can also find your member card, your event places and the job board in your account.', 'Questions? Use the contact form and a volunteer will reply.'),
        'audience' => json_encode(audience_default()), 'status' => 'published', 'published_at' => utc_now(), 'created_by' => $userId,
    ]);
    db_insert('campaigns', [
        'subject' => 'News from Harbourside this month', 'preheader' => 'Events, projects and ways to help',
        'blocks' => json_encode(campaign_starter_blocks(), JSON_UNESCAPED_UNICODE), 'audience' => json_encode(audience_default()), 'created_by' => $userId,
    ]);
    $admin = q_one('SELECT * FROM users WHERE id = ?', [$userId]);
    if ($admin !== null) {
        $admin['permissions'] = [];
        foreach ([
            ['title' => 'Volunteer coordinator', 'organisation' => 'Harbourside Community Association', 'location' => 'Harbourside', 'job_type' => 'volunteer', 'description' => "Help us welcome and support new volunteers.\n\nAbout four hours a week; training provided.", 'apply_email' => 'hello@example.org'],
            ['title' => 'Youth club assistant', 'organisation' => 'Quay Street Youth Club (demo)', 'location' => 'Harbourside', 'job_type' => 'part_time', 'salary_text' => '£12.50 an hour', 'description' => "Support Tuesday and Thursday sessions for 11 to 16 year olds.", 'apply_url' => 'https://example.org/apply'],
        ] as $in) {
            [$data] = job_validate($in);
            job_create($data, $admin);
        }
    }
}
