<?php
declare(strict_types=1);

namespace Core\Mail;

/**
 * Reads messages that arrive at the bounce mailbox:
 * - RFC 3464 delivery status notifications (multipart/report;
 *   report-type=delivery-status) from Postfix, Exim, Gmail, Outlook and others;
 * - RFC 5965 abuse reports (multipart/report; report-type=feedback-report);
 * - older plain-text bounces (e.g. Exim without DSN) by careful heuristics;
 * - auto-replies, which are recognised and ignored.
 *
 * The result never decides suppression by itself: it lists the recipients
 * with their Action/Status/Diagnostic and every place a VERP tag or our
 * X-Gather-Message-Id header may appear; src/lib/bounces.php classifies and
 * records them.
 */
final class BounceParser
{
    /**
     * @return array{
     *   kind: string,
     *   recipients: list<array{email:string, action:string, status:string, diagnostic:string}>,
     *   feedback_type: string,
     *   envelope: list<string>,
     *   gather_ids: list<string>,
     *   original_to: list<string>,
     *   original_message_id: string
     * } kind = dsn | arf | text-bounce | auto-reply | unknown
     */
    public static function parse(string $raw): array
    {
        $msg = MimeMessage::parse($raw);
        $out = [
            'kind' => 'unknown', 'recipients' => [], 'feedback_type' => '', 'envelope' => [],
            'gather_ids' => [], 'original_to' => [], 'original_message_id' => '',
        ];
        // Where the bounce was delivered: the VERP address shows up here.
        foreach (['to', 'delivered-to', 'x-original-to', 'envelope-to', 'x-envelope-to', 'x-rcpt-to', 'original-recipient'] as $h) {
            foreach ($msg->headerAll($h) as $v) {
                array_push($out['envelope'], ...MimeMessage::addresses($v));
            }
        }
        $out['envelope'] = array_values(array_unique($out['envelope']));

        $type = $msg->contentType();
        $report = $type === 'multipart/report' ? strtolower((string) $msg->param('content-type', 'report-type')) : '';
        $original = self::originalHeaders($msg);
        if ($original !== null) {
            $out['gather_ids'] = self::gatherIds(implode("\n", $original['x-gather-message-id'] ?? []) . "\n" . implode("\n", $original['message-id'] ?? []));
            $out['original_to'] = MimeMessage::addresses(implode(', ', $original['to'] ?? []));
            $out['original_message_id'] = trim((string) ($original['message-id'][0] ?? ''));
        }

        $status = $msg->find('message/delivery-status') ?? $msg->find('message/global-delivery-status');
        if ($status !== null && ($report === 'delivery-status' || $report === 'global-delivery-status' || $report === '')) {
            $out['kind'] = 'dsn';
            $out['recipients'] = self::dsnRecipients($status->decodedBody());
            if ($out['gather_ids'] === []) {
                $out['gather_ids'] = self::gatherIds(self::allText($msg));
            }
            return $out;
        }
        $feedback = $msg->find('message/feedback-report');
        if ($feedback !== null) {
            $out['kind'] = 'arf';
            $fields = MimeMessage::parseHeaders(trim($feedback->decodedBody()));
            $out['feedback_type'] = strtolower(trim((string) ($fields['feedback-type'][0] ?? 'abuse')));
            $rcpts = [];
            foreach ($fields['original-rcpt-to'] ?? [] as $v) {
                array_push($rcpts, ...MimeMessage::addresses($v));
            }
            if ($rcpts === []) {
                $rcpts = $out['original_to'];
            }
            foreach (array_unique($rcpts) as $email) {
                $out['recipients'][] = ['email' => $email, 'action' => 'complaint', 'status' => '', 'diagnostic' => $out['feedback_type']];
            }
            if ($out['gather_ids'] === []) {
                $out['gather_ids'] = self::gatherIds(self::allText($msg));
            }
            return $out;
        }
        $text = self::allText($msg);
        // Bounce first: daemons mark their bounces "Auto-Submitted: auto-replied" too.
        if (!self::looksLikeBounce($msg, $text) && self::isAutoReply($msg)) {
            $out['kind'] = 'auto-reply';
            return $out;
        }
        if (self::looksLikeBounce($msg, $text)) {
            $out['kind'] = 'text-bounce';
            $out['recipients'] = self::textRecipients($text);
            $out['gather_ids'] = self::gatherIds($text);
            if ($out['original_to'] === [] && preg_match('/^To:\s*(.+)$/mi', self::afterCopyMarker($text), $m)) {
                $out['original_to'] = MimeMessage::addresses($m[1]);
            }
        }
        return $out;
    }

    /**
     * Per-recipient blocks of a message/delivery-status body (RFC 3464 §2.3).
     * @return list<array{email:string, action:string, status:string, diagnostic:string}>
     */
    public static function dsnRecipients(string $body): array
    {
        $body = trim(str_replace("\r\n", "\n", $body));
        $groups = preg_split("/\n[ \t]*\n/", $body) ?: [];
        array_shift($groups); // per-message fields
        $out = [];
        foreach ($groups as $g) {
            $f = MimeMessage::parseHeaders($g);
            $final = self::addressField($f['final-recipient'][0] ?? '');
            $orig = self::addressField($f['original-recipient'][0] ?? '');
            $email = $final !== '' ? $final : $orig;
            if ($email === '') {
                continue;
            }
            $diag = (string) ($f['diagnostic-code'][0] ?? '');
            $diag = trim((string) preg_replace('/^[a-z0-9-]+;\s*/i', '', $diag));
            $out[] = [
                'email' => $email,
                'action' => strtolower(trim((string) ($f['action'][0] ?? ''))),
                'status' => self::statusCode((string) ($f['status'][0] ?? '')),
                'diagnostic' => mb_substr($diag, 0, 500),
            ];
        }
        return $out;
    }

    /** "rfc822; user@example.org" -> "user@example.org". */
    private static function addressField(string $v): string
    {
        $v = trim((string) preg_replace('/^[a-z0-9-]+;\s*/i', '', $v), " <>\t");
        return MimeMessage::addresses($v)[0] ?? '';
    }

    private static function statusCode(string $v): string
    {
        return preg_match('/\b([245]\.\d{1,3}\.\d{1,3})\b/', $v, $m) ? $m[1] : '';
    }

    /** Headers of the returned original message (message/rfc822 or text/rfc822-headers part). */
    private static function originalHeaders(MimeMessage $msg): ?array
    {
        foreach ($msg->flatten() as $p) {
            $t = $p->contentType();
            if (($t === 'message/rfc822' || $t === 'message/global') && $p->parts !== []) {
                return $p->parts[0]->headers;
            }
            if ($t === 'text/rfc822-headers' || $t === 'message/rfc822-headers' || $t === 'text/rfc822-header') {
                return MimeMessage::parseHeaders(trim($p->decodedBody()));
            }
        }
        return null;
    }

    /** Signed per-message tags (see verp_tag()) anywhere in the text. @return list<string> */
    public static function gatherIds(string $text): array
    {
        preg_match_all('/\b([qc]\d{1,12}-[a-f0-9]{12})\b/i', $text, $m);
        return array_values(array_unique(array_map('strtolower', $m[1])));
    }

    /** All text/* and message/* bodies, decoded, concatenated. */
    private static function allText(MimeMessage $msg): string
    {
        $out = '';
        foreach ($msg->flatten() as $p) {
            if ($p->parts !== []) {
                // A message/rfc822 part: include its headers as text for the heuristics.
                foreach ($p->parts[0]->headers as $name => $values) {
                    foreach ($values as $v) {
                        $out .= $name . ': ' . $v . "\n";
                    }
                }
                continue;
            }
            $t = $p->contentType();
            if (str_starts_with($t, 'text/') || str_starts_with($t, 'message/')) {
                $body = $p->decodedBody();
                if ($t === 'text/html') {
                    $body = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
                $out .= $body . "\n";
            }
        }
        return mb_substr($out, 0, 200000);
    }

    private static function isAutoReply(MimeMessage $msg): bool
    {
        $auto = strtolower((string) $msg->header('auto-submitted'));
        if ($auto !== '' && $auto !== 'no' && !str_starts_with($auto, 'auto-generated')) {
            return true; // auto-replied
        }
        if ($msg->header('x-autoreply') !== null || $msg->header('x-autorespond') !== null || strtolower((string) $msg->header('precedence')) === 'auto_reply') {
            return true;
        }
        $subject = strtolower((string) $msg->header('subject'));
        return (bool) preg_match('/^(auto(matic)?[ -]?reply|out of (the )?office|abwesenheit|r[ée]ponse automatique|autosvar)/', $subject);
    }

    private static function looksLikeBounce(MimeMessage $msg, string $text): bool
    {
        $from = strtolower((string) $msg->header('from'));
        $subject = strtolower((string) $msg->header('subject'));
        $daemon = (bool) preg_match('/mailer-daemon|postmaster|mail delivery (subsystem|system)/', $from);
        $subjectHit = (bool) preg_match('/undeliver|delivery (status notification|failure|has failed)|returned mail|mail delivery failed|failure notice|could not be delivered/', $subject);
        $textHit = (bool) preg_match('/could not be delivered|permanent error|following address(es)? failed|delivery to the following recipient|undeliverable/i', $text);
        return ($daemon || $subjectHit) && $textHit;
    }

    /** The part of a text bounce after "copy of the message" markers. */
    private static function afterCopyMarker(string $text): string
    {
        $pos = preg_match('/-{3,}\s*(This is a copy of the message|Original message|Below this line is a copy)/i', $text, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : null;
        return $pos === null ? '' : substr($text, $pos);
    }

    /**
     * Failed addresses in a plain-text bounce. Takes addresses listed after
     * "address(es) failed" (Exim) or near an SMTP error, before any copy of
     * the original message.
     * @return list<array{email:string, action:string, status:string, diagnostic:string}>
     */
    private static function textRecipients(string $text): array
    {
        $marker = self::afterCopyMarker($text);
        $head = $marker !== '' ? substr($text, 0, (int) strpos($text, $marker)) : $text;
        $out = [];
        $permanent = (bool) preg_match('/permanent (error|failure)|hard bounce/i', $head);
        if (preg_match('/address(?:\(es\)|es)? failed:\s*\n(.*)$/is', $head, $m)) {
            $blocks = preg_split('/\n(?=\s{0,4}[^\s@]+@[^\s]+\s*\n)/', "\n" . $m[1]) ?: [];
            foreach ($blocks as $b) {
                if (!preg_match('/^\s*<?([^\s<>@]+@[^\s<>]+?)>?\s*$/m', $b, $am)) {
                    continue;
                }
                $out[] = self::textRecipient(strtolower($am[1]), $b, $permanent);
            }
        }
        if ($out === []) {
            foreach (array_slice(MimeMessage::addresses($head), 0, 5) as $email) {
                if (preg_match('/^(mailer-daemon|postmaster)@/', $email)) {
                    continue;
                }
                $out[] = self::textRecipient($email, $head, $permanent);
            }
        }
        return $out;
    }

    private static function textRecipient(string $email, string $context, bool $permanent): array
    {
        $status = self::statusCode($context);
        if ($status === '' && preg_match('/\b([45])\d\d\b/', $context, $c)) {
            $status = $c[1] . '.0.0';
        }
        if ($status === '') {
            $status = $permanent ? '5.0.0' : '';
        }
        $diag = preg_match('/\b([45]\d\d[ -].{0,300})/', $context, $d) ? trim(preg_replace('/\s+/', ' ', $d[1]) ?? '') : '';
        return ['email' => $email, 'action' => 'failed', 'status' => $status, 'diagnostic' => mb_substr($diag, 0, 500)];
    }
}
