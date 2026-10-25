<?php
declare(strict_types=1);

/*
 * Fake IMAP / POP3 server for the bounce-mailbox tests (never deployed; the
 * tests/ folder is denied by .htaccess). Speaks just enough of RFC 3501 and
 * RFC 1939 for Core\Mail\ImapClient / Pop3Client, over real TLS:
 *   php fake_mail_server.php <imap|pop3> <ssl|starttls> <cert.pem> <key.pem> <maildir> <state.json> <user> <pass>
 * Prints the listening port on stdout, serves connections one after another
 * and writes its state (folders, deleted messages, command log) to
 * state.json after every command. Exits after 30 s without a connection.
 * Optional env FAKE_MAIL_LIE_SIZE=1 makes LIST / RFC822.SIZE report 100
 * bytes for every message (a server that lies about sizes).
 */

[, $proto, $mode, $cert, $key, $maildir, $stateFile, $user, $pass] = $argv;

$ctx = stream_context_create(['ssl' => ['local_cert' => $cert, 'local_pk' => $key, 'verify_peer' => false, 'allow_self_signed' => true]]);
$server = stream_socket_server(($mode === 'ssl' ? 'tls' : 'tcp') . '://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
if ($server === false) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr((string) $name, strrpos((string) $name, ':') + 1), "\n";
fflush(STDOUT);

// Mailbox: folder => uid => [raw, date (unix), deleted]
$folders = ['INBOX' => []];
$uidNext = 1;
$files = glob(rtrim($maildir, '/') . '/*.eml') ?: [];
sort($files);
foreach ($files as $f) {
    $folders['INBOX'][$uidNext++] = ['raw' => (string) file_get_contents($f), 'date' => (int) filemtime($f), 'deleted' => false, 'name' => basename($f)];
}
// Optional pre-existing "unprocessed" folder: <maildir>/aside/*.eml
foreach (glob(rtrim($maildir, '/') . '/aside/*.eml') ?: [] as $f) {
    $folders['Gather-Unprocessed'][$uidNext++] = ['raw' => (string) file_get_contents($f), 'date' => (int) filemtime($f), 'deleted' => false, 'name' => basename($f)];
}
$log = [];

$save = static function () use (&$folders, &$log, $stateFile): void {
    $out = ['folders' => [], 'log' => $log];
    foreach ($folders as $fname => $msgs) {
        foreach ($msgs as $uid => $m) {
            $out['folders'][$fname][] = ['uid' => $uid, 'name' => $m['name'], 'deleted' => $m['deleted']];
        }
        $out['folders'][$fname] ??= [];
    }
    file_put_contents($stateFile, json_encode($out, JSON_PRETTY_PRINT));
};
$save();

while (true) {
    $c = stream_socket_accept($server, 30);
    if ($c === false) {
        break;
    }
    stream_set_timeout($c, 10);
    $w = static function (string $s) use ($c): void {
        fwrite($c, $s . "\r\n");
    };
    $tls = $mode === 'ssl';
    $authed = false;
    if ($proto === 'imap') {
        $w('* OK [CAPABILITY IMAP4rev1' . ($mode === 'starttls' ? ' STARTTLS' : '') . '] fake IMAP ready');
        $folder = null;
        while (($line = fgets($c)) !== false) {
            $line = rtrim($line, "\r\n");
            // Synchronizing literals in commands: "{n}" -> "+", then n bytes.
            while (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $w('+ go ahead');
                $lit = (string) fread($c, (int) $m[1]);
                $line = substr($line, 0, -strlen($m[0])) . '"' . addcslashes($lit, '"\\') . '"' . rtrim((string) fgets($c), "\r\n");
            }
            if (!preg_match('/^(\S+) (.+)$/', $line, $m)) {
                continue;
            }
            [$tag, $cmd] = [$m[1], $m[2]];
            $log[] = ['tls' => $tls, 'cmd' => preg_replace('/^LOGIN .*/i', 'LOGIN ***', $cmd)];
            $upper = strtoupper($cmd);
            $box = $folder !== null ? ($folders[$folder] ?? []) : [];
            if ($upper === 'CAPABILITY') {
                $w('* CAPABILITY IMAP4rev1' . ($mode === 'starttls' && !$tls ? ' STARTTLS' : ''));
                $w("$tag OK done");
            } elseif ($upper === 'STARTTLS') {
                $w("$tag OK begin TLS");
                stream_context_set_option($c, 'ssl', 'local_cert', $cert);
                stream_context_set_option($c, 'ssl', 'local_pk', $key);
                $tls = (bool) stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            } elseif (str_starts_with($upper, 'LOGIN ')) {
                preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $cmd, $q);
                $u = stripcslashes($q[1][0] ?? '');
                $p = stripcslashes($q[1][1] ?? '');
                if (!$tls) {
                    $w("$tag NO [PRIVACYREQUIRED] TLS first");
                } elseif ($u === $user && $p === $pass) {
                    $authed = true;
                    $w("$tag OK logged in");
                } else {
                    $w("$tag NO [AUTHENTICATIONFAILED] bad credentials");
                }
            } elseif (!$authed && !str_starts_with($upper, 'LOGOUT')) {
                $w("$tag BAD log in first");
            } elseif (preg_match('/^SELECT "(.*)"$/i', $cmd, $s)) {
                if (!isset($folders[$s[1]])) {
                    $w("$tag NO no such folder");
                } else {
                    $folder = $s[1];
                    $w('* ' . count($folders[$folder]) . ' EXISTS');
                    $w("$tag OK [READ-WRITE] selected");
                }
            } elseif (preg_match('/^UID SEARCH (ALL|BEFORE (\S+))$/i', $cmd, $s)) {
                $limit = isset($s[2]) ? strtotime($s[2] . ' 00:00:00 UTC') : null;
                $uids = [];
                foreach ($box as $uid => $msg) {
                    if (!$msg['deleted'] && ($limit === null || $msg['date'] < $limit)) {
                        $uids[] = $uid;
                    }
                }
                $w('* SEARCH' . ($uids ? ' ' . implode(' ', $uids) : ''));
                $w("$tag OK search done");
            } elseif (preg_match('/^UID FETCH ([\d,]+) \(RFC822\.SIZE\)$/i', $cmd, $s)) {
                foreach (explode(',', $s[1]) as $u) {
                    $uid = (int) $u;
                    if (isset($box[$uid])) {
                        $seq = array_search($uid, array_keys($box), true) + 1;
                        $w("* $seq FETCH (UID $uid RFC822.SIZE " . (getenv('FAKE_MAIL_LIE_SIZE') ? 100 : strlen($box[$uid]['raw'])) . ')');
                    }
                }
                $w("$tag OK fetch done");
            } elseif (preg_match('/^UID FETCH (\d+) \(BODY\.PEEK\[\](?:<0\.(\d+)>)?\)$/i', $cmd, $s)) {
                $uid = (int) $s[1];
                if (isset($box[$uid])) {
                    // Partial fetch (RFC 3501 6.4.5): at most N octets from the start.
                    $raw = isset($s[2]) ? substr($box[$uid]['raw'], 0, (int) $s[2]) : $box[$uid]['raw'];
                    $seq = array_search($uid, array_keys($box), true) + 1;
                    fwrite($c, "* $seq FETCH (UID $uid BODY[]" . (isset($s[2]) ? '<0>' : '') . " {" . strlen($raw) . "}\r\n" . $raw . ")\r\n");
                }
                $w("$tag OK fetch done");
            } elseif (preg_match('/^UID STORE (\d+) \+FLAGS\.SILENT \(\\\\Deleted\)$/i', $cmd, $s)) {
                if (isset($folders[$folder][(int) $s[1]])) {
                    $folders[$folder][(int) $s[1]]['deleted'] = true;
                }
                $w("$tag OK stored");
            } elseif (preg_match('/^CREATE "(.*)"$/i', $cmd, $s)) {
                if (isset($folders[$s[1]])) {
                    $w("$tag NO [ALREADYEXISTS] exists");
                } else {
                    $folders[$s[1]] = [];
                    $w("$tag OK created");
                }
            } elseif (preg_match('/^UID COPY (\d+) "(.*)"$/i', $cmd, $s)) {
                if (!isset($folders[$s[2]])) {
                    $w("$tag NO [TRYCREATE] no such folder");
                } else {
                    $orig = $folders[$folder][(int) $s[1]];
                    $folders[$s[2]][$uidNext++] = ['raw' => $orig['raw'], 'date' => time(), 'deleted' => false, 'name' => $orig['name']];
                    $w("$tag OK copied");
                }
            } elseif ($upper === 'EXPUNGE') {
                foreach ($folders[$folder] ?? [] as $uid => $msg) {
                    if ($msg['deleted']) {
                        unset($folders[$folder][$uid]);
                        $w('* 1 EXPUNGE');
                    }
                }
                $w("$tag OK expunged");
            } elseif ($upper === 'LOGOUT') {
                $w('* BYE see you');
                $w("$tag OK logout");
                $save();
                break;
            } else {
                $w("$tag BAD unknown command");
            }
            $save();
        }
    } else {
        $w('+OK fake POP3 ready');
        $pending = [];
        $list = static fn (): array => array_keys(array_filter($folders['INBOX'], static fn (array $m): bool => !$m['deleted']));
        $uids = $list();
        $gotUser = '';
        while (($line = fgets($c)) !== false) {
            $line = rtrim($line, "\r\n");
            $log[] = ['tls' => $tls, 'cmd' => preg_replace('/^PASS .*/i', 'PASS ***', $line)];
            [$verb, $arg] = array_pad(explode(' ', $line, 2), 2, '');
            $verb = strtoupper($verb);
            if ($verb === 'STLS') {
                $w('+OK begin TLS');
                $tls = (bool) stream_socket_enable_crypto($c, true, STREAM_CRYPTO_METHOD_TLS_SERVER);
            } elseif ($verb === 'USER') {
                $gotUser = $arg;
                $w($tls ? '+OK send PASS' : '-ERR TLS first');
            } elseif ($verb === 'PASS') {
                $authed = $tls && $gotUser === $user && $arg === $pass;
                $w($authed ? '+OK logged in' : '-ERR bad credentials');
            } elseif (!$authed && $verb !== 'QUIT') {
                $w('-ERR log in first');
            } elseif ($verb === 'LIST') {
                $w('+OK ' . count($uids) . ' messages');
                foreach ($uids as $i => $uid) {
                    $w(($i + 1) . ' ' . (getenv('FAKE_MAIL_LIE_SIZE') ? 100 : strlen($folders['INBOX'][$uid]['raw'])));
                }
                $w('.');
            } elseif ($verb === 'RETR' && isset($uids[(int) $arg - 1])) {
                $raw = str_replace("\r\n", "\n", $folders['INBOX'][$uids[(int) $arg - 1]]['raw']);
                $w('+OK message follows');
                foreach (explode("\n", rtrim($raw, "\n")) as $l) {
                    $w(str_starts_with($l, '.') ? '.' . $l : $l);
                }
                $w('.');
            } elseif ($verb === 'DELE' && isset($uids[(int) $arg - 1])) {
                $pending[] = $uids[(int) $arg - 1];
                $w('+OK marked');
            } elseif ($verb === 'QUIT') {
                foreach ($pending as $uid) {
                    $folders['INBOX'][$uid]['deleted'] = true; // applied only on QUIT (RFC 1939 UPDATE state)
                }
                $w('+OK bye');
                $save();
                break;
            } else {
                $w('-ERR unknown');
            }
            $save();
        }
    }
    fclose($c);
}
