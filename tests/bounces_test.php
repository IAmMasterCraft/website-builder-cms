<?php
declare(strict_types=1);

// Addendum A5: bounce and complaint handling. Classification, VERP tags,
// DSN/ARF parsing of realistic samples (tests/fixtures/bounces), the
// suppression rules, SMTP-time rejections through a fake relay, the
// IMAP/POP3 clients against a fake TLS mail server, provider webhooks with
// real signatures, DNS hygiene with an injected resolver, throttling,
// reporting. No test touches real DNS or the network.

use Core\Mail\BounceParser;
use Core\Mail\ImapClient;
use Core\Mail\Message;
use Core\Mail\Pop3Client;
use Core\Mail\RecipientRejectedException;
use Core\Mail\SmtpMailer;

const BOUNCE_FIXTURES = __DIR__ . '/fixtures/bounces';
const WEBHOOK_FIXTURES = __DIR__ . '/fixtures/webhooks';

function reset_bounces(): void
{
    reset_comms();
    foreach (['email_events', 'email_domains'] as $t) {
        q('DELETE FROM ' . db_ident($t));
    }
    setting_set('mail.bounce', ['per_domain_minute' => 0, 'address' => 'bounces@harbourside.example.test']);
    setting_set('mail.bounce_state', null);
    dns_forget();
}

/** A fixture with its placeholders filled in. */
function bounce_fixture(string $name, array $vars): string
{
    $raw = (string) file_get_contents(BOUNCE_FIXTURES . '/' . $name);
    foreach ($vars as $k => $v) {
        $raw = str_replace('{{' . $k . '}}', (string) $v, $raw);
    }
    return $raw;
}

function webhook_fixture(string $name, array $vars): string
{
    $raw = (string) file_get_contents(WEBHOOK_FIXTURES . '/' . $name);
    foreach ($vars as $k => $v) {
        $raw = str_replace('{{' . $k . '}}', (string) $v, $raw);
    }
    return $raw;
}

/** A sent campaign with one sent recipient row per address. @return array{campaign:int, rows:array<string,int>} */
function sent_campaign(array $emails, string $status = 'sent'): array
{
    $cid = db_insert('campaigns', ['subject' => 'Bounce test ' . bin2hex(random_bytes(3)), 'blocks' => json_encode([['type' => 'heading', 'text' => 'Hi', 'size' => 'large']]),
        'audience' => json_encode(audience_default()), 'status' => $status, 'html_snapshot' => '<p>Hi</p>', 'text_snapshot' => 'Hi', 'subject_snapshot' => 'Hi']);
    $rows = [];
    foreach ($emails as $e) {
        $rows[$e] = db_insert('campaign_recipients', ['campaign_id' => $cid, 'email' => $e, 'name' => 'X', 'first_name' => 'X', 'status' => 'sent', 'sent_at' => utc_now()]);
    }
    return ['campaign' => $cid, 'rows' => $rows];
}

/** Self-signed certificate for 127.0.0.1 / localhost (created once per run). @return array{cert:string,key:string} */
function test_tls_cert(): array
{
    static $files = null;
    if ($files !== null) {
        return $files;
    }
    $dir = sys_get_temp_dir() . '/gather-tls-' . getmypid();
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    $cnf = $dir . '/openssl.cnf';
    file_put_contents($cnf, "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:localhost,IP:127.0.0.1\nbasicConstraints=critical,CA:TRUE\nkeyUsage=digitalSignature,keyEncipherment,keyCertSign\n");
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $cnf]);
    $csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['config' => $cnf, 'digest_alg' => 'sha256']);
    $x509 = openssl_csr_sign($csr, null, $key, 2, ['config' => $cnf, 'x509_extensions' => 'v3', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
    openssl_x509_export($x509, $certPem);
    openssl_pkey_export($key, $keyPem, null, ['config' => $cnf]);
    file_put_contents($dir . '/cert.pem', $certPem);
    file_put_contents($dir . '/key.pem', $keyPem);
    return $files = ['cert' => $dir . '/cert.pem', 'key' => $dir . '/key.pem'];
}

/**
 * Start a helper server script; returns [process, port].
 * @return array{0:resource,1:int}
 */
function start_helper(string $script, array $args, array $env = []): array
{
    $proc = proc_open(array_merge([PHP_BINARY, $script], $args), [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', BASE . '/storage/logs/test-helper.log', 'a']], $pipes,
        null, $env !== [] ? $env + getenv() : null);
    stream_set_timeout($pipes[1], 10);
    $port = (int) trim((string) fgets($pipes[1]));
    if ($port <= 0) {
        proc_terminate($proc);
        throw new RuntimeException('helper server did not start: ' . basename($script));
    }
    return [$proc, $port];
}

function stop_helper($proc): void
{
    proc_terminate($proc);
    proc_close($proc);
}

/** A fake mailbox folder with the given messages. */
function fake_maildir(array $messages): string
{
    $dir = sys_get_temp_dir() . '/gather-maildir-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $i = 0;
    foreach ($messages as $name => $raw) {
        file_put_contents(sprintf('%s/%02d-%s.eml', $dir, ++$i, $name), $raw);
    }
    return $dir;
}

// ---- Classification and tags -------------------------------------------------------------

test('A5 classification: hard, soft, block from status codes, SMTP codes and text', function (): void {
    $cases = [
        ['5.1.1', 550, 'User unknown', 'hard'],
        ['5.1.10', 550, 'RESOLVER.ADR.RecipientNotFound', 'hard'],
        ['5.4.4', 0, 'Unable to route: no MX', 'hard'],
        ['5.2.1', 550, 'Mailbox disabled', 'hard'],
        ['4.2.2', 452, 'over quota', 'soft'],
        ['5.2.2', 552, 'mailbox full', 'soft'],
        ['5.7.1', 550, 'Message rejected as spam', 'block'],
        ['5.7.26', 550, 'Unauthenticated email is not accepted (DMARC)', 'block'],
        ['4.7.0', 421, 'Try again later', 'soft'],
        ['', 450, 'Greylisted, please try again', 'soft'],
        ['', 550, 'No such user here', 'hard'],
        ['', 554, 'Your IP is listed in a block list', 'block'],
        ['', 0, 'Connection timed out', 'soft'],
        ['5.0.0', 550, 'mailbox is full', 'soft'],
        ['2.0.0', 250, 'OK', 'none'],
        ['', 0, 'something odd', 'unknown'],
    ];
    foreach ($cases as [$status, $code, $text, $want]) {
        same($want, bounce_classify($status, $code, $text), "$status $code $text");
    }
    same('hard', bounce_classify('', 0, 'smtp; 550 5.1.1 <a@b>: Recipient address rejected'), 'codes found inside the text');
});

test('A5 VERP tags are signed per row and address; forged, altered or moved tags are ignored', function (): void {
    reset_bounces();
    $c = sent_campaign(['tagged@recipient.example.test', 'other@recipient.example.test']);
    $id = $c['rows']['tagged@recipient.example.test'];
    $tag = verp_tag('c', $id, 'tagged@recipient.example.test');
    ok((bool) preg_match('/^c' . $id . '-[a-f0-9]{12}$/', $tag));
    $hit = verp_parse('bounces+' . $tag . '@harbourside.example.test');
    same(['campaign_recipients', $id, 'tagged@recipient.example.test'], [$hit['table'], $hit['id'], $hit['email']]);
    same(verp_tag('c', $id, 'TAGGED@recipient.example.test'), $tag, 'the address is compared in lower case');
    $q = db_insert('email_queue', ['to_email' => 'queued@recipient.example.test', 'subject' => 'S', 'text_body' => '', 'status' => 'sent', 'sent_at' => utc_now()]);
    same(['email_queue', $q], array_values(array_slice(verp_parse('<' . verp_tag('q', $q, 'queued@recipient.example.test') . '.abc@x>'), 0, 2)));
    $other = $c['rows']['other@recipient.example.test'];
    same(null, verp_parse('c' . $other . '-' . substr($tag, strlen('c' . $id) + 1)), 'signature moved to another row');
    same(null, verp_parse(verp_tag('c', $id, 'attacker@evil.example.test')), 'signed for another address');
    same(null, verp_parse('c' . $id . '-000000000000'), 'signature forged');
    same(null, verp_parse('c' . $id . '-' . substr(app_sign('verp|c' . $id), 0, 12)), 'old-style tag (without the address) is no longer trusted');
    same(null, verp_parse(verp_tag('c', 999999, 'x@recipient.example.test')), 'no such row');
    same('bounces+' . $tag . '@harbourside.example.test', verp_return_path($tag));
    setting_set('mail.bounce', ['address' => '']);
    same(null, verp_return_path($tag), 'no bounce address: the From address stays the envelope sender');
    throws(InvalidArgumentException::class, fn () => verp_tag('x', 1, 'a@b.test'));
});

// ---- Parsing the sample reports -------------------------------------------------------------

test('A5 parser reads Postfix, Gmail, Outlook and Exim DSNs, legacy text bounces and ARF reports', function (): void {
    reset_bounces();
    $v = ['TAG' => verp_tag('c', 77, 'gone@recipient.example.test'), 'EMAIL' => 'gone@recipient.example.test'];
    $r = BounceParser::parse(bounce_fixture('postfix_hard_511.eml', $v));
    same('dsn', $r['kind']);
    same([['email' => 'gone@recipient.example.test', 'action' => 'failed', 'status' => '5.1.1']], array_map(static fn (array $x): array => array_slice($x, 0, 3), $r['recipients']));
    contains('User unknown in virtual mailbox table', $r['recipients'][0]['diagnostic'], 'folded Diagnostic-Code is unfolded');
    ok(in_array('bounces+' . $v['TAG'] . '@harbourside.example.test', $r['envelope'], true), 'VERP address from Delivered-To/To');
    same([$v['TAG']], $r['gather_ids']);

    $r = BounceParser::parse(bounce_fixture('gmail_hard_511_no_verp.eml', $v));
    same('dsn', $r['kind']);
    same('5.1.1', $r['recipients'][0]['status']);
    same([$v['TAG']], $r['gather_ids'], 'tag from the returned message headers');

    $r = BounceParser::parse(bounce_fixture('gmail_soft_422_mailbox_full.eml', $v));
    same(['failed', '4.2.2'], [$r['recipients'][0]['action'], $r['recipients'][0]['status']]);
    $r = BounceParser::parse(bounce_fixture('outlook_mailbox_full_522.eml', $v));
    same('5.2.2', $r['recipients'][0]['status']);
    same('soft', bounce_classify($r['recipients'][0]['status'], 0, $r['recipients'][0]['diagnostic']));
    $r = BounceParser::parse(bounce_fixture('exim_block_571.eml', $v));
    same('block', bounce_classify($r['recipients'][0]['status'], 0, $r['recipients'][0]['diagnostic']));

    $r = BounceParser::parse(bounce_fixture('exim_legacy_text_511.eml', $v));
    same('text-bounce', $r['kind'], 'not mistaken for an auto-reply despite Auto-Submitted');
    same('gone@recipient.example.test', $r['recipients'][0]['email']);
    same('5.1.1', $r['recipients'][0]['status']);
    same([$v['TAG']], $r['gather_ids']);

    $r = BounceParser::parse(bounce_fixture('arf_complaint.eml', $v));
    same(['arf', 'abuse'], [$r['kind'], $r['feedback_type']]);
    same('redacted@mailbox.example.test', $r['recipients'][0]['email']);
    same('delayed', BounceParser::parse(bounce_fixture('postfix_delayed.eml', $v))['recipients'][0]['action']);
    same('auto-reply', BounceParser::parse(bounce_fixture('auto_reply.eml', $v))['kind']);
    same('unknown', BounceParser::parse(bounce_fixture('unparseable.eml', $v))['kind']);
});

// ---- Suppression rules --------------------------------------------------------------------

test('A5 hard bounce (DSN) suppresses at once and maps to exactly one row by tag', function (): void {
    reset_bounces();
    $c = sent_campaign(['gone@recipient.example.test', 'fine@recipient.example.test']);
    $row = $c['rows']['gone@recipient.example.test'];
    $raw = bounce_fixture('postfix_hard_511.eml', ['TAG' => verp_tag('c', $row, 'gone@recipient.example.test'), 'EMAIL' => 'gone@recipient.example.test']);
    same('recorded', bounce_process_message($raw));
    same('hard_bounce', email_suppression('gone@recipient.example.test')['reason'] ?? null);
    same('hard', q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$row]));
    same(null, q_val("SELECT bounce FROM campaign_recipients WHERE email = 'fine@recipient.example.test'"));
    same('ignored', bounce_process_message($raw), 'the same report twice is recorded once');
    same(1, (int) q_val('SELECT COUNT(*) FROM email_events'));
    $counts = campaign_counts($c['campaign']);
    same([1, 1], [$counts['hard'], $counts['delivered']]);
});

test('A5 a report naming a forwarded address still suppresses the address we sent to (tag wins)', function (): void {
    reset_bounces();
    $c = sent_campaign(['alias@club.example.test']);
    $raw = bounce_fixture('postfix_hard_511.eml', ['TAG' => verp_tag('c', $c['rows']['alias@club.example.test'], 'alias@club.example.test'), 'EMAIL' => 'final.mailbox@elsewhere.example.test']);
    bounce_process_message($raw);
    ok(email_suppressed('alias@club.example.test'));
    ok(!email_suppressed('final.mailbox@elsewhere.example.test'));
});

test('A5 soft bounces: suppressed only after 3 within 30 days (mailbox full counts as soft)', function (): void {
    reset_bounces();
    $email = 'full@recipient.example.test';
    $fixtures = ['gmail_soft_422_mailbox_full.eml', 'outlook_mailbox_full_522.eml', 'gmail_soft_422_mailbox_full.eml'];
    foreach ($fixtures as $i => $f) {
        $c = sent_campaign([$email]);
        same('recorded', bounce_process_message(bounce_fixture($f, ['TAG' => verp_tag('c', $c['rows'][$email], $email), 'EMAIL' => $email])));
        same($i < 2 ? null : 'soft_bounce', email_suppression($email)['reason'] ?? null, 'after soft bounce ' . ($i + 1));
    }
    // Soft bounces older than 30 days do not count.
    reset_bounces();
    sent_campaign([$email]);
    $old = time() - 31 * 86400;
    same('recorded', bounce_record(['email' => $email, 'type' => 'soft', 'source' => 'ses', 'now' => $old]));
    bounce_record(['email' => $email, 'type' => 'soft', 'source' => 'ses', 'now' => $old + 60]);
    bounce_record(['email' => $email, 'type' => 'soft', 'source' => 'ses']);
    ok(!email_suppressed($email), 'two of the three are older than 30 days');
});

test('A5 block (5.7.1) from the provider pauses the campaign and does not suppress the address', function (): void {
    reset_bounces();
    $c = sent_campaign(['strict@recipient.example.test'], 'sending');
    db_insert('campaign_recipients', ['campaign_id' => $c['campaign'], 'email' => 'waiting@recipient.example.test', 'name' => 'W', 'first_name' => 'W']);
    same('recorded', bounce_process_message(bounce_fixture('exim_block_571.eml', ['TAG' => verp_tag('c', $c['rows']['strict@recipient.example.test'], 'strict@recipient.example.test'), 'EMAIL' => 'strict@recipient.example.test'])));
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]), 'a single mailbox block only records');
    // The same block reported by an authenticated provider webhook pauses at once.
    same('recorded', bounce_record(['email' => 'strict@recipient.example.test', 'type' => 'block', 'detail' => '550 5.7.1 Message rejected as spam', 'source' => 'ses', 'ext_id' => 'ses:block-1']));
    ok(!email_suppressed('strict@recipient.example.test'));
    same('paused', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]));
    contains('spam or for policy reasons', (string) q_val('SELECT pause_reason FROM campaigns WHERE id = ?', [$c['campaign']]));
    $rec = rec_mailer();
    mail_queue_run();
    same([], $rec->to(), 'a paused campaign sends nothing');
    ok(campaign_resume($c['campaign']));
    mail_queue_run();
    same(['waiting@recipient.example.test'], $rec->to(), 'resumed');
    $stats = bounce_stats(7);
    same(2, $stats['block']);
    mailer(null, true);
});

test('A5 ARF complaint suppresses bulk mail for the recipient of the tagged message (redacted report)', function (): void {
    reset_bounces();
    $m = fixture_full_member(['email' => 'complainer'], ['newsletters' => true]);
    $c = sent_campaign([$m['email']]);
    same('recorded', bounce_process_message(bounce_fixture('arf_complaint.eml', ['TAG' => verp_tag('c', $c['rows'][$m['email']], $m['email']), 'EMAIL' => $m['email']])));
    same('complaint', email_suppression($m['email'])['reason'] ?? null);
    same('complaint', q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$c['rows'][$m['email']]]));
    same(null, mail_undeliverable_reason($m['email']), 'transactional mail still goes to a complainer');
    ok(mail_bulk_block_reason($m['email']) !== null, 'bulk mail does not');
    ok(mail_bulk_block_reason($m['email'], true) !== null, 'not even essential notices');
    // A stronger reason replaces a weaker one, never the other way round.
    email_suppress($m['email'], 'unsubscribe');
    same('complaint', email_suppression($m['email'])['reason']);
    email_suppress($m['email'], 'hard_bounce', 'gone');
    same('hard_bounce', email_suppression($m['email'])['reason']);
});

test('A5 delay notices and auto-replies are ignored; unparseable messages are reported as such', function (): void {
    reset_bounces();
    $c = sent_campaign(['slow@recipient.example.test']);
    $v = ['TAG' => verp_tag('c', $c['rows']['slow@recipient.example.test'], 'slow@recipient.example.test'), 'EMAIL' => 'slow@recipient.example.test'];
    same('ignored', bounce_process_message(bounce_fixture('postfix_delayed.eml', $v)));
    same('ignored', bounce_process_message(bounce_fixture('auto_reply.eml', $v)));
    same('unparsed', bounce_process_message(bounce_fixture('unparseable.eml', $v)));
    same(0, (int) q_val('SELECT COUNT(*) FROM email_events'));
    same('recorded', bounce_process_message(bounce_fixture('exim_legacy_text_511.eml', $v)), 'legacy text bounce');
    same('hard_bounce', email_suppression('slow@recipient.example.test')['reason'] ?? null);
});

test('A5 H1: an untagged mailbox report suppresses nothing and waits for review', function (): void {
    reset_bounces();
    $c = sent_campaign(['plain@recipient.example.test']);
    // No VERP, no X-Gather-Message-Id: Final-Recipient only. Anyone can write this.
    $raw = str_replace(['X-Gather-Message-Id: {{TAG}}', '{{TAG}}.'], ['', 'x.'], (string) file_get_contents(BOUNCE_FIXTURES . '/postfix_hard_511.eml'));
    $raw = str_replace(['bounces+{{TAG}}@', '{{EMAIL}}'], ['bounces@', 'plain@recipient.example.test'], $raw);
    same('review', bounce_process_message($raw));
    ok(!email_suppressed('plain@recipient.example.test'), 'not suppressed');
    same(null, q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$c['rows']['plain@recipient.example.test']]), 'the message is not marked');
    same(['hard', 'pending', null], array_values(q_one('SELECT type, review, queue_id FROM email_events')));
    same(1, bounce_review_count());
    same(0, bounce_stats(7)['hard'], 'reports waiting for review do not count in the rates');
    // An admin applies it: now it suppresses (source "review", clearable).
    $id = (int) q_val('SELECT id FROM email_events');
    same('plain@recipient.example.test', bounce_review_decide($id, 'apply')['email']);
    $sup = email_suppression('plain@recipient.example.test');
    same(['hard_bounce', 'review'], [$sup['reason'] ?? null, $sup['source'] ?? null]);
    same(null, bounce_review_decide($id, 'dismiss'), 'decided once');
    same(0, bounce_review_count());
});

test('A5 H1: forged untagged DSN and ARF for any address never suppress', function (): void {
    reset_bounces();
    $victim = fixture_full_member(['email' => 'victim'], ['newsletters' => true]);
    sent_campaign([$victim['email']]);
    // Forged DSN without a tag naming the victim (and a random address we never mailed).
    $dsn = str_replace(['X-Gather-Message-Id: {{TAG}}', '{{TAG}}.'], ['', 'x.'], (string) file_get_contents(BOUNCE_FIXTURES . '/postfix_hard_511.eml'));
    same('review', bounce_process_message(str_replace(['bounces+{{TAG}}@', '{{EMAIL}}'], ['bounces@', $victim['email']], $dsn)));
    same('review', bounce_process_message(str_replace(['bounces+{{TAG}}@', '{{EMAIL}}'], ['bounces@', 'never-mailed@recipient.example.test'], $dsn)));
    // Forged ARF complaint without a tag.
    $arf = bounce_fixture('arf_complaint.eml', ['TAG' => 'c1-000000000000', 'EMAIL' => $victim['email']]);
    $arf = str_replace('redacted@mailbox.example.test', $victim['email'], $arf);
    same('review', bounce_process_message($arf));
    // A forged tag for the victim's row signed for another address, and a valid-looking tag for a row that was never sent.
    $q = db_insert('email_queue', ['to_email' => $victim['email'], 'subject' => 'S', 'text_body' => '', 'status' => 'queued']);
    same('review', bounce_process_message(bounce_fixture('postfix_hard_511.eml', ['TAG' => verp_tag('q', $q, $victim['email']), 'EMAIL' => $victim['email']])), 'tag for an unsent message');
    ok(!email_suppressed($victim['email']), 'nothing suppressed the victim');
    ok(!email_suppressed('never-mailed@recipient.example.test'));
    same(0, (int) q_val("SELECT COUNT(*) FROM email_events WHERE review = ''"), 'every event waits for review');
});

test('A5 H1: a tagged report acts on the address in our row, once, whatever the report lists', function (): void {
    reset_bounces();
    $c = sent_campaign(['mine@recipient.example.test']);
    $tag = verp_tag('c', $c['rows']['mine@recipient.example.test'], 'mine@recipient.example.test');
    // A DSN with our valid tag that lists other people as failed recipients too.
    $raw = bounce_fixture('postfix_hard_511.eml', ['TAG' => $tag, 'EMAIL' => 'someone.else@recipient.example.test']);
    $extra = '';
    foreach (range(1, 8) as $i) {
        $extra .= "\nFinal-Recipient: rfc822; extra$i@recipient.example.test\nAction: failed\nStatus: 5.1.1\n";
    }
    $raw = preg_replace('/(Action: failed\s*\nStatus: 5\.1\.1[^\n]*\n)/', '$1' . $extra, $raw, 1);
    same('recorded', bounce_process_message($raw));
    ok(email_suppressed('mine@recipient.example.test'), 'the address we sent to');
    same(0, (int) q_val("SELECT COUNT(*) FROM email_suppressions WHERE email LIKE 'extra%' OR email = 'someone.else@recipient.example.test'"), 'never addresses written in the report');
    same(1, (int) q_val('SELECT COUNT(*) FROM email_events'), 'one event per tagged report');
});

test('A5 H1: a multi-recipient legacy text bounce records at most 5 events, all for review', function (): void {
    reset_bounces();
    $list = '';
    foreach (range(1, 40) as $i) {
        $list .= "  person$i@recipient.example.test\n    SMTP error from remote mail server after RCPT TO: 550 5.1.1 User unknown\n";
    }
    $raw = "From: Mail Delivery System <Mailer-Daemon@mx.example.test>\nTo: bounces@harbourside.example.test\nSubject: Mail delivery failed: returning message to sender\n\n"
        . "This message was created automatically by mail delivery software.\n\nA message that you sent could not be delivered to one or more of its\nrecipients. This is a permanent error. The following addresses failed:\n\n" . $list;
    $parsed = BounceParser::parse($raw);
    same('text-bounce', $parsed['kind']);
    same(BounceParser::MAX_RECIPIENTS, count($parsed['recipients']), 'the parser keeps at most 20');
    same('review', bounce_process_message($raw));
    same(BOUNCE_REPORT_MAX_RECIPIENTS, (int) q_val('SELECT COUNT(*) FROM email_events'));
    same(0, (int) q_val('SELECT COUNT(*) FROM email_suppressions'));
});

test('A5 H1: admins may clear complaints from emailed reports, not provider-webhook complaints', function (): void {
    reset_bounces();
    email_suppress('arf-complaint@recipient.example.test', 'complaint', 'Feedback report: abuse', 'arf');
    email_suppress('ses-complaint@recipient.example.test', 'complaint', 'Complaint: abuse', 'ses');
    ok(\Admin\Suppressions::clearable(email_suppression('arf-complaint@recipient.example.test')));
    ok(!\Admin\Suppressions::clearable(email_suppression('ses-complaint@recipient.example.test')));
    act_as(fixture_admin('admin', ['communications']));
    foreach (['arf-complaint@recipient.example.test', 'ses-complaint@recipient.example.test'] as $addr) {
        request('POST', '/admin/email-suppressions/clear', ['_csrf' => csrf_token(), 'email' => $addr]);
        throws(\Core\Redirect::class, fn () => \Admin\Suppressions::clear([]));
    }
    ok(!email_suppressed('arf-complaint@recipient.example.test'), 'emailed complaint cleared');
    ok(email_suppressed('ses-complaint@recipient.example.test'), 'provider complaint stays (person opts in again)');
    // Review decisions through the handler: CSRF first, permission, audit.
    bounce_record(['email' => 'untagged@recipient.example.test', 'type' => 'complaint', 'source' => 'arf', 'tag' => '', 'ext_id' => 'x1']);
    $id = (int) q_val("SELECT id FROM email_events WHERE review = 'pending'");
    request('POST', '/admin/email-suppressions/review/' . $id, ['_csrf' => csrf_token(), 'decision' => 'dismiss']);
    throws(\Core\Redirect::class, fn () => \Admin\Suppressions::review(['id' => (string) $id]));
    same('dismissed', q_val('SELECT review FROM email_events WHERE id = ?', [$id]));
    ok(!email_suppressed('untagged@recipient.example.test'));
    same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'review_dismiss'"));
    act_as(fixture_admin('admin', ['content']));
    request('POST', '/admin/email-suppressions/review/' . $id, ['_csrf' => csrf_token(), 'decision' => 'apply']);
    same(403, throws(\Core\HttpException::class, fn () => \Admin\Suppressions::review(['id' => (string) $id]))->status);
    act_as(null);
});

test('A5 M1: a recipient-forged 5.7.x DSN with their own tag does not pause; blocks from 3 domains do', function (): void {
    reset_bounces();
    $emails = ['a@one.example.test', 'b@one.example.test', 'c@two.example.test', 'd@three.example.test'];
    $c = sent_campaign($emails, 'sending');
    db_insert('campaign_recipients', ['campaign_id' => $c['campaign'], 'email' => 'waiting@recipient.example.test', 'name' => 'W', 'first_name' => 'W']);
    $block = static fn (string $e): string => bounce_fixture('exim_block_571.eml', ['TAG' => verp_tag('c', $c['rows'][$e], $e), 'EMAIL' => $e]);
    same('recorded', bounce_process_message($block('a@one.example.test')));
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]), 'one recipient cannot pause the campaign');
    same('block', q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$c['rows']['a@one.example.test']]), 'still recorded on the row');
    bounce_process_message($block('b@one.example.test'));
    bounce_process_message($block('c@two.example.test'));
    same('sending', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]), 'two domains: still sending');
    bounce_process_message($block('d@three.example.test'));
    same('paused', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]), 'three different domains: paused');
    ok(!email_suppressed('a@one.example.test'), 'blocks never suppress');
});

// ---- Sending: envelope, headers, SMTP-time rejections -----------------------------------

test('A5 queued mail gets a VERP Return-Path, unique Message-ID, tag and bulk headers', function (): void {
    reset_bounces();
    $rec = rec_mailer();
    $m = fixture_full_member(['email' => 'hdr'], ['newsletters' => true]);
    $id = db_insert('campaigns', ['subject' => 'Headers', 'blocks' => json_encode([['type' => 'heading', 'text' => 'Hi', 'size' => 'large']]), 'audience' => json_encode(['type' => 'custom_field', 'value' => 'x', 'field_id' => 0])]);
    db_insert('campaign_recipients', ['campaign_id' => $id, 'email' => $m['email'], 'name' => 'A', 'first_name' => 'A']);
    q("UPDATE campaigns SET status = 'sending', html_snapshot = '<p>x</p>', text_snapshot = 'x' WHERE id = ?", [$id]);
    same(1, mail_queue_run()['sent']);
    $msg = $rec->sent[0];
    $rowId = (int) q_val('SELECT id FROM campaign_recipients WHERE campaign_id = ?', [$id]);
    $tag = verp_tag('c', $rowId, $m['email']);
    same('bounces+' . $tag . '@harbourside.example.test', $msg->returnPath);
    same($tag, $msg->headers['X-Gather-Message-Id']);
    ok(str_starts_with($msg->headers['Message-ID'], '<' . $tag . '.'), 'Message-ID carries the tag');
    same('c' . $id . ':campaign:members:gather', $msg->headers['Feedback-ID']);
    same('bulk', $msg->headers['Precedence']);
    same('auto-generated', $msg->headers['Auto-Submitted']);
    ok(isset($msg->headers['List-Unsubscribe'], $msg->headers['List-Unsubscribe-Post']));
    // Transactional mail sent directly is logged (no body kept) and tagged too.
    $rec->sent = [];
    ok(send_template_mail('direct@recipient.example.test', 'Di', 'password_reset', ['reset_url' => 'https://x.test/r']));
    $row = q_one("SELECT * FROM email_queue WHERE to_email = 'direct@recipient.example.test'");
    same(['sent', ''], [$row['status'], $row['text_body']]);
    same(verp_tag('q', (int) $row['id'], 'direct@recipient.example.test'), $rec->sent[0]->headers['X-Gather-Message-Id']);
    not_contains('Feedback-ID', implode(',', array_keys($rec->sent[0]->headers)), 'transactional mail has no Feedback-ID');
    mailer(null, true);
});

test('A5 SMTP-time rejections through a relay: 5xx hard is final, 4xx retried, 5.7.1 at DATA blocks and pauses', function (): void {
    reset_bounces();
    $log = sys_get_temp_dir() . '/gather-smtp-' . bin2hex(random_bytes(3)) . '.json';
    [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_smtp_server.php', [$log,
        'gone@recipient.example.test=550 5.1.1 <gone@recipient.example.test>: Recipient address rejected: User unknown;grey@recipient.example.test=450 4.2.0 Greylisted, try again later;*=250 2.1.5 OK',
        'strict@recipient.example.test=550 5.7.1 Message rejected as spam;*=250 2.0.0 Queued']);
    try {
        $smtp = new SmtpMailer(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'none', 'username' => '', 'password' => '', 'timeout' => 5], 'news@harbourside.example.test', 'Harbourside');
        mailer($smtp);
        $e = throws(RecipientRejectedException::class, fn () => $smtp->send(new Message('gone@recipient.example.test', 'G', 'S', "T\n")));
        same([550, '5.1.1'], [$e->smtpCode, $e->enhancedCode]);
        ok($e->permanent());

        $c = sent_campaign([], 'sending');
        $rows = [];
        foreach (['ok@recipient.example.test', 'gone@recipient.example.test', 'grey@recipient.example.test', 'strict@recipient.example.test', 'after@recipient.example.test'] as $addr) {
            $rows[$addr] = db_insert('campaign_recipients', ['campaign_id' => $c['campaign'], 'email' => $addr, 'name' => 'R', 'first_name' => 'R']);
        }
        $s = mail_queue_run(null, 50);
        same(1, $s['sent'], 'ok@ delivered');
        same(2, $s['rejected'], 'gone@ (hard) and strict@ (block)');
        same(1, $s['retry'], 'grey@ is retried later');
        same('failed', q_val('SELECT status FROM campaign_recipients WHERE id = ?', [$rows['gone@recipient.example.test']]));
        same('hard_bounce', email_suppression('gone@recipient.example.test')['reason'] ?? null, 'hard bounce at SMTP time suppresses at once');
        same('queued', q_val('SELECT status FROM campaign_recipients WHERE id = ?', [$rows['grey@recipient.example.test']]));
        ok(!email_suppressed('strict@recipient.example.test'), 'a block does not suppress the address');
        same('paused', q_val('SELECT status FROM campaigns WHERE id = ?', [$c['campaign']]), 'a block pauses the campaign');
        same('queued', q_val('SELECT status FROM campaign_recipients WHERE id = ?', [$rows['after@recipient.example.test']]), 'the rest waits while paused');
        same(1, campaign_counts($c['campaign'])['retryable'], 'only the blocked recipient can be retried, never the hard bounce');
        unset($smtp);
        mailer(null, true);
        $sessions = json_decode((string) file_get_contents($log), true)['sessions'] ?? [];
        $froms = array_merge(...array_column($sessions, 'mail_from'));
        ok(in_array('bounces+' . verp_tag('c', $rows['ok@recipient.example.test'], 'ok@recipient.example.test') . '@harbourside.example.test', $froms, true), 'MAIL FROM is the VERP address: ' . json_encode($froms));
        $data = implode("\n", array_merge(...array_column($sessions, 'data')));
        contains('X-Gather-Message-Id: ' . verp_tag('c', $rows['ok@recipient.example.test'], 'ok@recipient.example.test'), $data);
        contains('Message-ID: <' . verp_tag('c', $rows['ok@recipient.example.test'], 'ok@recipient.example.test') . '.', $data);
    } finally {
        stop_helper($proc);
        is_file($log) && unlink($log);
        mailer(null, true);
    }
});

test('A5 soft rejections at SMTP time count as a soft bounce only when the attempts run out', function (): void {
    reset_bounces();
    $row = ['id' => db_insert('email_queue', ['to_email' => 'grey@recipient.example.test', 'subject' => 'S', 'text_body' => 'T', 'status' => 'sending', 'attempts' => 1]), 'to_email' => 'grey@recipient.example.test', 'attempts' => 1];
    $e = new RecipientRejectedException(452, '4.2.2', 'Mailbox full');
    same('retry', mail_rejected('email_queue', 'last_error', $row, $e, time()));
    same(0, (int) q_val('SELECT COUNT(*) FROM email_events'));
    same('failed', mail_rejected('email_queue', 'last_error', ['attempts' => MAIL_MAX_ATTEMPTS] + $row, $e, time()));
    same(1, (int) q_val("SELECT COUNT(*) FROM email_events WHERE type = 'soft' AND source = 'smtp'"));
});

test('A5 bounced addresses get no automatic mail; user-initiated mail (password reset) still goes', function (): void {
    reset_bounces();
    $rec = rec_mailer();
    email_suppress('bounced@recipient.example.test', 'hard_bounce', 'User unknown');
    same(false, send_template_mail('bounced@recipient.example.test', 'B', 'birthday_greeting', ['account_url' => 'https://x.test']));
    ok(send_template_mail('bounced@recipient.example.test', 'B', 'password_reset', ['reset_url' => 'https://x.test/r']));
    same(['bounced@recipient.example.test'], $rec->to());
    mail_queue_message(new Message('bounced@recipient.example.test', 'B', 'Queued', "T\n"));
    same(1, mail_queue_run()['suppressed'], 'queued transactional mail is skipped at send time');
    mailer(null, true);
});

test('A5 per-domain throttling: at most N per minute to one domain; others go first', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['per_domain_minute' => 2]);
    $rec = rec_mailer();
    $c = sent_campaign([], 'sending');
    foreach (['a', 'b', 'c', 'd'] as $x) {
        db_insert('campaign_recipients', ['campaign_id' => $c['campaign'], 'email' => "$x@bigmail.example.test", 'name' => 'R', 'first_name' => 'R']);
    }
    db_insert('campaign_recipients', ['campaign_id' => $c['campaign'], 'email' => 'z@small.example.test', 'name' => 'R', 'first_name' => 'R']);
    $t = 1_950_000_000;
    $s = mail_queue_run($t, 50);
    same(3, $s['sent'], 'two to bigmail, one to small');
    same(2, $s['throttled']);
    same(0, mail_queue_run($t + 30, 50)['sent'], 'still the same minute for bigmail');
    same(2, mail_queue_run($t + 61, 50)['sent']);
    mailer(null, true);
});

// ---- Mailbox polling against a fake TLS mail server --------------------------------------

test('A5 IMAP over TLS: processed reports deleted, unreadable ones moved aside, login only after TLS', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    foreach (['ssl', 'starttls'] as $mode) {
        reset_bounces();
        $c = sent_campaign(['gone@recipient.example.test', 'complainer@recipient.example.test']);
        $msgs = [
            'hard' => bounce_fixture('postfix_hard_511.eml', ['TAG' => verp_tag('c', $c['rows']['gone@recipient.example.test'], 'gone@recipient.example.test'), 'EMAIL' => 'gone@recipient.example.test']),
            'arf' => bounce_fixture('arf_complaint.eml', ['TAG' => verp_tag('c', $c['rows']['complainer@recipient.example.test'], 'complainer@recipient.example.test'), 'EMAIL' => 'x']),
            'auto' => bounce_fixture('auto_reply.eml', ['TAG' => 'x', 'EMAIL' => 'someone@recipient.example.test']),
            'junk' => bounce_fixture('unparseable.eml', []),
        ];
        $dir = fake_maildir($msgs);
        $state = $dir . '/state.json';
        [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['imap', $mode, $cert['cert'], $cert['key'], $dir, $state, 'bounce-user', 'p@ss "word"']);
        try {
            $client = new ImapClient(['host' => '127.0.0.1', 'port' => $port, 'encryption' => $mode, 'username' => 'bounce-user', 'password' => 'p@ss "word"',
                'cafile' => $cert['cert'], 'timeout' => 5, 'folder' => 'INBOX', 'aside_folder' => 'Gather-Unprocessed']);
            $s = bounce_mailbox_poll($client);
            same('', $s['error'], $mode);
            same([4, 2, 1, 1], [$s['read'], $s['recorded'], $s['ignored'], $s['unparsed']], $mode);
            $st = json_decode((string) file_get_contents($state), true);
            same([], $st['folders']['INBOX'], "$mode: the inbox is emptied");
            same(1, count($st['folders']['Gather-Unprocessed']), "$mode: the unreadable message was moved aside");
            ok(str_contains($st['folders']['Gather-Unprocessed'][0]['name'], 'junk'));
            $login = array_values(array_filter($st['log'], static fn (array $l): bool => $l['cmd'] === 'LOGIN ***'))[0];
            ok($login['tls'] === true, "$mode: credentials only sent over TLS");
            ok(in_array('UID FETCH 1 (BODY.PEEK[]<0.' . (BOUNCE_MAX_MESSAGE_BYTES + 1) . '>)', array_column($st['log'], 'cmd'), true), 'partial fetch capped at the limit');
            same('hard_bounce', email_suppression('gone@recipient.example.test')['reason'] ?? null);
            same('complaint', email_suppression('complainer@recipient.example.test')['reason'] ?? null);
            same(1, bounce_mailbox_state()['unparsed']);
        } finally {
            stop_helper($proc);
        }
    }
});

test('A5 IMAP: wrong password and an untrusted certificate are reported, nothing is deleted', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    $dir = fake_maildir(['junk' => bounce_fixture('unparseable.eml', [])]);
    $state = $dir . '/state.json';
    [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['imap', 'ssl', $cert['cert'], $cert['key'], $dir, $state, 'u', 'right']);
    try {
        $s = bounce_mailbox_poll(new ImapClient(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'wrong', 'cafile' => $cert['cert'], 'timeout' => 5]));
        contains('login was refused', $s['error']);
        contains('login was refused', bounce_mailbox_state()['last_error']);
        $s = bounce_mailbox_poll(new ImapClient(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'right', 'timeout' => 5]));
        ok($s['error'] !== '', 'a self-signed certificate is refused without the CA file: ' . $s['error']);
        $st = json_decode((string) file_get_contents($state), true);
        same(1, count($st['folders']['INBOX']));
    } finally {
        stop_helper($proc);
    }
});

test('A5 IMAP: the unprocessed folder is kept under the maximum count', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    $dir = fake_maildir([]);
    mkdir($dir . '/aside');
    for ($i = 1; $i <= 6; $i++) {
        file_put_contents($dir . "/aside/old-$i.eml", bounce_fixture('unparseable.eml', []));
        touch($dir . "/aside/old-$i.eml", time() - ($i <= 2 ? 90 : 1) * 86400);
    }
    $state = $dir . '/state.json';
    setting_set('mail.bounce', ['per_domain_minute' => 0, 'mailbox' => ['max_messages' => 3, 'max_age_days' => 30]]);
    [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['imap', 'ssl', $cert['cert'], $cert['key'], $dir, $state, 'u', 'p']);
    try {
        $s = bounce_mailbox_poll(new ImapClient(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p', 'cafile' => $cert['cert'], 'timeout' => 5]));
        same('', $s['error']);
        same(3, $s['pruned'], 'two older than 30 days, then the oldest beyond 3');
        same(3, count(json_decode((string) file_get_contents($state), true)['folders']['Gather-Unprocessed']));
    } finally {
        stop_helper($proc);
    }
});

test('A5 POP3 with STLS: reports deleted on QUIT, unreadable messages kept locally', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    foreach (bounce_local_aside_files() as $f) {
        unlink($f);
    }
    $c = sent_campaign(['full@recipient.example.test']);
    $dir = fake_maildir([
        'soft' => bounce_fixture('gmail_soft_422_mailbox_full.eml', ['TAG' => verp_tag('c', $c['rows']['full@recipient.example.test'], 'full@recipient.example.test'), 'EMAIL' => 'full@recipient.example.test']),
        'dotted' => bounce_fixture('unparseable.eml', []) . "\n.\n..leading dots survive\n",
    ]);
    $state = $dir . '/state.json';
    [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['pop3', 'starttls', $cert['cert'], $cert['key'], $dir, $state, 'popper', 'secret']);
    try {
        $s = bounce_mailbox_poll(new Pop3Client(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'starttls', 'username' => 'popper', 'password' => 'secret', 'cafile' => $cert['cert'], 'timeout' => 5]));
        same('', $s['error']);
        same([2, 1, 1], [$s['read'], $s['recorded'], $s['unparsed']]);
        $st = json_decode((string) file_get_contents($state), true);
        same([true, true], array_column($st['folders']['INBOX'], 'deleted'), 'both deleted (applied at QUIT)');
        ok(array_values(array_filter($st['log'], static fn (array $l): bool => $l['cmd'] === 'PASS ***'))[0]['tls'], 'password only after STLS');
        $kept = bounce_local_aside_files();
        same(1, count($kept));
        contains("\n.\n..leading dots survive", str_replace("\r\n", "\n", (string) file_get_contents($kept[0])), 'dot-unstuffing is exact');
        same(1, (int) q_val("SELECT COUNT(*) FROM email_events WHERE type = 'soft'"));
        unlink($kept[0]);
    } finally {
        stop_helper($proc);
    }
});

test('A5 M2: an oversized message is set aside unread (IMAP) and never blocks the messages behind it', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    $c = sent_campaign(['after-big@recipient.example.test']);
    $big = "From: someone@evil.example.test\nSubject: huge\nContent-Type: text/plain\n\n" . str_repeat(str_repeat('A', 998) . "\n", (int) (BOUNCE_MAX_MESSAGE_BYTES / 999) + 50);
    $dir = fake_maildir([
        'big' => $big,
        'hard' => bounce_fixture('postfix_hard_511.eml', ['TAG' => verp_tag('c', $c['rows']['after-big@recipient.example.test'], 'after-big@recipient.example.test'), 'EMAIL' => 'after-big@recipient.example.test']),
    ]);
    $state = $dir . '/state.json';
    foreach ([[], ['FAKE_MAIL_LIE_SIZE' => '1']] as $env) {
        q('DELETE FROM email_events');
        q('DELETE FROM email_suppressions');
        setting_set('mail.bounce_state', null);
        is_file($state) && unlink($state);
        [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['imap', 'ssl', $cert['cert'], $cert['key'], $dir, $state, 'u', 'p'], $env);
        $label = $env === [] ? 'size reported' : 'server lies about the size';
        try {
            $s = bounce_mailbox_poll(new ImapClient(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p', 'cafile' => $cert['cert'], 'timeout' => 5]));
            same('', $s['error'], $label);
            same([2, 1, 1], [$s['read'], $s['oversized'], $s['recorded']], $label);
            $st = json_decode((string) file_get_contents($state), true);
            same([], $st['folders']['INBOX'], "$label: the inbox is emptied");
            same(['01-big.eml'], array_column($st['folders']['Gather-Unprocessed'], 'name'), "$label: the big one is set aside");
            $cmds = array_column($st['log'], 'cmd');
            if ($env === []) {
                same([], array_values(array_filter($cmds, static fn (string $cmd): bool => str_starts_with($cmd, 'UID FETCH 1 (BODY'))), 'never downloaded');
            } else {
                ok(in_array('UID FETCH 1 (BODY.PEEK[]<0.' . (BOUNCE_MAX_MESSAGE_BYTES + 1) . '>)', $cmds, true), 'only a capped partial fetch');
            }
            ok(email_suppressed('after-big@recipient.example.test'), "$label: the report behind it was processed");
            same(1, bounce_mailbox_state()['oversized']);
        } finally {
            stop_helper($proc);
        }
    }
});

test('A5 M2: POP3 deletes an oversized message without RETR; a lying server is cut off at the limit', function (): void {
    reset_bounces();
    $cert = test_tls_cert();
    foreach (bounce_local_aside_files() as $f) {
        unlink($f);
    }
    $c = sent_campaign(['pop-after@recipient.example.test']);
    $big = "From: someone@evil.example.test\nSubject: huge\n\n" . str_repeat("\n", BOUNCE_MAX_MESSAGE_BYTES) . "end\n"; // many empty lines: also over the line cap
    $tagged = bounce_fixture('gmail_soft_422_mailbox_full.eml', ['TAG' => verp_tag('c', $c['rows']['pop-after@recipient.example.test'], 'pop-after@recipient.example.test'), 'EMAIL' => 'pop-after@recipient.example.test']);
    foreach ([[], ['FAKE_MAIL_LIE_SIZE' => '1']] as $env) {
        q('DELETE FROM email_events');
        setting_set('mail.bounce_state', null);
        $dir = fake_maildir(['big' => $big, 'soft' => $tagged]);
        $state = $dir . '/state.json';
        [$proc, $port] = start_helper(__DIR__ . '/fixtures/fake_mail_server.php', ['pop3', 'ssl', $cert['cert'], $cert['key'], $dir, $state, 'popper', 'secret'], $env);
        $label = $env === [] ? 'size reported' : 'server lies about the size';
        try {
            $s = bounce_mailbox_poll(new Pop3Client(['host' => '127.0.0.1', 'port' => $port, 'encryption' => 'ssl', 'username' => 'popper', 'password' => 'secret', 'cafile' => $cert['cert'], 'timeout' => 5]));
            same('', $s['error'], $label);
            same([2, 1, 1], [$s['read'], $s['oversized'], $s['recorded']], $label);
            $st = json_decode((string) file_get_contents($state), true);
            same([true, true], array_column($st['folders']['INBOX'], 'deleted'), "$label: both deleted, the big one too");
            if ($env === []) {
                ok(!in_array('RETR 1', array_column($st['log'], 'cmd'), true), 'the big message was never downloaded');
            }
            same([], bounce_local_aside_files(), 'nothing big written to disk');
            same(1, (int) q_val("SELECT COUNT(*) FROM email_events WHERE type = 'soft'"), "$label: the next report was processed");
        } finally {
            stop_helper($proc);
        }
    }
});

test('A5 M2: MIME parsing stops after 100 parts across all levels', function (): void {
    $part = static function (int $depth) use (&$part): string {
        if ($depth === 0) {
            return "Content-Type: text/plain\n\nleaf\n";
        }
        $b = 'b' . $depth;
        $out = "Content-Type: multipart/mixed; boundary=\"$b\"\n\n";
        for ($i = 0; $i < 20; $i++) {
            $out .= "--$b\n" . $part($depth - 1);
        }
        return $out . "--$b--\n";
    };
    $raw = $part(3); // 20 + 400 + 8,000 parts if unbounded
    ok(strlen($raw) < BOUNCE_MAX_MESSAGE_BYTES * 2);
    $msg = \Core\Mail\MimeMessage::parse($raw);
    ok(count($msg->flatten()) <= \Core\Mail\MimeMessage::MAX_TOTAL_PARTS, 'parts kept: ' . count($msg->flatten()));
    same('unparsed', bounce_process_message($raw), 'not a report, and parsing finished');
});

// ---- Provider webhooks ----------------------------------------------------------------------

/** An RSA key and self-signed certificate standing in for Amazon's SNS signing certificate. */
function sns_signer(): array
{
    static $s = null;
    if ($s === null) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $x509 = openssl_csr_sign(openssl_csr_new(['commonName' => 'sns.amazonaws.com'], $key), null, $key, 1);
        openssl_x509_export($x509, $pem);
        $s = ['key' => $key, 'pem' => $pem, 'url' => 'https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-' . bin2hex(random_bytes(8)) . '.pem'];
    }
    return $s;
}

function sns_message(string $type, array $fields, int $version = 2): array
{
    $s = sns_signer();
    $m = $fields + ['Type' => $type, 'MessageId' => 'a1b2c3d4-' . bin2hex(random_bytes(4)), 'TopicArn' => 'arn:aws:sns:eu-west-2:123456789012:ses-bounces',
        'Timestamp' => gmdate('Y-m-d\TH:i:s.000\Z'), 'SignatureVersion' => (string) $version, 'SigningCertURL' => $s['url']];
    openssl_sign((string) webhook_sns_string_to_sign($m), $sig, $s['key'], $version === 1 ? OPENSSL_ALGO_SHA1 : OPENSSL_ALGO_SHA256);
    return $m + ['Signature' => base64_encode($sig)];
}

test('A5 SES via SNS: signature checked against the certificate from an allow-listed host only', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['webhooks' => ['ses' => true, 'ses_topics' => 'arn:aws:sns:eu-west-2:123456789012:ses-bounces']]);
    $signer = sns_signer();
    $fetched = [];
    $fetch = static function (string $url) use (&$fetched, $signer): ?string {
        $fetched[] = $url;
        return $url === $signer['url'] ? $signer['pem'] : 'confirmed';
    };
    $c = sent_campaign(['ses-gone@recipient.example.test']);
    $tag = verp_tag('c', $c['rows']['ses-gone@recipient.example.test'], 'ses-gone@recipient.example.test');
    $msg = sns_message('Notification', ['Message' => webhook_fixture('ses_bounce_message.json', ['EMAIL' => 'ses-gone@recipient.example.test', 'TAG' => $tag])]);
    $r = webhook_handle('ses', json_encode($msg), [], $fetch);
    same([200, 1], [$r['status'], $r['recorded']]);
    same('hard_bounce', email_suppression('ses-gone@recipient.example.test')['reason'] ?? null);
    same('ses', q_val('SELECT source FROM email_events'));
    same(0, webhook_handle('ses', json_encode($msg), [], $fetch)['recorded'], 'replayed notification: recorded once');
    // SignatureVersion 1 (SHA1) works too; a complaint.
    $msg1 = sns_message('Notification', ['Message' => webhook_fixture('ses_complaint_message.json', ['EMAIL' => 'ses-gone@recipient.example.test', 'TAG' => $tag]), 'Subject' => 'Amazon SES Email Event Notification'], 1);
    same(200, webhook_handle('ses', json_encode($msg1), [], $fetch)['status']);
    same(1, (int) q_val("SELECT COUNT(*) FROM email_events WHERE type = 'complaint'"));
    // Tampering and hostile certificate URLs.
    $bad = $msg;
    $bad['Message'] = str_replace('Permanent', 'Transient', $bad['Message']);
    same(403, webhook_handle('ses', json_encode($bad), [], $fetch)['status'], 'altered message');
    foreach (['https://sns.eu-west-2.amazonaws.com.evil.example/cert.pem', 'http://sns.eu-west-2.amazonaws.com/cert.pem', 'https://evil.example/sns.eu-west-2.amazonaws.com.pem',
        'https://sns.eu-west-2.amazonaws.com:8443/cert.pem', 'https://user@sns.eu-west-2.amazonaws.com/cert.pem', 'https://sns.eu-west-2.amazonaws.com/cert.txt'] as $url) {
        $evil = sns_message('Notification', ['Message' => '{}', 'SigningCertURL' => $url]);
        $before = count($fetched);
        same(403, webhook_handle('ses', json_encode($evil), [], $fetch)['status'], $url);
        same($before, count($fetched), 'never fetched: ' . $url);
    }
    $otherTopic = sns_message('Notification', ['Message' => '{}', 'TopicArn' => 'arn:aws:sns:eu-west-2:999999999999:other']);
    same(403, webhook_handle('ses', json_encode($otherTopic), [], $fetch)['status'], 'topic not on the allow-list');
    // Subscription confirmation: SubscribeURL fetched (allow-listed host only).
    $sub = sns_message('SubscriptionConfirmation', ['Message' => 'You have chosen to subscribe', 'Token' => 'tok', 'SubscribeURL' => 'https://sns.eu-west-2.amazonaws.com/?Action=ConfirmSubscription&Token=tok']);
    same('Subscription confirmed', webhook_handle('ses', json_encode($sub), [], $fetch)['message']);
    ok(in_array('https://sns.eu-west-2.amazonaws.com/?Action=ConfirmSubscription&Token=tok', $fetched, true));
    $subEvil = sns_message('SubscriptionConfirmation', ['Message' => 'x', 'Token' => 'tok', 'SubscribeURL' => 'https://evil.example/confirm']);
    same(400, webhook_handle('ses', json_encode($subEvil), [], $fetch)['status']);
    setting_set('mail.bounce', ['webhooks' => ['ses' => false]]);
    same(404, webhook_handle('ses', json_encode($msg), [], $fetch)['status'], 'off unless configured');
});

test('A5 Mailgun webhook: HMAC signature and timestamp checked', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['webhooks' => ['mailgun_key' => secret_seal('key-mailgun-signing-123')]]);
    $c = sent_campaign(['mg@recipient.example.test']);
    $ts = (string) time();
    $token = bin2hex(random_bytes(25));
    $vars = ['TS' => $ts, 'TOKEN' => $token, 'SIGNATURE' => hash_hmac('sha256', $ts . $token, 'key-mailgun-signing-123'), 'EMAIL' => 'mg@recipient.example.test', 'TAG' => verp_tag('c', $c['rows']['mg@recipient.example.test'], 'mg@recipient.example.test')];
    $r = webhook_handle('mailgun', webhook_fixture('mailgun_failed_permanent.json', $vars), []);
    same([200, 1], [$r['status'], $r['recorded']]);
    same('hard', q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$c['rows']['mg@recipient.example.test']]));
    same(401, webhook_handle('mailgun', webhook_fixture('mailgun_failed_permanent.json', ['SIGNATURE' => str_repeat('0', 64)] + $vars), [])['status'], 'bad signature');
    $old = (string) (time() - 3600);
    same(401, webhook_handle('mailgun', webhook_fixture('mailgun_failed_permanent.json', ['TS' => $old, 'SIGNATURE' => hash_hmac('sha256', $old . $token, 'key-mailgun-signing-123')] + $vars), [])['status'], 'stale timestamp');
});

test('A5 Postmark webhook: basic auth or token header required', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['webhooks' => ['postmark_user' => 'pm', 'postmark_password' => secret_seal('pm-pass'), 'postmark_token' => secret_seal('pm-token')]]);
    $c = sent_campaign(['pm@recipient.example.test', 'pm2@recipient.example.test']);
    $vars = ['EMAIL' => 'pm@recipient.example.test', 'TAG' => verp_tag('c', $c['rows']['pm@recipient.example.test'], 'pm@recipient.example.test')];
    same(401, webhook_handle('postmark', webhook_fixture('postmark_hard_bounce.json', $vars), [])['status']);
    same(401, webhook_handle('postmark', webhook_fixture('postmark_hard_bounce.json', $vars), ['PHP_AUTH_USER' => 'pm', 'PHP_AUTH_PW' => 'nope'])['status']);
    $r = webhook_handle('postmark', webhook_fixture('postmark_hard_bounce.json', $vars), ['HTTP_AUTHORIZATION' => 'Basic ' . base64_encode('pm:pm-pass')]);
    same([200, 1], [$r['status'], $r['recorded']]);
    ok(email_suppressed('pm@recipient.example.test'));
    $vars2 = ['EMAIL' => 'pm2@recipient.example.test', 'TAG' => verp_tag('c', $c['rows']['pm2@recipient.example.test'], 'pm2@recipient.example.test')];
    same(1, webhook_handle('postmark', webhook_fixture('postmark_spam_complaint.json', $vars2), ['HTTP_X_GATHER_WEBHOOK_TOKEN' => 'pm-token'])['recorded']);
    same('complaint', email_suppression('pm2@recipient.example.test')['reason'] ?? null);
});

test('A5 SendGrid signed event webhook: ECDSA signature over timestamp + body', function (): void {
    reset_bounces();
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $pub = openssl_pkey_get_details($key)['key'];
    $b64 = str_replace(["-----BEGIN PUBLIC KEY-----", "-----END PUBLIC KEY-----", "\n"], '', $pub);
    setting_set('mail.bounce', ['webhooks' => ['sendgrid_key' => $b64]]);
    $c = sent_campaign(['sg@recipient.example.test', 'sg2@recipient.example.test']);
    $ts = (string) time();
    $body = webhook_fixture('sendgrid_events.json', ['TS' => $ts, 'EMAIL' => 'sg@recipient.example.test', 'EMAIL2' => 'sg2@recipient.example.test', 'TAG' => verp_tag('c', $c['rows']['sg@recipient.example.test'], 'sg@recipient.example.test')]);
    openssl_sign($ts . $body, $sig, $key, OPENSSL_ALGO_SHA256);
    $server = ['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_SIGNATURE' => base64_encode($sig), 'HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => $ts];
    $r = webhook_handle('sendgrid', $body, $server);
    same([200, 2], [$r['status'], $r['recorded']], 'bounce + spam report; processed and deferred ignored');
    same('hard_bounce', email_suppression('sg@recipient.example.test')['reason'] ?? null);
    same('complaint', email_suppression('sg2@recipient.example.test')['reason'] ?? null);
    same(401, webhook_handle('sendgrid', $body . ' ', $server)['status'], 'body changed');
    same(401, webhook_handle('sendgrid', $body, ['HTTP_X_TWILIO_EMAIL_EVENT_WEBHOOK_TIMESTAMP' => (string) ((int) $ts + 1)] + $server)['status'], 'timestamp changed');
});

test('A5 Brevo webhook: bearer token required', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['webhooks' => ['brevo_token' => secret_seal('brevo-secret-token')]]);
    $c = sent_campaign(['bv@recipient.example.test']);
    $body = webhook_fixture('brevo_hard_bounce.json', ['EMAIL' => 'bv@recipient.example.test', 'TAG' => verp_tag('c', $c['rows']['bv@recipient.example.test'], 'bv@recipient.example.test')]);
    same(401, webhook_handle('brevo', $body, ['HTTP_AUTHORIZATION' => 'Bearer wrong'])['status']);
    same(1, webhook_handle('brevo', $body, ['HTTP_AUTHORIZATION' => 'Bearer brevo-secret-token'])['recorded']);
    same('hard', q_val('SELECT bounce FROM campaign_recipients WHERE id = ?', [$c['rows']['bv@recipient.example.test']]));
    same(404, webhook_handle('nosuch', '{}', [])['status']);
});

test('A5 M3: SNS certificate URLs with a query or odd path are refused unfetched; topic checked first; cache normalised and capped', function (): void {
    reset_bounces();
    q("DELETE FROM rate_hits WHERE bucket = 'sns-cert-fetch'");
    foreach (glob(webhook_sns_cache_dir() . '/sns-*.pem') ?: [] as $f) {
        unlink($f);
    }
    setting_set('mail.bounce', ['webhooks' => ['ses' => true, 'ses_topics' => 'arn:aws:sns:eu-west-2:123456789012:ses-bounces']]);
    $signer = sns_signer();
    $fetched = [];
    $fetch = static function (string $url) use (&$fetched, $signer): ?string {
        $fetched[] = $url;
        return $signer['pem'];
    };
    $path = (string) parse_url($signer['url'], PHP_URL_PATH);
    foreach ([$signer['url'] . '?x=1', $signer['url'] . '?', $signer['url'] . '#frag', 'https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-zz.pem',
        'https://sns.eu-west-2.amazonaws.com/other/SimpleNotificationService-0123456789abcdef.pem', 'https://sns.eu-west-2.amazonaws.com/cert.pem'] as $url) {
        same(403, webhook_handle('ses', json_encode(sns_message('Notification', ['Message' => '{}', 'SigningCertURL' => $url])), [], $fetch)['status'], $url);
    }
    same([], $fetched, 'nothing fetched for refused URLs');
    $other = sns_message('Notification', ['Message' => '{}', 'TopicArn' => 'arn:aws:sns:eu-west-2:999999999999:other', 'SigningCertURL' => 'https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-' . str_repeat('ab', 16) . '.pem']);
    same(403, webhook_handle('ses', json_encode($other), [], $fetch)['status']);
    same([], $fetched, 'a topic that is not allowed never causes a fetch');
    // The same certificate under an upper-case host is one cache entry: fetched once.
    $ok = sns_message('Notification', ['Message' => '{}']);
    same(200, webhook_handle('ses', json_encode($ok), [], $fetch)['status']);
    $upper = sns_message('Notification', ['Message' => '{}', 'SigningCertURL' => 'https://SNS.EU-WEST-2.AMAZONAWS.COM' . $path]);
    same(200, webhook_handle('ses', json_encode($upper), [], $fetch)['status']);
    same(['https://sns.eu-west-2.amazonaws.com' . $path], $fetched, 'fetched once, by its normalised URL');
    // Different (allowed-looking) certificate URLs: at most 10 fetches an hour, at most 20 cached files.
    for ($i = 0; $i < 15; $i++) {
        $m = sns_message('Notification', ['Message' => '{}', 'SigningCertURL' => 'https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-' . sprintf('%032x', $i) . '.pem']);
        webhook_handle('ses', json_encode($m), [], $fetch);
    }
    same(WEBHOOK_SNS_FETCH_PER_HOUR, count($fetched), 'fetches are rate limited');
    ok(count(glob(webhook_sns_cache_dir() . '/sns-*.pem') ?: []) <= WEBHOOK_SNS_CACHE_MAX);
    q("DELETE FROM rate_hits WHERE bucket = 'sns-cert-fetch'");
    for ($i = 100; $i < 130; $i++) {
        webhook_sns_cert('https://sns.eu-west-2.amazonaws.com/SimpleNotificationService-' . sprintf('%032x', $i) . '.pem', $fetch, time());
        q("DELETE FROM rate_hits WHERE bucket = 'sns-cert-fetch'");
    }
    same(WEBHOOK_SNS_CACHE_MAX, count(glob(webhook_sns_cache_dir() . '/sns-*.pem') ?: []), 'the cache never grows past its cap');
});

test('A5 M5: array-typed webhook fields answer 400 (never a 500) for every provider', function (): void {
    reset_bounces();
    q("DELETE FROM rate_hits WHERE bucket = 'sns-cert-fetch'");
    $c = sent_campaign(['typed@recipient.example.test']);
    // Mailgun (signed), fields as arrays.
    setting_set('mail.bounce', ['webhooks' => ['mailgun_key' => secret_seal('key-mg'), 'postmark_token' => secret_seal('pm-token'), 'brevo_token' => secret_seal('bv-token'),
        'ses' => true, 'ses_topics' => 'arn:aws:sns:eu-west-2:123456789012:ses-bounces']]);
    $sign = static function (array $eventData): string {
        $ts = (string) time();
        $tok = bin2hex(random_bytes(16));
        return json_encode(['signature' => ['timestamp' => $ts, 'token' => $tok, 'signature' => hash_hmac('sha256', $ts . $tok, 'key-mg')], 'event-data' => $eventData]);
    };
    foreach ([['event' => ['failed']], ['event' => 'failed', 'severity' => 'permanent', 'recipient' => ['a@b.test']], ['event' => 'complained', 'recipient' => 'typed@recipient.example.test', 'id' => ['x']],
        ['event' => 'failed', 'severity' => 'permanent', 'recipient' => 'typed@recipient.example.test', 'delivery-status' => ['code' => [550]]]] as $ed) {
        same(400, webhook_handle('mailgun', $sign($ed), [])['status'], json_encode($ed));
    }
    same(400, webhook_handle('postmark', json_encode(['RecordType' => ['Bounce'], 'Type' => 'HardBounce', 'Email' => 'typed@recipient.example.test']), ['HTTP_X_GATHER_WEBHOOK_TOKEN' => 'pm-token'])['status']);
    same(400, webhook_handle('postmark', json_encode(['RecordType' => 'Bounce', 'Type' => 'HardBounce', 'Email' => ['typed@recipient.example.test']]), ['HTTP_X_GATHER_WEBHOOK_TOKEN' => 'pm-token'])['status']);
    same(400, webhook_handle('brevo', json_encode(['event' => 'hard_bounce', 'email' => ['x' => 1]]), ['HTTP_AUTHORIZATION' => 'Bearer bv-token'])['status']);
    same(400, webhook_handle('brevo', json_encode(['event' => ['hard_bounce']]), ['HTTP_AUTHORIZATION' => 'Bearer bv-token'])['status']);
    same(400, webhook_handle('ses', json_encode(['Type' => 'Notification', 'TopicArn' => ['arn'], 'Message' => '{}']), [], static fn (): ?string => null)['status'], 'topic as an array');
    same(400, webhook_handle('ses', json_encode(['Type' => 'Notification', 'TopicArn' => 'arn:aws:sns:eu-west-2:123456789012:ses-bounces', 'SigningCertURL' => ['x']]), [], static fn (): ?string => null)['status']);
    ok(!email_suppressed('typed@recipient.example.test'), 'nothing recorded');
});

test('A5 LOW: a Mailgun token is accepted once (replay with a valid signature refused)', function (): void {
    reset_bounces();
    setting_set('mail.bounce', ['webhooks' => ['mailgun_key' => secret_seal('key-mailgun-signing-123')]]);
    $c = sent_campaign(['mg-replay@recipient.example.test']);
    $ts = (string) time();
    $token = bin2hex(random_bytes(25));
    $vars = ['TS' => $ts, 'TOKEN' => $token, 'SIGNATURE' => hash_hmac('sha256', $ts . $token, 'key-mailgun-signing-123'), 'EMAIL' => 'mg-replay@recipient.example.test',
        'TAG' => verp_tag('c', $c['rows']['mg-replay@recipient.example.test'], 'mg-replay@recipient.example.test')];
    same(200, webhook_handle('mailgun', webhook_fixture('mailgun_failed_permanent.json', $vars), [])['status']);
    same(401, webhook_handle('mailgun', webhook_fixture('mailgun_failed_permanent.json', $vars), [])['status'], 'same signed token again');
});

// ---- DNS hygiene ----------------------------------------------------------------------------

test('A5 MX checks: injected resolver, cache, reserved names, time budget; typo suggestions', function (): void {
    reset_bounces();
    $calls = [];
    dns_resolver(static function (string $d) use (&$calls): string {
        $calls[] = $d;
        return ['nomail.example.net' => 'none', 'flaky.example.net' => 'unknown'][$d] ?? 'ok';
    });
    try {
        same('none', email_domain_status('nomail.example.net'));
        same('ok', email_domain_status('club.example.net'));
        same('unknown', email_domain_status('flaky.example.net'));
        same(['nomail.example.net', 'club.example.net', 'flaky.example.net'], $calls);
        dns_forget();
        email_domain_status('nomail.example.net');
        email_domain_status('club.example.net');
        same(3, count($calls), 'answers come from the cache table');
        same(null, q_val("SELECT status FROM email_domains WHERE domain = 'flaky.example.net'"), 'lookup errors are not cached');
        q("UPDATE email_domains SET checked_at = UTC_TIMESTAMP() - INTERVAL 2 DAY WHERE domain = 'nomail.example.net'");
        dns_forget();
        email_domain_status('nomail.example.net');
        same(4, count($calls), 'a "no mail" answer is re-checked after a day');
        same('ok', email_domain_status('gmail.com'));
        same('ok', email_domain_status('members.example.test'));
        same('none', email_domain_status('anything.invalid'));
        same(4, count($calls), 'well-known and reserved names are never looked up');
        contains('could not find a mail server', (string) email_domain_problem('x@nomail.example.net'));
        same(null, email_domain_problem('x@flaky.example.net'), 'unknown never rejects');
        same(5, count($calls), 'flaky was not cached, so it was asked again');
        dns_forget();
        dns_budget_spent(DNS_REQUEST_BUDGET_SECONDS);
        same('unknown', email_domain_status('new-domain.example.net'), 'over the time budget: accepted without a lookup');
        same(5, count($calls));
    } finally {
        dns_resolver(static fn (string $d): string => 'unknown');
        dns_forget();
    }
    same('amara@gmail.com', email_typo_suggestion('amara@gmial.com'));
    same('amara@hotmail.com', email_typo_suggestion('amara@hotmail.con'));
    same('amara@yahoo.co.uk', email_typo_suggestion('amara@yahooo.co.uk'));
    same(null, email_typo_suggestion('amara@gmail.com'));
    same(null, email_typo_suggestion('amara@harbourside.org'));
});

test('A5 M4: sign-up DNS checks are MX-only, globally capped and the cache is pruned', function (): void {
    reset_bounces();
    q("DELETE FROM rate_hits WHERE bucket = 'dns-lookup'");
    $calls = [];
    dns_resolver(static function (string $d, bool $mxOnly = false) use (&$calls): string {
        $calls[] = [$d, $mxOnly];
        return 'ok';
    });
    try {
        email_domain_problem('a@quick-one.example.net', true);
        email_domain_problem('a@full-one.example.net');
        same([['quick-one.example.net', true], ['full-one.example.net', false]], $calls, 'sign-up asks for MX only');
        // The quick budget is smaller than the normal one.
        dns_forget();
        dns_budget_spent(DNS_QUICK_BUDGET_SECONDS);
        same('unknown', email_domain_status('quick-two.example.net', null, true), 'quick budget used up');
        same('ok', email_domain_status('normal-two.example.net'), 'the normal budget is larger');
        // Global cap on uncached lookups (all visitors together).
        dns_forget();
        q("DELETE FROM rate_hits WHERE bucket = 'dns-lookup'");
        for ($i = 0; $i < DNS_LOOKUPS_PER_10_MIN; $i++) {
            rate_hit('dns-lookup');
        }
        $before = count($calls);
        same('unknown', email_domain_status('capped.example.net'), 'over the cap: accepted without a lookup');
        same($before, count($calls));
        q("DELETE FROM rate_hits WHERE bucket = 'dns-lookup'");
    } finally {
        dns_resolver(static fn (string $d): string => 'unknown');
        dns_forget();
    }
    q('DELETE FROM email_domains');
    q("INSERT INTO email_domains (domain, status, checked_at) VALUES ('fresh.example.net', 'ok', UTC_TIMESTAMP()), ('stale.example.net', 'ok', UTC_TIMESTAMP() - INTERVAL 8 DAY),
        ('none-stale.example.net', 'none', UTC_TIMESTAMP() - INTERVAL 2 DAY), ('none-fresh.example.net', 'none', UTC_TIMESTAMP() - INTERVAL 1 HOUR)");
    same(2, email_domains_prune());
    same(['fresh.example.net', 'none-fresh.example.net'], array_column(q_all('SELECT domain FROM email_domains ORDER BY domain'), 'domain'));
    contains('cached domain checks', cron_cleanup());
});

test('A5 campaign queueing and CSV import skip domains without a mail server', function (): void {
    reset_bounces();
    dns_resolver(static fn (string $d): string => $d === 'deadmail.example.net' ? 'none' : 'ok');
    try {
        $a = fixture_full_member(['email' => 'live@livemail.example.net', 'region' => 'Dns Region'], ['newsletters' => true]);
        $b = fixture_full_member(['email' => 'dead@deadmail.example.net', 'region' => 'Dns Region'], ['newsletters' => true]);
        $id = db_insert('campaigns', ['subject' => 'DNS', 'blocks' => json_encode([['type' => 'heading', 'text' => 'Hi', 'size' => 'large']]), 'audience' => json_encode(['type' => 'region', 'value' => 'Dns Region', 'field_id' => 0])]);
        same(1, campaign_queue($id));
        same('suppressed', q_val('SELECT status FROM campaign_recipients WHERE email = ?', [$b['email']]));
        contains('no mail server', (string) q_val('SELECT error FROM campaign_recipients WHERE email = ?', [$b['email']]));
        $csv = fixture_file("email,first_name,last_name\nimport-ok@livemail.example.net,Ima,Port\nimport-dead@deadmail.example.net,Dee,Ad\n", 'csv');
        $report = members_import_csv($csv);
        same([1, 1], [$report['created'], $report['skipped']]);
        contains('no mail server', implode(' ', $report['errors']));
    } finally {
        dns_resolver(static fn (string $d): string => 'unknown');
        dns_forget();
    }
});

// ---- Reporting, admin rules -----------------------------------------------------------------

test('A5 health page: bounce and complaint rates with warnings above 2% / 0.1%', function (): void {
    reset_bounces();
    q('DELETE FROM campaign_recipients');
    q('DELETE FROM email_queue');
    $c = sent_campaign(array_map(static fn (int $i): string => "r$i@recipient.example.test", range(1, 50)));
    bounce_record(['email' => 'r1@recipient.example.test', 'type' => 'hard', 'source' => 'ses']);
    $find = static fn (string $label): array => array_values(array_filter(health_checks()['Bounces and complaints'], static fn (array $i): bool => $i['label'] === $label))[0];
    same('ok', $find('Bounce rate (7 days)')['status'], '1 of 50 = 2% is not above 2%');
    bounce_record(['email' => 'r2@recipient.example.test', 'type' => 'hard', 'source' => 'ses']);
    same('warn', $find('Bounce rate (7 days)')['status']);
    contains('4.00%', $find('Bounce rate (30 days)')['detail']);
    same('ok', $find('Complaint rate (7 days)')['status']);
    bounce_record(['email' => 'r3@recipient.example.test', 'type' => 'complaint', 'source' => 'ses']);
    same('warn', $find('Complaint rate (7 days)')['status'], '1 complaint in 50 is above 0.1%');
    same('warn', $find('Bounce mailbox')['status'], 'not set up');
    setting_set('mail.bounce_state', ['last_poll' => time(), 'last_error' => 'The mail server closed the connection.']);
    setting_set('mail.bounce', ['mailbox' => ['protocol' => 'imap', 'host' => 'imap.example.net']]);
    same('bad', $find('Bounce mailbox')['status']);
    same(0, $c['campaign'] - $c['campaign']);
});

test('A5 suppression list: admins clear bounces, never unsubscribes', function (): void {
    reset_bounces();
    sent_campaign(['fixed@recipient.example.test']);
    email_suppress('fixed@recipient.example.test', 'hard_bounce', 'User unknown', 'dsn');
    email_suppress('choice@recipient.example.test', 'unsubscribe');
    same('recorded', bounce_record(['email' => 'fixed@recipient.example.test', 'type' => 'soft', 'source' => 'ses']));
    act_as(fixture_admin('admin', ['communications']));
    request('POST', '/admin/email-suppressions/clear', ['_csrf' => csrf_token(), 'email' => 'choice@recipient.example.test']);
    throws(\Core\Redirect::class, fn () => \Admin\Suppressions::clear([]));
    ok(email_suppressed('choice@recipient.example.test'), 'an unsubscribe stays');
    request('POST', '/admin/email-suppressions/clear', ['_csrf' => csrf_token(), 'email' => 'fixed@recipient.example.test']);
    throws(\Core\Redirect::class, fn () => \Admin\Suppressions::clear([]));
    ok(!email_suppressed('fixed@recipient.example.test'));
    same(0, (int) q_val("SELECT COUNT(*) FROM email_events WHERE email = 'fixed@recipient.example.test' AND type = 'soft'"), 'old soft bounces no longer count');
    same(1, (int) q_val("SELECT COUNT(*) FROM audit_log WHERE action = 'clear' AND item_type = 'suppression' AND summary LIKE 'fixed@%'"));
    act_as(null);
});
