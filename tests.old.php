<?php
declare(strict_types=1);

/**
 * Emmmail — self-contained test suite for index.php.
 * Run: php tests.php  (exit 0 = all pass, 1 = any failure)
 */

/* ---------------------------------------------------------------------------
 * Bootstrap
 * ------------------------------------------------------------------------ */

$GLOBALS['TEST_DB_PATH'] = '/tmp/emmmail_test_' . uniqid() . '.sqlite';
$GLOBALS['TEST_ATTACH_DIR'] = '/tmp/emmmail_test_' . uniqid() . '/attachments';
$GLOBALS['TEST_KEY'] = bin2hex(random_bytes(32));
$GLOBALS['INDEX_PHP'] = __DIR__ . '/index.php';

putenv('EMMMAIL_DB=' . $GLOBALS['TEST_DB_PATH']);
putenv('EMMMAIL_KEY=' . $GLOBALS['TEST_KEY']);
putenv('EMMMAIL_ATTACHMENTS=' . $GLOBALS['TEST_ATTACH_DIR']);
putenv('EMMMAIL_DEBUG=1');

ob_start();
require $GLOBALS['INDEX_PHP'];
ob_end_clean();

/* ---------------------------------------------------------------------------
 * Helpers
 * ------------------------------------------------------------------------ */

$GLOBALS['TEST_PASS'] = 0;
$GLOBALS['TEST_FAIL'] = 0;
$GLOBALS['TEST_SKIP'] = 0;

function colorGreen(string $s): string { return "\033[32m" . $s . "\033[0m"; }
function colorRed(string $s): string { return "\033[31m" . $s . "\033[0m"; }
function colorYellow(string $s): string { return "\033[33m" . $s . "\033[0m"; }
function colorBold(string $s): string { return "\033[1m" . $s . "\033[0m"; }

function assertTest(bool $condition, string $message): void
{
    if ($condition) {
        $GLOBALS['TEST_PASS']++;
        echo '  ' . colorGreen('PASS') . ' ' . $message . "\n";
    } else {
        $GLOBALS['TEST_FAIL']++;
        echo '  ' . colorRed('FAIL') . ' ' . $message . "\n";
    }
}

function skipTest(string $message): void
{
    $GLOBALS['TEST_SKIP']++;
    echo '  ' . colorYellow('SKIP') . ' ' . $message . "\n";
}

function assertEquals(mixed $expected, mixed $actual, string $message): void
{
    if ($expected === $actual) {
        assertTest(true, $message);
        return;
    }
    assertTest(false, $message);
    echo '    ' . colorRed('expected: ' . var_export($expected, true)) . "\n";
    echo '    ' . colorRed('actual:   ' . var_export($actual, true)) . "\n";
}

function assertThrows(callable $fn, string $message): void
{
    try {
        $fn();
        assertTest(false, $message . ' (no exception thrown)');
    } catch (Throwable $e) {
        assertTest(true, $message . ' (' . get_class($e) . ')');
    }
}

function group(string $name): void
{
    echo "\n" . colorBold("### {$name} ###") . "\n";
}

function testHeader(): void
{
    $sep = str_repeat('=', 80);
    echo colorBold("Emmmail Test Suite") . "\n";
    echo colorBold($sep) . "\n";
}

function summary(): void
{
    $pass = $GLOBALS['TEST_PASS'];
    $fail = $GLOBALS['TEST_FAIL'];
    $skip = $GLOBALS['TEST_SKIP'];
    $total = $pass + $fail + $skip;
    $sep = str_repeat('=', 80);
    echo "\n" . colorBold($sep) . "\n";
    echo colorGreen("{$pass} PASSED.") . ' ';
    if ($fail > 0) echo colorRed("{$fail} FAILED.") . ' ';
    else echo "{$fail} FAILED. ";
    if ($skip > 0) echo colorYellow("{$skip} SKIP.") . ' ';
    else echo "{$skip} SKIP. ";
    echo "\n" . colorBold("TOTAL: {$total} TEST CASES.") . "\n";
    echo colorBold($sep) . "\n";
    exit($fail === 0 ? 0 : 1);
}

function withDbGroup(callable $fn): void
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $fn($pdo);
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

function createTestAccount(PDO $pdo, ?string $email = null): int
{
    $email ??= 'acct' . uniqid() . '@test.com';
    $enc = encryptPassword('pw', getEncryptionKey());
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, 'Test Account', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);
    return (int) $pdo->lastInsertId();
}

function imapReflector(): object
{
    static $pair = null;
    if ($pair === null) {
        $imap = new ImapClient('localhost', 993, 1, 'test@test.com', 'pass');
        $tokenize = new ReflectionMethod($imap, 'tokenize');
        $parseFetchItems = new ReflectionMethod($imap, 'parseFetchItems');
        if (PHP_VERSION_ID < 80100) {
            $tokenize->setAccessible(true);
            $parseFetchItems->setAccessible(true);
        }
        $pair = (object) ['imap' => $imap, 'tokenize' => $tokenize, 'parseFetchItems' => $parseFetchItems];
    }
    return $pair;
}

/** Runs a code body in a separate PHP process against the same test DB. */
function runInSubprocess(string $body): string
{
    $script = sys_get_temp_dir() . '/emmmail_sub_' . uniqid() . '.php';
    $code = '<?php' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DB=' . $GLOBALS['TEST_DB_PATH'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_KEY=' . $GLOBALS['TEST_KEY'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_ATTACHMENTS=' . $GLOBALS['TEST_ATTACH_DIR'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DEBUG=1', true) . ');' . "\n"
        . "ob_start();\n"
        . 'require ' . var_export($GLOBALS['INDEX_PHP'], true) . ";\n"
        . "ob_end_clean();\n"
        . $body . "\n";
    file_put_contents($script, $code);
    exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
    @unlink($script);
    return implode("\n", $output);
}

function rmrf(string $path): void
{
    if (!is_dir($path)) return;
    foreach (glob(rtrim($path, '/') . '/*') ?: [] as $f) {
        is_dir($f) ? rmrf($f) : @unlink($f);
    }
    @rmdir($path);
}

function cleanupTestArtifacts(): void
{
    foreach ([$GLOBALS['TEST_DB_PATH'], $GLOBALS['TEST_DB_PATH'] . '-wal', $GLOBALS['TEST_DB_PATH'] . '-shm'] as $f) {
        if (is_file($f)) @unlink($f);
    }
    $attachParent = dirname($GLOBALS['TEST_ATTACH_DIR']);
    rmrf($GLOBALS['TEST_ATTACH_DIR']);
    @rmdir($attachParent);
}
register_shutdown_function('cleanupTestArtifacts');

/* ---------------------------------------------------------------------------
 * GROUP: .env Parser
 * ------------------------------------------------------------------------ */

function testEnvParser(): void
{
    group('.env Parser');

    $file = sys_get_temp_dir() . '/emm_env_' . uniqid() . '.env';
    file_put_contents($file, "DB_PATH=/var/data.sqlite\nLOGIN_MAX=5\n");
    $env = parseEnvFile($file);
    assertEquals('/var/data.sqlite', $env['DB_PATH'] ?? null, 'parseEnvFile with valid file');
    assertEquals('5', $env['LOGIN_MAX'] ?? null, 'parseEnvFile parses value');
    @unlink($file);

    $file = sys_get_temp_dir() . '/emm_env_' . uniqid() . '.env';
    file_put_contents($file, "# comment line\n\n\n   \nFOO=bar\nEMPTY=\n");
    $env = parseEnvFile($file);
    assertEquals('bar', $env['FOO'] ?? null, 'parseEnvFile with comments and empty lines');
    assertTest(isset($env['EMPTY']) && $env['EMPTY'] === '', 'parseEnvFile keeps empty value');
    assertTest(!isset($env['# comment line']), 'parseEnvFile ignores comments');
    assertTest(!isset($env['']), 'parseEnvFile skips blank lines');
    @unlink($file);

    $file = sys_get_temp_dir() . '/emm_env_' . uniqid() . '.env';
    file_put_contents($file, "NAME=\"John Doe\"\nSINGLE='Jane'\n");
    $env = parseEnvFile($file);
    assertEquals('John Doe', $env['NAME'] ?? null, 'parseEnvFile with quoted values (double)');
    assertEquals('Jane', $env['SINGLE'] ?? null, 'parseEnvFile with quoted values (single)');
    @unlink($file);

    $missing = sys_get_temp_dir() . '/definitely_missing_' . uniqid() . '.env';
    assertEquals([], parseEnvFile($missing), 'parseEnvFile with missing file');
}

/* ---------------------------------------------------------------------------
 * GROUP: Encryption
 * ------------------------------------------------------------------------ */

function testEncryption(): void
{
    group('Encryption');

    $key = getEncryptionKey();
    $enc = encryptPassword('SuperSecret', $key);
    assertTest(
        is_array($enc) && isset($enc['encrypted'], $enc['iv'], $enc['tag'])
            && $enc['encrypted'] !== '' && $enc['iv'] !== '' && $enc['tag'] !== '',
        'encryptPassword returns array with encrypted/iv/tag'
    );

    $account = ['password_encrypted' => $enc['encrypted'], 'password_iv' => $enc['iv'], 'password_tag' => $enc['tag']];
    assertEquals('SuperSecret', decryptPassword($account, $key), 'decryptPassword round-trips correctly');

    assertEquals('', decryptPassword(['password_encrypted' => '!!!', 'password_iv' => 'AA==', 'password_tag' => 'BB=='], $key), 'decryptPassword returns empty string for invalid data');
    assertEquals('', decryptPassword([], $key), 'decryptPassword returns empty string for missing data');

    $enc2 = encryptPassword('OtherPassword', $key);
    assertTest($enc['encrypted'] !== $enc2['encrypted'], 'Different passwords produce different ciphertext');

    assertThrows(fn() => @encryptPassword('pw', 'zz'), 'encryptPassword throws on invalid key');
}

/* ---------------------------------------------------------------------------
 * GROUP: Database Schema
 * ------------------------------------------------------------------------ */

function testDatabaseSchema(): void
{
    group('Database Schema');

    $pdo = db();
    $expected = ['login_attempts', 'accounts', 'folders', 'threads', 'messages', 'messages_fts', 'attachments', 'contacts', 'tags', 'message_tags', 'drafts', 'outbox', 'settings'];
    $rows = fetchAll($pdo, "SELECT name FROM sqlite_master WHERE type = 'table'");
    $names = array_column($rows, 'name');
    foreach ($expected as $t) {
        assertTest(in_array($t, $names, true), "initDatabase creates table: {$t}");
    }

    assertEquals('wal', $pdo->query('PRAGMA journal_mode')->fetchColumn(), 'WAL mode enabled');
    assertEquals(1, (int) $pdo->query('PRAGMA foreign_keys')->fetchColumn(), 'Foreign keys enabled');

    try {
        initDatabase($pdo);
        $fresh = new PDO('sqlite:' . EMMMAIL_DB_PATH, null, null, [PDO::ATTR_TIMEOUT => 5]);
        $fresh->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        initDatabase($fresh);
        assertTest(true, 'initDatabase is idempotent (run twice, no error)');
    } catch (Throwable $e) {
        assertTest(false, 'initDatabase is idempotent: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------------------
 * GROUP: IMAP Role Detection
 * ------------------------------------------------------------------------ */

function testImapRoleDetection(): void
{
    group('IMAP Role Detection');

    assertEquals('inbox', ImapClient::detectRole('INBOX', ['\Inbox']), 'detectRole with \Inbox flag');
    assertEquals('sent', ImapClient::detectRole('Whatever', ['\Sent']), 'detectRole with \Sent flag');
    assertEquals('drafts', ImapClient::detectRole('Whatever', ['\Drafts']), 'detectRole with \Drafts flag');
    assertEquals('trash', ImapClient::detectRole('Whatever', ['\Trash']), 'detectRole with \Trash flag');
    assertEquals('junk', ImapClient::detectRole('Whatever', ['\Junk']), 'detectRole with \Junk flag');
    assertEquals('archive', ImapClient::detectRole('Whatever', ['\Archive']), 'detectRole with \Archive flag');

    assertEquals('inbox', ImapClient::detectRole('inbox', []), 'detectRole fallback by name ("inbox")');
    assertEquals('sent', ImapClient::detectRole('Sent Messages', []), 'detectRole fallback by name ("Sent Messages")');
    assertEquals('trash', ImapClient::detectRole('Deleted Messages', []), 'detectRole fallback by name ("Deleted Messages")');
    assertEquals('junk', ImapClient::detectRole('Spam', []), 'detectRole fallback by name ("Spam")');
    assertEquals('archive', ImapClient::detectRole('All Mail', []), 'detectRole fallback by name ("All Mail")');
    assertEquals(null, ImapClient::detectRole('Random Folder', []), 'detectRole returns null for unknown');
}

/* ---------------------------------------------------------------------------
 * GROUP: IMAP Tokenizer (via ReflectionMethod)
 * ------------------------------------------------------------------------ */

function testImapTokenizer(): void
{
    group('IMAP Tokenizer (via ReflectionMethod)');

    $r = imapReflector();
    assertEquals(['hello', 'world', 'foo'], $r->tokenize->invoke($r->imap, 'hello world foo'), 'tokenize simple words');
    assertEquals(['quoted string', 'here'], $r->tokenize->invoke($r->imap, '"quoted string" here'), 'tokenize quoted strings');
    assertEquals(['say \"hi\"', 'x'], $r->tokenize->invoke($r->imap, '"say \"hi\"" x'), 'tokenize escaped quotes');
    assertEquals(['(FLAGS (\\Seen \\Flagged))'], $r->tokenize->invoke($r->imap, '(FLAGS (\\Seen \\Flagged))'), 'tokenize parentheses groups');
    assertEquals(['(A (B (C)))'], $r->tokenize->invoke($r->imap, '(A (B (C)))'), 'tokenize nested parentheses');
    assertEquals([], $r->tokenize->invoke($r->imap, ''), 'tokenize empty string');
}

/* ---------------------------------------------------------------------------
 * GROUP: IMAP Fetch Items Parser (via ReflectionMethod)
 * ------------------------------------------------------------------------ */

function testImapFetchItemsParser(): void
{
    group('IMAP Fetch Items Parser (via ReflectionMethod)');

    $r = imapReflector();
    assertEquals(
        ['UID' => '1', 'FLAGS' => ['\Seen', '\Flagged']],
        $r->parseFetchItems->invoke($r->imap, 'UID 1 FLAGS (\\Seen \\Flagged)'),
        'parseFetchItems with FLAGS'
    );
    assertEquals(['UID' => '42'], $r->parseFetchItems->invoke($r->imap, 'UID 42'), 'parseFetchItems with UID');
    assertEquals(
        ['UID' => '42', 'FLAGS' => ['\Seen'], 'SIZE' => '100'],
        $r->parseFetchItems->invoke($r->imap, 'UID 42 FLAGS (\\Seen) SIZE 100'),
        'parseFetchItems with multiple items'
    );
    assertEquals(['FLAGS' => ['\Seen', '\Answered']], $r->parseFetchItems->invoke($r->imap, 'FLAGS (\\Seen \\Answered)'), 'parseFetchItems with flag list');
    assertEquals(['FLAGS' => []], $r->parseFetchItems->invoke($r->imap, 'FLAGS ()'), 'parseFetchItems with empty flag list');
}

/* ---------------------------------------------------------------------------
 * GROUP: Header Parsing
 * ------------------------------------------------------------------------ */

function testHeaderParsing(): void
{
    group('Header Parsing');

    $h = parseRawHeaders("Subject: Hello\r\nFrom: a@b.com");
    assertEquals('Hello', $h['subject'] ?? null, 'parseRawHeaders simple');
    assertEquals('a@b.com', $h['from'] ?? null, 'parseRawHeaders parses from');

    $h = parseRawHeaders("Subject: Hello\r\n\tWorld\r\nFrom: a@b.com");
    assertEquals('Hello World', $h['subject'] ?? null, 'parseRawHeaders with continuation lines (folded)');

    $h = parseRawHeaders("SUBJECT: Hi\r\nCONTENT-TYPE: text/plain");
    assertTest(isset($h['subject']) && $h['subject'] === 'Hi', 'parseRawHeaders case-insensitive keys (subject)');
    assertTest(isset($h['content-type']) && $h['content-type'] === 'text/plain', 'parseRawHeaders case-insensitive keys (content-type)');

    assertEquals('', headerValue([], 'missing'), 'headerValue returns empty string for missing key');
}

/* ---------------------------------------------------------------------------
 * GROUP: Boundary Extraction
 * ------------------------------------------------------------------------ */

function testBoundaryExtraction(): void
{
    group('Boundary Extraction');

    assertEquals('abc', extractBoundary('multipart/mixed; boundary="abc"'), 'extractBoundary with quoted value');
    assertEquals('abc', extractBoundary('multipart/mixed; boundary=abc'), 'extractBoundary with unquoted value');
    assertEquals(null, extractBoundary('text/plain'), 'extractBoundary returns null when missing');
}

/* ---------------------------------------------------------------------------
 * GROUP: Transfer Encoding
 * ------------------------------------------------------------------------ */

function testTransferEncoding(): void
{
    group('Transfer Encoding');

    assertEquals('hello world', decodeTransfer(base64_encode('hello world'), 'base64'), 'decodeTransfer base64');
    assertEquals('Hello World', decodeTransfer('Hello=20World', 'quoted-printable'), 'decodeTransfer quoted-printable');
    assertEquals('plain text', decodeTransfer('plain text', '7bit'), 'decodeTransfer passthrough (7bit)');
    assertEquals('plain text', decodeTransfer('plain text', '8bit'), 'decodeTransfer passthrough (8bit)');
    assertEquals('plain text', decodeTransfer('plain text', ''), 'decodeTransfer passthrough (empty encoding)');
}

/* ---------------------------------------------------------------------------
 * GROUP: Charset Conversion
 * ------------------------------------------------------------------------ */

function testCharsetConversion(): void
{
    group('Charset Conversion');

    assertEquals('hello', convertCharset('hello', 'UTF-8'), 'convertCharset UTF-8 passthrough');
    assertEquals('hello', convertCharset('hello', 'utf-8'), 'convertCharset lowercase utf-8 passthrough');
    assertEquals('', convertCharset('', 'utf-8'), 'convertCharset empty string passthrough');
    assertEquals('hello', convertCharset('hello', ''), 'convertCharset empty charset passthrough');

    if (function_exists('mb_convert_encoding')) {
        assertEquals('é', convertCharset("\xE9", 'ISO-8859-1'), 'convertCharset ISO-8859-1 to UTF-8');
    } else {
        skipTest('convertCharset ISO-8859-1 to UTF-8 (mb_convert_encoding unavailable)');
    }
}

/* ---------------------------------------------------------------------------
 * GROUP: MIME Header Decoding
 * ------------------------------------------------------------------------ */

function testMimeHeaderDecoding(): void
{
    group('MIME Header Decoding');

    assertEquals('Hello', decodeMimeHeader('Hello'), 'decodeMimeHeader plain ASCII');
    assertEquals('Café', decodeMimeHeader('=?UTF-8?B?' . base64_encode('Café') . '?='), 'decodeMimeHeader RFC 2047 B encoded (UTF-8)');
    assertEquals('Café', decodeMimeHeader('=?UTF-8?Q?Caf=C3=A9?='), 'decodeMimeHeader RFC 2047 Q encoded');
    assertEquals('Hello World', decodeMimeHeader('Hello =?UTF-8?B?' . base64_encode('World') . '?='), 'decodeMimeHeader mixed plain + encoded');
}

/* ---------------------------------------------------------------------------
 * GROUP: Address Parsing
 * ------------------------------------------------------------------------ */

function testAddressParsing(): void
{
    group('Address Parsing');

    assertEquals(
        [['name' => 'John Doe', 'email' => 'john@example.com']],
        parseAddresses('John Doe <john@example.com>'),
        'parseAddresses single "Name <email>"'
    );
    assertEquals(
        [['name' => '', 'email' => 'bare@example.com']],
        parseAddresses('bare@example.com'),
        'parseAddresses bare email'
    );
    assertEquals(
        [['name' => '', 'email' => 'a@b.com'], ['name' => '', 'email' => 'c@d.com']],
        parseAddresses('a@b.com, c@d.com'),
        'parseAddresses multiple addresses'
    );
    assertEquals(
        [['name' => 'John', 'email' => 'j@e.com'], ['name' => '', 'email' => 'other@x.com']],
        parseAddresses('"John" <j@e.com>, other@x.com'),
        'parseAddresses mixed formats'
    );
    assertEquals([], parseAddresses(''), 'parseAddresses empty string');
}

/* ---------------------------------------------------------------------------
 * GROUP: Filename Sanitization
 * ------------------------------------------------------------------------ */

function testFilenameSanitization(): void
{
    group('Filename Sanitization');

    assertEquals('report.pdf', sanitizeFilename('report.pdf'), 'sanitizeFilename normal name');
    assertEquals('a_b_c.txt', sanitizeFilename('a/b\\c.txt'), 'sanitizeFilename with path separators');
    assertEquals('evilname.txt', sanitizeFilename("evil\0name.txt"), 'sanitizeFilename with null bytes');
    assertEquals('report.final.pdf', sanitizeFilename('report...final.pdf'), 'sanitizeFilename with double dots');
    assertEquals('badname.txt', sanitizeFilename("bad\x01\x1Fname.txt"), 'sanitizeFilename with control chars');
    assertEquals('attachment', sanitizeFilename(''), 'sanitizeFilename empty string returns "attachment"');
}

/* ---------------------------------------------------------------------------
 * GROUP: Date Normalization
 * ------------------------------------------------------------------------ */

function testDateNormalization(): void
{
    group('Date Normalization');

    assertEquals('2020-08-03T12:00:00Z', normalizeDate('Mon, 03 Aug 2020 12:00:00 +0000'), 'normalizeDate valid RFC 2822 date');
    assertEquals(gmdate('Y-m-d\TH:i:s\Z'), normalizeDate(''), 'normalizeDate empty string returns current time');
    assertEquals(gmdate('Y-m-d\TH:i:s\Z'), normalizeDate('not a valid date'), 'normalizeDate invalid string returns current time');
}

/* ---------------------------------------------------------------------------
 * GROUP: MIME Parser
 * ------------------------------------------------------------------------ */

function testMimeParser(): void
{
    group('MIME Parser');

    $r = parseMimeMessage("Content-Type: text/plain; charset=utf-8\r\n\r\nHello world");
    assertEquals('Hello world', $r['text_body'], 'parseMimeMessage simple text/plain');

    $r = parseMimeMessage("Content-Type: text/html; charset=utf-8\r\n\r\n<p>Hello</p>");
    assertEquals('<p>Hello</p>', $r['html_body'], 'parseMimeMessage text/html');

    $alt = "Content-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
        . "--ALT\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nplain text\r\n"
        . "--ALT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>html</p>\r\n"
        . "--ALT--\r\n";
    $r = parseMimeMessage($alt);
    assertEquals('plain text', $r['text_body'], 'parseMimeMessage multipart/alternative (text)');
    assertEquals('<p>html</p>', $r['html_body'], 'parseMimeMessage multipart/alternative (html)');

    $mix = "Content-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n"
        . "--MIX\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nbody here\r\n"
        . "--MIX\r\nContent-Type: application/pdf; name=\"file.pdf\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"file.pdf\"\r\n\r\nJVBERi0xLg==\r\n"
        . "--MIX--\r\n";
    $r = parseMimeMessage($mix);
    assertEquals('body here', $r['text_body'], 'parseMimeMessage multipart/mixed text');
    assertEquals(1, count($r['attachments']), 'parseMimeMessage multipart/mixed with attachment');
    assertEquals('file.pdf', $r['attachments'][0]['filename'], 'parseMimeMessage attachment filename');
    assertEquals('%PDF-1.', $r['attachments'][0]['data'], 'parseMimeMessage attachment base64 decoded');

    $nested = "Content-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n"
        . "--MIX\r\nContent-Type: multipart/alternative; boundary=\"ALT\"\r\n\r\n"
        . "--ALT\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<b>nested html</b>\r\n"
        . "--ALT\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nnested plain\r\n"
        . "--ALT--\r\n"
        . "--MIX\r\nContent-Type: text/plain; charset=utf-8\r\n\r\ntail plain\r\n"
        . "--MIX--\r\n";
    $r = parseMimeMessage($nested);
    assertEquals('tail plain', $r['text_body'], 'parseMimeMessage nested multipart text');
    assertEquals('<b>nested html</b>', $r['html_body'], 'parseMimeMessage nested multipart html');

    $b64Part = "--MIX\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode('encoded body here') . "\r\n--MIX--\r\n";
    $r = parseMimeMessage("Content-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n" . $b64Part);
    assertEquals('encoded body here', $r['text_body'], 'parseMimeMessage base64 encoded body');

    $qpPart = "--MIX\r\nContent-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\nCaf=C3=A9 time\r\n--MIX--\r\n";
    $r = parseMimeMessage("Content-Type: multipart/mixed; boundary=\"MIX\"\r\n\r\n" . $qpPart);
    assertEquals("Café time", $r['text_body'], 'parseMimeMessage quoted-printable body');

    $r = parseMimeMessage("Subject: Test\r\n\r\nNo content type body");
    assertEquals('No content type body', $r['text_body'], 'parseMimeMessage no content-type header');
}

/* ---------------------------------------------------------------------------
 * GROUP: Subject Normalization
 * ------------------------------------------------------------------------ */

function testSubjectNormalization(): void
{
    group('Subject Normalization');

    assertEquals('hello', normalizeSubject('Re: Hello'), 'normalizeSubject "Re: Hello"');
    assertEquals('hello', normalizeSubject('Fwd: Hello'), 'normalizeSubject "Fwd: Hello"');
    assertEquals('hello', normalizeSubject('Re: Re: Hello'), 'normalizeSubject "Re: Re: Hello"');
    assertEquals('hello', normalizeSubject('Fw: Hello'), 'normalizeSubject "Fw: Hello"');
    assertEquals('hello', normalizeSubject('RE: Hello'), 'normalizeSubject case insensitive');
    assertEquals('hello world', normalizeSubject('Hello World'), 'normalizeSubject no prefix');
}

/* ---------------------------------------------------------------------------
 * GROUP: Display Name Derivation
 * ------------------------------------------------------------------------ */

function testDisplayNameDerivation(): void
{
    group('Display Name Derivation');

    assertEquals('User', deriveDisplayName('user@example.com'), 'deriveDisplayName "user@example.com"');
    assertEquals('John Doe', deriveDisplayName('john.doe@example.com'), 'deriveDisplayName "john.doe@example.com"');
    assertEquals('First Last', deriveDisplayName('first-last@example.com'), 'deriveDisplayName "first-last@example.com"');
}

/* ---------------------------------------------------------------------------
 * GROUP: HTML Escaping
 * ------------------------------------------------------------------------ */

function testHtmlEscaping(): void
{
    group('HTML Escaping');

    assertEquals('&lt;b&gt;hi&lt;/b&gt;', escapeHtml('<b>hi</b>'), 'escapeHtml with tags');
    assertEquals('say &quot;hi&quot; &amp; bye', escapeHtml('say "hi" & bye'), 'escapeHtml with quotes');
    assertEquals('a&#039;b&quot;c', escapeAttr("a'b\"c"), 'escapeAttr with special chars');
    assertEquals('', escapeHtml(null), 'escapeHtml null returns empty');
}

/* ---------------------------------------------------------------------------
 * GROUP: Blocked Attachments
 * ------------------------------------------------------------------------ */

function testBlockedAttachments(): void
{
    group('Blocked Attachments');

    assertTest(isBlockedAttachment('script.php') === true, 'isBlockedAttachment "script.php"');
    assertTest(isBlockedAttachment('image.jpg') === false, 'isBlockedAttachment "image.jpg" not blocked');
    assertTest(isBlockedAttachment('malware.exe') === true, 'isBlockedAttachment "malware.exe"');
    assertTest(isBlockedAttachment('document.pdf') === false, 'isBlockedAttachment "document.pdf" not blocked');
}

/* ---------------------------------------------------------------------------
 * GROUP: Header Value Cleaning
 * ------------------------------------------------------------------------ */

function testHeaderValueCleaning(): void
{
    group('Header Value Cleaning');

    assertEquals('line1  line2', cleanHeaderValue("line1\r\nline2"), 'cleanHeaderValue removes newlines');
    assertEquals('abc', cleanHeaderValue("a\x01b\x1Fc"), 'cleanHeaderValue removes control chars');
    assertEquals('hello', cleanHeaderValue('  hello  '), 'cleanHeaderValue trims whitespace');
}

/* ---------------------------------------------------------------------------
 * GROUP: Header Encoding
 * ------------------------------------------------------------------------ */

function testHeaderEncoding(): void
{
    group('Header Encoding');

    assertEquals('plain ASCII', encodeHeader('plain ASCII'), 'encodeHeader ASCII passthrough');
    $enc = encodeHeader('Café');
    assertTest(str_starts_with($enc, '=?UTF-8?B?'), 'encodeHeader UTF-8 becomes RFC 2047 B');
}

/* ---------------------------------------------------------------------------
 * GROUP: Recipient Sanitization
 * ------------------------------------------------------------------------ */

function testRecipientSanitization(): void
{
    group('Recipient Sanitization');

    $r = sanitizeRecipients([['email' => 'a@b.com', 'name' => 'A'], ['email' => 'c@d.com']]);
    assertEquals(2, count($r), 'sanitizeRecipients valid list');
    assertEquals('A', $r[0]['name'], 'sanitizeRecipients keeps name');
    assertEquals('c@d.com', $r[1]['email'], 'sanitizeRecipients keeps bare email');

    $r = sanitizeRecipients([['email' => 'not-an-email'], ['email' => 'valid@x.com']]);
    assertEquals(1, count($r), 'sanitizeRecipients invalid emails filtered');
    assertEquals('valid@x.com', $r[0]['email'], 'sanitizeRecipients keeps valid only');

    $r = sanitizeRecipients(['a@b.com', ['email' => 'c@d.com', 'name' => 'C']]);
    assertEquals(2, count($r), 'sanitizeRecipients mixed array/string');
    assertEquals('C', $r[1]['name'], 'sanitizeRecipients mixed keeps name');
}

/* ---------------------------------------------------------------------------
 * GROUP: Address List Formatting
 * ------------------------------------------------------------------------ */

function testAddressListFormatting(): void
{
    group('Address List Formatting');

    assertEquals('John <j@e.com>', formatAddressList([['name' => 'John', 'email' => 'j@e.com']]), 'formatAddressList with name + email');
    assertEquals('<j@e.com>', formatAddressList([['name' => '', 'email' => 'j@e.com']]), 'formatAddressList email only');
    assertEquals('', formatAddressList([]), 'formatAddressList empty list');
}

/* ---------------------------------------------------------------------------
 * GROUP: FTS5 Search
 * ------------------------------------------------------------------------ */

function testFtsSearch(): void
{
    group('FTS5 Search');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        $raw = "Date: Mon, 03 Aug 2020 12:00:00 +0000\r\nFrom: Alice <alice@test.com>\r\nSubject: Quarterly Report\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nThe quarterly numbers were discussed in depth";
        storeMessage($pdo, $acctId, 'INBOX', 1, parseMimeMessage($raw), [], $raw);

        $ids = $pdo->query("SELECT rowid FROM messages_fts WHERE messages_fts MATCH 'quarterly'")->fetchAll(PDO::FETCH_COLUMN);
        assertEquals(1, count($ids), 'insert message, search by subject finds it');

        $ids = $pdo->query("SELECT rowid FROM messages_fts WHERE messages_fts MATCH 'discussed'")->fetchAll(PDO::FETCH_COLUMN);
        assertEquals(1, count($ids), 'search by body text finds it');

        $ids = $pdo->query("SELECT rowid FROM messages_fts WHERE messages_fts MATCH 'nonexistentterm'")->fetchAll(PDO::FETCH_COLUMN);
        assertEquals(0, count($ids), 'search returns empty for no match');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Account CRUD
 * ------------------------------------------------------------------------ */

function testAccountCrud(): void
{
    group('Account CRUD');

    withDbGroup(function (PDO $pdo) {
        $email = 'crud' . uniqid() . '@test.com';
        $acctId = createTestAccount($pdo, $email);
        assertTest($acctId > 0, 'insert account');

        $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$acctId]);
        assertEquals($email, $acct['email'] ?? null, 'fetch account by id');
        assertEquals('Test Account', $acct['display_name'] ?? null, 'fetch account display_name');

        $raw = "Date: Mon, 03 Aug 2020 12:00:00 +0000\r\nFrom: x@test.com\r\nSubject: Cascade Test\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nbody";
        $mid = storeMessage($pdo, $acctId, 'INBOX', 1, parseMimeMessage($raw), [], $raw);
        $pdo->prepare('INSERT INTO folders (account_id, name, role) VALUES (?, ?, ?)')->execute([$acctId, 'INBOX', 'inbox']);
        $pdo->prepare('INSERT INTO drafts (account_id, subject) VALUES (?, ?)')->execute([$acctId, 'draft']);
        $pdo->prepare('INSERT INTO outbox (account_id, to_addresses, subject, body, send_at) VALUES (?, ?, ?, ?, datetime("now"))')->execute([$acctId, '[]', 's', 'b']);
        $pdo->prepare('INSERT INTO tags (account_id, name) VALUES (?, ?)')->execute([$acctId, 'Tag']);
        $tagId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$mid, $tagId]);
        $pdo->prepare('INSERT INTO contacts (account_id, email, name) VALUES (?, ?, ?)')->execute([$acctId, 'c@test.com', 'C']);

        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$acctId]);

        foreach (['folders', 'messages', 'threads', 'contacts', 'tags', 'drafts', 'outbox'] as $tbl) {
            $c = fetchOne($pdo, "SELECT COUNT(*) AS c FROM {$tbl} WHERE account_id = ?", [$acctId]);
            assertEquals(0, (int) $c['c'], "delete account cascades ({$tbl})");
        }
        $c = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM message_tags WHERE tag_id = ?', [$tagId]);
        assertEquals(0, (int) $c['c'], 'delete account cascades (message_tags)');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Message + Thread
 * ------------------------------------------------------------------------ */

function testMessageThread(): void
{
    group('Message + Thread');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        $threadKey = hash('sha1', 'hello world');

        $raw1 = "Date: Mon, 03 Aug 2020 12:00:00 +0000\r\nFrom: Alice <alice@test.com>\r\nTo: Bob <bob@test.com>\r\nSubject: Hello World\r\nMessage-ID: <abc@test.com>\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nFirst message body";
        $mid1 = storeMessage($pdo, $acctId, 'INBOX', 1, parseMimeMessage($raw1), ['\Seen'], $raw1);
        $thread = fetchOne($pdo, 'SELECT * FROM threads WHERE account_id = ? AND thread_key = ?', [$acctId, $threadKey]);
        assertTest($thread !== null, 'storeMessage creates message + thread');
        $msg = fetchOne($pdo, 'SELECT * FROM messages WHERE id = ?', [$mid1]);
        assertEquals('Hello World', $msg['subject'] ?? null, 'stored message subject');
        assertEquals('alice@test.com', $msg['from_address'] ?? null, 'stored message from_address');
        assertEquals(1, (int) $thread['message_count'], 'thread message_count starts at 1');

        $raw1b = "Date: Mon, 03 Aug 2020 13:00:00 +0000\r\nFrom: Alice <alice@test.com>\r\nSubject: Hello World\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nX";
        $mid1b = storeMessage($pdo, $acctId, 'INBOX', 1, parseMimeMessage($raw1b), ['\Flagged'], $raw1b);
        assertEquals($mid1, $mid1b, 'storeMessage same UID returns existing id');
        $m = fetchOne($pdo, 'SELECT is_read, is_starred FROM messages WHERE id = ?', [$mid1b]);
        assertEquals(0, (int) $m['is_read'], 'storeMessage same UID updates flags (is_read)');
        assertEquals(1, (int) $m['is_starred'], 'storeMessage same UID updates flags (is_starred)');

        $raw2 = "Date: Tue, 04 Aug 2020 12:00:00 +0000\r\nFrom: Alice <alice@test.com>\r\nSubject: Hello World\r\nContent-Type: text/plain; charset=utf-8\r\n\r\nLatest body preview text";
        $mid2 = storeMessage($pdo, $acctId, 'INBOX', 2, parseMimeMessage($raw2), [], $raw2);
        assertTest($mid2 !== $mid1, 'storeMessage different UID creates new message');
        updateThreadAggregates($pdo, (int) $thread['id']);
        $t = fetchOne($pdo, 'SELECT * FROM threads WHERE id = ?', [(int) $thread['id']]);
        assertEquals(2, (int) $t['message_count'], 'updateThreadAggregates counts correctly');
        assertEquals(0, (int) $t['is_read'], 'updateThreadAggregates marks thread unread');
        assertEquals('Latest body preview text', $t['preview'] ?? null, 'thread preview generated from last message');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Contacts
 * ------------------------------------------------------------------------ */

function testContacts(): void
{
    group('Contacts');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        updateContacts($pdo, $acctId, [['email' => 'friend@test.com', 'name' => 'Friend']]);
        $c = fetchOne($pdo, 'SELECT * FROM contacts WHERE account_id = ? AND email = ?', [$acctId, 'friend@test.com']);
        assertTest($c !== null, 'insert contact');
        assertEquals(1, (int) $c['use_count'], 'contact use_count starts at 1');

        updateContacts($pdo, $acctId, [['email' => 'friend@test.com', 'name' => 'Friend']]);
        $c = fetchOne($pdo, 'SELECT * FROM contacts WHERE account_id = ? AND email = ?', [$acctId, 'friend@test.com']);
        assertEquals(2, (int) $c['use_count'], 'upsert increments use_count');
        assertEquals($acctId, (int) $c['account_id'], 'contact linked to account');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Tags
 * ------------------------------------------------------------------------ */

function testTags(): void
{
    group('Tags');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        $pdo->prepare('INSERT INTO tags (account_id, name) VALUES (?, ?)')->execute([$acctId, 'Important']);
        $tagId = (int) $pdo->lastInsertId();
        assertTest($tagId > 0, 'create tag');

        $pdo->prepare('INSERT INTO messages (account_id, folder_name, uid, subject, date) VALUES (?, ?, ?, ?, ?)')->execute([$acctId, 'INBOX', 1, 'M', '2020-01-01T00:00:00Z']);
        $mid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$mid, $tagId]);
        $link = fetchOne($pdo, 'SELECT * FROM message_tags WHERE message_id = ? AND tag_id = ?', [$mid, $tagId]);
        assertTest($link !== null, 'tag message');

        $pdo->prepare('DELETE FROM message_tags WHERE message_id = ? AND tag_id = ?')->execute([$mid, $tagId]);
        $link = fetchOne($pdo, 'SELECT * FROM message_tags WHERE message_id = ? AND tag_id = ?', [$mid, $tagId]);
        assertTest($link === null, 'untag message');

        $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$mid, $tagId]);
        $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$tagId]);
        $link = fetchOne($pdo, 'SELECT * FROM message_tags WHERE tag_id = ?', [$tagId]);
        assertTest($link === null, 'delete tag cascades');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Drafts
 * ------------------------------------------------------------------------ */

function testDrafts(): void
{
    group('Drafts');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        $pdo->prepare('INSERT INTO drafts (account_id, subject, body) VALUES (?, ?, ?)')->execute([$acctId, 'My Draft', 'Draft body']);
        $draftId = (int) $pdo->lastInsertId();
        assertTest($draftId > 0, 'insert draft');

        $d = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ?', [$draftId]);
        assertEquals('My Draft', $d['subject'] ?? null, 'insert draft stores subject');

        $all = fetchAll($pdo, 'SELECT * FROM drafts WHERE account_id = ?', [$acctId]);
        assertEquals(1, count($all), 'list drafts');

        $pdo->prepare('DELETE FROM drafts WHERE id = ?')->execute([$draftId]);
        $d = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ?', [$draftId]);
        assertTest($d === null, 'delete draft');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Outbox
 * ------------------------------------------------------------------------ */

function testOutbox(): void
{
    group('Outbox');

    withDbGroup(function (PDO $pdo) {
        $acctId = createTestAccount($pdo);
        $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(['bad' . uniqid() . '@test.com', 'Bad', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, '!!!', '@@@', '###']);
        $badAcctId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO outbox (account_id, to_addresses, subject, body, send_at, status) VALUES (?, ?, ?, ?, datetime("now"), "pending")')
            ->execute([$badAcctId, '[{"email":"x@y.com"}]', 'Hello', 'Body']);
        $oId = (int) $pdo->lastInsertId();
        $row = fetchOne($pdo, 'SELECT * FROM outbox WHERE id = ?', [$oId]);
        assertEquals('pending', $row['status'] ?? null, 'insert outbox item');
        assertTest(!empty($row['send_at']), 'outbox item has send_at');

        $res = sendOutboxItem($pdo, $oId);
        assertEquals('failed', $res['status'] ?? null, 'status transitions (pending -> sending -> failed)');
        $row = fetchOne($pdo, 'SELECT * FROM outbox WHERE id = ?', [$oId]);
        assertEquals('failed', $row['status'] ?? null, 'outbox row marked failed');
        assertEquals(1, (int) $row['retry_count'], 'retry_count incremented on failure');

        $pdo->prepare('INSERT INTO outbox (account_id, to_addresses, subject, body, send_at, status) VALUES (?, ?, ?, ?, datetime("now"), "sending")')
            ->execute([$acctId, '[]', 'S', 'B']);
        $bId = (int) $pdo->lastInsertId();
        $res = sendOutboxItem($pdo, $bId);
        assertEquals('busy', $res['status'] ?? null, 'status transitions (sending -> busy)');
    });
}

/* ---------------------------------------------------------------------------
 * GROUP: Rate Limiting
 * ------------------------------------------------------------------------ */

function testRateLimiting(): void
{
    group('Rate Limiting');

    withDbGroup(function (PDO $pdo) {
        $email = 'rl' . uniqid() . '@test.com';
        recordLoginFailure($email, '9.9.9.9');
        $c = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM login_attempts WHERE email = ? AND ip = ?', [$email, '9.9.9.9']);
        assertEquals(1, (int) $c['c'], 'recordLoginFailure inserts record');
    });

    $blocked = <<<'PHP'
$pdo = db();
for ($i = 0; $i < EMMMAIL_LOGIN_MAX_ATTEMPTS; $i++) {
    $pdo->prepare("INSERT INTO login_attempts (email, ip) VALUES (?, ?)")->execute(["block@test.com", "1.2.3.4"]);
}
checkRateLimit("block@test.com", "1.2.3.4");
echo "NOT_BLOCKED";
PHP;
    $out = runInSubprocess($blocked);
    assertTest(strpos($out, 'NOT_BLOCKED') === false, 'checkRateLimit blocks after max attempts');

    $expired = <<<'PHP'
$pdo = db();
for ($i = 0; $i < EMMMAIL_LOGIN_MAX_ATTEMPTS; $i++) {
    $pdo->prepare("INSERT INTO login_attempts (email, ip, attempted_at) VALUES (?, ?, datetime('now', '-2 hours'))")->execute(["old@test.com", "5.6.7.8"]);
}
checkRateLimit("old@test.com", "5.6.7.8");
echo "NOT_BLOCKED";
PHP;
    $out = runInSubprocess($expired);
    assertTest(strpos($out, 'NOT_BLOCKED') !== false, 'rate limit window expiry');
}

/* ---------------------------------------------------------------------------
 * MAIN
 * ------------------------------------------------------------------------ */

testHeader();

testEnvParser();
testEncryption();
testDatabaseSchema();
testImapRoleDetection();
testImapTokenizer();
testImapFetchItemsParser();
testHeaderParsing();
testBoundaryExtraction();
testTransferEncoding();
testCharsetConversion();
testMimeHeaderDecoding();
testAddressParsing();
testFilenameSanitization();
testDateNormalization();
testMimeParser();
testSubjectNormalization();
testDisplayNameDerivation();
testHtmlEscaping();
testBlockedAttachments();
testHeaderValueCleaning();
testHeaderEncoding();
testRecipientSanitization();
testAddressListFormatting();
testFtsSearch();
testAccountCrud();
testMessageThread();
testContacts();
testTags();
testDrafts();
testOutbox();
testRateLimiting();

summary();
