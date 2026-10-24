<?php
declare(strict_types=1);

namespace Admin;

/**
 * Email campaigns (§5). The block editor is a normal form (works without
 * JavaScript: add/move/remove buttons submit it); campaigns.js adds
 * autosave and a live preview refresh.
 */
final class Campaigns
{
    private static function find(int $id): array
    {
        $c = q_one('SELECT * FROM campaigns WHERE id = ?', [$id]);
        if ($c === null) {
            abort(404);
        }
        return $c;
    }

    private static function editable(array $c): bool
    {
        return in_array($c['status'], ['draft', 'scheduled'], true);
    }

    public static function index(array $p): void
    {
        require_permission('communications');
        $status = query_param('status');
        $where = array_key_exists($status, ['draft' => 1, 'scheduled' => 1, 'sending' => 1, 'sent' => 1]) ? 'WHERE c.status = ?' : '';
        $rows = q_all("SELECT c.*, u.display_name AS author,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'sent') AS sent_count,
                (SELECT COUNT(*) FROM campaign_recipients r WHERE r.campaign_id = c.id AND r.status = 'failed') AS failed_count
              FROM campaigns c LEFT JOIN users u ON u.id = c.created_by $where ORDER BY c.updated_at DESC LIMIT 200", $where !== '' ? [$status] : []);
        $counts = ['' => (int) q_val('SELECT COUNT(*) FROM campaigns')];
        foreach (q_all('SELECT status, COUNT(*) AS n FROM campaigns GROUP BY status') as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        render('admin/campaigns_index', ['title' => 'Campaigns', 'rows' => $rows, 'status' => $where !== '' ? $status : '', 'counts' => $counts], 'admin');
    }

    public static function store(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $subject = input('subject');
        if ($subject === '' || mb_strlen($subject) > 200) {
            $subject = 'News from ' . setting('site.name');
        }
        $id = db_insert('campaigns', [
            'subject' => $subject, 'blocks' => json_encode(campaign_starter_blocks(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'audience' => json_encode(audience_default(), JSON_THROW_ON_ERROR), 'created_by' => $user['id'],
        ]);
        audit('create', 'campaign', $id, $subject);
        redirect('/admin/campaigns/' . $id);
    }

    public static function edit(array $p): void
    {
        $user = require_permission('communications');
        $c = self::find((int) $p['id']);
        $old = take_old_input();
        $aud = audience_decode($c['audience']);
        render('admin/campaign_edit', [
            'title' => $c['subject'], 'c' => $c, 'blocks' => campaign_blocks_decode($c['blocks']), 'audience' => $aud,
            'choices' => audience_choices(), 'reach' => self::editable($c) ? audience_resolve($aud) : null, 'errors' => $old['errors'],
            'counts' => campaign_counts((int) $c['id']), 'editable' => self::editable($c), 'user' => $user,
            'images' => q_all("SELECT id, title, original_name FROM media WHERE kind = 'image' AND visibility = 'public' ORDER BY id DESC LIMIT 80"),
            'body_class' => 'wide-mode',
        ], 'admin');
    }

    /** Save the editor form; op = save | add | up:i | down:i | dup:i | remove:i. */
    public static function save(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $c = self::find((int) $p['id']);
        $json = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if (!self::editable($c)) {
            $json ? json_out(['ok' => false, 'error' => 'This campaign has been sent and can no longer be edited.'], 409) : redirect('/admin/campaigns/' . $c['id']);
        }
        $raw = is_array($_POST['blocks'] ?? null) ? array_values($_POST['blocks']) : [];
        $op = input('op', 'save');
        $focus = null;
        if (preg_match('/^(up|down|dup|remove):(\d+)$/', $op, $m) && isset($raw[(int) $m[2]])) {
            $i = (int) $m[2];
            switch ($m[1]) {
                case 'up':
                    if ($i > 0) {
                        [$raw[$i - 1], $raw[$i]] = [$raw[$i], $raw[$i - 1]];
                        $focus = $i - 1;
                    }
                    break;
                case 'down':
                    if ($i < count($raw) - 1) {
                        [$raw[$i + 1], $raw[$i]] = [$raw[$i], $raw[$i + 1]];
                        $focus = $i + 1;
                    }
                    break;
                case 'dup':
                    array_splice($raw, $i + 1, 0, [$raw[$i]]);
                    $focus = $i + 1;
                    break;
                case 'remove':
                    array_splice($raw, $i, 1);
                    break;
            }
        } elseif ($op === 'add' && isset(campaign_block_types()[input('add_type')]) && count($raw) < CAMPAIGN_MAX_BLOCKS) {
            $raw[] = campaign_new_block(input('add_type'));
            $focus = count($raw) - 1;
        }
        [$blocks, $errors] = campaign_blocks_validate($raw);
        [$aud, $audErrors] = audience_validate($_POST);
        $errors += $audErrors;
        $subject = input('subject');
        if ($subject === '' || mb_strlen($subject) > 200) {
            $errors['subject'] = 'Enter a subject line (up to 200 characters).';
            $subject = $subject === '' ? (string) $c['subject'] : mb_substr($subject, 0, 200);
        }
        $pre = mb_substr(input('preheader'), 0, 200);
        db_update('campaigns', (int) $c['id'], [
            'subject' => $subject, 'preheader' => $pre,
            'blocks' => json_encode($blocks, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'audience' => json_encode($aud, JSON_THROW_ON_ERROR),
        ]);
        if ($json) {
            $reach = audience_resolve($aud);
            json_out(['ok' => true, 'errors' => $errors, 'saved_at' => fmt_date(utc_now(), 'H:i'), 'recipients' => count($reach['recipients']), 'excluded' => $reach['excluded']]);
        }
        // Autosave (JSON) is not logged: it runs every few seconds. Form saves are.
        audit('update', 'campaign', (int) $c['id'], $subject . ($op !== 'save' ? ' (' . $op . ')' : ''));
        if ($errors) {
            remember_input([], $errors);
        } elseif ($op === 'save') {
            flash('Campaign saved.');
        }
        redirect('/admin/campaigns/' . $c['id'] . ($focus !== null ? '#block-' . $focus : ''));
    }

    public static function preview(array $p): void
    {
        $user = require_permission('communications');
        $c = self::find((int) $p['id']);
        $recipient = ['email' => (string) $user['email'], 'first_name' => explode(' ', (string) $user['display_name'])[0]];
        if (!self::editable($c) && (string) $c['html_snapshot'] !== '') {
            $parts = campaign_personalise(['subject' => (string) $c['subject'], 'html' => (string) $c['html_snapshot'], 'text' => (string) $c['text_snapshot']], $recipient);
        } else {
            $parts = campaign_render($c, $recipient);
        }
        email_preview_out($parts['html']);
    }

    public static function test(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $c = self::find((int) $p['id']);
        $to = strtolower(input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('Enter a valid address for the test.', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        if (rate_limited('campaign-test:' . $user['id'], 20, 3600)) {
            flash('You have sent many tests in the last hour. Please wait a little.', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        rate_hit('campaign-test:' . $user['id']);
        $ok = campaign_test_send($c, $to, explode(' ', (string) $user['display_name'])[0]);
        audit('test', 'campaign', (int) $c['id'], 'test to ' . $to . ($ok ? '' : ' (failed)'));
        flash($ok ? 'Test sent to ' . $to . '.' : 'The test could not be sent. Check the email settings.', $ok ? 'success' : 'error');
        redirect('/admin/campaigns/' . $c['id']);
    }

    /** mode=now queues immediately; mode=schedule sets send_at (cron queues it). */
    public static function send(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $c = self::find((int) $p['id']);
        if (!self::editable($c)) {
            flash('This campaign has already been sent.', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        [, $errors] = campaign_blocks_validate(json_decode((string) $c['blocks'], true));
        $blocks = campaign_blocks_decode($c['blocks']);
        if ($errors || $blocks === []) {
            flash('Fix the campaign content before sending (every block needs its details, and there must be at least one block).', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        $reach = audience_resolve(audience_decode($c['audience']));
        if ($reach['recipients'] === []) {
            flash('Nobody in this audience can receive the campaign (no one has email newsletters switched on). Choose another audience.', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        if (input('mode') === 'schedule') {
            $at = local_to_utc(input('send_at'));
            if ($at === null || $at <= utc_now()) {
                flash('Choose a date and time in the future to schedule the campaign.', 'error');
                redirect('/admin/campaigns/' . $c['id']);
            }
            q("UPDATE campaigns SET status = 'scheduled', send_at = ? WHERE id = ?", [$at, $c['id']]);
            audit('schedule', 'campaign', (int) $c['id'], $c['subject']);
            flash('Scheduled for ' . fmt_datetime($at) . '. It will go to about ' . pluralise(count($reach['recipients']), 'recipient') . '.');
            redirect('/admin/campaigns/' . $c['id']);
        }
        $n = campaign_queue((int) $c['id']);
        audit('send', 'campaign', (int) $c['id'], $c['subject'] . ' to ' . $n);
        flash('Sending to ' . pluralise($n, 'recipient') . '. Emails go out in batches of ' . mail_per_minute() . ' a minute.');
        redirect('/admin/campaigns/' . $c['id'] . '/log');
    }

    public static function cancel(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $c = self::find((int) $p['id']);
        if ($c['status'] === 'scheduled') {
            q("UPDATE campaigns SET status = 'draft', send_at = NULL WHERE id = ? AND status = 'scheduled'", [$c['id']]);
            audit('cancel', 'campaign', (int) $c['id'], (string) $c['subject']);
            flash('The schedule is cancelled; the campaign is a draft again.');
        }
        redirect('/admin/campaigns/' . $c['id']);
    }

    public static function duplicate(array $p): void
    {
        csrf_check();
        $user = require_permission('communications');
        $c = self::find((int) $p['id']);
        $id = db_insert('campaigns', ['subject' => mb_substr('Copy of ' . $c['subject'], 0, 200), 'preheader' => $c['preheader'], 'blocks' => $c['blocks'], 'audience' => $c['audience'], 'created_by' => $user['id']]);
        audit('create', 'campaign', $id, 'copy of #' . $c['id']);
        flash('Copied. This is a new draft.');
        redirect('/admin/campaigns/' . $id);
    }

    public static function delete(array $p): void
    {
        csrf_check();
        require_permission('communications');
        $c = self::find((int) $p['id']);
        if (in_array($c['status'], ['sending'], true)) {
            flash('A campaign that is being sent cannot be deleted.', 'error');
            redirect('/admin/campaigns/' . $c['id']);
        }
        q('DELETE FROM campaigns WHERE id = ?', [$c['id']]);
        audit('delete', 'campaign', (int) $c['id'], $c['subject']);
        flash('Campaign deleted.');
        redirect('/admin/campaigns');
    }

    /** Delivery log: every recipient with status. */
    public static function log(array $p): void
    {
        require_permission('communications');
        $c = self::find((int) $p['id']);
        $status = query_param('status');
        $pg = paging(50);
        $params = [$c['id']];
        $where = 'campaign_id = ?';
        if (in_array($status, ['queued', 'sending', 'sent', 'failed'], true)) {
            $where .= ' AND status = ?';
            $params[] = $status;
        } else {
            $status = '';
        }
        $rows = q_all("SELECT * FROM campaign_recipients WHERE $where ORDER BY id LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);
        $total = (int) q_val("SELECT COUNT(*) FROM campaign_recipients WHERE $where", $params);
        render('admin/campaign_log', ['title' => 'Delivery log', 'c' => $c, 'rows' => $rows, 'counts' => campaign_counts((int) $c['id']), 'status' => $status, 'total' => $total, 'page' => $pg['page'], 'per' => $pg['per']], 'admin');
    }
}
