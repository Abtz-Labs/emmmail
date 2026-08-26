<?php
declare(strict_types=1);

/**
 * Emmmail — test suite for index.php.
 * Run: php tests.php (exit 0 = all pass, 1 = any failure)
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
putenv('EMMMAIL_DISABLE_SSRF_CHECK=1');

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

/** Runs code in a subprocess against the same test DB. */
function runInSubprocess(string $body): string
{
    $script = sys_get_temp_dir() . '/emmmail_sub_' . uniqid() . '.php';
    $code = '<?php' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DB=' . $GLOBALS['TEST_DB_PATH'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_KEY=' . $GLOBALS['TEST_KEY'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_ATTACHMENTS=' . $GLOBALS['TEST_ATTACH_DIR'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DEBUG=1', true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DISABLE_SSRF_CHECK=1', true) . ');' . "\n"
        . "ob_start();\n"
        . 'require ' . var_export($GLOBALS['INDEX_PHP'], true) . ";\n"
        . "ob_end_clean();\n"
        . $body . "\n";
    file_put_contents($script, $code);
    exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
    @unlink($script);
    return implode("\n", $output);
}

/** Simulates an HTTP request to index.php and returns decoded JSON. */
function apiRequest(string $action, array $data = [], string $method = 'POST', array $headers = []): array
{
    $script = sys_get_temp_dir() . '/emmmail_api_' . uniqid() . '.php';
    $json = json_encode($data, JSON_UNESCAPED_SLASHES);
    $inputFile = sys_get_temp_dir() . '/emmmail_input_' . uniqid() . '.json';
    file_put_contents($inputFile, $json);
    $headersJson = json_encode($headers);
    $code = '<?php' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DB=' . $GLOBALS['TEST_DB_PATH'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_KEY=' . $GLOBALS['TEST_KEY'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_ATTACHMENTS=' . $GLOBALS['TEST_ATTACH_DIR'], true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_DEBUG=1', true) . ');' . "\n"
        . 'putenv(' . var_export('EMMMAIL_CLI_INPUT=' . $inputFile, true) . ');' . "\n"
        . '$_GET[' . var_export('action', true) . '] = ' . var_export($action, true) . ';' . "\n"
        . '$_SERVER[' . var_export('REQUEST_METHOD', true) . '] = ' . var_export($method, true) . ';' . "\n"
        . '$_SERVER[' . var_export('CONTENT_TYPE', true) . '] = "application/json";' . "\n"
        . '$_SERVER[' . var_export('REMOTE_ADDR', true) . '] = "127.0.0.1";' . "\n"
        . '$headers = json_decode(' . var_export($headersJson, true) . ', true);' . "\n"
        . 'foreach ($headers as $k => $v) { $_SERVER["HTTP_" . strtoupper(str_replace("-", "_", $k))] = $v; }' . "\n"
        . 'require ' . var_export($GLOBALS['INDEX_PHP'], true) . ";\n";
    file_put_contents($script, $code);
    exec(PHP_BINARY . ' ' . escapeshellarg($script) . ' 2>&1', $output, $exitCode);
    @unlink($script);
    @unlink($inputFile);
    $result = implode("\n", $output);
    $decoded = json_decode($result, true);
    if (!is_array($decoded)) {
        $decoded = ['_raw' => $result, '_exit' => $exitCode];
    }
    return $decoded;
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
    $expected = ['login_attempts', 'accounts'];
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
 * GROUP: Auth Endpoint
 * ------------------------------------------------------------------------ */

function testAuthEndpoint(): void
{
    group('Auth Endpoint');

    $res = apiRequest('auth', [
        'imap_host' => 'imap.doesnotexist.invalid',
        'imap_port' => 993,
        'imap_tls' => 1,
        'smtp_host' => 'smtp.doesnotexist.invalid',
        'smtp_port' => 587,
        'smtp_tls' => 2,
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);
    assertEquals(false, $res['ok'] ?? null, 'auth returns ok:false for unreachable server');
    assertTest(!empty($res['error']), 'auth returns error message for unreachable server');

    $res = apiRequest('auth', []);
    assertEquals(false, $res['ok'] ?? null, 'auth returns ok:false for empty payload');

    $res = apiRequest('auth', [
        'imap_host' => 'imap.doesnotexist.invalid',
        'imap_port' => 993,
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);
    assertEquals(false, $res['ok'] ?? null, 'auth returns ok:false for missing fields');

    $res = apiRequest('auth', [
        'preset' => 'nonexistent',
        'email' => 'user@example.com',
        'password' => 'secret',
    ]);
    assertEquals(false, $res['ok'] ?? null, 'auth returns ok:false for unknown preset');
    assertTest(str_contains($res['error'] ?? '', 'Unknown preset'), 'unknown preset error message');
}

/* ---------------------------------------------------------------------------
 * GROUP: Provider Presets
 * ------------------------------------------------------------------------ */

function testProviderPresets(): void
{
    group('Provider Presets');

    $presets = getPresets();

    $expected = ['gmail', 'yahoo', 'outlook', 'migadu', 'icloud'];
    foreach ($expected as $name) {
        assertTest(isset($presets[$name]), "preset '{$name}' exists");
        $p = $presets[$name];
        assertTest(!empty($p['imap_host']), "preset '{$name}' has imap_host");
        assertTest(!empty($p['imap_port']), "preset '{$name}' has imap_port");
        assertTest(is_int($p['imap_tls']), "preset '{$name}' has imap_tls (int)");
        assertTest(!empty($p['smtp_host']), "preset '{$name}' has smtp_host");
        assertTest(!empty($p['smtp_port']), "preset '{$name}' has smtp_port");
        assertTest(is_int($p['smtp_tls']), "preset '{$name}' has smtp_tls (int)");
    }

    assertEquals('imap.gmail.com', $presets['gmail']['imap_host'], 'gmail imap_host');
    assertEquals(993, $presets['gmail']['imap_port'], 'gmail imap_port');
    assertEquals(1, $presets['gmail']['imap_tls'], 'gmail imap_tls (SSL)');
    assertEquals('smtp.gmail.com', $presets['gmail']['smtp_host'], 'gmail smtp_host');
    assertEquals(465, $presets['gmail']['smtp_port'], 'gmail smtp_port');
    assertEquals(1, $presets['gmail']['smtp_tls'], 'gmail smtp_tls (SSL)');

    assertEquals('imap.mail.yahoo.com', $presets['yahoo']['imap_host'], 'yahoo imap_host');
    assertEquals('smtp.mail.yahoo.com', $presets['yahoo']['smtp_host'], 'yahoo smtp_host');

    assertEquals('outlook.office365.com', $presets['outlook']['imap_host'], 'outlook imap_host');
    assertEquals(587, $presets['outlook']['smtp_port'], 'outlook smtp_port (STARTTLS)');
    assertEquals(2, $presets['outlook']['smtp_tls'], 'outlook smtp_tls (STARTTLS)');

    assertEquals('imap.migadu.com', $presets['migadu']['imap_host'], 'migadu imap_host');
    assertEquals('smtp.migadu.com', $presets['migadu']['smtp_host'], 'migadu smtp_host');

    assertEquals('imap.mail.me.com', $presets['icloud']['imap_host'], 'icloud imap_host');
    assertEquals(587, $presets['icloud']['smtp_port'], 'icloud smtp_port');
    assertEquals(2, $presets['icloud']['smtp_tls'], 'icloud smtp_tls (STARTTLS)');
}

/* ---------------------------------------------------------------------------
 * GROUP: Auth Endpoint (Real Server)
 * ------------------------------------------------------------------------ */

function testAuthEndpointReal(): void
{
    group('Auth Endpoint (Real Server)');

    $preset = getenv('EMMMAIL_TEST_PRESET') ?: '';
    $email = getenv('EMMMAIL_TEST_EMAIL') ?: '';
    $password = getenv('EMMMAIL_TEST_PASSWORD') ?: '';

    if ($email === '' || $password === '') {
        skipTest('EMMMAIL_TEST_EMAIL and EMMMAIL_TEST_PASSWORD not set — skipping');
        return;
    }

    if ($preset !== '') {
        $res = apiRequest('auth', [
            'preset' => $preset,
            'email' => $email,
            'password' => $password,
        ]);
        if (!($res['ok'] ?? false)) {
            assertTest(false, "auth ok:true with preset={$preset}");
            echo '    ' . colorRed('error: ' . ($res['error'] ?? 'unknown')) . "\n";
            return;
        }
        assertEquals(true, $res['ok'] ?? null, "auth ok:true with preset={$preset}");
        assertTest(($res['data']['account_id'] ?? 0) > 0, 'auth returns account_id > 0');
        assertEquals($email, $res['data']['email'] ?? null, 'auth returns correct email');
        assertEquals(true, $res['data']['imap']['ok'] ?? null, 'IMAP connection succeeded');
        assertEquals(true, $res['data']['smtp']['ok'] ?? null, 'SMTP connection succeeded');
        assertTest(is_array($res['data']['imap']['folders'] ?? null), 'IMAP returns folder list');
        assertTest(count($res['data']['imap']['folders'] ?? []) > 0, 'IMAP folder list is non-empty');

        $accountId = $res['data']['account_id'];
        $pdo = db();
        $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
        assertTest($acct !== null, 'account persisted in database');
        assertEquals($email, $acct['email'] ?? null, 'persisted account email matches');
        assertTest(!empty($acct['password_encrypted']), 'password is encrypted in database');
        $decrypted = decryptPassword($acct, getEncryptionKey());
        assertEquals($password, $decrypted, 'encrypted password decrypts to original');
    } else {
        $imapHost = getenv('EMMMAIL_TEST_IMAP_HOST') ?: '';
        $imapPort = (int) (getenv('EMMMAIL_TEST_IMAP_PORT') ?: 0);
        $imapTls = (int) (getenv('EMMMAIL_TEST_IMAP_TLS') ?: 0);
        $smtpHost = getenv('EMMMAIL_TEST_SMTP_HOST') ?: '';
        $smtpPort = (int) (getenv('EMMMAIL_TEST_SMTP_PORT') ?: 0);
        $smtpTls = (int) (getenv('EMMMAIL_TEST_SMTP_TLS') ?: 0);

        if ($imapHost === '' || $smtpHost === '') {
            skipTest('EMMMAIL_TEST_IMAP_HOST and EMMMAIL_TEST_SMTP_HOST not set — skipping');
            return;
        }

        $payload = [
            'imap_host' => $imapHost,
            'imap_port' => $imapPort,
            'imap_tls' => $imapTls,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_tls' => $smtpTls,
            'email' => $email,
            'password' => $password,
        ];

        $res = apiRequest('auth', $payload);
        if (!($res['ok'] ?? false)) {
            assertTest(false, 'auth ok:true with real credentials');
            echo '    ' . colorRed('error: ' . ($res['error'] ?? 'unknown')) . "\n";
            return;
        }
        assertEquals(true, $res['ok'] ?? null, 'auth ok:true with real credentials');
        assertTest(($res['data']['account_id'] ?? 0) > 0, 'auth returns account_id > 0');
        assertEquals($email, $res['data']['email'] ?? null, 'auth returns correct email');
        assertEquals(true, $res['data']['imap']['ok'] ?? null, 'IMAP connection succeeded');
        assertEquals(true, $res['data']['smtp']['ok'] ?? null, 'SMTP connection succeeded');
        assertTest(is_array($res['data']['imap']['folders'] ?? null), 'IMAP returns folder list');
        assertTest(count($res['data']['imap']['folders'] ?? []) > 0, 'IMAP folder list is non-empty');

        $accountId = $res['data']['account_id'];
        $pdo = db();
        $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
        assertTest($acct !== null, 'account persisted in database');
        assertEquals($email, $acct['email'] ?? null, 'persisted account email matches');
        assertTest(!empty($acct['password_encrypted']), 'password is encrypted in database');
        $decrypted = decryptPassword($acct, getEncryptionKey());
        assertEquals($password, $decrypted, 'encrypted password decrypts to original');
    }

    $res2 = apiRequest('auth', [
        'preset' => $preset ?: 'migadu',
        'email' => $email,
        'password' => 'WrongPassword123!',
    ]);
    assertEquals(false, $res2['ok'] ?? null, 'auth ok:false with wrong password');
    assertTest(!empty($res2['error']), 'auth returns error for wrong password');
}

/* ---------------------------------------------------------------------------
 * GROUP: Sync Endpoint
 * ------------------------------------------------------------------------ */

function testSyncEndpoint(): void
{
    group('Sync Endpoint');

    $session = createTestAccountAndSession();
    $token = $session['token'];

    $res = apiRequest('sync', ['account_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'sync returns ok:false for nonexistent account');

    $email = 'sync_fail_' . uniqid() . '@test.com';
    $enc = encryptPassword('pw', getEncryptionKey());
    $pdo = db();
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, 'Bad Account', 'imap.doesnotexist.invalid', 993, 1, 'smtp.doesnotexist.invalid', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);
    $badId = (int) $pdo->lastInsertId();

    $res = apiRequest('sync', ['account_id' => $badId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'sync returns ok:false for unreachable IMAP');
    assertTest(str_contains($res['error'] ?? '', 'IMAP'), 'unreachable IMAP error message');

    $email2 = 'sync_corrupt_' . uniqid() . '@test.com';
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email2, 'Corrupt', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, '!!!invalid!!!', 'AA==', 'BB==']);
    $corruptId = (int) $pdo->lastInsertId();

    $res = apiRequest('sync', ['account_id' => $corruptId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'sync returns ok:false for corrupt password');
}

/* ---------------------------------------------------------------------------
 * GROUP: Sync Endpoint (Real Server)
 * ------------------------------------------------------------------------ */

function testSyncEndpointReal(): void
{
    group('Sync Endpoint (Real Server)');

    $preset = getenv('EMMMAIL_TEST_PRESET') ?: '';
    $email = getenv('EMMMAIL_TEST_EMAIL') ?: '';
    $password = getenv('EMMMAIL_TEST_PASSWORD') ?: '';

    if ($email === '' || $password === '') {
        skipTest('EMMMAIL_TEST_EMAIL and EMMMAIL_TEST_PASSWORD not set — skipping');
        return;
    }

    $authPayload = ['email' => $email, 'password' => $password];
    if ($preset !== '') $authPayload['preset'] = $preset;
    $authRes = apiRequest('auth', $authPayload);
    assertEquals(true, $authRes['ok'] ?? null, 'auth ok:true before sync');
    $accountId = $authRes['data']['account_id'] ?? 0;
    assertTest($accountId > 0, 'account_id obtained from auth');

    $res = apiRequest('sync', ['account_id' => $accountId]);
    assertEquals(true, $res['ok'] ?? null, 'sync ok:true');
    assertTest(($res['data']['folders_synced'] ?? 0) > 0, 'folders_synced > 0');
    assertTest(($res['data']['messages_synced'] ?? 0) > 0, 'messages_synced > 0');
    assertTest(!empty($res['data']['last_sync_at']), 'last_sync_at returned');

    $pdo = db();
    $folders = fetchAll($pdo, 'SELECT * FROM folders WHERE account_id = ?', [$accountId]);
    assertTest(count($folders) > 0, 'folders persisted in database');
    $msgCount = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM messages WHERE account_id = ?', [$accountId]);
    assertTest((int) ($msgCount['c'] ?? 0) > 0, 'messages persisted in database');
    $threadCount = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM threads WHERE account_id = ?', [$accountId]);
    assertTest((int) ($threadCount['c'] ?? 0) > 0, 'threads created');

    $inbox = null;
    foreach ($folders as $f) {
        if (strtolower($f['name']) === 'inbox') { $inbox = $f; break; }
    }
    assertTest($inbox !== null, 'INBOX folder found');
    if ($inbox) {
        assertTest(($inbox['uid_validity'] ?? 0) > 0, 'INBOX uid_validity set');
        assertTest(($inbox['total_count'] ?? 0) > 0, 'INBOX total_count > 0');
    }

    $firstMsg = fetchOne($pdo, 'SELECT * FROM messages WHERE account_id = ? AND folder_name = ? LIMIT 1', [$accountId, 'INBOX']);
    if ($firstMsg) {
        assertTest(!empty($firstMsg['subject']), 'message has subject');
        assertTest(!empty($firstMsg['from_address']), 'message has from_address');
        assertTest(!empty($firstMsg['date']), 'message has date');
        assertTest($firstMsg['thread_id'] > 0, 'message linked to thread');
    }

    $res2 = apiRequest('sync', ['account_id' => $accountId, 'folder' => 'INBOX']);
    assertEquals(true, $res2['ok'] ?? null, 'incremental sync (specific folder) ok:true');

    $res3 = apiRequest('sync', [
        'account_id' => $accountId,
        'since' => gmdate('Y-m-d\TH:i:s\Z', strtotime('-7 days')),
    ]);
    assertEquals(true, $res3['ok'] ?? null, 'sync with since parameter ok:true');
}

/* ---------------------------------------------------------------------------
 * GROUP: Unknown Action
 * ------------------------------------------------------------------------ */

function testUnknownAction(): void
{
    group('Unknown Action');

    $session = createTestAccountAndSession();
    $token = $session['token'];

    $res = apiRequest('nonexistent_action', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'unknown action returns ok:false');
    assertTest(str_contains($res['error'] ?? '', 'Unknown'), 'unknown action returns error message');
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
 * GROUP: Subject Normalization
 * ------------------------------------------------------------------------ */

function testSubjectNormalization(): void
{
    group('Subject Normalization');

    assertEquals('Hello', normalizeSubject('Re: Hello'), 'normalizeSubject "Re: Hello"');
    assertEquals('Hello', normalizeSubject('Fwd: Hello'), 'normalizeSubject "Fwd: Hello"');
    assertEquals('Re: Hello', normalizeSubject('Re: Re: Hello'), 'normalizeSubject "Re: Re: Hello"');
    assertEquals('Hello', normalizeSubject('Fw: Hello'), 'normalizeSubject "Fw: Hello"');
    assertEquals('Hello', normalizeSubject('RE: Hello'), 'normalizeSubject case insensitive');
    assertEquals('Hello World', normalizeSubject('Hello World'), 'normalizeSubject no prefix');
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

function testSsrfProtection(): void
{
    group('SSRF Protection');

    $code = <<<'PHP'
putenv('EMMMAIL_DISABLE_SSRF_CHECK=');
echo json_encode([
    "google" => isSafeHost("imap.gmail.com"),
    "migadu" => isSafeHost("imap.migadu.com"),
    "localhost" => isSafeHost("localhost"),
    "loopback" => isSafeHost("127.0.0.1"),
    "private10" => isSafeHost("10.0.0.1"),
    "private172" => isSafeHost("172.16.0.1"),
    "private192" => isSafeHost("192.168.1.1"),
    "metadata" => isSafeHost("169.254.169.254"),
    "zero" => isSafeHost("0.0.0.0"),
    "multicast" => isSafeHost("224.0.0.1"),
    "broadcast" => isSafeHost("255.255.255.255"),
    "ipv6_loopback" => isSafeHost("::1"),
    "ipv6_linklocal" => isSafeHost("fe80::1"),
    "empty" => isSafeHost(""),
]);
PHP;
    $out = runInSubprocess($code);
    $result = json_decode($out, true);
    assertTest($result !== null, 'SSRF test produced valid JSON');

    assertEquals(true, $result['google'] ?? null, 'isSafeHost allows imap.gmail.com');
    assertEquals(true, $result['migadu'] ?? null, 'isSafeHost allows imap.migadu.com');
    assertEquals(false, $result['localhost'] ?? null, 'isSafeHost blocks localhost');
    assertEquals(false, $result['loopback'] ?? null, 'isSafeHost blocks 127.0.0.1');
    assertEquals(false, $result['private10'] ?? null, 'isSafeHost blocks 10.x.x.x');
    assertEquals(false, $result['private172'] ?? null, 'isSafeHost blocks 172.16-31.x.x');
    assertEquals(false, $result['private192'] ?? null, 'isSafeHost blocks 192.168.x.x');
    assertEquals(false, $result['metadata'] ?? null, 'isSafeHost blocks 169.254.169.254');
    assertEquals(false, $result['zero'] ?? null, 'isSafeHost blocks 0.0.0.0');
    assertEquals(false, $result['multicast'] ?? null, 'isSafeHost blocks 224.x.x.x');
    assertEquals(false, $result['broadcast'] ?? null, 'isSafeHost blocks 255.255.255.255');
    assertEquals(false, $result['ipv6_loopback'] ?? null, 'isSafeHost blocks ::1');
    assertEquals(false, $result['ipv6_linklocal'] ?? null, 'isSafeHost blocks fe80::');
    assertEquals(false, $result['empty'] ?? null, 'isSafeHost blocks empty string');
}

function testComposeEndpoint(): void
{
    group('Compose Endpoint');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('compose', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'compose returns ok:false for empty payload');
    assertTest(str_contains($res['error'] ?? '', 'to'), 'missing to error');

    $res = apiRequest('compose', ['account_id' => 99999, 'to' => ['test@test.com']], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'compose returns ok:false for nonexistent account');

    $res = apiRequest('compose', ['account_id' => $accountId, 'to' => [], 'subject' => 'Hi'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'compose returns ok:false for empty to');

    $res = apiRequest('compose', ['account_id' => $accountId, 'to' => ['test@test.com']], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'compose returns ok:false for empty subject and body');

    $res = apiRequest('compose', ['account_id' => $accountId, 'to' => ['test@test.com'], 'subject' => 'Draft', 'draft' => true], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'compose saves draft');
    assertTest(($res['data']['draft_id'] ?? 0) > 0, 'draft_id returned');
    assertEquals(true, $res['data']['saved'] ?? null, 'draft saved flag');
    $draftId = $res['data']['draft_id'];

    $res = apiRequest('drafts', ['account_id' => $accountId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'list drafts ok');
    assertTest(count($res['data']['drafts'] ?? []) > 0, 'drafts list not empty');
    $found = false;
    foreach ($res['data']['drafts'] as $d) {
        if ($d['id'] == $draftId) $found = true;
    }
    assertTest($found, 'saved draft found in list');

    $res = apiRequest('draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'get draft ok');
    assertEquals('Draft', $res['data']['subject'] ?? '', 'get draft subject');

    $res = apiRequest('update_draft', ['draft_id' => $draftId, 'subject' => 'Updated Draft'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'update draft ok');

    $res = apiRequest('draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token]);
    assertEquals('Updated Draft', $res['data']['subject'] ?? '', 'updated draft subject');

    $res = apiRequest('delete_draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'delete draft ok');

    $res = apiRequest('draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'deleted draft not found');
}

function testDraftEndpointReal(): void
{
    group('Draft Endpoint (Real Server)');

    if (getenv('EMMMAIL_TEST_EMAIL') === false || getenv('EMMMAIL_TEST_EMAIL') === '' || getenv('EMMMAIL_TEST_PASSWORD') === false || getenv('EMMMAIL_TEST_PASSWORD') === '') {
        skipTest('EMMMAIL_TEST_EMAIL and EMMMAIL_TEST_PASSWORD not set — skipping');
        return;
    }

    $res = apiRequest('auth', [
        'preset' => getenv('EMMMAIL_TEST_PRESET') ?: 'migadu',
        'email' => getenv('EMMMAIL_TEST_EMAIL'),
        'password' => getenv('EMMMAIL_TEST_PASSWORD'),
    ]);
    if (!($res['ok'] ?? false)) {
        assertTest(false, 'auth ok:true with real credentials');
        echo '    ' . colorRed('error: ' . ($res['error'] ?? 'unknown')) . "\n";
        return;
    }
    $accountId = $res['data']['account_id'];

    $res = apiRequest('compose', [
        'account_id' => $accountId,
        'to' => [getenv('EMMMAIL_TEST_EMAIL')],
        'subject' => 'Test Draft ' . uniqid(),
        'body' => 'This is a test draft.',
        'draft' => true,
    ]);
    assertEquals(true, $res['ok'] ?? null, 'compose saves draft');
    $draftId = $res['data']['draft_id'];

    $res = apiRequest('drafts', ['account_id' => $accountId]);
    assertEquals(true, $res['ok'] ?? null, 'list drafts ok');
    assertTest(count($res['data']['drafts'] ?? []) > 0, 'drafts list not empty');

    $res = apiRequest('draft', ['draft_id' => $draftId]);
    assertEquals(true, $res['ok'] ?? null, 'get draft ok');
    assertEquals('Test Draft ' . substr($draftId, -6), $res['data']['subject'] ?? '', 'get draft subject');

    $res = apiRequest('delete_draft', ['draft_id' => $draftId]);
    assertEquals(true, $res['ok'] ?? null, 'delete draft ok');
}

function testAttachmentEndpoint(): void
{
    group('Attachment Endpoint');

    $session = createTestAccountAndSession();
    $token = $session['token'];

    $res = apiRequest('message_attachments', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'message_attachments returns ok:false for empty payload');

    $res = apiRequest('message_attachments', ['message_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'message_attachments returns ok:false for nonexistent message');

    $res = apiRequest('download_attachment', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'download_attachment returns ok:false for empty payload');

    $res = apiRequest('download_attachment', ['attachment_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'download_attachment returns ok:false for nonexistent attachment');
}

function testAttachmentStorage(): void
{
    group('Attachment Storage');

    withDbGroup(function (PDO $pdo) {
        $email = 'att' . uniqid() . '@test.com';
        $enc = encryptPassword('pw', getEncryptionKey());
        $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$email, 'Att Test', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);
        $accountId = (int) $pdo->lastInsertId();

        $pdo->prepare('INSERT INTO messages (account_id, folder_name, uid, subject, from_address, to_addresses, text_body, is_read) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, 'INBOX', 1, 'Test', 'from@test.com', json_encode([['email' => 'to@test.com']]), 'Body', 1]);
        $messageId = (int) $pdo->lastInsertId();

        $attId = storeTestAttachment($pdo, $messageId, $accountId);

        $att = fetchOne($pdo, 'SELECT * FROM attachments WHERE id = ?', [$attId]);
        assertTest($att !== null, 'attachment stored in database');
        assertEquals('test.txt', $att['filename'] ?? '', 'attachment filename');
        assertEquals('text/plain', $att['content_type'] ?? '', 'attachment content type');
        assertTest((int) ($att['size'] ?? 0) > 0, 'attachment size > 0');
        assertTest(is_file($att['file_path'] ?? ''), 'attachment file exists on disk');

        $content = @file_get_contents($att['file_path']);
        assertTest(str_contains($content, 'Hello World'), 'attachment content matches');
    });
}

function storeTestAttachment(PDO $pdo, int $messageId, int $accountId): int
{
    $dir = EMMMAIL_ATTACH_DIR . '/' . $accountId . '/' . $messageId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    $filename = 'test.txt';
    $content = 'Hello World';
    $filePath = $dir . '/' . $filename;
    @file_put_contents($filePath, $content);

    $pdo->prepare('INSERT INTO attachments (message_id, account_id, filename, content_type, size, file_path) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$messageId, $accountId, $filename, 'text/plain', strlen($content), $filePath]);
    return (int) $pdo->lastInsertId();
}

function createTestAccountAndSession(): array
{
    $pdo = db();
    $email = 'test_' . uniqid() . '@test.com';
    $password = 'testpass123';
    $enc = encryptPassword($password, getEncryptionKey());
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, 'Test User', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);
    $accountId = (int) $pdo->lastInsertId();

    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $token = createSession($accountId, $ip, 'Test Agent');
    return ['account_id' => $accountId, 'email' => $email, 'password' => $password, 'token' => $token];
}

function testSessionEndpoint(): void
{
    group('Session Endpoint');

    $res = apiRequest('session', []);
    assertEquals(true, $res['ok'] ?? null, 'session returns ok:true when not authenticated');
    assertEquals(false, $res['data']['authenticated'] ?? null, 'session shows not authenticated');

    $res = apiRequest('login', []);
    assertEquals(false, $res['ok'] ?? null, 'login returns ok:false for empty payload');

    $res = apiRequest('login', ['email' => 'test@test.com', 'password' => 'wrong']);
    assertEquals(false, $res['ok'] ?? null, 'login returns ok:false for invalid credentials');

    $email = 'sess' . uniqid() . '@test.com';
    $password = 'testpass';
    $enc = encryptPassword($password, getEncryptionKey());
    $pdo = db();
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, 'Session Test', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);

    $res = apiRequest('login', ['email' => $email, 'password' => $password]);
    assertEquals(true, $res['ok'] ?? null, 'login returns ok:true with valid credentials');
    assertTest(!empty($res['data']['session_token']), 'login returns session_token');
    $token = $res['data']['session_token'];

    $res = apiRequest('session', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'session returns ok:true with valid token');
    assertEquals(true, $res['data']['authenticated'] ?? null, 'session shows authenticated');
    assertEquals($email, $res['data']['email'] ?? '', 'session returns email');

    $res = apiRequest('logout', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'logout returns ok:true');

    $res = apiRequest('session', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['data']['authenticated'] ?? null, 'session shows not authenticated after logout');
}

function testSessionProtection(): void
{
    group('Session Protection');

    $res = apiRequest('sync', []);
    assertEquals(false, $res['ok'] ?? null, 'sync returns ok:false without session');
    assertTest(str_contains($res['error'] ?? '', 'Authentication'), 'sync requires authentication');

    $res = apiRequest('compose', []);
    assertEquals(false, $res['ok'] ?? null, 'compose returns ok:false without session');

    $res = apiRequest('drafts', []);
    assertEquals(false, $res['ok'] ?? null, 'drafts returns ok:false without session');
}

function testAccountTakeoverPrevention(): void
{
    group('Account Takeover Prevention');

    $session1 = createTestAccountAndSession();
    $email1 = $session1['email'];

    $res = apiRequest('auth', [
        'preset' => 'migadu',
        'email' => $email1,
        'password' => 'newpassword',
    ]);
    assertEquals(false, $res['ok'] ?? null, 'auth rejects overwrite without session');
}

function testIdorPrevention(): void
{
    group('IDOR Prevention');

    $session1 = createTestAccountAndSession();
    $token1 = $session1['token'];
    $accountId1 = $session1['account_id'];

    $session2 = createTestAccountAndSession();
    $token2 = $session2['token'];
    $accountId2 = $session2['account_id'];

    $pdo = db();
    $enc = encryptPassword('pw', getEncryptionKey());
    $pdo->prepare('INSERT INTO drafts (account_id, to_addresses, subject, body) VALUES (?, ?, ?, ?)')
        ->execute([$accountId2, json_encode([['email' => 'test@test.com']]), 'Draft', 'Body']);
    $draftId = (int) $pdo->lastInsertId();

    $res = apiRequest('draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token1]);
    assertEquals(false, $res['ok'] ?? null, 'draft rejects cross-account access');

    $res = apiRequest('delete_draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token1]);
    assertEquals(false, $res['ok'] ?? null, 'delete_draft rejects cross-account access');

    $res = apiRequest('update_draft', ['draft_id' => $draftId, 'subject' => 'Hacked'], 'POST', ['X-Session-Token' => $token1]);
    assertEquals(false, $res['ok'] ?? null, 'update_draft rejects cross-account access');
}

function testCrlfInjectionRejection(): void
{
    group('CRLF Injection Rejection');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('compose', [
        'account_id' => $accountId,
        'to' => ['test@test.com'],
        'subject' => "Subject\r\nBcc: evil@hacker.com",
        'body' => 'Test',
        'draft' => true,
    ], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'compose accepts sanitized subject');

    if ($res['ok'] && isset($res['data']['draft_id'])) {
        $draftId = $res['data']['draft_id'];
        $res2 = apiRequest('draft', ['draft_id' => $draftId], 'POST', ['X-Session-Token' => $token]);
        $subject = $res2['data']['subject'] ?? '';
        assertTest(!str_contains($subject, "\r"), 'draft subject has no CR');
        assertTest(!str_contains($subject, "\n"), 'draft subject has no LF');
    }
}

function testLoginRateLimiting(): void
{
    group('Login Rate Limiting');

    $session = createTestAccountAndSession();
    $email = $session['email'];

    for ($i = 0; $i < EMMMAIL_LOGIN_MAX_ATTEMPTS; $i++) {
        apiRequest('login', ['email' => $email, 'password' => 'wrong']);
    }

    $res = apiRequest('login', ['email' => $email, 'password' => 'wrong']);
    assertEquals(false, $res['ok'] ?? null, 'login blocked after max attempts');
    assertTest(str_contains($res['error'] ?? '', 'Too many'), 'login returns rate limit error');
}

function testSessionIpBinding(): void
{
    group('Session IP Binding');

    $pdo = db();
    $email = 'ipbind' . uniqid() . '@test.com';
    $enc = encryptPassword('testpass', getEncryptionKey());
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, 'IP Bind Test', 'imap.test.com', 993, 1, 'smtp.test.com', 587, 2, $enc['encrypted'], $enc['iv'], $enc['tag']]);
    $accountId = (int) $pdo->lastInsertId();

    $token = createSession($accountId, '10.0.0.1', 'Test Agent');

    $res = apiRequest('session', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['data']['authenticated'] ?? null, 'session rejects different IP');
}

function testPathContainment(): void
{
    group('Path Containment');

    $session = createTestAccountAndSession();
    $token = $session['token'];

    $res = apiRequest('download_attachment', ['attachment_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'download_attachment rejects nonexistent attachment');
}

function insertTestFolder(int $accountId, string $name, string $role, int $unread = 0, int $total = 0): int
{
    $pdo = db();
    $pdo->prepare('INSERT INTO folders (account_id, name, role, unread_count, total_count) VALUES (?, ?, ?, ?, ?)')
        ->execute([$accountId, $name, $role, $unread, $total]);
    return (int) $pdo->lastInsertId();
}

function insertTestMessage(int $accountId, string $folder, int $uid, array $attrs = []): int
{
    $pdo = db();
    $subject = $attrs['subject'] ?? 'Test Subject ' . $uid;
    $fromAddr = $attrs['from_address'] ?? 'sender@test.com';
    $fromName = $attrs['from_name'] ?? 'Sender';
    $toAddrs = $attrs['to_addresses'] ?? json_encode(['recipient@test.com']);
    $date = $attrs['date'] ?? date('Y-m-d\TH:i:s\Z');
    $textBody = $attrs['text_body'] ?? 'Plain text body for message ' . $uid;
    $htmlBody = $attrs['html_body'] ?? '<p>HTML body for message ' . $uid . '</p>';
    $isRead = $attrs['is_read'] ?? 0;
    $isStarred = $attrs['is_starred'] ?? 0;
    $hasAttachments = $attrs['has_attachments'] ?? 0;
    $messageId = $attrs['message_id'] ?? '<msg' . $uid . '@test.com>';
    $inReplyTo = $attrs['in_reply_to'] ?? '';
    $refs = $attrs['refs'] ?? '';

    $pdo->prepare('INSERT INTO messages (account_id, folder_name, uid, message_id, in_reply_to, refs, subject, from_address, from_name, to_addresses, cc_addresses, date, text_body, html_body, is_read, is_starred, has_attachments) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$accountId, $folder, $uid, $messageId, $inReplyTo, $refs, $subject, $fromAddr, $fromName, $toAddrs, '[]', $date, $textBody, $htmlBody, $isRead, $isStarred, $hasAttachments]);
    return (int) $pdo->lastInsertId();
}

function insertTestThread(int $accountId, string $subject, string $lastMessageAt, int $messageCount = 1, string $preview = '', int $isRead = 0, int $isStarred = 0): int
{
    $pdo = db();
    $threadKey = hash('sha1', strtolower(preg_replace('/^(re|fwd?|fw):\s*/i', '', $subject)));
    $pdo->prepare('INSERT INTO threads (account_id, thread_key, subject, last_message_at, message_count, preview, is_read, is_starred) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$accountId, $threadKey, $subject, $lastMessageAt, $messageCount, $preview, $isRead, $isStarred]);
    return (int) $pdo->lastInsertId();
}

function testListFolders(): void
{
    group('list_folders');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('list_folders', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'list_folders returns ok:true');
    assertEquals([], $res['data']['folders'] ?? null, 'list_folders returns empty array when no folders');

    insertTestFolder($accountId, 'INBOX', 'inbox', 5, 120);
    insertTestFolder($accountId, 'Sent', 'sent', 0, 40);
    insertTestFolder($accountId, 'Trash', 'trash', 0, 10);

    $res = apiRequest('list_folders', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'list_folders returns ok:true with folders');
    assertEquals(3, count($res['data']['folders'] ?? []), 'list_folders returns 3 folders');

    $folders = $res['data']['folders'] ?? [];
    assertEquals('INBOX', $folders[0]['name'] ?? '', 'list_folders sorts inbox first');
    assertEquals('inbox', $folders[0]['role'] ?? '', 'list_folders includes role');
    assertEquals(5, $folders[0]['unread_count'] ?? 0, 'list_folders includes unread_count');
    assertEquals(120, $folders[0]['total_count'] ?? 0, 'list_folders includes total_count');
    assertEquals('Sent', $folders[1]['name'] ?? '', 'list_folders sorts sent second');
    assertEquals('Trash', $folders[2]['name'] ?? '', 'list_folders sorts trash last');

    $res = apiRequest('list_folders', [], 'POST', ['X-Session-Token' => 'invalid-token']);
    assertEquals(false, $res['ok'] ?? null, 'list_folders rejects invalid session');
}

function testListMessages(): void
{
    group('list_messages');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('list_messages', [], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'list_messages fails without folder');

    $res = apiRequest('list_messages', ['folder' => 'INBOX'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'list_messages returns ok:true for empty folder');
    assertEquals([], $res['data']['messages'] ?? null, 'list_messages returns empty array for empty folder');
    assertEquals(false, $res['data']['has_more'] ?? null, 'list_messages has_more false when empty');

    insertTestFolder($accountId, 'INBOX', 'inbox');
    insertTestMessage($accountId, 'INBOX', 1, ['subject' => 'First', 'date' => '2026-08-25T10:00:00Z']);
    insertTestMessage($accountId, 'INBOX', 2, ['subject' => 'Second', 'date' => '2026-08-26T10:00:00Z']);
    insertTestMessage($accountId, 'INBOX', 3, ['subject' => 'Third', 'date' => '2026-08-26T14:00:00Z']);

    $res = apiRequest('list_messages', ['folder' => 'INBOX'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'list_messages returns ok:true');
    assertEquals(3, count($res['data']['messages'] ?? []), 'list_messages returns 3 messages');
    assertEquals('Third', $res['data']['messages'][0]['subject'] ?? '', 'list_messages sorts newest first');
    assertEquals('First', $res['data']['messages'][2]['subject'] ?? '', 'list_messages sorts oldest last');

    $msg = $res['data']['messages'][0];
    assertTest(is_array($msg['to_addresses'] ?? null), 'list_messages to_addresses is array');
    assertTest(isset($msg['preview']), 'list_messages includes preview');
    assertTest(!isset($msg['text_body']), 'list_messages excludes text_body');
    assertTest(!isset($msg['html_body']), 'list_messages excludes html_body');

    $res = apiRequest('list_messages', ['folder' => 'INBOX', 'limit' => 2], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, count($res['data']['messages'] ?? []), 'list_messages respects limit');
    assertEquals(true, $res['data']['has_more'] ?? null, 'list_messages has_more true when more exist');
    assertTest(!empty($res['data']['next_cursor']), 'list_messages returns next_cursor');

    $cursor = $res['data']['next_cursor'];
    $res = apiRequest('list_messages', ['folder' => 'INBOX', 'limit' => 2, 'cursor' => $cursor], 'POST', ['X-Session-Token' => $token]);
    assertEquals(1, count($res['data']['messages'] ?? []), 'list_messages cursor returns remaining');
    assertEquals('First', $res['data']['messages'][0]['subject'] ?? '', 'list_messages cursor returns older messages');
    assertEquals(false, $res['data']['has_more'] ?? null, 'list_messages cursor has_more false at end');

    $res = apiRequest('list_messages', ['folder' => 'INBOX', 'limit' => 0], 'POST', ['X-Session-Token' => $token]);
    assertTest(count($res['data']['messages'] ?? []) >= 1, 'list_messages limit=0 clamped to 1');

    $res = apiRequest('list_messages', ['folder' => 'INBOX', 'limit' => 500], 'POST', ['X-Session-Token' => $token]);
    assertTest(count($res['data']['messages'] ?? []) <= 100, 'list_messages limit=500 clamped to 100');

    $res = apiRequest('list_messages', ['folder' => 'INBOX'], 'POST', ['X-Session-Token' => 'invalid']);
    assertEquals(false, $res['ok'] ?? null, 'list_messages rejects invalid session');
}

function testGetMessage(): void
{
    group('get_message');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('get_message', ['message_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'get_message fails for nonexistent id');

    insertTestFolder($accountId, 'INBOX', 'inbox');
    $msgId = insertTestMessage($accountId, 'INBOX', 1, [
        'subject' => 'Test Email',
        'from_address' => 'alice@example.com',
        'from_name' => 'Alice',
        'to_addresses' => json_encode(['bob@test.com']),
        'cc_addresses' => json_encode(['carol@test.com']),
        'text_body' => 'Hello Bob',
        'html_body' => '<p>Hello Bob</p>',
    ]);

    $res = apiRequest('get_message', ['message_id' => $msgId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'get_message returns ok:true');
    assertEquals('Test Email', $res['data']['message']['subject'] ?? '', 'get_message returns subject');
    assertEquals('Hello Bob', $res['data']['message']['text_body'] ?? '', 'get_message returns text_body');
    assertEquals('<p>Hello Bob</p>', $res['data']['message']['html_body'] ?? '', 'get_message returns html_body');
    assertEquals('alice@example.com', $res['data']['message']['from_address'] ?? '', 'get_message returns from_address');
    assertTest(is_array($res['data']['message']['to_addresses'] ?? null), 'get_message to_addresses is array');
    assertTest(is_array($res['data']['message']['cc_addresses'] ?? null), 'get_message cc_addresses is array');
    assertTest(!isset($res['data']['message']['raw_headers']), 'get_message excludes raw_headers');
    assertEquals([], $res['data']['attachments'] ?? null, 'get_message returns empty attachments');

    $session2 = createTestAccountAndSession();
    $token2 = $session2['token'];
    $res = apiRequest('get_message', ['message_id' => $msgId], 'POST', ['X-Session-Token' => $token2]);
    assertEquals(false, $res['ok'] ?? null, 'get_message blocks cross-account access');
}

function testGetThread(): void
{
    group('get_thread');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    $res = apiRequest('get_thread', ['thread_id' => 99999], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'get_thread fails for nonexistent id');

    insertTestFolder($accountId, 'INBOX', 'inbox');
    $threadId = insertTestThread($accountId, 'Hello Thread', '2026-08-26T10:00:00Z', 2, 'Latest preview', 0, 1);
    insertTestMessage($accountId, 'INBOX', 1, ['subject' => 'Hello Thread', 'date' => '2026-08-25T08:00:00Z']);
    insertTestMessage($accountId, 'INBOX', 2, ['subject' => 'Re: Hello Thread', 'date' => '2026-08-26T10:00:00Z']);

    $pdo = db();
    $pdo->prepare('UPDATE messages SET thread_id = ? WHERE account_id = ? AND uid IN (1, 2)')
        ->execute([$threadId, $accountId]);

    $res = apiRequest('get_thread', ['thread_id' => $threadId], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'get_thread returns ok:true');
    assertEquals('Hello Thread', $res['data']['thread']['subject'] ?? '', 'get_thread returns subject');
    assertEquals(2, $res['data']['thread']['message_count'] ?? 0, 'get_thread returns message_count');
    assertEquals(2, count($res['data']['messages'] ?? []), 'get_thread returns 2 messages');
    assertEquals('Hello Thread', $res['data']['messages'][0]['subject'] ?? '', 'get_thread messages sorted by date ASC');
    assertEquals('Re: Hello Thread', $res['data']['messages'][1]['subject'] ?? '', 'get_thread second message is newer');

    $msg = $res['data']['messages'][0];
    assertTest(isset($msg['text_body']), 'get_thread messages include text_body');
    assertTest(!isset($msg['html_body']), 'get_thread messages exclude html_body');

    $session2 = createTestAccountAndSession();
    $token2 = $session2['token'];
    $res = apiRequest('get_thread', ['thread_id' => $threadId], 'POST', ['X-Session-Token' => $token2]);
    assertEquals(false, $res['ok'] ?? null, 'get_thread blocks cross-account access');
}

function testSetFlags(): void
{
    group('set_flags');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    insertTestFolder($accountId, 'INBOX', 'inbox');
    $msgId = insertTestMessage($accountId, 'INBOX', 1, ['is_read' => 0, 'is_starred' => 0]);

    $res = apiRequest('set_flags', ['message_id' => $msgId, 'is_read' => 1], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'set_flags returns ok:true');
    assertEquals(1, $res['data']['is_read'] ?? null, 'set_flags marks as read');

    $pdo = db();
    $row = $pdo->query("SELECT is_read FROM messages WHERE id = $msgId")->fetch();
    assertEquals(1, (int) $row['is_read'], 'set_flags persisted is_read');

    $res = apiRequest('set_flags', ['message_id' => $msgId, 'is_starred' => 1], 'POST', ['X-Session-Token' => $token]);
    assertEquals(1, $res['data']['is_starred'] ?? null, 'set_flags marks as starred');

    $row = $pdo->query("SELECT is_starred FROM messages WHERE id = $msgId")->fetch();
    assertEquals(1, (int) $row['is_starred'], 'set_flags persisted is_starred');

    $res = apiRequest('set_flags', ['message_id' => 99999, 'is_read' => 1], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'set_flags fails for nonexistent message');

    $session2 = createTestAccountAndSession();
    $token2 = $session2['token'];
    $res = apiRequest('set_flags', ['message_id' => $msgId, 'is_read' => 1], 'POST', ['X-Session-Token' => $token2]);
    assertEquals(false, $res['ok'] ?? null, 'set_flags blocks cross-account');
}

function testBatchAction(): void
{
    group('batch_action');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    insertTestFolder($accountId, 'INBOX', 'inbox');
    $msg1 = insertTestMessage($accountId, 'INBOX', 1, ['is_read' => 0, 'is_starred' => 0]);
    $msg2 = insertTestMessage($accountId, 'INBOX', 2, ['is_read' => 0, 'is_starred' => 0]);
    $msg3 = insertTestMessage($accountId, 'INBOX', 3, ['is_read' => 0, 'is_starred' => 0]);

    $res = apiRequest('batch_action', ['message_ids' => [], 'action' => 'read'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'batch_action fails for empty ids');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg2], 'action' => 'invalid'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'batch_action fails for invalid action');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg2, $msg3], 'action' => 'read'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'batch_action read returns ok:true');
    assertEquals(3, $res['data']['affected'] ?? 0, 'batch_action read affects 3 messages');

    $pdo = db();
    $unread = $pdo->query("SELECT COUNT(*) AS c FROM messages WHERE account_id = $accountId AND is_read = 0")->fetch();
    assertEquals(0, (int) $unread['c'], 'batch_action read marks all as read');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg2], 'action' => 'star'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, $res['data']['affected'] ?? 0, 'batch_action star affects 2 messages');

    $starred = $pdo->query("SELECT COUNT(*) AS c FROM messages WHERE account_id = $accountId AND is_starred = 1")->fetch();
    assertEquals(2, (int) $starred['c'], 'batch_action star marks 2 as starred');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg2], 'action' => 'unstar'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, $res['data']['affected'] ?? 0, 'batch_action unstar affects 2 messages');

    $starred = $pdo->query("SELECT COUNT(*) AS c FROM messages WHERE account_id = $accountId AND is_starred = 1")->fetch();
    assertEquals(0, (int) $starred['c'], 'batch_action unstar clears starred');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg2], 'action' => 'unread'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, $res['data']['affected'] ?? 0, 'batch_action unread affects 2 messages');

    $unread = $pdo->query("SELECT COUNT(*) AS c FROM messages WHERE account_id = $accountId AND is_read = 0")->fetch();
    assertEquals(2, (int) $unread['c'], 'batch_action unread marks 2 as unread');

    $res = apiRequest('batch_action', ['message_ids' => [$msg1, $msg1, $msg2], 'action' => 'read'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, $res['data']['affected'] ?? 0, 'batch_action dedupes ids');

    $session2 = createTestAccountAndSession();
    $token2 = $session2['token'];
    $res = apiRequest('batch_action', ['message_ids' => [$msg1], 'action' => 'read'], 'POST', ['X-Session-Token' => $token2]);
    assertEquals(0, $res['data']['affected'] ?? -1, 'batch_action blocks cross-account');
}

function testSearch(): void
{
    group('search');

    $session = createTestAccountAndSession();
    $token = $session['token'];
    $accountId = $session['account_id'];

    insertTestFolder($accountId, 'INBOX', 'inbox');
    insertTestMessage($accountId, 'INBOX', 1, ['subject' => 'Quarterly Report', 'from_name' => 'Alice', 'text_body' => 'Please find attached the Q3 report.']);
    insertTestMessage($accountId, 'INBOX', 2, ['subject' => 'Lunch Plans', 'from_name' => 'Bob', 'text_body' => 'Want to grab lunch tomorrow?']);
    insertTestMessage($accountId, 'INBOX', 3, ['subject' => 'Re: Quarterly Report', 'from_name' => 'Carol', 'text_body' => 'Thanks for the report, looks great.']);

    $res = apiRequest('search', ['query' => ''], 'POST', ['X-Session-Token' => $token]);
    assertEquals(false, $res['ok'] ?? null, 'search fails for empty query');

    $res = apiRequest('search', ['query' => 'report'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'search returns ok:true');
    assertEquals(2, count($res['data']['messages'] ?? []), 'search finds 2 messages with "report"');

    $res = apiRequest('search', ['query' => 'lunch'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(1, count($res['data']['messages'] ?? []), 'search finds 1 message with "lunch"');

    $res = apiRequest('search', ['query' => 'Alice'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(1, count($res['data']['messages'] ?? []), 'search finds by sender name');

    $res = apiRequest('search', ['query' => 'report', 'folder' => 'INBOX'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(2, count($res['data']['messages'] ?? []), 'search with folder filter works');

    $res = apiRequest('search', ['query' => 'report AND lunch'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'search special chars do not cause 500');

    $res = apiRequest('search', ['query' => '"test" OR *'], 'POST', ['X-Session-Token' => $token]);
    assertEquals(true, $res['ok'] ?? null, 'search quotes and wildcards do not cause 500');

    $res = apiRequest('search', ['query' => 'report', 'limit' => 1], 'POST', ['X-Session-Token' => $token]);
    assertEquals(1, count($res['data']['messages'] ?? []), 'search respects limit');

    $res = apiRequest('search', ['query' => 'report'], 'POST', ['X-Session-Token' => 'invalid']);
    assertEquals(false, $res['ok'] ?? null, 'search rejects invalid session');
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
testAuthEndpoint();
testProviderPresets();
testAuthEndpointReal();
testUnknownAction();
testHtmlEscaping();
testDisplayNameDerivation();
testAddressParsing();
testFilenameSanitization();
testDateNormalization();
testSubjectNormalization();
testBlockedAttachments();
testRateLimiting();
testSsrfProtection();
testSyncEndpoint();
testSyncEndpointReal();
testComposeEndpoint();
testDraftEndpointReal();
testAttachmentEndpoint();
testAttachmentStorage();
testSessionEndpoint();
testSessionProtection();
testAccountTakeoverPrevention();
testIdorPrevention();
testCrlfInjectionRejection();
testLoginRateLimiting();
testSessionIpBinding();
testPathContainment();
testListFolders();
testListMessages();
testGetMessage();
testGetThread();
testSetFlags();
testBatchAction();
testSearch();

summary();
