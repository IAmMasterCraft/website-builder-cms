<?php
declare(strict_types=1);

// Addendum A2: newsletter opt-in prompt, preferences link in every email,
// essential membership notices, sign-up checkbox default.

use Core\Mail\Message;

test('A2 dashboard prompt: shown until opted in; dismissal lasts 90 days; not for complaints or bounces', function (): void {
    reset_comms();
    $m = fixture_full_member([], ['newsletters' => false]);
    $row = fn (): array => member_for_user($m['user_id']);
    ok(newsletter_prompt_due($row(), $m['email']), 'not opted in: card shown');
    q('UPDATE members SET optin_prompt_dismissed_at = UTC_TIMESTAMP() - INTERVAL 89 DAY WHERE id = ?', [$m['member_id']]);
    ok(!newsletter_prompt_due($row(), $m['email']), 'dismissed 89 days ago: hidden');
    q('UPDATE members SET optin_prompt_dismissed_at = UTC_TIMESTAMP() - INTERVAL 91 DAY WHERE id = ?', [$m['member_id']]);
    ok(newsletter_prompt_due($row(), $m['email']), 'after 90 days it comes back');
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'complaint')", [$m['email']]);
    ok(!newsletter_prompt_due($row(), $m['email']), 'never after a spam complaint');
    q("UPDATE email_suppressions SET reason = 'hard_bounce' WHERE email = ?", [$m['email']]);
    ok(!newsletter_prompt_due($row(), $m['email']), 'not while the address bounces');
    q("UPDATE email_suppressions SET reason = 'unsubscribe' WHERE email = ?", [$m['email']]);
    ok(newsletter_prompt_due($row(), $m['email']), 'shown to someone who unsubscribed (they may opt in again)');
    newsletter_opt_in($row(), $m['email']);
    ok(member_pref($row(), 'newsletters'), 'opt-in switches news emails on');
    ok(!email_suppressed($m['email']), 'and lifts their own unsubscribe');
    ok(!newsletter_prompt_due($row(), $m['email']), 'no card once opted in');
});

test('A2 opting in again lifts unsubscribe/complaint but never a bounce suppression', function (): void {
    reset_comms();
    q("INSERT INTO email_suppressions (email, reason) VALUES ('a@example.test', 'unsubscribe'), ('b@example.test', 'complaint'), ('c@example.test', 'hard_bounce'), ('d@example.test', 'soft_bounce')");
    foreach (['a', 'b', 'c', 'd'] as $x) {
        email_resubscribe($x . '@example.test');
    }
    same(['c@example.test', 'd@example.test'], array_column(q_all('SELECT email FROM email_suppressions ORDER BY email'), 'email'));
});

test('A2 every email has a "Manage email preferences" link (transactional, campaign, notice)', function (): void {
    reset_comms();
    $parts = email_template_render('password_reset', ['first_name' => 'Ada', 'reset_url' => base_url('/reset/x')]);
    contains('Manage email preferences', $parts['html']);
    contains(base_url('/account/preferences'), $parts['html']);
    contains('Manage email preferences: ' . base_url('/account/preferences'), $parts['text']);
    not_contains('Unsubscribe from these emails', $parts['html'], 'transactional mail has no unsubscribe link');
    $c = ['subject' => 'News', 'preheader' => '', 'blocks' => json_encode([['type' => 'heading', 'text' => 'Hello', 'size' => 'large']]), 'essential' => 0];
    $p = campaign_render($c, ['email' => 'x@example.test', 'first_name' => 'X']);
    contains('Manage email preferences', $p['html']);
    contains('Unsubscribe from these emails', $p['html']);
    contains('Manage email preferences: ', $p['text']);
});

test('A2 essential notices reach members with news emails off, never suppressed addresses', function (): void {
    reset_comms();
    $rec = rec_mailer();
    $on = fixture_full_member(['email' => 'ess-on', 'region' => 'Ess Region'], ['newsletters' => true]);
    $off = fixture_full_member(['email' => 'ess-off', 'region' => 'Ess Region'], ['newsletters' => false]);
    $bounced = fixture_full_member(['email' => 'ess-bounced', 'region' => 'Ess Region'], ['newsletters' => false]);
    $unsub = fixture_full_member(['email' => 'ess-unsub', 'region' => 'Ess Region'], ['newsletters' => true]);
    q("INSERT INTO email_suppressions (email, reason) VALUES (?, 'hard_bounce'), (?, 'unsubscribe')", [$bounced['email'], $unsub['email']]);
    $a = ['type' => 'region', 'value' => 'Ess Region', 'field_id' => 0];
    same([$on['email']], array_column(audience_resolve($a)['recipients'], 'email'), 'normal campaign: opted-in members only');
    $ess = array_column(audience_resolve($a, true, null, true)['recipients'], 'email');
    sort($ess);
    same([$off['email'], $on['email']], $ess, 'essential: preference ignored, suppressed still left out');

    $blocks = json_encode([['type' => 'heading', 'text' => 'AGM notice', 'size' => 'large']]);
    $id = db_insert('campaigns', ['subject' => 'Annual general meeting', 'blocks' => $blocks, 'audience' => json_encode($a), 'essential' => 1]);
    same(2, campaign_queue($id));
    contains('essential membership notice', (string) q_val('SELECT html_snapshot FROM campaigns WHERE id = ?', [$id]));
    // The member switches news emails off after queueing: an essential notice is still sent.
    q('UPDATE members SET prefs = ? WHERE id = ?', [json_encode(['newsletters' => false]), $on['member_id']]);
    $stats = mail_queue_run();
    same(2, $stats['sent']);
    $sent = $rec->to();
    sort($sent);
    same([$off['email'], $on['email']], $sent);
    foreach ($rec->sent as $msg) {
        contains('essential membership notice', $msg->text, 'the footer explains why it was received');
    }
    // A normal campaign re-checks the preference at send time.
    $rec->sent = [];
    $id2 = db_insert('campaigns', ['subject' => 'Newsletter', 'blocks' => $blocks, 'audience' => json_encode($a), 'essential' => 0]);
    q('UPDATE members SET prefs = ? WHERE id = ?', [json_encode(['newsletters' => true]), $on['member_id']]);
    same(1, campaign_queue($id2));
    q('UPDATE members SET prefs = ? WHERE id = ?', [json_encode(['newsletters' => false]), $on['member_id']]);
    same(1, mail_queue_run()['suppressed']);
    same([], $rec->to());
    mailer(null, true);
});

test('A2 sign-up newsletter box starts unticked unless the super admin changes the setting', function (): void {
    setting_set('members', []);
    ok(member_settings()['newsletter_default'] === false, 'default: unticked');
    ob_start();
    \Site\Join::form([]);
    $html = (string) ob_get_clean();
    ok((bool) preg_match('/name="newsletters" value="1"(?! checked)/', $html), 'unticked on the form');
    setting_set('members', ['newsletter_default' => true]);
    ob_start();
    \Site\Join::form([]);
    $html = (string) ob_get_clean();
    contains('name="newsletters" value="1" checked', $html);
    setting_set('members', []);
});

test('A2 (review 2): the essential flag needs the essential_notices permission to change or to send', function (): void {
    reset_comms();
    $blocks = json_encode([['type' => 'heading', 'text' => 'AGM', 'size' => 'large']]);
    $aud = json_encode(audience_default());
    $id = db_insert('campaigns', ['subject' => 'Notice', 'blocks' => $blocks, 'audience' => $aud]);
    $form = static fn (string $essential): array => ['_csrf' => csrf_token(), 'op' => 'save', 'subject' => 'Notice', 'preheader' => '', 'essential' => $essential,
        'blocks' => [['type' => 'heading', 'text' => 'AGM', 'size' => 'large']]] + array_map('strval', audience_default());
    // Communications only: the box is ignored, the flag stays off; and on stays on.
    act_as(fixture_admin('admin', ['communications']));
    request('POST', '/admin/campaigns/' . $id, $form('1'));
    throws(\Core\Redirect::class, fn () => \Admin\Campaigns::save(['id' => (string) $id]));
    same(0, (int) q_val('SELECT essential FROM campaigns WHERE id = ?', [$id]), 'cannot mark as essential');
    same(0, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'essential_on' AND item_id = ?", [$id]));
    q('UPDATE campaigns SET essential = 1 WHERE id = ?', [$id]);
    request('POST', '/admin/campaigns/' . $id, $form(''));
    throws(\Core\Redirect::class, fn () => \Admin\Campaigns::save(['id' => (string) $id]));
    same(1, (int) q_val('SELECT essential FROM campaigns WHERE id = ?', [$id]), 'cannot unmark either');
    request('POST', '/admin/campaigns/' . $id . '/send', ['_csrf' => csrf_token(), 'mode' => 'now']);
    throws(\Core\Redirect::class, fn () => \Admin\Campaigns::send(['id' => (string) $id]));
    same('draft', q_val('SELECT status FROM campaigns WHERE id = ?', [$id]), 'cannot send an essential notice');
    contains('Essential membership notices', (string) ($_SESSION['flash']['message'] ?? ''));
    // With the permission: may unmark and mark.
    act_as(fixture_admin('admin', ['communications', 'essential_notices']));
    request('POST', '/admin/campaigns/' . $id, $form(''));
    throws(\Core\Redirect::class, fn () => \Admin\Campaigns::save(['id' => (string) $id]));
    same(0, (int) q_val('SELECT essential FROM campaigns WHERE id = ?', [$id]));
    request('POST', '/admin/campaigns/' . $id, $form('1'));
    throws(\Core\Redirect::class, fn () => \Admin\Campaigns::save(['id' => (string) $id]));
    same(1, (int) q_val('SELECT essential FROM campaigns WHERE id = ?', [$id]));
    same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'essential_on' AND item_id = ?", [$id]));
    // essential_notices alone opens no admin area.
    act_as(fixture_admin('admin', ['essential_notices']));
    same(403, throws(\Core\HttpException::class, fn () => \Admin\Campaigns::edit(['id' => (string) $id]))->status);
    act_as(null);
});
