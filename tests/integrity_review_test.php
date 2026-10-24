<?php
declare(strict_types=1);

// Regression tests for the data-integrity review findings (R1-R3, R7, R8)
// and the low-priority items.

// ---- R1: CSV import update mode ------------------------------------------------

test('R1 import: update mode changes only the columns present in the file', function (): void {
    $r = fixture_signup('upd-' . bin2hex(random_bytes(3)) . '@example.test', ['phone' => '07700 900555', 'birth_day' => '9', 'birth_month' => '7']);
    account_verify($r['token']);
    q("UPDATE members SET address = '1 Quay Street', city = 'Leeds' WHERE user_id = ?", [$r['user_id']]);
    $email = (string) q_val('SELECT email FROM users WHERE id = ?', [$r['user_id']]);
    $rep = members_import_csv(fixture_file("email,first_name,last_name\n$email,Renamed,Person\n", 'csv'), true);
    same(1, $rep['updated'], json_encode($rep));
    $m = member_for_user($r['user_id']);
    same('Renamed', $m['first_name']);
    same('07700 900555', $m['phone'], 'phone kept');
    same('1 Quay Street', $m['address'], 'address kept');
    same('Leeds', $m['city']);
    same(9, (int) $m['birth_day'], 'birthday kept');
    same(7, (int) $m['birth_month']);
    same('England', $m['region']);
    // A column that is present but empty does clear the value.
    members_import_csv(fixture_file("email,first_name,last_name,city\n$email,Renamed,Person,\n", 'csv'), true);
    same(null, member_for_user($r['user_id'])['city']);
});

test('R1 import: the quote csv_safe() adds on export is removed again on import', function (): void {
    same('+44 7700 900123', csv_unsafe("'+44 7700 900123"));
    same('=SUM(A1)', csv_unsafe("'=SUM(A1)"));
    same("'Ofa", csv_unsafe("'Ofa"), 'a real leading apostrophe is kept');
    same("'-5", csv_unsafe("'-5"), 'numbers were never escaped, so the quote is real');
    same('-5', csv_unsafe('-5'));
    $r = fixture_signup('round-' . bin2hex(random_bytes(3)) . '@example.test', ['phone' => '+44 7700 900999']);
    account_verify($r['token']);
    $rows = members_csv_rows(['q' => 'round-']);
    $fh = fopen($file = tempnam(sys_get_temp_dir(), 'gcsv'), 'w');
    foreach ($rows as $row) {
        fputcsv($fh, $row, ',', '"', '');
    }
    fclose($fh);
    q('UPDATE members SET phone = NULL WHERE user_id = ?', [$r['user_id']]);
    $rep = members_import_csv($file, true);
    ok($rep['updated'] >= 1, json_encode($rep));
    same('+44 7700 900999', member_for_user($r['user_id'])['phone'], 'export -> import round trip keeps the phone number');
    unlink($file);
});

// ---- R2: media usage ----------------------------------------------------------------

test('R2 media: images used in sections, revisions, templates, campaigns or card settings cannot be deleted', function (): void {
    $admin = fixture_admin();
    act_as($admin);
    $uses = [];
    $mk = static fn (): int => fixture_image_media();
    $hero = $mk();
    $pageId = builder_fixture_page($admin);
    $s = builder_new_section('text_image');
    $s['settings']['media_id'] = $hero;
    q('UPDATE pages SET sections = ? WHERE id = ?', [json_encode([$s]), $pageId]);
    $uses['live page section'] = $hero;
    $bg = $mk();
    $s2 = builder_new_section('cta');
    $s2['style']['background'] = 'image';
    $s2['style']['bg_media_id'] = $bg;
    db_insert('page_revisions', ['page_id' => $pageId, 'revision' => 99, 'sections' => json_encode([$s2])]);
    $uses['revision background'] = $bg;
    $tpl = $mk();
    db_insert('section_templates', ['name' => 'Mine', 'kind' => 'section', 'sections' => json_encode([['type' => 'hero', 'settings' => ['slides' => [['media_id' => (string) $tpl]]]]])]);
    $uses['saved template (repeater, string id)'] = $tpl;
    $camp = $mk();
    db_insert('campaigns', ['subject' => 'Img', 'blocks' => json_encode([['type' => 'image', 'media_id' => $camp]]), 'audience' => '{}']);
    $uses['campaign image block'] = $camp;
    $sig = $mk();
    setting_set('card.signature_media_id', $sig);
    $uses['card signature'] = $sig;
    $bgCard = $mk();
    setting_set('card.background_media_id', $bgCard);
    $uses['card background'] = $bgCard;
    foreach ($uses as $label => $id) {
        ok(media_usage($id) !== [], "$label is reported as a use");
        throws(Core\UploadException::class, fn () => media_delete($id));
    }
    same([], media_usage($hero + 100000));
    // Ids are matched exactly: media 1 is not "used" by a section that uses media 12.
    ok(!in_array(1, media_ids_in_json(json_encode([['settings' => ['media_id' => 12]]])), true));
    setting_set('card.signature_media_id', null);
    setting_set('card.background_media_id', null);
});

// ---- R3: comments -------------------------------------------------------------------

test('R3 comments: deleting your own comment keeps other members\' replies (shown as [deleted])', function (): void {
    reset_comms();
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    $post = fixture_content('posts', ['title' => 'Talk', 'category_id' => $cat, 'status' => 'published', 'visibility' => 'public', 'allow_comments' => '1']);
    setting_set('comments.moderation', 'post');
    $a = fixture_member();
    $b = fixture_member();
    act_as($a);
    [$top] = comment_add(current_user(), 'post', $post, 'Original thought');
    act_as($b);
    [$reply] = comment_add(current_user(), 'post', $post, 'A reply from someone else');
    $reply = comment_add(current_user(), 'post', $post, 'Reply to top', $top)[0];
    act_as($a);
    ok(comment_delete_own($top, current_user()));
    ok(q_val('SELECT id FROM comments WHERE id = ?', [$reply]) !== null, 'the other member\'s reply survives');
    $thread = comment_thread('post', $post, current_user());
    $deleted = array_values(array_filter($thread, static fn (array $c): bool => (int) $c['id'] === $top))[0] ?? null;
    ok($deleted !== null && $deleted['deleted_at'] !== null && $deleted['body'] === '' && $deleted['user_id'] === null, 'blanked, author removed');
    same(1, count($deleted['replies']));
    $html = view('partials/comments', ['type' => 'post', 'id' => $post, 'viewer' => current_user(), 'return' => '/news/talk', 'error' => null, 'thread' => $thread, 'count' => 2]);
    contains('[deleted]', $html);
    not_contains('Original thought', $html);
    engagement_forget();
    same(2, engagement_counts('post', $post)['comments'], 'the deleted comment is not counted, its reply and the other comment are');
    ok(!comment_delete_own($top, current_user()), 'cannot delete twice');
    act_as($b);
    ok(comment_delete_own($reply, current_user()));
    same(null, q_val('SELECT id FROM comments WHERE id = ?', [$top]), 'a [deleted] comment goes once its last reply is gone');
    setting_set('comments.moderation', 'pre');
});

test('R3 comments: deleting a comment without replies removes it; moderator delete keeps replies too', function (): void {
    reset_comms();
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    $post = fixture_content('posts', ['title' => 'Talk 2', 'category_id' => $cat, 'status' => 'published', 'visibility' => 'public', 'allow_comments' => '1']);
    setting_set('comments.moderation', 'post');
    $a = fixture_member();
    act_as($a);
    [$solo] = comment_add(current_user(), 'post', $post, 'Alone');
    ok(comment_delete_own($solo, current_user()));
    same(null, q_val('SELECT id FROM comments WHERE id = ?', [$solo]));
    [$top] = comment_add(current_user(), 'post', $post, 'Top');
    act_as(fixture_member());
    [$reply] = comment_add(current_user(), 'post', $post, 'Reply', $top);
    ok(comment_moderate($top, 'delete', fixture_admin()));
    ok(q_val('SELECT id FROM comments WHERE id = ?', [$reply]) !== null);
    setting_set('comments.moderation', 'pre');
});

test('Low comments: replies under a rejected comment are not counted', function (): void {
    reset_comms();
    $cat = (string) q_val("SELECT id FROM categories WHERE slug = 'news'");
    $post = fixture_content('posts', ['title' => 'Talk 3', 'category_id' => $cat, 'status' => 'published', 'visibility' => 'public', 'allow_comments' => '1']);
    setting_set('comments.moderation', 'post');
    act_as(fixture_member());
    [$top] = comment_add(current_user(), 'post', $post, 'Top');
    comment_add(current_user(), 'post', $post, 'Reply', $top);
    engagement_forget();
    same(2, engagement_counts('post', $post)['comments']);
    comment_moderate($top, 'reject', fixture_admin());
    engagement_forget();
    same(0, engagement_counts('post', $post)['comments'], 'hidden replies are not counted');
    same([], comment_thread('post', $post, current_user()));
    setting_set('comments.moderation', 'pre');
});

// ---- R7 + low items: jobs -----------------------------------------------------------

test('R7 jobs: deleting a rejected post does not make the poster trusted again', function (): void {
    reset_comms();
    $poster = fixture_member();
    $admin = fixture_admin();
    act_as($poster);
    [$d] = job_validate(job_input());
    [$ok] = job_create($d, current_user());
    job_approve($ok, $admin);
    ok(job_poster_trusted($poster));
    [$bad] = job_create($d, current_user());
    job_reject($bad, $admin, 'Spam');
    ok(!job_poster_trusted($poster));
    q('DELETE FROM jobs WHERE id = ? AND user_id = ?', [$bad, $poster]); // what "Remove" in My jobs does
    ok(!job_poster_trusted($poster), 'the rejection is remembered on the account');
    same(1, (int) q_val('SELECT job_rejections FROM users WHERE id = ?', [$poster]));
});

test('Low jobs: re-approval gives a fresh publication date; an expired job edited by a trusted owner stays expired', function (): void {
    reset_comms();
    setting_set('jobs', ['auto_approve_trusted' => true]);
    $poster = fixture_member();
    $admin = fixture_admin();
    act_as($poster);
    [$d] = job_validate(job_input());
    [$id] = job_create($d, current_user());
    $t0 = new DateTimeImmutable('2030-01-01 10:00:00', utc());
    job_approve($id, $admin, $t0);
    job_reject($id, $admin, 'Fix the salary', $t0->modify('+5 days'));
    $t1 = $t0->modify('+10 days');
    ok(job_approve($id, $admin, $t1));
    same($t1->format('Y-m-d H:i:s'), q_val('SELECT published_at FROM jobs WHERE id = ?', [$id]), 'fresh publication date');
    // Not a bug (verified): a trusted owner's edit of an expired job keeps it expired.
    [$id2] = job_create($d, current_user());
    job_approve($id2, $admin);
    q("UPDATE jobs SET status = 'expired' WHERE id = ?", [$id2]);
    q('UPDATE users SET job_rejections = 0 WHERE id = ?', [$poster]);
    q("DELETE FROM jobs WHERE id = ?", [$id]);
    ok(job_poster_trusted($poster));
    same('expired', job_update(q_one('SELECT * FROM jobs WHERE id = ?', [$id2]), $d, current_user()));
    setting_set('jobs', []);
});

// ---- R8: unverified purge -----------------------------------------------------------

test('R8 purge: a newer verification link keeps the account; a verified account is never purged', function (): void {
    $r = fixture_signup('resent-' . bin2hex(random_bytes(3)) . '@example.test');
    q('UPDATE users SET created_at = UTC_TIMESTAMP() - INTERVAL 20 DAY WHERE id = ?', [$r['user_id']]);
    user_token_create($r['user_id'], 'verify'); // "resend" 1 day ago: link valid for 13 more days
    q('UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() + INTERVAL 13 DAY WHERE user_id = ?', [$r['user_id']]);
    purge_unverified();
    ok(q_val('SELECT id FROM users WHERE id = ?', [$r['user_id']]) !== null, 'resent link still valid: kept');
    q('UPDATE user_tokens SET expires_at = UTC_TIMESTAMP() - INTERVAL 1 HOUR WHERE user_id = ?', [$r['user_id']]);
    purge_unverified();
    same(null, q_val('SELECT id FROM users WHERE id = ?', [$r['user_id']]), 'purged once the latest link expired');
    $v = fixture_signup('raced-' . bin2hex(random_bytes(3)) . '@example.test');
    account_verify($v['token']);
    ok(!account_delete($v['user_id'], true), 'the purge re-checks the status under a lock and keeps a just-verified account');
    ok(q_val('SELECT id FROM users WHERE id = ?', [$v['user_id']]) !== null);
});

// ---- Low items ---------------------------------------------------------------------------

test('Low cards: "valid until" is clamped to the end of the month', function (): void {
    $tz = new DateTimeZone('UTC');
    same('2027-02-28', card_valid_until(new DateTimeImmutable('2026-01-31', $tz), 13));
    same('2025-02-28', card_valid_until(new DateTimeImmutable('2024-02-29', $tz), 12));
    same('2028-02-28', card_valid_until(new DateTimeImmutable('2027-02-28', $tz), 12), 'the day is kept when it exists');
    same('2026-04-30', card_valid_until(new DateTimeImmutable('2026-03-31', $tz), 1));
    same('2027-06-15', card_valid_until(new DateTimeImmutable('2026-06-15', $tz), 12));
});

test('Low deletion: guest registrations made with the member\'s address are anonymised too', function (): void {
    $cat = fixture_content('events', ['title' => 'Guest anon', 'start_at' => local_in('+5 days'), 'status' => 'published', 'visibility' => 'public', 'registration_open' => '1']);
    $r = fixture_signup('guestanon-' . bin2hex(random_bytes(3)) . '@example.test');
    $email = (string) q_val('SELECT email FROM users WHERE id = ?', [$r['user_id']]);
    $reg = db_insert('event_registrations', ['event_id' => $cat, 'user_id' => null, 'name' => 'Guest Me', 'email' => $email, 'phone' => '0123', 'quantity' => 1]);
    account_delete($r['user_id']);
    $row = q_one('SELECT name, email, phone FROM event_registrations WHERE id = ?', [$reg]);
    same(['name' => 'Former member', 'email' => '', 'phone' => ''], $row);
});

test('Low time: a time in the daylight-saving gap gets a clear message', function (): void {
    $london = new DateTimeZone('Europe/London');
    same(null, local_to_utc('2026-03-29T01:30', $london));
    contains('clocks go forward', (string) local_time_problem('2026-03-29T01:30', $london));
    same(null, local_time_problem('2026-03-29T02:30', $london));
    same('Enter a valid date and time.', local_time_problem('2026-02-30T10:00', $london));
    setting_set('site.timezone', 'Europe/London');
    [, $errors] = content_validate(content_type('events'), ['title' => 'Gap', 'start_at' => '2026-03-29T01:30', 'timezone' => 'Europe/London', 'status' => 'draft', 'visibility' => 'public']);
    contains('clocks go forward', $errors['start_at'] ?? '');
});

test('Low radio: slot overlap check and insert happen under one schedule lock', function (): void {
    $src = (string) file_get_contents(BASE . '/src/Admin/Radio.php');
    contains('radio_slot_save($_POST, null', $src);
    not_contains("db_insert('radio_slots'", $src);
    $fnSrc = (string) file_get_contents(BASE . '/src/lib/radio.php');
    contains("WHERE `key` = 'radio.schedule_lock' FOR UPDATE", $fnSrc);
});

test('Low radio: changing the site time zone reports slots that would clash', function (): void {
    q('DELETE FROM radio_slots');
    $tz = new DateTimeZone('Europe/London');
    $mediaId = fixture_pdf_media();
    $rm = db_insert('radio_media', ['media_id' => $mediaId, 'title' => 'Show', 'duration' => 3600, 'on_demand' => 0]);
    // A daily 13:30 slot that started in winter, and a one-off on 15 July 12:00-13:00 UTC.
    // Read in UTC the daily repeats stay at 13:30 UTC (no clash); read in London they keep
    // 13:30 local time, which is 12:30 UTC in summer, and clash with the one-off.
    db_insert('radio_slots', ['title' => 'One-off', 'start_at' => '2030-07-15 12:00:00', 'duration' => 3600, 'repeat_rule' => 'none', 'days_mask' => 0, 'media_id' => $rm]);
    db_insert('radio_slots', ['title' => 'Daily', 'start_at' => '2030-01-10 13:30:00', 'duration' => 3600, 'repeat_rule' => 'daily', 'days_mask' => 0, 'media_id' => $rm]);
    same([], radio_schedule_conflicts(new DateTimeZone('UTC')), 'no clash read in UTC');
    ok(radio_schedule_conflicts($tz) !== [], 'switching to a zone with daylight saving makes the repeats clash');
    q('DELETE FROM radio_slots');
    q('DELETE FROM radio_media WHERE id = ?', [$rm]);
});
