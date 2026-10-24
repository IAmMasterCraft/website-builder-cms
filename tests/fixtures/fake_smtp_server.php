<?php
declare(strict_types=1);

/*
 * Fake SMTP relay for the bounce tests (never deployed). Answers RCPT TO and
 * the end of DATA with the replies given on the command line, logs the
 * conversation (MAIL FROM, RCPT TO, message data) to a JSON file:
 *   php fake_smtp_server.php <log.json> "<rcpt reply>" "<data reply>"
 * e.g. "550 5.1.1 <x@y>: Recipient address rejected: User unknown".
 * A reply may name a recipient: "rcpt:addr=550 ...;other=250 OK". Prints the
 * port; serves connections until 20 s without one.
 */

[, $logFile, $rcptReply, $dataReply] = $argv;
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($server === false) {
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ':') + 1), "\n";
fflush(STDOUT);

$replyFor = static function (string $spec, string $rcpt): string {
    if (!str_contains($spec, '=')) {
        return $spec;
    }
    foreach (explode(';', $spec) as $pair) {
        [$addr, $reply] = explode('=', $pair, 2);
        if (strcasecmp(trim($addr), $rcpt) === 0 || trim($addr) === '*') {
            return $reply;
        }
    }
    return '250 2.1.5 OK';
};
$log = ['sessions' => []];
while (($c = stream_socket_accept($server, 20)) !== false) {
    $session = ['mail_from' => [], 'rcpt' => [], 'data' => []];
    $w = static function (string $s) use ($c): void {
        fwrite($c, $s . "\r\n");
    };
    $w('220 fake.relay.test ESMTP');
    $inData = false;
    $buf = '';
    $currentRcpt = '';
    while (($line = fgets($c)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                $session['data'][] = $buf;
                $buf = '';
                $w($replyFor($dataReply, $currentRcpt));
            } else {
                $buf .= $line;
            }
            continue;
        }
        $cmd = trim($line);
        $verb = strtoupper(substr($cmd, 0, 4));
        if ($verb === 'EHLO' || $verb === 'HELO') {
            fwrite($c, "250-fake.relay.test\r\n250-8BITMIME\r\n250 SIZE 10240000\r\n");
        } elseif ($verb === 'MAIL') {
            $session['mail_from'][] = preg_match('/<([^>]*)>/', $cmd, $m) ? $m[1] : '';
            $w('250 2.1.0 OK');
        } elseif ($verb === 'RCPT') {
            $currentRcpt = preg_match('/<([^>]*)>/', $cmd, $m) ? $m[1] : '';
            $session['rcpt'][] = $currentRcpt;
            $w($replyFor($rcptReply, $currentRcpt));
        } elseif ($verb === 'DATA') {
            $inData = true;
            $w('354 go ahead');
        } elseif ($verb === 'RSET') {
            $w('250 2.0.0 OK');
        } elseif ($verb === 'QUIT') {
            $w('221 2.0.0 bye');
            break;
        } else {
            $w('250 OK');
        }
        $log['sessions'][count($log['sessions'])] = $session;
        file_put_contents($logFile, json_encode($log));
        array_pop($log['sessions']);
    }
    $log['sessions'][] = $session;
    file_put_contents($logFile, json_encode($log));
    fclose($c);
}
