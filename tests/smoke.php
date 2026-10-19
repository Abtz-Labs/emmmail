<?php
/**
 * tests/smoke.php — Tier 3 smoke test against a LIVE IMAP/SMTP server.
 * Run with: php tests/smoke.php   (or all suites: php tests/run.php)
 *
 * Uses the real MailImap + SMTP code paths, no mocks. Skips cleanly unless
 * credentials are supplied, so it is safe to run in the default suite.
 *
 * Credentials come from env vars, never hard-coded:
 *   EMMMAIL_SMOKE_HOST (or _IMAP_HOST / _SMTP_HOST), _IMAP_PORT, _SMTP_PORT,
 *   _EMAIL, _PASSWORD, _TO, and EMMMAIL_MAIL_SERVER as a fallback host.
 * `just test PRESET EMAIL [PASSWORD]` sets these for you.
 *
 * Safety: connects to YOUR mailbox and SENDS one message to EMMMAIL_SMOKE_TO.
 * It never deletes anything.
 */

require_once __DIR__ . '/_harness.php';
test_suite('smoke');

// ------------------------------------------------------------ config -+
$baseHost = getenv('EMMMAIL_MAIL_SERVER') ?: '';
$imapHost = getenv('EMMMAIL_SMOKE_IMAP_HOST') ?: getenv('EMMMAIL_SMOKE_HOST') ?: $baseHost;
$smtpHost = getenv('EMMMAIL_SMOKE_SMTP_HOST') ?: getenv('EMMMAIL_SMOKE_HOST') ?: $baseHost;
$imapPort = getenv('EMMMAIL_SMOKE_IMAP_PORT') ?: getenv('EMMMAIL_IMAP_PORT') ?: '993';
$smtpPort = getenv('EMMMAIL_SMOKE_SMTP_PORT') ?: getenv('EMMMAIL_SMTP_PORT') ?: '587';
$email    = getenv('EMMMAIL_SMOKE_EMAIL') ?: '';
$password = getenv('EMMMAIL_SMOKE_PASSWORD') ?: '';
$to       = getenv('EMMMAIL_SMOKE_TO') ?: $email;

echo "imap_host={$imapHost} imap_port={$imapPort} smtp_host={$smtpHost} smtp_port={$smtpPort} email=" . ($email ?: '(none)') . " to=" . ($to ?: '(none)') . "\n";

$imapAvailable = function_exists('imap_open');
if (!$imapAvailable) {
    echo "\n[notice] ext-imap is NOT installed — IMAP steps will be skipped.\n";
}
$hasCreds = $imapHost !== '' && $smtpHost !== '' && $email !== '' && $password !== '';

// ------------------------------------------------------------- IMAP -+
echo "\n== IMAP ==\n";
if ($imapAvailable && $hasCreds) {
    $imap = new MailImap($imapHost, (int)$imapPort, $email, $password, IMAP_ENCRYPTION);
    assert_true($imap->isConnected(), 'connect() establishes an IMAP session' . ($imap->isConnected() ? '' : ' (' . $imap->lastError . ')'));

    if ($imap->isConnected()) {
        $folders = $imap->getFolders();
        assert_true(is_array($folders) && count($folders) > 0, 'getFolders() returns the mailbox folder list');

        $imap->selectFolder('INBOX');
        $count = $imap->getEmailCount('INBOX');
        assert_true(is_int($count) && $count >= 0, 'getEmailCount(INBOX) returns a count');

        $emails = $imap->getEmails('INBOX', 1, 5);
        assert_true(is_array($emails), 'getEmails(INBOX) lists messages');

        if (count($emails) > 0) {
            $uid = (int)$emails[0]['uid'];
            $one = $imap->getEmailByUid($uid);
            assert_true(is_array($one) && !empty($one), 'getEmailByUid() fetches a full message');
            $imap->markAsUnreadByUid($uid);
            $imap->markAsReadByUid($uid);
            assert_true(true, 'markAsRead/markAsUnread round-trips without error');
        }

        $trash = $imap->findTrashFolder();
        assert_true($trash !== null, 'findTrashFolder() resolves a trash folder');

        $imap->disconnect();
    }
} elseif (!$hasCreds) {
    skip('IMAP: no EMMMAIL_SMOKE_IMAP_HOST(_SMTP_HOST)/_EMAIL/_PASSWORD provided');
} else {
    skip('IMAP: ext-imap not installed');
}

// ------------------------------------------------------------- SMTP -+
echo "\n== SMTP ==\n";
if ($hasCreds) {
    $raw = smtp_build_raw_message(
        $email,
        $to,
        'emmmail smoke test ' . date('c'),
        '<p>This is a smoke test sent by emmmail on ' . date('c') . '.</p>',
        '',
        [],
        '',
        '',
        ''
    );
    $conn = smtp_connect($smtpHost, (int)$smtpPort, $email, $password, $smtpErr);
    assert_true(is_resource($conn), 'smtp_connect() negotiates SMTP (+STARTTLS/AUTH)' . (is_resource($conn) ? '' : ' — ' . ($smtpErr ?: 'unknown')));

    if (is_resource($conn)) {
        $ok = smtp_send($conn, $email, [$to], $raw, $sendErr);
        assert_true($ok, 'smtp_send() delivers one message' . ($ok ? '' : ' — ' . ($sendErr ?: 'failed')));
        fclose($conn);
    }
} else {
    skip('SMTP: no EMMMAIL_SMOKE_SMTP_HOST(_IMAP_HOST)/_EMAIL/_PASSWORD provided');
}

summary();
