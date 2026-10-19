<?php
/**
 * tests/imap.php — offline IMAP tests driven by tests/_imap_stub.php.
 * Run with: php tests/imap.php   (or all suites: php tests/run.php)
 *
 * Uses the real MailImap code path against a real TCP server, so it asserts both
 * behaviour AND the exact number of IMAP round trips. No mocks, no network, no
 * credentials. Skips cleanly if ext-imap is missing.
 */

putenv('EMMMAIL_VERIFY_CERT=0');
require_once __DIR__ . '/_harness.php';
test_suite('imap');

// ------------------------------------------------------------- stub control -+
$STUB_LOG  = sys_get_temp_dir() . '/emmmail_test_stub.log';
$STUB_SCEN = sys_get_temp_dir() . '/emmmail_test_scenario.json';
$stubProc  = null;
$stubPort  = 0;
$liveImap  = null;

function scenario(array $s): void {
    global $STUB_SCEN;
    file_put_contents($STUB_SCEN, json_encode($s));
}
function cmds(): array {
    global $STUB_LOG;
    $raw = (string)@file_get_contents($STUB_LOG);
    return array_values(array_filter(array_map('trim', explode("\n", $raw))));
}
function resetLog(): void { global $STUB_LOG; @unlink($STUB_LOG); }
// Commands that cost a server round trip during the operation under test.
// LOGOUT happens at teardown, after the user already has their page.
function billable(): array {
    return array_values(array_filter(cmds(), function ($c) {
        return !preg_match('/^(LOGOUT|CAPABILITY)/i', $c);
    }));
}
function freePort(): int {
    for ($p = 14400; $p < 14500; $p++) {
        $s = @stream_socket_server("tcp://127.0.0.1:$p", $e, $m);
        if ($s) { fclose($s); return $p; }
    }
    return 0;
}
function connect(): ?MailImap {
    global $stubPort, $liveImap;
    // Release the previous session first: $x = connect() would otherwise open
    // the new connection before PHP destroys the old one.
    if ($liveImap instanceof MailImap) { $liveImap->disconnect(); }
    $liveImap = null;
    $i = new MailImap('127.0.0.1', $stubPort, 'u@example.com', 'p', 'notls');
    if (!$i->isConnected()) { return null; }
    $liveImap = $i;
    return $i;
}


if (!function_exists('imap_open')) {
    skip('ext-imap is not installed; all IMAP tests skipped');
    summary();
    return;
}

$stubPort = freePort();
if (!$stubPort) {
    skip('no free port in 14400-14499');
    summary();
    return;
}

scenario(['messages' => 10, 'uidvalidity' => 99]);
$stubProc = proc_open(
    ['php', __DIR__ . '/_imap_stub.php', (string)$stubPort, $STUB_LOG, $STUB_SCEN],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes
);
// The harness already removes the throwaway database; this stops the stub and
// its scratch files, so no stub ever outlives the run that started it.
// The harness already removes the throwaway database. Close the IMAP session
// BEFORE killing the stub: otherwise PHP tears the connection down after the
// server is gone and c-client emits "[CLOSED] IMAP connection broken" at
// shutdown, which is just noise in the test output.
register_shutdown_function(function () use (&$stubProc, $STUB_LOG, $STUB_SCEN) {
    global $liveImap;
    if ($liveImap instanceof MailImap) { $liveImap->disconnect(); }
    $liveImap = null;
    if (is_resource($stubProc)) { proc_terminate($stubProc); proc_close($stubProc); }
    @unlink($STUB_LOG);
    @unlink($STUB_SCEN);
});

// Wait for the listener.
for ($i = 0; $i < 50; $i++) {
    $probe = @stream_socket_client("tcp://127.0.0.1:$stubPort", $e, $m, 0.2);
    if ($probe) { fclose($probe); break; }
    usleep(50000);
}

$imap = connect();
if (!$imap) {
    skip('could not connect to the stub');
    summary();
    return;
}

// =========================================================== primitives =====
group('statusFor()');

scenario(['messages' => 10, 'uidvalidity' => 99, 'unseen' => [1003, 1005]]);
resetLog();
$st = $imap->statusFor('INBOX');
assert_eq(10, $st['messages'] ?? null, 'statusFor reports MESSAGES');
assert_eq(2, $st['unseen'] ?? null, 'statusFor reports UNSEEN');
assert_eq(1011, $st['uidnext'] ?? null, 'statusFor reports UIDNEXT');
assert_eq(99, $st['uidvalidity'] ?? null, 'statusFor reports UIDVALIDITY');
assert_eq(1, count(billable()), 'statusFor costs exactly one command');
assert_true(!in_array('SELECT INBOX', array_map('strtoupper', cmds()), true), 'statusFor does not SELECT');

// A server that does not return UIDNEXT/UIDVALIDITY must yield null, not 0:
// two zeroes would compare equal forever and freeze the cache.
scenario(['messages' => 10, 'status_omit' => ['uidnext', 'uidvalidity']]);
$st = $imap->statusFor('INBOX');
assert_eq(null, $st['uidnext'], 'omitted UIDNEXT is null, not 0');
assert_eq(null, $st['uidvalidity'], 'omitted UIDVALIDITY is null, not 0');
assert_eq(10, $st['messages'], 'other fields still present when some are omitted');

scenario(['messages' => 10, 'status_fail' => true]);
assert_eq(null, $imap->statusFor('INBOX'), 'failed STATUS returns null, not an array of zeroes');

group('uidSet()');

scenario(['messages' => 6, 'unseen' => [1002, 1004], 'deleted' => [1005], 'flagged' => []]);
$imap = connect();
$imap->selectFolder('INBOX');
assert_eq([1002, 1004], $imap->uidSet('UNSEEN'), 'uidSet returns matching uids');
assert_eq([1005], $imap->uidSet('DELETED'), 'uidSet reads the deleted set');
assert_eq([1001, 1002, 1003, 1004, 1006], $imap->uidSet('UNDELETED'), 'UNDELETED excludes deleted uids');

// imap_search() returns false for "no matches", which is indistinguishable from
// a real failure. It must surface as null so the reconcile deletes nothing.
assert_eq(null, $imap->uidSet('FLAGGED'), 'no matches yields null, never an empty array');

resetLog();
$imap->uidSet('UNDELETED');
assert_eq(1, count(billable()), 'uidSet costs exactly one command');

group('overviewRange()');

scenario(['messages' => 5, 'unseen' => [1004], 'flagged' => [1005]]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$rows = $imap->overviewRange('1:5');
assert_eq(5, count($rows), 'overviewRange returns one row per message');
assert_eq(1, count(billable()), 'overviewRange batches the whole range into one command');
$byUid = [];
foreach ($rows as $r) { $byUid[$r['uid']] = $r; }
assert_eq('Subject 1001', $byUid[1001]['subject'], 'subject is decoded into the row');
assert_eq('s1001@example.com', $byUid[1001]['from_address'], 'from_address extracted');
assert_true($byUid[1001]['date_ts'] > 0, 'date_ts populated from INTERNALDATE');
assert_true($byUid[1005]['date_ts'] > $byUid[1001]['date_ts'], 'later message has a larger date_ts');
assert_eq(true, $byUid[1004]['unread'], 'unseen message is unread');
assert_eq(false, $byUid[1001]['unread'], 'seen message is not unread');
assert_eq(true, $byUid[1005]['flagged'], 'flagged message is flagged');
assert_true(array_key_exists('from_display', $byUid[1001]), 'row carries from_display for the view');

// A UID range always includes the last message even when the low bound is above
// every uid (RFC 3501 6.4.8), so this must return the stray row rather than
// erroring -- the caller filters it by min_new_uid.
$stray = $imap->overviewRange('9999:*', true);
assert_true(count($stray) <= 1, 'overshooting UID range returns at most the one stray row'
    . ' (got ' . count($stray) . ' rows: ' . implode(',', array_column($stray, 'uid')) . ')');

group('overviewRange() exposes the threading ids');

// The References header rides in the SAME FETCH as the envelope: c-client
// requests BODY.PEEK[HEADER.FIELDS (... References)] alongside it. Reading it
// must not add a command, or a cold sync would pay one per message.
scenario(['messages' => 3, 'references' => '<root@example.com>', 'in_reply_to' => '<parent@example.com>']);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$rows = $imap->overviewRange('1:3');
assert_eq(1, count(billable()), 'reading the threading ids costs no extra command');
$byUid = [];
foreach ($rows as $r) { $byUid[$r['uid']] = $r; }
assert_eq('<m1001@example.com>', $byUid[1001]['message_id'] ?? null, 'message_id is parsed');
assert_eq('<parent@example.com>', $byUid[1001]['in_reply_to'] ?? null, 'in_reply_to is parsed');
assert_eq('<root@example.com>', $byUid[1001]['references'] ?? null, 'references is parsed from the same FETCH');

// Per-message chains, which the grouping tests drive.
scenario(['messages' => 3,
    'references_map' => ['1002' => '<m1001@example.com>', '1003' => '<m1001@example.com>']]);
$imap = connect();
$imap->selectFolder('INBOX');
$rows = $imap->overviewRange('1:3');
$refs = [];
foreach ($rows as $r) { $refs[$r['uid']] = $r['references'] ?? null; }
assert_eq('', $refs[1001], 'the root carries no references');
assert_eq('<m1001@example.com>', $refs[1002] ?? null, 'a reply references the root');
assert_eq('<m1001@example.com>', $refs[1003] ?? null, 'a second reply references it too');

group('getEmails() exposes the threading ids');

scenario(['messages' => 3, 'references' => '<root@example.com>']);
$imap = connect();
$res = $imap->getEmails('INBOX', 1, 3);
$e = $res['emails'][0] ?? [];
assert_eq('<m1003@example.com>', $e['message_id'] ?? null, 'the live list carries message_id');
assert_eq('<root@example.com>', $e['references'] ?? null, 'the live list carries references');

group('overviewRange() with attachment flags');

// Resolving structures ascending BEFORE the envelopes lets c-client batch both
// into one FETCH. Doing it afterwards costs one command per message, which is
// what made a cold sync expensive.
scenario(['messages' => 40, 'attachment_uids' => [1003, 1039]]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$rows = $imap->overviewRange('1:40', false, true);
$n = count(billable());
assert_eq(40, count($rows), 'all rows returned');
assert_true($n <= 4, "40 rows with attachment flags cost at most 4 commands (got $n)");
$byUid = [];
foreach ($rows as $r) { $byUid[$r['uid']] = $r; }
assert_eq(true, $byUid[1003]['has_attachment'], 'attachment detected inline');
assert_eq(true, $byUid[1039]['has_attachment'], 'second attachment detected inline');
assert_eq(false, $byUid[1001]['has_attachment'], 'no false positive');
assert_eq(true, $byUid[1001]['structure_synced'], 'rows are marked structure_synced');

// Without the flag the structure is not fetched at all.
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$plain = $imap->overviewRange('1:40');
assert_eq(false, array_key_exists('structure_synced', $plain[0]), 'plain rows carry no structure flag');
$bs = array_values(array_filter(cmds(), fn($c) => stripos($c, 'BODYSTRUCTURE') !== false));
assert_eq(0, count($bs), 'plain overviewRange fetches no structures');

group('attachmentFlags()');

scenario(['messages' => 4, 'attachment_uids' => [1002, 1004]]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$flags = $imap->attachmentFlags([1001, 1002, 1003, 1004]);
assert_eq(false, $flags[1001] ?? null, 'message without an attachment part is false');
assert_eq(true, $flags[1002] ?? null, 'message with an ATTACHMENT disposition is true');
assert_eq(true, $flags[1004] ?? null, 'second attachment detected');
// Walking uids ascending lets c-client's lookahead batch the structures, so a
// small consecutive run costs one BODYSTRUCTURE fetch, not one per uid.
$bs = array_values(array_filter(cmds(), fn($c) => stripos($c, 'BODYSTRUCTURE') !== false));
assert_eq(1, count($bs), 'consecutive uids batch into a single BODYSTRUCTURE fetch');

group('getEmails() round-trip cost');

// The paperclip used to cost one FETCH BODYSTRUCTURE per message. Resolving
// structures ascending BEFORE the overview lets c-client batch them, which is
// the difference between ~21 commands per page and ~2.
scenario(['messages' => 200, 'attachment_uids' => [1183, 1185]]);
$imap = connect();
resetLog();
$res = $imap->getEmails('INBOX', 1, 20);
$n = count(billable());
assert_eq(20, count($res['emails']), 'a full page of 20 is returned');
assert_eq(200, $res['total'], 'total reflects the whole folder');
assert_true($n <= 5, "a 20-message page costs at most 5 commands (got $n)");
$bs = array_values(array_filter(cmds(), fn($c) => stripos($c, 'BODYSTRUCTURE') !== false));
assert_eq(1, count($bs), 'the whole page needs one BODYSTRUCTURE fetch');
$flagged = [];
foreach ($res['emails'] as $e) { $flagged[$e['uid']] = $e['has_attachment']; }
assert_eq(true, $flagged[1183], 'attachment flag still correct after the reorder');
assert_eq(true, $flagged[1185], 'second attachment flag correct');
assert_eq(false, $flagged[1200], 'no false positive on a text-only message');
assert_eq(1200, $res['emails'][0]['uid'], 'newest message is still first');

// Page 2 must be just as cheap.
$imap = connect();
resetLog();
$res2 = $imap->getEmails('INBOX', 2, 20);
$n2 = count(billable());
assert_true($n2 <= 5, "page 2 costs at most 5 commands (got $n2)");
assert_eq(1180, $res2['emails'][0]['uid'], 'page 2 starts where page 1 ended');

// ========================================================== sync_folder =====
group('sync_folder() cold start');

$U = 'sync@example.com';
scenario(['messages' => 5, 'uidvalidity' => 99, 'unseen' => [1004], 'attachment_uids' => [1003]]);
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'INBOX', ['window' => 500, 'backfill' => 0, 'now' => 1700000000]);
assert_eq(true, $r['ok'], 'cold sync succeeds');
assert_eq('cold', $r['reason'], 'cold sync is reported as cold');
assert_eq(5, db_count_messages($U, 'INBOX'), 'cold sync caches every message in the window');
assert_eq(5, $r['changed'], 'cold sync reports every row as changed');
assert_eq(1, $r['unread'], 'unread count comes from STATUS');
$cachedRows = db_list_messages($U, 'INBOX', 1, 10);
assert_eq(1005, $cachedRows[0]['uid'], 'newest message is first');
assert_eq(1001, $cachedRows[4]['uid'], 'oldest cached message is last');
$state = db_get_folder_state($U, 'INBOX');
assert_eq(99, $state['uidvalidity'], 'folder state records uidvalidity');
assert_eq(1006, $state['uidnext'], 'folder state records uidnext from the opening STATUS');
assert_eq(5, $state['cached_rows'], 'folder state records how many rows are cached');

group('sync_folder() idempotency');

// Nothing changed on the server. This is the hot path: it must cost exactly one
// STATUS and report no changes, or the client reload-loops forever.
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000100]);
assert_eq(true, $r['ok'], 'second sync succeeds');
assert_eq(0, $r['changed'], 'second sync reports no changes');
assert_eq('unchanged', $r['reason'], 'second sync short-circuits on STATUS');
assert_eq(1, count(billable()), 'an unchanged folder costs exactly ONE command');
assert_eq(5, db_count_messages($U, 'INBOX'), 'cache is untouched');

group('sync_folder() incremental');

// One new message arrives.
scenario(['messages' => 6, 'uidvalidity' => 99, 'unseen' => [1004, 1006], 'attachment_uids' => [1003]]);
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000200]);
assert_eq(true, $r['ok'], 'incremental sync succeeds');
assert_eq(6, db_count_messages($U, 'INBOX'), 'the new message is cached');
assert_eq(1, $r['changed'], 'only the new message is reported as changed');
$newest = db_list_messages($U, 'INBOX', 1, 1)[0];
assert_eq(1006, $newest['uid'], 'new message sorts newest');
assert_eq(true, $newest['unread'], 'new message is unread');

group('sync_folder() stores the conversation key');

scenario(['messages' => 3, 'uidvalidity' => 99,
    'references_map' => ['1002' => '<m1001@example.com>', '1003' => '<m1001@example.com>']]);
$T = 'thread@example.com';
$imap = connect();
$r = sync_folder($imap, $T, 'INBOX', ['window' => 500, 'backfill' => 0, 'now' => 1700000000]);
assert_eq(true, $r['ok'], 'sync succeeds');
$rows = [];
foreach (db_list_messages($T, 'INBOX', 1, 10) as $row) { $rows[(int)$row['uid']] = $row; }
assert_eq('<m1001@example.com>', $rows[1001]['thread_key'] ?? null, 'a root keys on its own Message-ID');
assert_eq('<m1001@example.com>', $rows[1002]['thread_key'] ?? null, 'a reply keys on the root');
assert_eq('<m1001@example.com>', $rows[1003]['thread_key'] ?? null, 'a second reply joins the same key');

// A message with no ids at all cannot be grouped, so it gets a unique sentinel
// instead of sharing an empty key with every other id-less message.
scenario(['messages' => 3, 'uidvalidity' => 99, 'message_id_omit' => [1003]]);
$S = 'sentinel@example.com';
$imap = connect();
sync_folder($imap, $S, 'INBOX', ['window' => 500, 'backfill' => 0, 'now' => 1700000000]);
$rows = [];
foreach (db_list_messages($S, 'INBOX', 1, 10) as $row) { $rows[(int)$row['uid']] = $row; }
assert_eq('uid:INBOX:1003', $rows[1003]['thread_key'] ?? null, 'an id-less message gets a unique sentinel');
assert_eq('<m1001@example.com>', $rows[1001]['thread_key'] ?? null, 'its neighbours are unaffected');

group('sync_folder() rebuilds an unthreaded cache');

// A cache written before the key existed has thread_key ''. Grouping it by ''
// would show one conversation of the whole folder, so the next sync must rebuild
// it, not leave it.
$RU = 'rebuild@example.com';
db_upsert_messages($RU, 'INBOX', [
    ['uid' => 1001, 'subject' => 'legacy', 'date_ts' => 1700000000, 'thread_key' => ''],
]);
assert_eq(1, db_count_unthreaded($RU, 'INBOX'), 'the fixture starts unthreaded');
scenario(['messages' => 3, 'uidvalidity' => 99, 'uidbase' => 1000,
    'references_map' => ['1002' => '<m1001@example.com>', '1003' => '<m1001@example.com>']]);
$imap = connect();
$r = sync_folder($imap, $RU, 'INBOX', ['window' => 500, 'backfill' => 0, 'now' => 1700000000]);
assert_eq(true, $r['ok'], 'the rebuild sync succeeds');
assert_eq(0, db_count_unthreaded($RU, 'INBOX'), 'no row is left without a conversation key');
assert_eq(3, db_count_messages($RU, 'INBOX'), 'the folder is refetched');
assert_eq(1, db_count_threads($RU, 'INBOX'), 'and the replies group into one conversation');

group('sync_folder() reports arrivals (new unread messages)');

// A notification may only fire for a message that just arrived, so `arrivals` is
// a separate number from `changed`: a flag flip on a 500-message folder is a
// change, not an arrival.
$AU = 'arrivals@example.com';
db_delete_messages($AU, 'INBOX');
scenario(['messages' => 4, 'uidvalidity' => 99, 'unseen' => [1003, 1004]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000000]);
assert_eq(0, $r['arrivals'], 'a cold start reports no arrivals: nothing arrived, everything did');
assert_eq(4, $r['changed'], 'but it did change everything');

// The hot path, with nothing new. It must not report an arrival, or every poll
// would re-notify about the same mail.
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000100]);
assert_eq(0, $r['changed'], 'a settled folder reports no changes');
assert_eq(0, $r['arrivals'], 'and no arrivals');

// One new unread message.
scenario(['messages' => 5, 'uidvalidity' => 99, 'unseen' => [1003, 1004, 1005]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000200]);
assert_eq(1, $r['changed'], 'the new message is a change');
assert_eq(1, $r['arrivals'], 'and an arrival');

// A message that arrived already read (a rule, or a fetch on a phone) is
// cached, but must not raise a notification.
scenario(['messages' => 6, 'uidvalidity' => 99, 'unseen' => [1003, 1004, 1005]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 3600, 'backfill' => 0, 'now' => 1700000300]);
assert_eq(1, $r['changed'], 'a message that arrived \\Seen is still a change');
assert_eq(0, $r['arrivals'], 'but not an arrival: nobody wants a notification for it');

// A flag flip on an existing message: a change, never an arrival. This is the
// case `changed` cannot serve for — it is > 0 while nothing whatsoever arrived.
scenario(['messages' => 6, 'uidvalidity' => 99, 'unseen' => [1003, 1004, 1005, 1006]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000400]);
assert_true($r['changed'] > 0, 'marking a message unread elsewhere is a change');
assert_eq(0, $r['arrivals'], 'and never an arrival, or a flag flip would notify');

// A UIDVALIDITY reset renumbers everything, so every row is a "new uid" while
// none of them is a new message. Reporting arrivals here would notify about
// the whole mailbox.
scenario(['messages' => 3, 'uidvalidity' => 500, 'uidbase' => 7000, 'unseen' => [7001, 7002, 7003]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000500]);
assert_eq('uidvalidity', $r['reason'], 'the reason is the uidvalidity reset');
assert_eq(0, $r['arrivals'], 'a UIDVALIDITY purge reports no arrivals at all');

// The emptied-folder case. The user deleted everything, so the cache is empty
// and the sync refills the window by message number, re-upserting rows it
// already knew. The old uidnext still separates them from the one new message,
// so the refill reports exactly one arrival instead of the whole window.
db_delete_messages($AU, 'INBOX');
scenario(['messages' => 2, 'uidvalidity' => 99, 'uidbase' => 8000, 'unseen' => [8002]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000600]);
assert_eq(2, db_count_messages($AU, 'INBOX'), 'precondition: the window was refilled from empty');
assert_eq(0, $r['arrivals'], 'a refill of an emptied folder with only old mail reports no arrival');

scenario(['messages' => 3, 'uidvalidity' => 99, 'uidbase' => 8000, 'unseen' => [8003]]);
$imap = connect();
$r = sync_folder($imap, $AU, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000700]);
assert_eq(2, $r['changed'], 'precondition: the new message, plus a flag flip on the refill');
assert_eq(1, $r['arrivals'], 'and the one genuinely new message is the single arrival');


group('sync_folder() expunge and flags');

// uid 1002 expunged elsewhere; 1003 marked \Deleted but not yet expunged.
scenario([
    'messages'    => 5,
    'uids'        => [1001, 1003, 1004, 1005, 1006],
    'uidvalidity' => 99,
    'unseen'      => [],
    'deleted'     => [1003],
]);
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000300]);
assert_eq(true, $r['ok'], 'reconcile sync succeeds');
assert_eq(null, $r['refused'], 'a plausible reconcile is not refused');
assert_eq(5, db_count_messages($U, 'INBOX'), 'expunged uid removed, \\Deleted uid retained');
$uids = array_column(db_list_messages($U, 'INBOX', 1, 10), 'uid');
assert_true(!in_array(1002, $uids, true), 'expunged uid 1002 is gone from the cache');
assert_true(in_array(1003, $uids, true), '\\Deleted-but-unexpunged uid 1003 is still cached');
$row1003 = null; $row1006 = null;
foreach (db_list_messages($U, 'INBOX', 1, 10) as $row) {
    if ($row['uid'] === 1003) { $row1003 = $row; }
    if ($row['uid'] === 1006) { $row1006 = $row; }
}
assert_eq(true, $row1003['deleted'], 'uid 1003 is marked deleted in the cache');
assert_eq(false, $row1006['unread'], 'uid 1006 was read elsewhere and is no longer unread');

group('sync_folder() guards');

// A failing SELECT must abort. Everything after it in the sync operates on
// whatever mailbox is really selected, and emptyTrash deletes by sequence
// number, so proceeding is a data-loss path.
scenario(['messages' => 5, 'uidvalidity' => 99, 'select_fail' => true, 'uidnext' => 1099]);
$imap = connect();
// imap_reopen() returns true even when the server answers NO to SELECT, so
// selectFolder() has to detect the refusal on the error stack instead.
assert_eq(false, $imap->selectFolder('Archive'), 'selectFolder detects a refused SELECT');
$imap = connect();
$before = db_count_messages($U, 'INBOX');
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000400]);
assert_eq(false, $r['ok'], 'sync fails when the folder cannot be selected');
assert_eq('select-failed', $r['reason'], 'the failure reason is reported');
assert_eq($before, db_count_messages($U, 'INBOX'), 'a failed select changes nothing in the cache');

// A failing STATUS must leave the cache alone too.
scenario(['messages' => 5, 'status_fail' => true]);
$imap = connect();
$r = sync_folder($imap, $U, 'INBOX', ['now' => 1700000500]);
assert_eq(false, $r['ok'], 'sync fails when STATUS fails');
assert_eq('status-failed', $r['reason'], 'STATUS failure is reported');
assert_eq($before, db_count_messages($U, 'INBOX'), 'a failed STATUS changes nothing in the cache');

group('sync_folder() uidvalidity reset');

// The server renumbered the mailbox: every cached uid is meaningless.
scenario(['messages' => 3, 'uidvalidity' => 500, 'uidbase' => 7000]);
$imap = connect();
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000600]);
assert_eq(true, $r['ok'], 'uidvalidity reset sync succeeds');
assert_eq('uidvalidity', $r['reason'], 'the reason is uidvalidity');
assert_eq(3, db_count_messages($U, 'INBOX'), 'cache is rebuilt from scratch');
$uids = array_column(db_list_messages($U, 'INBOX', 1, 10), 'uid');
assert_eq([7003, 7002, 7001], $uids, 'only the new generation of uids remains');
assert_eq(500, db_get_folder_state($U, 'INBOX')['uidvalidity'], 'new uidvalidity stored');

group('sync_folder() resolves attachments inline');

db_delete_messages($U, 'INBOX');
scenario(['messages' => 3, 'uidvalidity' => 500, 'uidbase' => 7000, 'attachment_uids' => [7002]]);
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'INBOX', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000700]);
assert_eq(0, count(db_pending_structure_uids($U, 'INBOX', 10)),
    'a cold sync resolves attachment flags inline, leaving nothing pending');
$flagged = [];
foreach (db_list_messages($U, 'INBOX', 1, 10) as $row) { $flagged[$row['uid']] = $row['has_attachment']; }
assert_eq(true, $flagged[7002], 'attachment detected for the right uid');
assert_eq(false, $flagged[7001], 'no false positive on a text-only message');

// A cold sync of a decent window must stay cheap now that structures ride along
// with the envelopes instead of costing one command each.
db_delete_messages($U, 'Big');
scenario(['messages' => 40, 'uidvalidity' => 77, 'uidbase' => 8000, 'attachment_uids' => [8010]]);
$imap = connect();
resetLog();
$r = sync_folder($imap, $U, 'Big', ['full_interval' => 0, 'backfill' => 0, 'now' => 1700000750]);
$n = count(billable());
assert_eq(40, db_count_messages($U, 'Big'), 'the whole window is cached');
assert_true($n <= 12, "a 40-message cold sync costs at most 12 commands (got $n)");
assert_eq(0, count(db_pending_structure_uids($U, 'Big', 100)), 'no structures left pending');

// The bounded backfill still exists for rows cached before this change.
db_delete_messages($U, 'Legacy');
db_upsert_messages($U, 'Legacy', [
    ['uid' => 8001, 'subject' => 'old', 'date_ts' => 10],
    ['uid' => 8002, 'subject' => 'old', 'date_ts' => 20],
]);
assert_eq(2, count(db_pending_structure_uids($U, 'Legacy', 10)), 'legacy rows are pending');

// The backfill must not be counted as a change, or every sync reports work and
// the client reloads in a loop. Re-sync the folder we just filled, unchanged.
scenario(['messages' => 40, 'uidvalidity' => 77, 'uidbase' => 8000, 'attachment_uids' => [8010]]);
$imap = connect();
$r = sync_folder($imap, $U, 'Big', ['full_interval' => 0, 'backfill' => 20, 'now' => 1700000900]);
assert_eq(0, $r['changed'], 'a settled folder reports zero changes even with backfill enabled');
assert_eq(null, $r['refused'], 'and nothing is refused');

// ====================================================== folder_view_data =====
group('folder_view_data() cold start');

$V = 'view@example.com';
scenario(['messages' => 45, 'uidvalidity' => 99, 'unseen' => [1044, 1045], 'attachment_uids' => [1043]]);
$imap = connect();
$factory = function () use (&$imap) { return $imap; };
$calls = 0;
$counting = function () use (&$imap, &$calls) { $calls++; return $imap; };

resetLog();
$d = folder_view_data($counting, $V, 'INBOX', 1, 20, ['window' => 500, 'backfill' => 0, 'now' => 1700000000]);
assert_eq('cache', $d['source'], 'cold start still renders from the cache it just filled');
assert_eq(20, count($d['emails']), 'a full page is returned');
assert_eq(45, $d['total'], 'total comes from the server, not the cached row count');
assert_eq(3, $d['pages'], 'pages derived from the server total');
assert_eq(1045, $d['emails'][0]['uid'], 'newest message first');
assert_eq(true, $d['emails'][0]['unread'], 'unread flag survives the round trip');
assert_true(count($d['folders']) > 0, 'folder list is populated');
assert_eq(null, $d['error'], 'no error on a healthy cold start');
assert_true($calls > 0, 'cold start does connect to IMAP');

group('folder_view_data() warm render');

// The whole point: a second render must not touch IMAP at all.
$calls = 0;
resetLog();
$d = folder_view_data($counting, $V, 'INBOX', 1, 20, ['now' => 1700000010]);
assert_eq(0, $calls, 'a warm render never asks for an IMAP connection');
assert_eq(0, count(cmds()), 'a warm render issues ZERO IMAP commands');
assert_eq('cache', $d['source'], 'served from cache');
assert_eq(20, count($d['emails']), 'still a full page');
assert_eq(45, $d['total'], 'total preserved from folder_state');
assert_eq(1045, $d['emails'][0]['uid'], 'ordering preserved');
assert_true(count($d['folders']) > 0, 'folder list served from cache too');

// Page 2 is inside the cached window, so it must also be free.
$calls = 0;
resetLog();
$d2 = folder_view_data($counting, $V, 'INBOX', 2, 20, ['now' => 1700000020]);
assert_eq(0, count(cmds()), 'page 2 inside the window is also free');
assert_eq(1025, $d2['emails'][0]['uid'], 'page 2 starts where page 1 ended');
assert_eq(20, count($d2['emails']), 'page 2 is full');

// An empty folder is not a cold cache. Drafts is usually empty, and if "no rows"
// is read as "nothing cached" then every single visit pays a full connect and
// sync while the inbox renders instantly. The folder_state row is the authority.
group('folder_view_data() empty folder stays warm');

db_delete_messages($V, 'Drafts');
scenario(['messages' => 0, 'uidvalidity' => 99]);
$imap = connect();
$calls = 0;
resetLog();
$empty = folder_view_data($counting, $V, 'Drafts', 1, 20, ['backfill' => 0, 'now' => 1700000400]);
assert_true($calls > 0, 'the first visit to an empty folder does connect');
assert_eq(0, $empty['total'], 'an empty folder reports a zero total');
assert_eq(0, count($empty['emails']), 'and no messages');
assert_eq(null, $empty['error'], 'an empty folder is not an error');

$calls = 0;
resetLog();
$empty2 = folder_view_data($counting, $V, 'Drafts', 1, 20, ['now' => 1700000410]);
assert_eq(0, $calls, 'a known-empty folder never asks for an IMAP connection');
assert_eq(0, count(cmds()), 'a known-empty folder issues ZERO IMAP commands');
assert_eq('cache', $empty2['source'], 'a known-empty folder is served from the cache');
assert_eq(0, $empty2['total'], 'still zero');
assert_eq(0, count($empty2['emails']), 'still no messages');
assert_eq(0, $empty2['pages'], 'no pages to page through');
assert_eq(null, $empty2['error'], 'and still no error');

// A folder the server says holds mail but has no cached rows IS cold, and must
// still sync -- otherwise the fix above would silently blank real mailboxes.
db_delete_messages($V, 'Archive2');
scenario(['messages' => 5, 'uidvalidity' => 99, 'uidbase' => 5000]);
$imap = connect();
folder_view_data($counting, $V, 'Archive2', 1, 20, ['backfill' => 0, 'now' => 1700000420]);
db_delete_messages($V, 'Archive2');
$calls = 0;
$cold = folder_view_data($counting, $V, 'Archive2', 1, 20, ['backfill' => 0, 'now' => 1700000430]);
assert_true($calls > 0, 'a non-empty folder with no cached rows still syncs');
assert_eq(5, $cold['total'], 'and reports the real total');
assert_true(count($cold['emails']) > 0, 'and shows the messages');

group('folder_view_data() deep page falls back to live');

// Cache only the newest 10 of 45, then ask for a page past the window.
db_delete_messages($V, 'INBOX');
scenario(['messages' => 45, 'uidvalidity' => 99]);
$imap = connect();
$d = folder_view_data($counting, $V, 'INBOX', 1, 20, ['window' => 10, 'backfill' => 0, 'now' => 1700000100]);
assert_eq(10, db_count_messages($V, 'INBOX'), 'only the window is cached');

$calls = 0;
resetLog();
$deep = folder_view_data($counting, $V, 'INBOX', 3, 20, ['now' => 1700000110]);
assert_eq('live', $deep['source'], 'a page beyond the cached window is served live');
assert_true($calls > 0, 'the deep page does connect');
assert_true(count($deep['emails']) > 0, 'the deep page returns messages');
assert_eq(45, $deep['total'], 'live fallback still reports the real total');

// Page 1 is still inside the window, so it stays free even now.
$calls = 0;
resetLog();
$shallow = folder_view_data($counting, $V, 'INBOX', 1, 10, ['now' => 1700000120]);
assert_eq('cache', $shallow['source'], 'page 1 still comes from the cache');
assert_eq(0, count(cmds()), 'page 1 still costs nothing');

group('folder_view_data() degrades safely');

// STATUS fails on a cold cache: the user must get a live listing and an error,
// never a silent empty mailbox.
db_delete_messages($V, 'Archive');
scenario(['messages' => 8, 'status_fail' => true]);
$imap = connect();
$d = folder_view_data($counting, $V, 'Archive', 1, 20, ['backfill' => 0, 'now' => 1700000200]);
assert_eq('live', $d['source'], 'a failed cold sync falls back to live IMAP');
assert_true($d['error'] !== null, 'the failure is surfaced, not swallowed');
assert_true(count($d['emails']) > 0, 'the user still sees their messages');

group('folder_view_data() harvests contacts during sync');

db_delete_messages($V, 'Sent');
scenario(['messages' => 3, 'uidvalidity' => 99, 'uidbase' => 3000]);
$imap = connect();
folder_view_data($counting, $V, 'Sent', 1, 20, ['backfill' => 0, 'now' => 1700000300]);
$found = db_search_contacts($V, 's3001@example.com');
assert_eq(1, count($found), 'senders seen during sync become contacts');

group('folder_view_data() groups replies into conversations');

$G = 'grouping@example.com';
db_delete_messages($G, 'INBOX');
scenario(['messages' => 5, 'uidvalidity' => 99,
    'references_map' => ['1002' => '<m1001@example.com>', '1003' => '<m1001@example.com>']]);
$imap = connect();
$countingG = function () use (&$imap) { return $imap; };
$g = folder_view_data($countingG, $G, 'INBOX', 1, 20, ['backfill' => 0, 'now' => 1700000000]);
assert_eq(true, $g['threads'] ?? null, 'a fully cached folder is threaded');
assert_eq(3, $g['total'], 'the total counts conversations, not messages');
assert_eq(3, count($g['emails']), 'one row per conversation');
$byKey = [];
foreach ($g['emails'] as $e) { $byKey[$e['thread_key']] = $e; }
assert_eq(3, $byKey['<m1001@example.com>']['thread_count'], 'the reply chain collapses into one row');
assert_eq([1001, 1002, 1003], $byKey['<m1001@example.com>']['thread_uids'], 'the row carries its member uids');
assert_eq(1, $byKey['<m1004@example.com>']['thread_count'], 'a lone message is its own conversation');

// Only a fully cached folder is threaded: a partial window would let a
// conversation straddle the page boundary and double up against the live tail.
group('folder_view_data() leaves a partially cached folder flat');

$G2 = 'grouping2@example.com';
db_delete_messages($G2, 'INBOX');
scenario(['messages' => 45, 'uidvalidity' => 99]);
$imap = connect();
$countingG2 = function () use (&$imap) { return $imap; };
$partial = folder_view_data($countingG2, $G2, 'INBOX', 1, 10, ['window' => 10, 'backfill' => 0, 'now' => 1700000000]);
assert_eq(10, db_count_messages($G2, 'INBOX'), 'only the window is cached');
assert_eq('cache', $partial['source'], 'the cached window still renders from cache');
assert_eq(false, $partial['threads'] ?? null, 'a partially cached folder is not threaded');
$deep = folder_view_data($countingG2, $G2, 'INBOX', 3, 20, ['now' => 1700000010]);
assert_eq('live', $deep['source'], 'and its deep pages still fall back to live');
assert_eq(45, $deep['total'], 'with the real message total');

group('folder_view_data() keeps Drafts flat');

$G3 = 'drafts-flat@example.com';
db_delete_messages($G3, 'Drafts');
scenario(['messages' => 3, 'uidvalidity' => 99, 'uidbase' => 7000,
    'references_map' => ['7002' => '<m7001@example.com>', '7003' => '<m7001@example.com>']]);
$imap = connect();
$countingG3 = function () use (&$imap) { return $imap; };
$dr = folder_view_data($countingG3, $G3, 'Drafts', 1, 20, ['backfill' => 0, 'now' => 1700000000]);
assert_eq(false, $dr['threads'] ?? null, 'Drafts is never threaded');
assert_eq(3, count($dr['emails']), 'drafts stay one row each');
assert_eq(3, $dr['total'], 'and the total stays the message count');

// ======================================================== sync_request =======
group('sync_request() staleness gate');

$S = 'req@example.com';
db_delete_messages($S, 'INBOX');
scenario(['messages' => 6, 'uidvalidity' => 99, 'unseen' => [1006]]);
$imap = connect();
$calls = 0;
$counting = function () use (&$imap, &$calls) { $calls++; return $imap; };

// Cold: must actually sync.
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000000, 'min_interval' => 15]);
assert_eq(true, $r['ok'], 'cold sync_request succeeds');
assert_eq(false, $r['skipped'], 'cold sync_request is not skipped');
assert_eq(6, db_count_messages($S, 'INBOX'), 'cold sync_request fills the cache');
assert_eq(1, $r['unread'], 'unread reported');
assert_eq(6, $r['total'], 'total reported');
assert_eq(0, $r['arrivals'], 'a cold sync_request reports no arrivals');

// Called again immediately: the gate must fire BEFORE a connection is made,
// or every page load pays a TCP+TLS+LOGIN for nothing.
$calls = 0;
resetLog();
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000005, 'min_interval' => 15]);
assert_eq(true, $r['skipped'], 'a request inside the interval is skipped');
assert_eq(0, $calls, 'a skipped request never asks for a connection');
assert_eq(0, count(cmds()), 'a skipped request issues zero IMAP commands');
assert_eq(0, $r['changed'], 'a skipped request reports no changes');
assert_eq(0, $r['arrivals'], 'and no arrivals either: it never looked');
assert_true($r['last_sync_ts'] > 0, 'a skipped request still reports the last sync time');

// force=1 bypasses the gate.
$calls = 0;
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000006, 'min_interval' => 15, 'force' => true]);
assert_eq(false, $r['skipped'], 'force bypasses the gate');
assert_true($calls > 0, 'forced request does connect');

// Past the interval it runs on its own.
$calls = 0;
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000100, 'min_interval' => 15]);
assert_eq(false, $r['skipped'], 'past the interval it runs');

group('sync_request() reports real changes');

scenario(['messages' => 7, 'uidvalidity' => 99, 'unseen' => [1006, 1007]]);
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000200, 'min_interval' => 15]);
assert_eq(1, $r['changed'], 'a new message is reported as one change');
assert_eq(1, $r['arrivals'], 'and as one arrival');
assert_eq(2, $r['unread'], 'unread count follows the server');
assert_eq(7, db_count_messages($S, 'INBOX'), 'the new message is cached');

// Nothing changed: the client must be told so, or it reloads forever.
$r = sync_request($counting, $S, 'INBOX', ['backfill' => 0, 'now' => 1700000400, 'min_interval' => 15, 'full_interval' => 3600]);
assert_eq(0, $r['changed'], 'a settled folder reports zero changes');
assert_eq(0, $r['arrivals'], 'and zero arrivals');
assert_eq(false, $r['skipped'], 'and it did actually check');

group('sync_request() rejects unsafe folder names');

$calls = 0;
$r = sync_request($counting, $S, '}{evil:143/imap}INBOX', ['now' => 1700000500]);
assert_eq(false, $r['ok'], 'a folder name with braces is rejected');
assert_eq(0, $calls, 'a rejected folder name never opens a connection');
assert_true($r['error'] !== null, 'the rejection is reported');

$r = sync_request($counting, $S, "INBOX\r\nA1 DELETE x", ['now' => 1700000500]);
assert_eq(false, $r['ok'], 'a folder name with CRLF is rejected');

group('body fetches must not silently set \\Seen');

// Nothing in the app used FT_PEEK, so imap_body()/imap_fetchbody() marked
// messages read as a side effect of replying, forwarding or downloading an
// attachment. Assert at the wire level that BODY.PEEK is used instead.
scenario(['messages' => 5, 'attachment_uids' => [1003]]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$imap->getEmailByUid(1003);
$bodyFetches = array_values(array_filter(cmds(), fn($c) => preg_match('/BODY(\.PEEK)?\[/i', $c)));
assert_true(count($bodyFetches) > 0, 'reading a message does fetch a body part');
$nonPeek = array_values(array_filter($bodyFetches, fn($c) => !preg_match('/BODY\.PEEK\[/i', $c)));
assert_eq(0, count($nonPeek), 'every body fetch uses BODY.PEEK (' . implode(' | ', $nonPeek) . ')');

$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$imap->getAttachmentByUid(1003, '2');
$dl = array_values(array_filter(cmds(), fn($c) => preg_match('/BODY(\.PEEK)?\[/i', $c)));
$dlNonPeek = array_values(array_filter($dl, fn($c) => !preg_match('/BODY\.PEEK\[/i', $c)));
assert_eq(0, count($dlNonPeek), 'downloading an attachment does not mark the message read');

// ============================================== mailbox management ===========
group('createFolder() / deleteFolder()');

scenario(['messages' => 0, 'folders' => ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam', 'Archive']]);
$imap = connect();
// Warm the LIST memo, so dropping it is actually observable below.
assert_eq(6, count($imap->getFolders()), 'the scenario folder list is served');

resetLog();
assert_true($imap->createFolder('Testing'), 'CREATE reported as success');
$created = array_values(array_filter(cmds(), fn($c) => preg_match('/^CREATE/i', $c)));
assert_eq(1, count($created), 'exactly one CREATE command was issued');
assert_true(strpos($created[0], 'Testing') !== false, 'CREATE names the new folder');

// The memo must have been dropped, or the sidebar keeps showing the old set.
scenario(['messages' => 0, 'folders' => ['INBOX', 'Sent', 'Drafts', 'Trash', 'Spam', 'Archive', 'Testing']]);
$names = array_column($imap->getFolders(), 'name');
assert_true(in_array('Testing', $names, true), 'the new folder appears without a reconnect');

resetLog();
assert_true($imap->deleteFolder('Testing'), 'DELETE reported as success');
$deleted = array_values(array_filter(cmds(), fn($c) => preg_match('/^DELETE\s/i', $c)));
assert_eq(1, count($deleted), 'exactly one DELETE command was issued');
assert_true(strpos($deleted[0], 'Testing') !== false, 'DELETE names the folder');

// A server that refuses must be reported as a failure, not swallowed.
scenario(['messages' => 0, 'create_fail' => true, 'delete_fail' => true]);
$imap = connect();
assert_false($imap->createFolder('Nope'), 'a server NO to CREATE is a failure');
assert_true(strpos($imap->lastError, 'refused') !== false, 'the server reason is captured');
assert_false($imap->deleteFolder('Nope'), 'a server NO to DELETE is a failure');
// c-client queues errors and PHP dumps them at shutdown, blaming whatever ran
// last. Both methods must leave the stack clean. imap_errors() returns false,
// not [], when there is nothing queued.
$leftover = @imap_errors();
assert_true($leftover === false || $leftover === [],
    'the IMAP error stack is drained on failure (' . var_export($leftover, true) . ')');

group('findDraftsFolder()');

scenario(['messages' => 0, 'folders' => ['INBOX', 'Sent', 'Drafts', 'Trash']]);
$imap = connect();
assert_eq('Drafts', $imap->findDraftsFolder(), 'the Drafts folder is found by name');

scenario(['messages' => 0, 'folders' => ['INBOX', 'Sent', 'Trash']]);
$imap = connect();
assert_eq(null, $imap->findDraftsFolder(), 'no Drafts folder reports null, not a guess');

// ============================================== reply headers ================
group('getEmailByUid() exposes the reply headers');

// Threading and Reply-To come off the ENVELOPE. handle_send has always been able
// to set In-Reply-To, but nothing produced a Message-ID to put in it, so replies
// never threaded. Assert the parsing at the wire level.
scenario(['messages' => 3, 'uidvalidity' => 99, 'reply_to' => 'list@example.com',
    'in_reply_to' => '<parent@example.com>']);
$imap = connect();
$imap->selectFolder('INBOX');
$msg = $imap->getEmailByUid(1002);
assert_eq('<m1002@example.com>', $msg['message_id'], 'the Message-ID is parsed');
assert_eq('<parent@example.com>', $msg['in_reply_to'], 'the In-Reply-To is parsed');
assert_contains('list@example.com', $msg['reply_to_address'], 'the Reply-To is parsed');
assert_true(array_key_exists('references', $msg), 'references is always present, even when the server omits it');

// A reply to that message must address the Reply-To and thread onto it.
$form = reply_form_data($msg, 'me@example.com', false);
assert_eq('list@example.com', $form['to'], 'the reply goes to the Reply-To address');
assert_eq('<m1002@example.com>', $form['in_reply_to'], 'and threads onto the message it answers');

// No Reply-To on the message: the sender is used.
scenario(['messages' => 3, 'uidvalidity' => 99]);
$imap = connect();
$imap->selectFolder('INBOX');
$msg2 = $imap->getEmailByUid(1002);
$form2 = reply_form_data($msg2, 'me@example.com', false);
assert_contains('s1002@example.com', $form2['to'], 'without Reply-To the reply goes to the sender');

// ========================================================= batch mutations ==
// The point of a batch is that N messages cost a fixed number of round trips, not
// N of them. A source grep cannot see an expunge that ran or did not, so these
// assert the command trace the stub actually received.
//
// The stub advertises no MOVE capability, and c-client 2007f has none either, so
// a move is UID COPY + UID STORE +Flags \Deleted and the wipe may arrive as
// EXPUNGE or as CLOSE. Filter both rather than naming one.
function wipeCount(): int {
    return count(array_filter(cmds(), fn($c) => preg_match('/^(EXPUNGE|CLOSE)/i', $c)));
}
function moveCount(): int {
    return count(array_filter(cmds(), fn($c) => preg_match('/^UID\s+COPY/i', $c)));
}
function storeCount(): int {
    return count(array_filter(cmds(), fn($c) => preg_match('/^UID\s+STORE/i', $c)));
}

group('batch mutations: one round trip per batch, not per UID');

scenario(['messages' => 5, 'uidvalidity' => 99]);
$imap = connect();
$imap->selectFolder('INBOX');

// One UID: the same trace a batch of one produces.
resetLog();
$ok = $imap->moveToFolder(1001, 'Archive');
assert_true($ok, 'moveToFolder reports success');
assert_eq(1, moveCount(), 'one UID is one COPY');
assert_eq(1, wipeCount(), 'and exactly one wipe');

resetLog();
$batch = $imap->moveManyToFolder([1001, 1002, 1003], 'Archive');
assert_eq(3, $batch, 'moveManyToFolder reports how many messages it moved');
assert_eq(1, moveCount(), 'three UIDs are still one COPY, not three');
assert_eq(1, wipeCount(), 'and still one wipe, not three');

resetLog();
$seen = $imap->markManyAsRead([1001, 1002, 1003, 1004], true);
assert_eq(4, $seen, 'markManyAsRead reports the batch size');
assert_eq(1, storeCount(), 'four UIDs are one UID STORE, not four');
assert_eq(0, wipeCount(), 'setting a flag must not wipe the folder');

resetLog();
$unseen = $imap->markManyAsRead([1001, 1002], false);
assert_eq(2, $unseen, 'marking a batch unread reports the batch size');
assert_eq(1, storeCount(), 'unread is one UID STORE too');
assert_eq(0, wipeCount(), 'clearing a flag must not wipe either');

resetLog();
$gone = $imap->deleteManyByUid([1001, 1002, 1003]);
assert_eq(3, $gone, 'deleteManyByUid reports the batch size');
assert_eq(1, storeCount(), 'three deletions are one UID STORE +\\Deleted');
assert_eq(1, wipeCount(), 'and one wipe');

// The 200-UID cap is a real request, not a theoretical one: one STORE, no wipes.
$many = [];
for ($i = 1; $i <= 200; $i++) { $many[] = 1000 + $i; }
resetLog();
assert_eq(200, $imap->markManyAsRead($many, true), 'a 200-UID batch reports 200');
assert_eq(1, storeCount(), 'a 200-UID batch is still one round trip');
assert_eq(0, wipeCount(), 'and still no wipe');

group('batch mutations: an empty or junk batch costs nothing');

// A batch of nothing must not become "SELECT, COPY nothing, EXPUNGE the folder".
// The second wipe would be catastrophic: it deletes unrelated \Deleted mail.
resetLog();
assert_eq(0, $imap->moveManyToFolder([], 'Archive'), 'an empty move changes nothing');
assert_eq(0, wipeCount(), 'an empty move does not wipe the folder');
assert_eq(0, $imap->markManyAsRead([], true), 'an empty mark changes nothing');
assert_eq(0, $imap->deleteManyByUid([]), 'an empty delete changes nothing');
assert_eq(0, $imap->moveManyToTrash([]), 'an empty trash move changes nothing');
assert_eq(0, moveCount() + storeCount(), 'none of them reached the server');

resetLog();
assert_eq(0, $imap->moveManyToFolder([0, -1, 'x'], 'Archive'), 'a batch of junk UIDs changes nothing');
assert_eq(0, wipeCount(), 'and still does not wipe the folder');

group('moveManyToTrash() falls back to delete when there is no Trash');

scenario(['messages' => 5, 'uidvalidity' => 99, 'folders' => ['INBOX', 'Sent', 'Archive']]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
$n = $imap->moveManyToTrash([1001, 1002]);
assert_eq(2, $n, 'without a Trash the batch still reports a count');
assert_eq(0, moveCount(), 'nothing was moved anywhere');
assert_eq(1, storeCount(), 'it was deleted instead');
assert_eq(1, wipeCount(), 'and the delete still wiped once');

scenario(['messages' => 5, 'uidvalidity' => 99]);
$imap = connect();
$imap->selectFolder('INBOX');
resetLog();
assert_eq(2, $imap->moveManyToTrash([1001, 1002]), 'with a Trash the count is the same');
assert_eq(1, moveCount(), 'and now it really is a COPY into Trash');

summary();
