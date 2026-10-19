<?php
/**
 * tests_imap_stub.php — a wire-level IMAP server for offline tests.
 *
 * Run by tests_imap.php; not part of the application.
 *   php tests_imap_stub.php <port> <logfile> <scenariofile>
 *
 * This is NOT a mock. c-client opens a real TCP connection and speaks real
 * IMAP to it, so the tests exercise the actual extension and can assert the
 * exact number of round trips a code path costs.
 *
 * Two rules learned the hard way:
 *  - Answer ONLY what was asked. An over-answering stub returns BODYSTRUCTURE
 *    on every FETCH, c-client caches it, and any N+1 under test disappears.
 *  - Re-read the scenario file on every command, so one running stub can serve
 *    several scenarios in sequence without a restart.
 *
 * Safety: binds to 127.0.0.1 only, and never touches the network or the app DB.
 */

$port     = (int)($argv[1] ?? 14300);
$logFile  = $argv[2] ?? '/tmp/emmmail_stub.log';
$scenFile = $argv[3] ?? '/tmp/emmmail_stub_scenario.json';

@unlink($logFile);

function scenario(string $f): array
{
    $raw = @file_get_contents($f);
    $s = $raw ? json_decode($raw, true) : [];
    if (!is_array($s)) { $s = []; }
    $s += [
        'uidbase'         => 1000,
        'messages'        => 0,
        'uidvalidity'     => 99,
        'uidnext'         => null,   // derived when absent
        'uids'            => null,   // derived when absent (contiguous)
        'status_omit'     => [],     // e.g. ["uidnext","uidvalidity"]
        'status_fail'     => false,
        'select_fail'     => false,
        'create_fail'     => false,  // server answers NO to CREATE
        'delete_fail'     => false,  // server answers NO to DELETE
        'reply_to'        => '',     // ENVELOPE reply-to; defaults to the sender
        'in_reply_to'     => '',     // ENVELOPE in-reply-to message-id
        'in_reply_to_map' => [],     // uid => in-reply-to override
        'references'      => '',     // References header for every message
        'references_map'  => [],     // uid => References override
        'message_id_omit' => [],     // uids whose ENVELOPE Message-ID is NIL
        'folders'         => [],     // LIST response; a default set when empty
        'unseen'          => [],
        'deleted'         => [],
        'flagged'         => [],
        'answered'        => [],
        'attachment_uids' => [],
    ];
    if ($s['uids'] === null) {
        $s['uids'] = [];
        for ($n = 1; $n <= (int)$s['messages']; $n++) { $s['uids'][] = (int)$s['uidbase'] + $n; }
    } else {
        $s['uids'] = array_values(array_map('intval', $s['uids']));
        $s['messages'] = count($s['uids']);
    }
    if ($s['uidnext'] === null) {
        $s['uidnext'] = ($s['uids'] ? max($s['uids']) : (int)$s['uidbase']) + 1;
    }
    return $s;
}

// msgno (1-based) -> uid, and back.
function uidOf(array $s, int $msgno): int { return $s['uids'][$msgno - 1] ?? 0; }
function msgnoOf(array $s, int $uid): int
{
    $i = array_search($uid, $s['uids'], true);
    return $i === false ? 0 : $i + 1;
}

// Expand an IMAP sequence set ("1,3:5", "8:*") to concrete values. $max is what
// "*" resolves to.
function expandSet(string $set, int $max): array
{
    $out = [];
    foreach (explode(',', $set) as $part) {
        if (strpos($part, ':') !== false) {
            [$a, $b] = explode(':', $part, 2);
            $a = ($a === '*') ? $max : (int)$a;
            $b = ($b === '*') ? $max : (int)$b;
            if ($a > $b) { [$a, $b] = [$b, $a]; }
            for ($i = $a; $i <= $b; $i++) { $out[] = $i; }
        } elseif ($part !== '') {
            $out[] = ($part === '*') ? $max : (int)$part;
        }
    }
    return $out;
}

function fetchResponse(array $s, int $msgno, int $uid, string $items, bool $forceUid): string
{
    $up = strtoupper($items);
    $parts = [];
    // Per-message overrides win over the scenario-wide scalars.
    $key = (string)$uid;
    $irt = array_key_exists($key, $s['in_reply_to_map'])
        ? (string)$s['in_reply_to_map'][$key]
        : (string)$s['in_reply_to'];
    $refs = array_key_exists($key, $s['references_map'])
        ? (string)$s['references_map'][$key]
        : (string)$s['references'];
    if ($forceUid || strpos($up, 'UID') !== false) { $parts[] = "UID $uid"; }
    if (strpos($up, 'ENVELOPE') !== false) {
        // Field order per RFC 3501: date subject from sender reply-to to cc bcc
        // in-reply-to message-id.
        $sender = '(("Sender ' . $uid . '" NIL "s' . $uid . '" "example.com"))';
        $replyTo = $s['reply_to'] !== ''
            ? '((NIL NIL "' . explode('@', $s['reply_to'])[0] . '" "' . explode('@', $s['reply_to'])[1] . '"))'
            : $sender;
        $inReplyTo = $irt !== '' ? '"' . $irt . '"' : 'NIL';
        $msgId = in_array($uid, array_map('intval', $s['message_id_omit']), true)
            ? 'NIL' : '"<m' . $uid . '@example.com>"';
        $parts[] = 'ENVELOPE ("Mon, 1 Jan 2026 10:00:00 +0000" "Subject ' . $uid . '" '
            . $sender . ' ' . $sender . ' ' . $replyTo . ' '
            . '(("Rcpt" NIL "rcpt" "example.com")) NIL NIL ' . $inReplyTo . ' ' . $msgId . ')';
    }
    if (strpos($up, 'INTERNALDATE') !== false) {
        // One distinct minute per msgno so ordering is deterministic.
        $ts = gmdate('d-M-Y H:i:s', 1700000000 + $msgno * 60);
        $parts[] = 'INTERNALDATE "' . $ts . ' +0000"';
    }
    if (strpos($up, 'RFC822.SIZE') !== false) { $parts[] = 'RFC822.SIZE ' . (1000 + $msgno); }
    if (strpos($up, 'FLAGS') !== false) {
        $f = [];
        if (!in_array($uid, $s['unseen'], true))   { $f[] = '\\Seen'; }
        if (in_array($uid, $s['flagged'], true))   { $f[] = '\\Flagged'; }
        if (in_array($uid, $s['answered'], true))  { $f[] = '\\Answered'; }
        if (in_array($uid, $s['deleted'], true))   { $f[] = '\\Deleted'; }
        $parts[] = 'FLAGS (' . implode(' ', $f) . ')';
    }
    if (strpos($up, 'BODYSTRUCTURE') !== false) {
        // Field order per RFC 3501: type subtype params id description encoding
        // size [lines] md5 disposition language location
        $text = '("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 100 5 NIL NIL NIL)';
        if (in_array($uid, $s['attachment_uids'], true)) {
            $pdf = '("APPLICATION" "PDF" ("NAME" "a.pdf") NIL NIL "BASE64" 1000 NIL ("ATTACHMENT" ("FILENAME" "a.pdf")) NIL)';
            $parts[] = 'BODYSTRUCTURE (' . $text . $pdf . ' "MIXED")';
        } else {
            $parts[] = 'BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "utf-8") NIL NIL "7BIT" 100 5 NIL NIL NIL)';
        }
    }
    if (preg_match('/BODY(?:\.PEEK)?\[([^\]]*)\]/i', $items, $bm)) {
        $section = $bm[1];
        if (stripos($section, 'HEADER.FIELDS') !== false) {
            // c-client asks for References inside the same overview FETCH.
            // Answer it with a real header line so the overview carries it.
            $hdrs = '';
            if (stripos($section, 'REFERENCES') !== false && $refs !== '') {
                $hdrs .= 'References: ' . $refs . "\r\n";
            }
            $body = $hdrs . "\r\n";
        } else {
            $body = "stub body for uid $uid\r\n";
        }
        $parts[] = 'BODY[' . $section . '] {' . strlen($body) . "}\r\n" . $body;
    }
    if (!$parts) { $parts[] = "UID $uid"; }
    return "* $msgno FETCH (" . implode(' ', $parts) . ")\r\n";
}

$srv = @stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$srv) { fwrite(STDERR, "stub: cannot bind $port: $errstr\n"); exit(1); }

// Serve many connections at once. A single-connection loop deadlocks the tests:
// `$imap = connect()` opens the new connection before PHP destroys the old one,
// so two are briefly alive and imap_open() would block forever on the greeting.
$clients = [];
$idle = 0;
while (true) {
    $read = array_merge([$srv], $clients);
    $write = null;
    $except = null;
    $ready = @stream_select($read, $write, $except, 1);
    if ($ready === false) { break; }
    if ($ready === 0) {
        // Exit once the tests are gone, so no stub outlives its run.
        if (++$idle > 120 && !$clients) { break; }
        continue;
    }
    $idle = 0;

    foreach ($read as $sock) {
        if ($sock === $srv) {
            $c = @stream_socket_accept($srv, 0);
            if ($c) {
                fwrite($c, "* OK [CAPABILITY IMAP4REV1 SORT UIDPLUS] stub ready\r\n");
                $clients[(int)$c] = $c;
            }
            continue;
        }

        $line = fgets($sock);
        if ($line === false || $line === '') {
            fclose($sock);
            unset($clients[(int)$sock]);
            continue;
        }
        $line = rtrim($line, "\r\n");
        if ($line === '' || !preg_match('/^(\S+)\s+(.*)$/', $line, $m)) { continue; }
        $tag = $m[1];
        $cmd = $m[2];
        file_put_contents($logFile, $cmd . "\n", FILE_APPEND);
        $s  = scenario($scenFile);
        $up = strtoupper($cmd);
        $c  = $sock;

        if (str_starts_with($up, 'LOGIN'))      { fwrite($c, "$tag OK LOGIN done\r\n"); continue; }
        if (str_starts_with($up, 'CAPABILITY')) { fwrite($c, "* CAPABILITY IMAP4REV1 SORT UIDPLUS\r\n$tag OK done\r\n"); continue; }
        if (str_starts_with($up, 'NOOP'))       { fwrite($c, "$tag OK NOOP done\r\n"); continue; }
        if (str_starts_with($up, 'CHECK'))      { fwrite($c, "$tag OK CHECK done\r\n"); continue; }
        if (str_starts_with($up, 'CLOSE'))      { fwrite($c, "$tag OK CLOSE done\r\n"); continue; }
        if (str_starts_with($up, 'LOGOUT')) {
            fwrite($c, "* BYE\r\n$tag OK LOGOUT done\r\n");
            fclose($c);
            unset($clients[(int)$c]);
            continue;
        }

        if (str_starts_with($up, 'STATUS')) {
            if ($s['status_fail']) { fwrite($c, "$tag NO STATUS refused\r\n"); continue; }
            $omit = array_map('strtolower', (array)$s['status_omit']);
            $items = [];
            if (!in_array('messages', $omit, true))    { $items[] = 'MESSAGES ' . (int)$s['messages']; }
            if (!in_array('unseen', $omit, true))      { $items[] = 'UNSEEN ' . count($s['unseen']); }
            if (!in_array('uidnext', $omit, true))     { $items[] = 'UIDNEXT ' . (int)$s['uidnext']; }
            if (!in_array('uidvalidity', $omit, true)) { $items[] = 'UIDVALIDITY ' . (int)$s['uidvalidity']; }
            preg_match('/STATUS\s+("[^"]*"|\S+)/i', $cmd, $mm);
            $mbox = $mm[1] ?? '"INBOX"';
            fwrite($c, "* STATUS $mbox (" . implode(' ', $items) . ")\r\n$tag OK STATUS done\r\n");
            continue;
        }

        if (str_starts_with($up, 'SELECT') || str_starts_with($up, 'EXAMINE')) {
            if ($s['select_fail']) { fwrite($c, "$tag NO SELECT refused\r\n"); continue; }
            fwrite($c, '* ' . (int)$s['messages'] . " EXISTS\r\n* 0 RECENT\r\n"
                     . "* FLAGS (\\Answered \\Flagged \\Deleted \\Seen \\Draft)\r\n"
                     . '* OK [UIDVALIDITY ' . (int)$s['uidvalidity'] . "] valid\r\n"
                     . '* OK [UIDNEXT ' . (int)$s['uidnext'] . "] next\r\n"
                     . "$tag OK [READ-WRITE] SELECT done\r\n");
            continue;
        }

        if (str_starts_with($up, 'LIST') || str_starts_with($up, 'LSUB')) {
            $names = $s['folders'] ?: ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam', 'Archive'];
            foreach ($names as $f) {
                fwrite($c, "* LIST () \"/\" \"$f\"\r\n");
            }
            fwrite($c, "$tag OK LIST done\r\n");
            continue;
        }

        // Mailbox management. The scenario decides whether the server agrees, so
        // both the happy path and a server NO can be driven from a test.
        if (str_starts_with($up, 'CREATE')) {
            fwrite($c, $s['create_fail'] ? "$tag NO CREATE refused\r\n" : "$tag OK CREATE done\r\n");
            continue;
        }
        if (str_starts_with($up, 'DELETE')) {
            fwrite($c, $s['delete_fail'] ? "$tag NO DELETE refused\r\n" : "$tag OK DELETE done\r\n");
            continue;
        }

        if (preg_match('/^(UID\s+)?SORT\s*\(([^)]*)\)/i', $cmd, $sm)) {
            $byUid = !empty($sm[1]);
            $ids = [];
            for ($n = count($s['uids']); $n >= 1; $n--) { $ids[] = $byUid ? uidOf($s, $n) : $n; }
            fwrite($c, "* SORT " . implode(' ', $ids) . "\r\n$tag OK SORT done\r\n");
            continue;
        }

        if (preg_match('/^(UID\s+)?SEARCH\s+(?:CHARSET\s+\S+\s+)?(.*)$/i', $cmd, $sm)) {
            $byUid = !empty($sm[1]);
            $crit  = strtoupper(trim($sm[2]));
            $all   = $s['uids'];
            $del   = array_map('intval', $s['deleted']);
            if     ($crit === 'UNSEEN')    { $set = array_map('intval', $s['unseen']); }
            elseif ($crit === 'DELETED')   { $set = $del; }
            elseif ($crit === 'UNDELETED') { $set = array_values(array_diff($all, $del)); }
            elseif ($crit === 'FLAGGED')   { $set = array_map('intval', $s['flagged']); }
            elseif ($crit === 'ANSWERED')  { $set = array_map('intval', $s['answered']); }
            else                           { $set = $all; }
            $ids = $byUid ? $set : array_map(fn($u) => msgnoOf($s, $u), $set);
            $ids = array_values(array_filter($ids));
            // No matches: send an empty untagged SEARCH. c-client then makes
            // imap_search() return false, which is what the guards must handle.
            fwrite($c, "* SEARCH" . ($ids ? ' ' . implode(' ', $ids) : '') . "\r\n$tag OK SEARCH done\r\n");
            continue;
        }

        if (preg_match('/^UID\s+FETCH\s+(\S+)\s+(.*)$/is', $cmd, $fm)) {
            $maxUid = $s['uids'] ? max($s['uids']) : 0;
            foreach (expandSet($fm[1], $maxUid) as $uid) {
                $n = msgnoOf($s, $uid);
                if ($n) { fwrite($c, fetchResponse($s, $n, $uid, $fm[2], true)); }
            }
            fwrite($c, "$tag OK UID FETCH done\r\n");
            continue;
        }

        if (preg_match('/^FETCH\s+(\S+)\s+(.*)$/is', $cmd, $fm)) {
            foreach (expandSet($fm[1], count($s['uids'])) as $n) {
                $uid = uidOf($s, $n);
                if ($uid) { fwrite($c, fetchResponse($s, $n, $uid, $fm[2], false)); }
            }
            fwrite($c, "$tag OK FETCH done\r\n");
            continue;
        }

        fwrite($c, "$tag OK done\r\n");
    }
}
