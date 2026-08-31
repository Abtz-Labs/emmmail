<?php
declare(strict_types=1);

/**
 * Emmmail — single-file PHP email client (IMAP + SMTP).
 */

const EMMMAIL_VERSION = '1.0.0';
const EMMMAIL_ROOT = __DIR__;
const EMMMAIL_ENV_FILE = EMMMAIL_ROOT . '/.env';
const EMMMAIL_SESSION_NAME = 'EMMMAILSESSID';
const EMMMAIL_SESSION_IDLE = 1800;
const EMMMAIL_SESSION_ABSOLUTE = 86400;
const EMMMAIL_LOGIN_MAX_ATTEMPTS = 5;
const EMMMAIL_LOGIN_WINDOW = 900;
const EMMMAIL_IP_MAX_ATTEMPTS = 15;
const EMMMAIL_MAX_MESSAGE_SIZE = 10485760;

define('EMMMAIL_DB_PATH', (getenv('EMMMAIL_DB') ?: (parseEnvFile(EMMMAIL_ENV_FILE)['EMMMAIL_DB'] ?? EMMMAIL_ROOT . '/emmmail.sqlite')));
define('EMMMAIL_ATTACH_DIR', (getenv('EMMMAIL_ATTACHMENTS') ?: (parseEnvFile(EMMMAIL_ENV_FILE)['EMMMAIL_ATTACHMENTS'] ?? EMMMAIL_ROOT . '/attachments')));

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

if (getenv('EMMMAIL_DEBUG')) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

@header_remove('X-Powered-By');

/* ---------------------------------------------------------------------------
 * .env Parser + Encryption (AES-256-GCM)
 * ------------------------------------------------------------------------ */

function parseEnvFile(string $path): array
{
    $env = [];
    if (!is_file($path)) return $env;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return $env;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function getEncryptionKey(): string
{
    if ($key = getenv('EMMMAIL_KEY')) return $key;

    $env = parseEnvFile(EMMMAIL_ENV_FILE);
    if (!empty($env['EMMMAIL_KEY'])) {
        putenv('EMMMAIL_KEY=' . $env['EMMMAIL_KEY']);
        return $env['EMMMAIL_KEY'];
    }

    $fp = @fopen(EMMMAIL_ENV_FILE, 'c');
    if ($fp) {
        flock($fp, LOCK_EX);
        $env = parseEnvFile(EMMMAIL_ENV_FILE);
        if (!empty($env['EMMMAIL_KEY'])) {
            flock($fp, LOCK_UN);
            fclose($fp);
            putenv('EMMMAIL_KEY=' . $env['EMMMAIL_KEY']);
            return $env['EMMMAIL_KEY'];
        }
        $key = bin2hex(random_bytes(32));
        fwrite($fp, "EMMMAIL_KEY={$key}\n");
        flock($fp, LOCK_UN);
        fclose($fp);
        @chmod(EMMMAIL_ENV_FILE, 0600);
        putenv('EMMMAIL_KEY=' . $key);
        return $key;
    }

    $key = bin2hex(random_bytes(32));
    @file_put_contents(EMMMAIL_ENV_FILE, "EMMMAIL_KEY={$key}\n", FILE_APPEND | LOCK_EX);
    @chmod(EMMMAIL_ENV_FILE, 0600);
    putenv('EMMMAIL_KEY=' . $key);
    return $key;
}

function encryptPassword(string $password, string $key): array
{
    $iv = random_bytes(12);
    $tag = '';
    $encrypted = openssl_encrypt($password, 'aes-256-gcm', hex2bin($key), OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if ($encrypted === false) throw new RuntimeException('Encryption failed');
    return [
        'encrypted' => base64_encode($encrypted),
        'iv' => base64_encode($iv),
        'tag' => base64_encode($tag),
    ];
}

function decryptPassword(array $account, string $key): string
{
    $encrypted = base64_decode($account['password_encrypted'] ?? '', true);
    $iv = base64_decode($account['password_iv'] ?? '', true);
    $tag = base64_decode($account['password_tag'] ?? '', true);
    if ($encrypted === false || $iv === false || $tag === false || $encrypted === '' || $iv === '' || $tag === '') return '';
    $plain = @openssl_decrypt($encrypted, 'aes-256-gcm', hex2bin($key), OPENSSL_RAW_DATA, $iv, $tag, '');
    return $plain === false ? '' : $plain;
}

/* ---------------------------------------------------------------------------
 * Database schema + migrations
 * ------------------------------------------------------------------------ */

const EMMMAIL_SCHEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL,
    ip TEXT NOT NULL,
    attempted_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_login_attempts_email_ip ON login_attempts(email, ip);
CREATE INDEX IF NOT EXISTS idx_login_attempts_time ON login_attempts(attempted_at);

CREATE TABLE IF NOT EXISTS accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    display_name TEXT,
    imap_host TEXT NOT NULL,
    imap_port INTEGER NOT NULL DEFAULT 993,
    imap_tls INTEGER NOT NULL DEFAULT 1,
    smtp_host TEXT NOT NULL,
    smtp_port INTEGER NOT NULL DEFAULT 587,
    smtp_tls INTEGER NOT NULL DEFAULT 2,
    password_encrypted TEXT NOT NULL,
    password_iv TEXT NOT NULL,
    password_tag TEXT NOT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    last_sync_at TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    role TEXT,
    uid_validity INTEGER,
    last_uid INTEGER DEFAULT 0,
    unread_count INTEGER DEFAULT 0,
    total_count INTEGER DEFAULT 0,
    UNIQUE(account_id, name)
);
CREATE INDEX IF NOT EXISTS idx_folders_account ON folders(account_id);

CREATE TABLE IF NOT EXISTS threads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    thread_key TEXT NOT NULL,
    subject TEXT,
    last_message_at TEXT NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 0,
    preview TEXT,
    is_read INTEGER NOT NULL DEFAULT 0,
    is_starred INTEGER NOT NULL DEFAULT 0,
    UNIQUE(account_id, thread_key)
);
CREATE INDEX IF NOT EXISTS idx_threads_account_date ON threads(account_id, last_message_at DESC);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    folder_name TEXT NOT NULL,
    uid INTEGER NOT NULL,
    message_id TEXT,
    in_reply_to TEXT,
    refs TEXT,
    subject TEXT,
    from_address TEXT,
    from_name TEXT,
    to_addresses TEXT,
    cc_addresses TEXT,
    date TEXT,
    text_body TEXT,
    html_body TEXT,
    thread_id INTEGER REFERENCES threads(id),
    is_read INTEGER NOT NULL DEFAULT 0,
    is_starred INTEGER NOT NULL DEFAULT 0,
    has_attachments INTEGER NOT NULL DEFAULT 0,
    size INTEGER,
    raw_headers TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, folder_name, uid)
);
CREATE INDEX IF NOT EXISTS idx_messages_thread ON messages(thread_id);
CREATE INDEX IF NOT EXISTS idx_messages_account_folder ON messages(account_id, folder_name);
CREATE INDEX IF NOT EXISTS idx_messages_account_date ON messages(account_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_messages_message_id ON messages(message_id);

CREATE TABLE IF NOT EXISTS drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    to_addresses TEXT,
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject TEXT,
    body TEXT,
    body_html TEXT,
    attachments TEXT,
    in_reply_to INTEGER,
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_drafts_account ON drafts(account_id);

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    content_type TEXT NOT NULL DEFAULT 'application/octet-stream',
    size INTEGER NOT NULL DEFAULT 0,
    file_path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_attachments_message ON attachments(message_id);
CREATE INDEX IF NOT EXISTS idx_attachments_account ON attachments(account_id);

CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_token TEXT NOT NULL UNIQUE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    ip TEXT NOT NULL,
    user_agent TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    last_activity_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_sessions_token ON sessions(session_token);
CREATE INDEX IF NOT EXISTS idx_sessions_account ON sessions(account_id);
CREATE INDEX IF NOT EXISTS idx_sessions_activity ON sessions(last_activity_at);

CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    email TEXT NOT NULL,
    name TEXT,
    use_count INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, email)
);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL COLLATE NOCASE,
    color TEXT NOT NULL DEFAULT '#6366f1',
    UNIQUE(account_id, name)
);

CREATE TABLE IF NOT EXISTS message_tags (
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (message_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_message_tags_tag ON message_tags(tag_id);

CREATE TABLE IF NOT EXISTS settings (
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    value TEXT NOT NULL,
    PRIMARY KEY (account_id, name)
);
SQL;

function initDatabase(PDO $pdo): void
{
    static $done = false;
    if ($done) return;
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $statements = [];
    $current = '';
    foreach (explode("\n", EMMMAIL_SCHEMA) as $line) {
        $current .= $line . "\n";
        if (str_ends_with(rtrim($line), ';') && !str_ends_with(rtrim($line), 'END;')) {
            $statements[] = trim($current);
            $current = '';
        }
    }
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->exec('PRAGMA user_version = 1');
    migrateDatabase($pdo);
    $done = true;
}

function migrateDatabase(PDO $pdo): void
{
    $version = (int) $pdo->query('PRAGMA user_version')->fetchColumn();
    if ($version < 2) {
        try {
            $pdo->exec(<<<'SQL'
                CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
                    subject, from_address, from_name, text_body,
                    content='messages', content_rowid='id'
                )
            SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS messages_ai AFTER INSERT ON messages BEGIN
                    INSERT INTO messages_fts(rowid, subject, from_address, from_name, text_body)
                    VALUES (new.id, new.subject, new.from_address, new.from_name, new.text_body);
                END
            SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS messages_ad AFTER DELETE ON messages BEGIN
                    INSERT INTO messages_fts(messages_fts, rowid, subject, from_address, from_name, text_body)
                    VALUES ('delete', old.id, old.subject, old.from_address, old.from_name, old.text_body);
                END
            SQL);
            $pdo->exec(<<<'SQL'
                CREATE TRIGGER IF NOT EXISTS messages_au AFTER UPDATE ON messages BEGIN
                    INSERT INTO messages_fts(messages_fts, rowid, subject, from_address, from_name, text_body)
                    VALUES ('delete', old.id, old.subject, old.from_address, old.from_name, old.text_body);
                    INSERT INTO messages_fts(rowid, subject, from_address, from_name, text_body)
                    VALUES (new.id, new.subject, new.from_address, new.from_name, new.text_body);
                END
            SQL);
            $pdo->exec("INSERT INTO messages_fts(messages_fts) VALUES('rebuild')");
            $pdo->exec('PRAGMA user_version = 2');
        } catch (Throwable $e) {
            // FTS5 not available; search will return 501
        }
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    $dir = dirname(EMMMAIL_DB_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (!is_dir(EMMMAIL_ATTACH_DIR)) @mkdir(EMMMAIL_ATTACH_DIR, 0755, true);
    $pdo = new PDO('sqlite:' . EMMMAIL_DB_PATH, null, null, [PDO::ATTR_TIMEOUT => 5]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    initDatabase($pdo);
    return $pdo;
}

function fetchOne(PDO $pdo, string $sql, array $params = []): ?array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch();
    return $row === false ? null : $row;
}

function fetchAll(PDO $pdo, string $sql, array $params = []): array
{
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/* ---------------------------------------------------------------------------
 * Pure PHP IMAP client
 * ------------------------------------------------------------------------ */

enum TlsMode: int
{
    case None = 0;
    case Ssl = 1;
    case StartTls = 2;
}

class ImapClient
{
    private $sock = null;
    private string $inbuf = '';
    private int $timeout;
    private int $tagCounter = 0;
    private string $host;
    private int $port;
    private int $tlsMode;
    private string $user;
    private string $pass;

    public function __construct(string $host, int $port, int $tlsMode, string $user, string $pass, int $timeout = 10)
    {
        validateHost($host);
        $this->host = $host;
        $this->port = $port;
        $this->tlsMode = TlsMode::tryFrom($tlsMode)?->value ?? TlsMode::Ssl->value;
        $this->user = $user;
        $this->pass = $pass;
        $this->timeout = $timeout;
    }

    private function streamContext(): array
    {
        $verify = getenv('EMMMAIL_VERIFY_SSL') !== '0';
        return [
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'SNI_enabled' => true,
                'allow_self_signed' => false,
                'disable_compression' => true,
            ],
        ];
    }

    public function connect(): void
    {
        $transport = $this->tlsMode === TlsMode::Ssl->value
            ? 'ssl://' . $this->host . ':' . $this->port
            : 'tcp://' . $this->host . ':' . $this->port;
        $errno = 0;
        $errstr = '';
        $this->sock = @stream_socket_client($transport, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, stream_context_create($this->streamContext()));
        if ($this->sock === false) {
            throw new RuntimeException('IMAP: ' . ($errstr !== '' ? $errstr : 'connection failed'));
        }
        stream_set_timeout($this->sock, $this->timeout);
        $greeting = $this->readLine();
        if (!preg_match('/^\* (OK|PREAUTH|BYE)/i', $greeting)) {
            throw new RuntimeException('IMAP: unexpected greeting');
        }
        if ($this->tlsMode === TlsMode::StartTls->value) {
            $res = $this->command('STARTTLS');
            if ($res['status'] !== 'OK') throw new RuntimeException('IMAP: STARTTLS failed');
            $ok = stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new RuntimeException('IMAP: TLS handshake failed');
        }
    }

    public function login(): void
    {
        $res = $this->command('LOGIN ' . $this->quote($this->user) . ' ' . $this->quote($this->pass));
        if ($res['status'] === 'OK') return;

        $tag = 'A' . (++$this->tagCounter);
        $this->sendLine($tag . ' AUTHENTICATE PLAIN');
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) break;
            if (preg_match('/^' . preg_quote($tag, '/') . ' (NO|BAD)/i', $line)) {
                throw new RuntimeException('IMAP login failed');
            }
        }
        $this->sendLine(base64_encode("\0" . $this->user . "\0" . $this->pass));
        while (true) {
            $line = $this->readLine();
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $line, $m)) {
                if (strtoupper($m[1]) !== 'OK') throw new RuntimeException('IMAP login failed');
                return;
            }
        }
    }

    public function connectWithRetry(int $attempts = 3): void
    {
        for ($i = 1; ; $i++) {
            try {
                $this->connect();
                $this->login();
                return;
            } catch (Throwable $e) {
                $this->close();
                if ($i >= $attempts) {
                    throw $e;
                }
                usleep(300000 * $i);
            }
        }
    }

    public function listFolders(): array
    {
        $res = $this->command('LIST "" "*"');
        $out = [];
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] !== 'line') continue;
            $line = $entry['text'];
            if (preg_match('/^\* LIST \((.*)\) ("[^"]*"|nil) (.+)$/i', $line, $m)) {
                $flags = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
                $name = $this->unquote($m[3]);
                $out[] = ['name' => $name, 'flags' => $flags];
            }
        }
        return $out;
    }

    public function selectFolder(string $name): array
    {
        $res = $this->command('SELECT ' . $this->quote($name));
        if ($res['status'] !== 'OK') {
            throw new RuntimeException('IMAP SELECT failed: ' . $res['message']);
        }
        $uidValidity = null;
        $exists = 0;
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] !== 'line') continue;
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $entry['text'], $m)) $uidValidity = (int) $m[1];
            if (preg_match('/^\* (\d+) EXISTS/i', $entry['text'], $m)) $exists = (int) $m[1];
        }
        $status = $this->statusFolder($name);
        return [
            'exists' => $exists,
            'uid_validity' => $uidValidity,
            'unseen' => (int) $status['unseen'],
            'uidnext' => (int) ($status['uidnext'] ?? 0),
        ];
    }

    public function statusFolder(string $name): array
    {
        $res = $this->command('STATUS ' . $this->quote($name) . ' (UIDVALIDITY MESSAGES UNSEEN UIDNEXT)');
        $out = ['uid_validity' => null, 'messages' => 0, 'unseen' => 0, 'uidnext' => null];
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] !== 'line') continue;
            if (preg_match('/^\* STATUS .*\((.*)\)$/i', $entry['text'], $m)) {
                $items = $this->parseFetchItems($m[1]);
                foreach ($items as $k => $v) {
                    $key = strtolower((string) $k);
                    if (array_key_exists($key, $out) && is_numeric($v)) {
                        $out[$key] = (int) $v;
                    }
                }
            }
        }
        return $out;
    }

    public function fetchMessages(): array
    {
        $res = $this->command('UID FETCH 1:* (FLAGS)');
        $msgs = [];
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] !== 'line') continue;
            if (preg_match('/^\* \d+ FETCH \((.*)\)$/i', $entry['text'], $m)) {
                $items = $this->parseFetchItems($m[1]);
                if (isset($items['UID'])) {
                    $msgs[] = [
                        'uid' => (int) $items['UID'],
                        'flags' => $items['FLAGS'] ?? [],
                    ];
                }
            }
        }
        return $msgs;
    }

    public function fetchMessageBody(int $uid): array
    {
        $res = $this->command('UID FETCH ' . $uid . ' (BODY.PEEK[] FLAGS)');
        $raw = '';
        $flags = [];
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] === 'literal') {
                $raw = $entry['text'];
                continue;
            }
            if (preg_match('/FLAGS \(([^)]*)\)/i', $entry['text'], $m)) {
                $flags = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        return ['uid' => $uid, 'raw' => $raw, 'flags' => $flags];
    }

    public function searchSince(string $date): array
    {
        $res = $this->command('UID SEARCH SINCE ' . $date);
        $uids = [];
        foreach ($res['lines'] as $entry) {
            if ($entry['type'] !== 'line') continue;
            if (preg_match('/^\* SEARCH (.*)$/i', $entry['text'], $m)) {
                $uids = array_map('intval', preg_split('/\s+/', trim($m[1])));
            }
        }
        return $uids;
    }

    public function fetchBodies(array $uids): array
    {
        if (empty($uids)) return [];
        $out = [];
        foreach (array_chunk($uids, 50) as $chunk) {
            $res = $this->command('UID FETCH ' . implode(',', $chunk) . ' (BODY.PEEK[] FLAGS)');
            $out = array_merge($out, $this->parseBodies($res['lines']));
        }
        return $out;
    }

    private function parseBodies(array $lines): array
    {
        $result = [];
        $pendingUid = null;
        foreach ($lines as $entry) {
            if ($entry['type'] === 'literal') {
                if ($pendingUid !== null) {
                    $result[$pendingUid]['raw'] = $entry['text'];
                }
                continue;
            }
            $text = $entry['text'];
            if (preg_match('/^\* \d+ FETCH \(.*\bUID (\d+)/i', $text, $m)) {
                $pendingUid = (int) $m[1];
                if (!isset($result[$pendingUid])) {
                    $result[$pendingUid] = ['uid' => $pendingUid, 'raw' => '', 'flags' => []];
                }
                continue;
            }
            if (preg_match('/FLAGS \(([^)]*)\)/i', $text, $m) && $pendingUid !== null) {
                $result[$pendingUid]['flags'] = preg_split('/\s+/', trim($m[1]), -1, PREG_SPLIT_NO_EMPTY);
            }
        }
        return array_values($result);
    }

    public function appendMessage(string $folder, string $message, array $flags = []): void
    {
        $flagStr = '';
        if (!empty($flags)) {
            $flagStr = ' (' . implode(' ', $flags) . ')';
        }
        $len = strlen($message);
        $tag = 'A' . (++$this->tagCounter);
        $this->sendLine($tag . ' APPEND ' . $this->quote($folder) . $flagStr . ' {' . $len . '}');
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) {
                $this->sendLine($message);
                $this->sendLine('');
                continue;
            }
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $line, $m)) {
                if (strtoupper($m[1]) !== 'OK') {
                    throw new RuntimeException('IMAP APPEND failed: ' . trim($m[0]));
                }
                return;
            }
        }
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function logout(): void
    {
        try {
            $this->command('LOGOUT');
        } catch (Throwable $e) {
            // ignore
        }
        $this->close();
    }

    public static function detectRole(string $name, array $flags): ?string
    {
        $map = [
            '\\inbox' => 'inbox',
            '\\sent' => 'sent',
            '\\drafts' => 'drafts',
            '\\trash' => 'trash',
            '\\junk' => 'junk',
            '\\archive' => 'archive',
        ];
        foreach ($flags as $f) {
            $f = strtolower($f);
            if (isset($map[$f])) return $map[$f];
        }
        $n = strtolower($name);
        if ($n === 'inbox') return 'inbox';
        if (preg_match('/(^|[^a-z])sent/i', $n)) return 'sent';
        if (preg_match('/draft/i', $n)) return 'drafts';
        if (preg_match('/trash|deleted|bin/i', $n)) return 'trash';
        if (preg_match('/junk|spam/i', $n)) return 'junk';
        if (preg_match('/archive|all mail|allmail/i', $n)) return 'archive';
        return null;
    }

    private function command(string $cmd): array
    {
        $tag = 'A' . (++$this->tagCounter);
        $this->sendLine($tag . ' ' . $cmd);
        $lines = [];
        while (true) {
            $line = $this->readLine();
            $entry = ['type' => 'line', 'text' => $line];
            $lines[] = $entry;
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $size = (int) $m[1];
                $lit = $this->readRaw($size);
                $lines[] = ['type' => 'literal', 'text' => $lit];
                $this->consumeCrlf();
            }
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)(?: (.*))?$/i', $line, $m)) {
                return [
                    'status' => strtoupper($m[1]),
                    'message' => trim($m[2] ?? ''),
                    'lines' => $lines,
                ];
            }
        }
    }

    private function sendLine(string $line): void
    {
        if (@fwrite($this->sock, $line . "\r\n") === false) {
            throw new RuntimeException('IMAP: write failed');
        }
    }

    private function readRaw(int $n): string
    {
        if ($n > EMMMAIL_MAX_MESSAGE_SIZE) {
            throw new RuntimeException('IMAP: message too large (' . $n . ' bytes)');
        }
        $out = '';
        while (strlen($out) < $n) {
            if ($this->inbuf === '') {
                $chunk = @fread($this->sock, 8192);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($this->sock);
                    if (!empty($meta['timed_out'])) throw new RuntimeException('IMAP: read timeout');
                    throw new RuntimeException('IMAP: connection lost');
                }
                $this->inbuf .= $chunk;
            }
            $take = min(strlen($this->inbuf), $n - strlen($out));
            $out .= substr($this->inbuf, 0, $take);
            $this->inbuf = substr($this->inbuf, $take);
        }
        return $out;
    }

    private function readLine(): string
    {
        $line = '';
        while (true) {
            $pos = strpos($this->inbuf, "\n");
            if ($pos !== false) {
                $line .= substr($this->inbuf, 0, $pos);
                $this->inbuf = substr($this->inbuf, $pos + 1);
                return rtrim($line, "\r");
            }
            $line .= $this->inbuf;
            $this->inbuf = '';
            $chunk = @fread($this->sock, 8192);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->sock);
                if (!empty($meta['timed_out'])) throw new RuntimeException('IMAP: read timeout');
                throw new RuntimeException('IMAP: connection lost');
            }
            $this->inbuf = $chunk;
        }
    }

    private function consumeCrlf(): void
    {
        $c = $this->readRaw(1);
        if ($c === "\r") {
            $this->readRaw(1);
        }
    }

    private function quote(string $s): string
    {
        return '"' . strtr($s, ['\\' => '\\\\', '"' => '\\"']) . '"';
    }

    private function unquote(string $s): string
    {
        $s = trim($s);
        if (str_starts_with($s, '"') && str_ends_with($s, '"')) {
            $s = substr($s, 1, -1);
        }
        return strtr($s, ['\\\\' => '\\', '\\"' => '"']);
    }

    private function tokenize(string $s): array
    {
        $tokens = [];
        $len = strlen($s);
        $i = 0;
        $depth = 0;
        $current = '';
        $inQuote = false;
        while ($i < $len) {
            $c = $s[$i];
            if ($inQuote) {
                if ($c === '\\' && $i + 1 < $len) {
                    $current .= $s[$i] . $s[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === '"') {
                    $inQuote = false;
                    $tokens[] = $current;
                    $current = '';
                    $i++;
                    continue;
                }
                $current .= $c;
                $i++;
                continue;
            }
            if ($c === '"') {
                $inQuote = true;
                $i++;
                continue;
            }
            if ($c === '(') {
                $depth++;
                $current .= $c;
                $i++;
                continue;
            }
            if ($c === ')') {
                $depth--;
                $current .= $c;
                $i++;
                continue;
            }
            if ($c === ' ' && $depth === 0) {
                if ($current !== '') {
                    $tokens[] = $current;
                    $current = '';
                }
                $i++;
                continue;
            }
            $current .= $c;
            $i++;
        }
        if ($current !== '') $tokens[] = $current;
        return $tokens;
    }

    private function parseFetchItems(string $s): array
    {
        $items = [];
        $tokens = $this->tokenize($s);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $t = $tokens[$i];
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && str_starts_with($next, '(') && str_ends_with($next, ')')) {
                $inner = substr($next, 1, -1);
                $items[strtoupper($t)] = $this->parseFlagList($inner);
                $i++;
                continue;
            }
            if ($next !== null && (preg_match('/^-?\d+$/', $next) || str_starts_with($next, '{'))) {
                $items[strtoupper($t)] = $next;
                $i++;
                continue;
            }
            $items[strtoupper($t)] = true;
        }
        return $items;
    }

    private function parseFlagList(string $inner): array
    {
        if ($inner === '') return [];
        return preg_split('/\s+/', trim($inner), -1, PREG_SPLIT_NO_EMPTY);
    }
}

/* ---------------------------------------------------------------------------
 * Pure PHP SMTP client
 * ------------------------------------------------------------------------ */

final class SmtpClient
{
    private $sock = null;
    private int $timeout;
    private string $host;
    private int $port;
    private int $tlsMode;
    private string $user;
    private string $pass;
    private array $ehlo = [];

    public function __construct(string $host, int $port, int $tlsMode, string $user, string $pass, int $timeout = 10)
    {
        validateHost($host);
        $this->host = $host;
        $this->port = $port;
        $this->tlsMode = TlsMode::tryFrom($tlsMode)?->value ?? TlsMode::StartTls->value;
        $this->user = $user;
        $this->pass = $pass;
        $this->timeout = $timeout;
    }

    public function connect(): void
    {
        $transport = $this->tlsMode === TlsMode::Ssl->value
            ? 'ssl://' . $this->host . ':' . $this->port
            : 'tcp://' . $this->host . ':' . $this->port;
        $verify = getenv('EMMMAIL_VERIFY_SSL') !== '0';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'SNI_enabled' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $this->sock = @stream_socket_client($transport, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($this->sock === false) {
            throw new RuntimeException('SMTP: ' . ($errstr !== '' ? $errstr : 'connection failed'));
        }
        stream_set_timeout($this->sock, $this->timeout);
        $greeting = $this->readReply();
        if ($greeting['code'] !== 220) {
            throw new RuntimeException('SMTP: unexpected greeting ' . $greeting['code']);
        }
        if ($this->tlsMode === TlsMode::StartTls->value) {
            $this->ehlo();
            if (!in_array('STARTTLS', $this->ehlo, true)) {
                throw new RuntimeException('SMTP: STARTTLS not offered by server');
            }
            $res = $this->cmd('STARTTLS');
            if ($res['code'] !== 220) throw new RuntimeException('SMTP: STARTTLS failed');
            $ok = stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($ok !== true) throw new RuntimeException('SMTP: TLS handshake failed');
            $this->ehlo = [];
            $this->ehlo();
        } else {
            $this->ehlo();
        }
    }

    public function login(): void
    {
        if (in_array('AUTH', $this->ehlo, true) && in_array('PLAIN', $this->ehlo, true)) {
            $b64 = base64_encode("\0" . $this->user . "\0" . $this->pass);
            $res = $this->cmd('AUTH PLAIN ' . $b64);
            if ($res['code'] === 235) return;
        }
        $res = $this->cmd('AUTH LOGIN');
        if ($res['code'] !== 334) {
            throw new RuntimeException('SMTP: server does not allow authentication');
        }
        $this->cmd(base64_encode($this->user));
        $res = $this->cmd(base64_encode($this->pass));
        if ($res['code'] !== 235) {
            throw new RuntimeException('SMTP authentication failed');
        }
    }

    public function send(string $from, array $to, string $message): void
    {
        $from = sanitizeHeader($from);
        $res = $this->cmd('MAIL FROM:<' . $from . '>');
        if ($res['code'] !== 250) {
            throw new RuntimeException('SMTP MAIL FROM failed: ' . $res['code']);
        }
        foreach ($to as $addr) {
            $addr = sanitizeHeader($addr);
            $res = $this->cmd('RCPT TO:<' . $addr . '>');
            if ($res['code'] !== 250 && $res['code'] !== 251) {
                throw new RuntimeException('SMTP RCPT TO failed: ' . $res['code']);
            }
        }
        $res = $this->cmd('DATA');
        if ($res['code'] !== 354) {
            throw new RuntimeException('SMTP DATA failed: ' . $res['code']);
        }
        $lines = explode("\r\n", $message);
        foreach ($lines as $line) {
            if (str_starts_with($line, '.')) {
                $line = '.' . $line;
            }
            $this->sendLine($line);
        }
        $this->sendLine('.');
        $res = $this->readReply();
        if ($res['code'] !== 250) {
            throw new RuntimeException('SMTP message failed: ' . $res['code']);
        }
    }

    public function close(): void
    {
        if ($this->sock !== null) {
            @fclose($this->sock);
            $this->sock = null;
        }
    }

    public function quit(): void
    {
        try {
            $this->cmd('QUIT');
        } catch (Throwable $e) {
            // ignore
        }
        $this->close();
    }

    private function ehlo(): void
    {
        $res = $this->cmd('EHLO ' . $this->host);
        if ($res['code'] !== 250) {
            $res = $this->cmd('HELO ' . $this->host);
            if ($res['code'] !== 250) {
                throw new RuntimeException('SMTP: EHLO/HELO failed');
            }
        }
        foreach ($res['lines'] as $line) {
            $line = preg_replace('/^[0-9]{3}[ -]/', '', trim($line));
            foreach (preg_split('/\s+/', $line) as $tok) {
                if ($tok !== '') $this->ehlo[] = strtoupper($tok);
            }
        }
    }

    private function cmd(string $line): array
    {
        $this->sendLine($line);
        return $this->readReply();
    }

    private function sendLine(string $line): void
    {
        if (@fwrite($this->sock, $line . "\r\n") === false) {
            throw new RuntimeException('SMTP: write failed');
        }
    }

    private function readReply(): array
    {
        $lines = [];
        $code = 0;
        while (true) {
            $line = fgets($this->sock);
            if ($line === false) {
                $meta = stream_get_meta_data($this->sock);
                throw new RuntimeException('SMTP: connection lost' . (!empty($meta['timed_out']) ? ' (timeout)' : ''));
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') continue;
            $lines[] = $line;
            if (strlen($line) >= 4 && ctype_digit(substr($line, 0, 3))) {
                $code = (int) substr($line, 0, 3);
                if (substr($line, 3, 1) === ' ') break;
            }
        }
        return ['code' => $code, 'lines' => $lines];
    }
}

/* ---------------------------------------------------------------------------
 * Utility functions
 * ------------------------------------------------------------------------ */

function jsonResponse(array $data, int $status = 200, bool $exit = true): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($exit) exit;
}

function fail(string $message, int $status = 400): void
{
    jsonResponse(['ok' => false, 'error' => $message], $status);
}

function getInput(): array
{
    // CLI input override: when EMMMAIL_CLI_INPUT env var points to a file, read from it.
    $cliInput = getenv('EMMMAIL_CLI_INPUT');
    if ($cliInput !== false && is_file($cliInput)) {
        $raw = file_get_contents($cliInput);
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains(strtolower($contentType), 'application/json')) {
        $raw = file_get_contents('php://input');
        $data = json_decode((string) $raw, true);
        return is_array($data) ? $data : [];
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
        return $_GET;
    }
    return $_POST;
}

function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function isSafeHost(string $host): bool
{
    if (getenv('EMMMAIL_DISABLE_SSRF_CHECK') === '1') return true;

    $host = trim($host);
    if ($host === '') return false;

    $ip = @inet_pton($host);
    if ($ip === false) {
        $resolved = @gethostbyname($host);
        if ($resolved === $host) return false;
        $ip = @inet_pton($resolved);
        if ($ip === false) return false;
    }

    $unpacked = @unpack('n*', $ip);
    if ($unpacked === false) return false;

    if (strlen($ip) === 4) {
        $a = $unpacked[1] >> 8;
        $b = $unpacked[1] & 0xFF;
        if ($a === 10) return false;
        if ($a === 172 && $b >= 16 && $b <= 31) return false;
        if ($a === 192 && $b === 168) return false;
        if ($a === 127) return false;
        if ($a === 0) return false;
        if ($a === 169 && $b === 254) return false;
        if ($a >= 224) return false;
    }

    if (strlen($ip) === 16) {
        $firstWord = $unpacked[1];
        $allZero = true;
        for ($i = 2; $i <= 7; $i++) {
            if ($unpacked[$i] !== 0) { $allZero = false; break; }
        }
        if ($firstWord === 0 && $allZero && $unpacked[8] === 1) return false;
        if (($firstWord & 0xFE00) === 0xFC00) return false;
        if (($firstWord & 0xFFC0) === 0xFE80) return false;
        if ($firstWord === 0x2001 && $unpacked[2] === 0x0000) return false;
        if ($firstWord === 0 && $unpacked[2] === 0 && $unpacked[3] === 0 && $unpacked[4] === 0 && $unpacked[5] === 0 && $unpacked[6] === 0xFFFF) {
            $ipv4 = ($unpacked[7] << 16) | $unpacked[8];
            $a = ($ipv4 >> 24) & 0xFF;
            $b = ($ipv4 >> 16) & 0xFF;
            if ($a === 10) return false;
            if ($a === 172 && $b >= 16 && $b <= 31) return false;
            if ($a === 192 && $b === 168) return false;
            if ($a === 127) return false;
            if ($a === 0) return false;
            if ($a === 169 && $b === 254) return false;
            if ($a >= 224) return false;
        }
    }

    return true;
}

function validateHost(string $host): void
{
    if (!isSafeHost($host)) {
        fail("Connection to host not allowed: {$host}");
    }
}

function sanitizeHeader(string $s): string
{
    return str_replace(["\r", "\n", "\0"], '', $s);
}

function validateEmail(string $email): bool
{
    $email = trim($email);
    if ($email === '') return false;
    if (preg_match('/[\r\n\0]/', $email)) return false;
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function escapeHtml(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function escapeAttr(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function deriveDisplayName(string $email): string
{
    $local = explode('@', $email, 2)[0] ?? '';
    $name = preg_replace('/[._-]+/', ' ', $local);
    $name = preg_replace_callback('/\b\w/', fn($c) => strtoupper($c[0]), (string) $name);
    return trim((string) $name);
}

function parseAddresses(string $raw): array
{
    $out = [];
    $raw = trim($raw);
    if ($raw === '') return [];
    if (preg_match_all('/"?([^"<,]+?)"?\s*<([^>]+)>/', $raw, $m, PREG_SET_ORDER)) {
        foreach ($m as $mm) {
            $name = trim($mm[1]);
            $email = strtolower(trim($mm[2]));
            if ($email === '') continue;
            $out[] = ['name' => decodeMimeHeader($name), 'email' => $email];
        }
    }
    if (preg_match_all('/[^\s<>,]+@[^\s<>,]+/', $raw, $m)) {
        foreach ($m[0] as $em) {
            $em = strtolower(trim($em));
            if ($em === '') continue;
            if (!array_filter($out, fn($a) => $a['email'] === $em)) {
                $out[] = ['name' => '', 'email' => $em];
            }
        }
    }
    return $out;
}

function sanitizeFilename(string $name): string
{
    $name = str_replace("\0", '', $name);
    $name = str_replace(['/', '\\'], '_', $name);
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    $name = preg_replace('/[.]{2,}/', '.', $name);
    $name = trim($name);
    $name = mb_substr($name, 0, 180);
    return $name !== '' ? $name : 'attachment';
}

function normalizeDate(?string $date): string
{
    $date = trim((string) $date);
    if ($date === '') return gmdate('Y-m-d\TH:i:s\Z');
    $ts = strtotime($date);
    if ($ts === false) return gmdate('Y-m-d\TH:i:s\Z');
    return gmdate('Y-m-d\TH:i:s\Z', $ts);
}

function normalizeSubject(string $subject): string
{
    return trim(preg_replace('/^(Re|Fwd?|Fw|FW|RE):\s*/i', '', $subject));
}

function isBlockedAttachment(string $filename): bool
{
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return in_array($ext, [
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'pht', 'html', 'htm', 'xhtml',
        'js', 'mjs', 'cgi', 'pl', 'py', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com', 'scr',
        'hta', 'svg', 'vbs', 'ps1', 'jar', 'dll', 'so', 'msi', 'app',
    ], true);
}

function decodeMimeHeader(string $s): string
{
    $s = trim($s);
    if ($s === '') return '';
    if (function_exists('mb_decode_mimeheader')) {
        $dec = @mb_decode_mimeheader($s);
        if ($dec !== false && $dec !== '') return $dec;
    }
    $parts = preg_split('/(=\?[^?]+\?[BbQq]\?[^?]*\?=)/', $s, -1, PREG_SPLIT_DELIM_CAPTURE);
    $out = '';
    if (is_array($parts)) {
        foreach ($parts as $p) {
            if (preg_match('/^=\?([^?]+)\?([BbQq])\?([^?]*)\?=$/', $p, $m)) {
                $charset = $m[1];
                $raw = $m[2] === 'B' || $m[2] === 'b'
                    ? base64_decode($m[3])
                    : quoted_printable_decode(str_replace('_', ' ', $m[3]));
                $out .= convertCharset((string) $raw, $charset);
            } else {
                $out .= $p;
            }
        }
    }
    return trim($out);
}

function convertCharset(string $s, string $charset): string
{
    $charset = trim($charset);
    if ($charset === '' || strtolower($charset) === 'utf-8' || strtolower($charset) === 'us-ascii') {
        return $s;
    }
    if (function_exists('mb_convert_encoding')) {
        $out = @mb_convert_encoding($s, 'UTF-8', $charset);
        if ($out !== false && $out !== '') return $out;
    }
    if (function_exists('iconv')) {
        $out = @iconv($charset, 'UTF-8//TRANSLIT', $s);
        if ($out !== false) return $out;
    }
    return $s;
}

function recordLoginFailure(string $email, string $ip): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < datetime("now", "-1 day")');
}

function checkRateLimit(string $email, string $ip): void
{
    $pdo = db();
    $window = '-' . EMMMAIL_LOGIN_WINDOW . ' seconds';

    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= datetime("now", ?)');
    $st->execute([$ip, $window]);
    if ((int) $st->fetchColumn() >= EMMMAIL_IP_MAX_ATTEMPTS) {
        fail('Too many attempts. Try again later.', 429);
    }

    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND attempted_at >= datetime("now", ?)');
    $st->execute([$email, $ip, $window]);
    if ((int) $st->fetchColumn() >= EMMMAIL_LOGIN_MAX_ATTEMPTS) {
        fail('Too many login attempts for this account. Try again later.', 429);
    }
}

/* ---------------------------------------------------------------------------
 * Session Management
 * ------------------------------------------------------------------------ */

function createSession(int $accountId, string $ip, string $userAgent): string
{
    $pdo = db();
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('INSERT INTO sessions (session_token, account_id, ip, user_agent) VALUES (?, ?, ?, ?)')
        ->execute([$token, $accountId, $ip, $userAgent]);

    return $token;
}

function validateSession(string $token): ?array
{
    $pdo = db();
    $session = fetchOne($pdo, 'SELECT * FROM sessions WHERE session_token = ?', [$token]);

    if (!$session) return null;

    $lastActivity = strtotime($session['last_activity_at']);
    $createdAt = strtotime($session['created_at']);
    $now = time();

    if ($now - $lastActivity > EMMMAIL_SESSION_IDLE) {
        destroySession($token);
        return null;
    }

    if ($now - $createdAt > EMMMAIL_SESSION_ABSOLUTE) {
        destroySession($token);
        return null;
    }

    $currentIp = getClientIp();
    if ($session['ip'] !== $currentIp) {
        destroySession($token);
        return null;
    }

    $pdo->prepare('UPDATE sessions SET last_activity_at = datetime("now") WHERE session_token = ?')
        ->execute([$token]);

    return $session;
}

function destroySession(string $token): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM sessions WHERE session_token = ?')->execute([$token]);
}

function destroyAllSessions(int $accountId): void
{
    $pdo = db();
    $pdo->prepare('DELETE FROM sessions WHERE account_id = ?')->execute([$accountId]);
}

function cleanupExpiredSessions(): void
{
    $pdo = db();
    $pdo->exec('DELETE FROM sessions WHERE last_activity_at < datetime("now", "-1 day")');
}

function getSessionToken(): ?string
{
    $token = $_SERVER['HTTP_X_SESSION_TOKEN'] ?? '';
    if ($token !== '') return $token;

    $token = $_COOKIE[EMMMAIL_SESSION_NAME] ?? '';
    if ($token !== '') return $token;

    return null;
}

function requireSession(): array
{
    $token = getSessionToken();
    if (!$token) {
        fail('Authentication required. Provide session_token via X-Session-Token header, cookie, or request body.', 401);
    }

    $session = validateSession($token);
    if (!$session) {
        fail('Invalid or expired session. Please log in again.', 401);
    }

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$session['account_id']]);
    if (!$account || !$account['is_active']) {
        fail('Account not found or deactivated.', 401);
    }

    $GLOBALS['EMMMAIL_CURRENT_ACCOUNT'] = $account;
    return $account;
}

function currentAccountId(): int
{
    return (int) ($GLOBALS['EMMMAIL_CURRENT_ACCOUNT']['id'] ?? 0);
}

/* ---------------------------------------------------------------------------
 * MIME Parser
 * ------------------------------------------------------------------------ */

function parseMimeMessage(string $raw): array
{
    $sep = "\r\n\r\n";
    $sepLen = 4;
    $pos = strpos($raw, $sep);
    if ($pos === false) {
        $sep = "\n\n";
        $sepLen = 2;
        $pos = strpos($raw, $sep);
    }
    if ($pos === false) {
        $pos = strlen($raw);
        $sepLen = 0;
    }
    $headerBlock = substr($raw, 0, $pos);
    $body = substr($raw, $pos + $sepLen);

    $headers = parseRawHeaders($headerBlock);
    $result = [
        'headers' => $headers,
        'text_body' => '',
        'html_body' => '',
        'attachments' => [],
        'raw_headers' => $headerBlock,
    ];

    $contentType = headerValue($headers, 'content-type');
    $transferEncoding = headerValue($headers, 'content-transfer-encoding');
    if (stripos($contentType, 'multipart/') === 0) {
        $boundary = extractBoundary($contentType);
        if ($boundary !== null) {
            foreach (splitMimeParts($body, $boundary) as $part) {
                parseMimePart($part, $result);
            }
        } else {
            parseBodyAsSingle($body, $contentType, $transferEncoding, $result);
        }
    } else {
        parseBodyAsSingle($body, $contentType, $transferEncoding, $result);
    }
    return $result;
}

function parseRawHeaders(string $block): array
{
    $lines = preg_split('/\r?\n/', $block);
    $headers = [];
    $lastKey = null;
    foreach ($lines as $line) {
        if ($line === '') continue;
        if (($line[0] === ' ' || $line[0] === "\t") && $lastKey !== null) {
            $headers[$lastKey] .= ' ' . trim($line);
        } else {
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $key = strtolower(trim(substr($line, 0, $pos)));
            $val = trim(substr($line, $pos + 1));
            $headers[$key] = $val;
            $lastKey = $key;
        }
    }
    return $headers;
}

function headerValue(array $headers, string $key): string
{
    return $headers[$key] ?? '';
}

function extractBoundary(string $contentType): ?string
{
    if (preg_match('/boundary\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {
        return trim($m[1], '"');
    }
    return null;
}

function splitMimeParts(string $body, string $boundary): array
{
    $regex = '/--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*\r?\n/';
    $parts = preg_split($regex, $body);
    if ($parts === false || count($parts) < 2) return [];
    array_shift($parts);
    if (str_ends_with(rtrim($body), '--' . $boundary . '--')) {
        array_pop($parts);
    }
    return array_map(fn($p) => rtrim($p, "\r\n"), $parts);
}

function parseBodyAsSingle(string $body, string $contentType, string $transferEncoding, array &$result): void
{
    parseMimePartRaw($body, $contentType, $transferEncoding, '', $result);
}

function parseMimePart(string $raw, array &$result): void
{
    $sep = "\r\n\r\n";
    $sepLen = 4;
    $pos = strpos($raw, $sep);
    if ($pos === false) {
        $sep = "\n\n";
        $sepLen = 2;
        $pos = strpos($raw, $sep);
    }
    if ($pos === false) {
        $pos = 0;
        $sepLen = 0;
    }
    $headerBlock = substr($raw, 0, $pos);
    $body = substr($raw, $pos + $sepLen);
    $headers = parseRawHeaders($headerBlock);
    parseMimePartRaw($body, headerValue($headers, 'content-type'), headerValue($headers, 'content-transfer-encoding'), headerValue($headers, 'content-disposition'), $result);
}

function parseMimePartRaw(string $body, string $contentType, string $transferEncoding, string $disposition, array &$result): void
{
    $ctype = strtolower(trim(explode(';', $contentType, 2)[0]));
    if (str_starts_with($ctype, 'multipart/')) {
        $boundary = extractBoundary($contentType);
        if ($boundary !== null) {
            foreach (splitMimeParts($body, $boundary) as $sub) {
                parseMimePart($sub, $result);
            }
        }
        return;
    }
    $charset = 'utf-8';
    if (preg_match('/charset\s*=\s*"?([^";]+)"?/i', $contentType, $m)) {
        $charset = trim($m[1], '"');
    }
    $decoded = decodeTransfer($body, $transferEncoding);
    $decoded = convertCharset($decoded, $charset);

    $isAttachment = stripos($disposition, 'attachment') !== false
        || stripos($disposition, 'inline') !== false
        || str_starts_with($ctype, 'application/')
        || str_starts_with($ctype, 'image/')
        || str_starts_with($ctype, 'audio/')
        || str_starts_with($ctype, 'video/')
        || str_starts_with($ctype, 'font/');

    if ($isAttachment) {
        $filename = '';
        if (preg_match('/filename\*\s*=\s*([^;]+)/i', $contentType . ';' . $disposition, $m)) {
            $filename = decodeRfc2231($m[1]);
        }
        if ($filename === '') {
            if (preg_match('/filename\s*=\s*"?([^";]+)"?/i', $disposition, $m)) $filename = trim($m[1], '"');
            if ($filename === '' && preg_match('/filename\s*=\s*"?([^";]+)"?/i', $contentType, $m)) $filename = trim($m[1], '"');
        }
        if ($filename === '') $filename = 'attachment-' . (count($result['attachments']) + 1) . '.bin';
        $result['attachments'][] = [
            'filename' => sanitizeFilename(decodeMimeHeader($filename)),
            'content_type' => $ctype !== '' ? $ctype : 'application/octet-stream',
            'size' => strlen($decoded),
            'data' => $decoded,
        ];
        return;
    }
    if ($ctype === 'text/html' || str_contains($ctype, 'html')) {
        $result['html_body'] .= $decoded;
    } elseif ($ctype === 'text/plain' || $ctype === '' || str_starts_with($ctype, 'text/')) {
        $result['text_body'] .= $decoded;
    }
}

function decodeTransfer(string $data, string $transferEncoding): string
{
    $te = strtolower(trim($transferEncoding));
    if ($te === 'base64') {
        $clean = str_replace(["\r", "\n", ' ', "\t"], '', $data);
        $decoded = base64_decode($clean, true);
        return $decoded === false ? '' : $decoded;
    }
    if ($te === 'quoted-printable') {
        return quoted_printable_decode($data);
    }
    return $data;
}

function decodeRfc2231(string $s): string
{
    $s = trim($s, " \t\n\r");
    $parts = explode("'", $s, 3);
    if (count($parts) === 3) return rawurldecode($parts[2]);
    return rawurldecode($s);
}

/* ---------------------------------------------------------------------------
 * MIME Builder
 * ------------------------------------------------------------------------ */

function generateMessageId(string $domain): string
{
    return bin2hex(random_bytes(16)) . '@' . $domain;
}

function foldHeader(string $header): string
{
    if (strlen($header) <= 78) return $header;
    $parts = preg_split('/:\s*/', $header, 2);
    if (count($parts) !== 2) return $header;
    $name = $parts[0];
    $value = $parts[1];
    $lines = [$name . ': ' . $value];
    while (strlen(end($lines)) > 78) {
        $current = end($lines);
        array_pop($lines);
        $cut = strrpos(substr($current, 0, 78), ' ');
        if ($cut === false || $cut < strlen($name) + 2) $cut = 78;
        $lines[] = substr($current, 0, $cut);
        $lines[] = ' ' . substr($current, $cut);
    }
    return implode("\r\n", $lines);
}

function buildMimeMessage(array $params): string
{
    $from = sanitizeHeader($params['from'] ?? '');
    $to = array_map('sanitizeHeader', $params['to'] ?? []);
    $cc = array_map('sanitizeHeader', $params['cc'] ?? []);
    $bcc = array_map('sanitizeHeader', $params['bcc'] ?? []);
    $subject = sanitizeHeader($params['subject'] ?? '');
    $body = $params['body'] ?? '';
    $bodyHtml = $params['body_html'] ?? '';
    $attachments = $params['attachments'] ?? [];
    $inReplyTo = sanitizeHeader($params['in_reply_to'] ?? '');
    $date = $params['date'] ?? '';
    $messageId = $params['message_id'] ?? '';

    if ($date === '') $date = date('r');
    if ($messageId === '') $domain = explode('@', $from)[1] ?? 'localhost';
    if ($messageId === '') $messageId = generateMessageId($domain);

    $headers = [];
    $headers[] = 'From: ' . $from;
    $headers[] = 'To: ' . implode(', ', $to);
    if (!empty($cc)) $headers[] = 'Cc: ' . implode(', ', $cc);
    $headers[] = 'Subject: ' . $subject;
    $headers[] = 'Date: ' . $date;
    $headers[] = 'Message-ID: <' . $messageId . '>';
    $headers[] = 'MIME-Version: 1.0';
    if ($inReplyTo !== '') $headers[] = 'In-Reply-To: <' . $inReplyTo . '>';

    $boundary = '=_boundary_' . bin2hex(random_bytes(16));
    $hasAttachments = !empty($attachments);
    $hasHtml = $bodyHtml !== '';

    if ($hasAttachments || $hasHtml) {
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundary . '"';
        $body = buildMultipartBody($boundary, $body, $bodyHtml, $attachments);
    } else {
        $headers[] = 'Content-Type: text/plain; charset="UTF-8"';
        $headers[] = 'Content-Transfer-Encoding: quoted-printable';
        $body = quoted_printable_encode($body);
    }

    $headerBlock = '';
    foreach ($headers as $h) {
        $headerBlock .= foldHeader($h) . "\r\n";
    }
    return $headerBlock . "\r\n" . $body;
}

function buildMultipartBody(string $boundary, string $text, string $html, array $attachments): string
{
    $parts = [];

    if ($text !== '' && $html !== '') {
        $altBoundary = '=_alt_' . bin2hex(random_bytes(16));
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $parts[] = '';
        $parts[] = '--' . $altBoundary;
        $parts[] = 'Content-Type: text/plain; charset="UTF-8"';
        $parts[] = 'Content-Transfer-Encoding: quoted-printable';
        $parts[] = '';
        $parts[] = quoted_printable_encode($text);
        $parts[] = '';
        $parts[] = '--' . $altBoundary;
        $parts[] = 'Content-Type: text/html; charset="UTF-8"';
        $parts[] = 'Content-Transfer-Encoding: quoted-printable';
        $parts[] = '';
        $parts[] = quoted_printable_encode($html);
        $parts[] = '';
        $parts[] = '--' . $altBoundary . '--';
    } elseif ($html !== '') {
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/html; charset="UTF-8"';
        $parts[] = 'Content-Transfer-Encoding: quoted-printable';
        $parts[] = '';
        $parts[] = quoted_printable_encode($html);
    } elseif ($text !== '') {
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: text/plain; charset="UTF-8"';
        $parts[] = 'Content-Transfer-Encoding: quoted-printable';
        $parts[] = '';
        $parts[] = quoted_printable_encode($text);
    }

    foreach ($attachments as $att) {
        $filename = $att['filename'] ?? 'attachment';
        $contentType = $att['content_type'] ?? 'application/octet-stream';
        $content = $att['content'] ?? '';
        $parts[] = '';
        $parts[] = '--' . $boundary;
        $parts[] = 'Content-Type: ' . $contentType . '; name="' . $filename . '"';
        $parts[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
        $parts[] = 'Content-Transfer-Encoding: base64';
        $parts[] = '';
        $parts[] = chunk_split(base64_encode($content), 76, "\r\n");
    }

    $parts[] = '';
    $parts[] = '--' . $boundary . '--';
    return implode("\r\n", $parts);
}

/* ---------------------------------------------------------------------------
 * Provider Presets
 * ------------------------------------------------------------------------ */

function getPresets(): array
{
    return [
        'gmail' => [
            'imap_host' => 'imap.gmail.com',
            'imap_port' => 993,
            'imap_tls' => 1,
            'smtp_host' => 'smtp.gmail.com',
            'smtp_port' => 465,
            'smtp_tls' => 1,
        ],
        'yahoo' => [
            'imap_host' => 'imap.mail.yahoo.com',
            'imap_port' => 993,
            'imap_tls' => 1,
            'smtp_host' => 'smtp.mail.yahoo.com',
            'smtp_port' => 465,
            'smtp_tls' => 1,
        ],
        'outlook' => [
            'imap_host' => 'outlook.office365.com',
            'imap_port' => 993,
            'imap_tls' => 1,
            'smtp_host' => 'smtp.office365.com',
            'smtp_port' => 587,
            'smtp_tls' => 2,
        ],
        'migadu' => [
            'imap_host' => 'imap.migadu.com',
            'imap_port' => 993,
            'imap_tls' => 1,
            'smtp_host' => 'smtp.migadu.com',
            'smtp_port' => 465,
            'smtp_tls' => 1,
        ],
        'icloud' => [
            'imap_host' => 'imap.mail.me.com',
            'imap_port' => 993,
            'imap_tls' => 1,
            'smtp_host' => 'smtp.mail.me.com',
            'smtp_port' => 587,
            'smtp_tls' => 2,
        ],
    ];
}

/* ---------------------------------------------------------------------------
 * Message Storage Helpers
 * ------------------------------------------------------------------------ */

function storeMessage(PDO $pdo, int $accountId, string $folderName, int $uid, array $parsed, array $flags, string $rawHeaders): int
{
    $existing = fetchOne($pdo, 'SELECT id FROM messages WHERE account_id = ? AND folder_name = ? AND uid = ?', [$accountId, $folderName, $uid]);

    $isRead = in_array('\\Seen', $flags) ? 1 : 0;
    $isStarred = in_array('\\Flagged', $flags) ? 1 : 0;
    $hasAttachments = count($parsed['attachments'] ?? []) > 0 ? 1 : 0;

    $fromParsed = parseAddresses($parsed['headers']['from'] ?? '');
    $fromAddress = $fromParsed[0]['email'] ?? '';
    $fromName = $fromParsed[0]['name'] ?? '';
    $toAddresses = json_encode(parseAddresses($parsed['headers']['to'] ?? ''));
    $ccAddresses = json_encode(parseAddresses($parsed['headers']['cc'] ?? ''));
    $messageId = trim($parsed['headers']['message-id'] ?? '', '<>');
    $inReplyTo = trim($parsed['headers']['in-reply-to'] ?? '', '<>');
    $refs = trim($parsed['headers']['references'] ?? '');
    $subject = $parsed['headers']['subject'] ?? '';
    $date = normalizeDate($parsed['headers']['date'] ?? '');

    if ($existing) {
        $pdo->prepare('UPDATE messages SET is_read = ?, is_starred = ? WHERE id = ?')
            ->execute([$isRead, $isStarred, $existing['id']]);
        return (int) $existing['id'];
    }

    $pdo->prepare('INSERT INTO messages (account_id, folder_name, uid, message_id, in_reply_to, refs, subject, from_address, from_name, to_addresses, cc_addresses, date, text_body, html_body, is_read, is_starred, has_attachments, raw_headers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$accountId, $folderName, $uid, $messageId, $inReplyTo, $refs, $subject, $fromAddress, $fromName, $toAddresses, $ccAddresses, $date, $parsed['text_body'] ?? '', $parsed['html_body'] ?? '', $isRead, $isStarred, $hasAttachments, $rawHeaders]);
    $msgId = (int) $pdo->lastInsertId();

    $contactAddresses = [];
    if ($fromAddress !== '') $contactAddresses[] = ['email' => $fromAddress, 'name' => $fromName];
    foreach (json_decode($toAddresses, true) ?? [] as $a) $contactAddresses[] = $a;
    foreach (json_decode($ccAddresses, true) ?? [] as $a) $contactAddresses[] = $a;
    updateContacts($pdo, $accountId, $contactAddresses);

    storeAttachments($pdo, $msgId, $accountId, $parsed['attachments'] ?? []);

    return $msgId;
}

function storeAttachments(PDO $pdo, int $messageId, int $accountId, array $attachments): void
{
    if (empty($attachments)) return;

    $dir = EMMMAIL_ATTACH_DIR . '/' . $accountId . '/' . $messageId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);

    foreach ($attachments as $att) {
        $filename = sanitizeFilename($att['filename'] ?? 'attachment');
        $contentType = $att['content_type'] ?? 'application/octet-stream';
        $data = $att['data'] ?? '';
        $size = strlen($data);

        $filePath = $dir . '/' . $filename;
        $written = @file_put_contents($filePath, $data);
        if ($written === false) continue;

        $pdo->prepare('INSERT INTO attachments (message_id, account_id, filename, content_type, size, file_path) VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([$messageId, $accountId, $filename, $contentType, $size, $filePath]);
    }
}

function deleteAttachmentFiles(int $accountId, int $messageId): void
{
    $dir = EMMMAIL_ATTACH_DIR . '/' . $accountId . '/' . $messageId;
    if (is_dir($dir)) {
        $files = glob($dir . '/*');
        if ($files) {
            foreach ($files as $file) {
                if (is_file($file)) @unlink($file);
            }
        }
        @rmdir($dir);
    }
}

function deleteAccountAttachments(int $accountId): void
{
    $dir = EMMMAIL_ATTACH_DIR . '/' . $accountId;
    if (is_dir($dir)) {
        $subdirs = glob($dir . '/*', GLOB_ONLYDIR);
        if ($subdirs) {
            foreach ($subdirs as $subdir) {
                $files = glob($subdir . '/*');
                if ($files) {
                    foreach ($files as $file) {
                        if (is_file($file)) @unlink($file);
                    }
                }
                @rmdir($subdir);
            }
        }
        @rmdir($dir);
    }
}

function updateThreadAggregates(PDO $pdo, int $threadId): void
{
    $thread = fetchOne($pdo, 'SELECT * FROM threads WHERE id = ?', [$threadId]);
    if (!$thread) return;

    $last = fetchOne($pdo, 'SELECT * FROM messages WHERE thread_id = ? ORDER BY date DESC LIMIT 1', [$threadId]);
    $count = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM messages WHERE thread_id = ?', [$threadId]);
    $unread = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM messages WHERE thread_id = ? AND is_read = 0', [$threadId]);
    $starred = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM messages WHERE thread_id = ? AND is_starred = 1', [$threadId]);

    $preview = '';
    if ($last) {
        $body = $last['text_body'] ?? '';
        $preview = mb_substr(trim(preg_replace('/\s+/', ' ', $body)), 0, 200);
    }

    $pdo->prepare('UPDATE threads SET last_message_at = ?, message_count = ?, preview = ?, is_read = ?, is_starred = ? WHERE id = ?')
        ->execute([
            $last['date'] ?? $thread['last_message_at'],
            (int) $count['c'],
            $preview,
            (int) $unread['c'] === 0 ? 1 : 0,
            (int) $starred['c'] > 0 ? 1 : 0,
            $threadId,
        ]);
}

function upsertThread(PDO $pdo, int $accountId, string $subject, string $messageId, string $inReplyTo, string $refs, string $date): int
{
    $threadKey = hash('sha1', normalizeSubject($subject));
    $existing = fetchOne($pdo, 'SELECT id FROM threads WHERE account_id = ? AND thread_key = ?', [$accountId, $threadKey]);
    if ($existing) {
        return (int) $existing['id'];
    }
    $pdo->prepare('INSERT INTO threads (account_id, thread_key, subject, last_message_at, message_count) VALUES (?, ?, ?, ?, 0)')
        ->execute([$accountId, $threadKey, $subject, $date]);
    return (int) $pdo->lastInsertId();
}

/* ---------------------------------------------------------------------------
 * API Actions
 * ------------------------------------------------------------------------ */

function actionAuth(): void
{
    $input = getInput();

    if (empty($input['email']) || empty($input['password'])) {
        fail('Missing required field: email, password');
    }

    $email = strtolower(trim($input['email']));
    $password = $input['password'];
    $ip = getClientIp();

    if (!validateEmail($email)) {
        fail('Invalid email address.');
    }

    if (!empty($input['preset'])) {
        $presets = getPresets();
        $preset = strtolower(trim($input['preset']));
        if (!isset($presets[$preset])) {
            fail("Unknown preset: {$preset}. Available: " . implode(', ', array_keys($presets)));
        }
        $p = $presets[$preset];
        $imapHost = $p['imap_host'];
        $imapPort = $p['imap_port'];
        $imapTls = $p['imap_tls'];
        $smtpHost = $p['smtp_host'];
        $smtpPort = $p['smtp_port'];
        $smtpTls = $p['smtp_tls'];
    } else {
        $required = ['imap_host', 'imap_port', 'imap_tls', 'smtp_host', 'smtp_port', 'smtp_tls'];
        foreach ($required as $field) {
            if (empty($input[$field])) {
                fail("Missing required field: {$field}");
            }
        }
        $imapHost = $input['imap_host'];
        $imapPort = (int) $input['imap_port'];
        $imapTls = (int) $input['imap_tls'];
        $smtpHost = $input['smtp_host'];
        $smtpPort = (int) $input['smtp_port'];
        $smtpTls = (int) $input['smtp_tls'];
    }

    checkRateLimit($email, $ip);

    $result = testImapSmtp($imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $email, $password);

    if (!$result['imapOk'] && !$result['smtpOk']) {
        recordLoginFailure($email, $ip);
        fail("IMAP: {$result['imap']['error']}; SMTP: {$result['smtp']['error']}");
    }

    $pdo = db();
    $enc = encryptPassword($password, getEncryptionKey());
    $existing = fetchOne($pdo, 'SELECT id FROM accounts WHERE email = ?', [$email]);

    if ($existing) {
        $session = validateSession(getSessionToken() ?? '');
        if (!$session || (int) $session['account_id'] !== (int) $existing['id']) {
            fail('Account already exists. Log in to update settings.', 409);
        }
        $pdo->prepare('UPDATE accounts SET imap_host=?, imap_port=?, imap_tls=?, smtp_host=?, smtp_port=?, smtp_tls=?, password_encrypted=?, password_iv=?, password_tag=?, is_active=1 WHERE id=?')
            ->execute([$imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $enc['encrypted'], $enc['iv'], $enc['tag'], $existing['id']]);
        $accountId = (int) $existing['id'];
    } else {
        $displayName = deriveDisplayName($email);
        $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$email, $displayName, $imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $enc['encrypted'], $enc['iv'], $enc['tag']]);
        $accountId = (int) $pdo->lastInsertId();
    }

    jsonResponse([
        'ok' => true,
        'data' => [
            'account_id' => $accountId,
            'email' => $email,
            'imap' => $result['imap'],
            'smtp' => $result['smtp'],
        ],
    ]);
}

function actionSync(): void
{
    $input = getInput();
    $accountId = currentAccountId();
    $folderFilter = $input['folder'] ?? null;
    $since = $input['since'] ?? null;

    if ($accountId <= 0) {
        fail('No account found. Run auth first.');
    }

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$account) {
        fail('Account not found.');
    }

    $password = decryptPassword($account, getEncryptionKey());
    if ($password === '') {
        fail('Could not decrypt account password.');
    }

    $imap = new ImapClient($account['imap_host'], (int) $account['imap_port'], (int) $account['imap_tls'], $account['email'], $password);
    try {
        $imap->connectWithRetry();
    } catch (Throwable $e) {
        fail('IMAP connection failed: ' . $e->getMessage());
    }

    try {
        $folders = $imap->listFolders();
        $foldersSynced = 0;
        $messagesSynced = 0;
        $sinceDate = $since ? strtoupper(date('d-M-Y', strtotime($since))) : null;

        foreach ($folders as $f) {
            $folderName = $f['name'];
            if ($folderFilter !== null && $folderName !== $folderFilter) {
                continue;
            }

            $role = ImapClient::detectRole($folderName, $f['flags']);

            try {
                $status = $imap->selectFolder($folderName);
            } catch (Throwable $e) {
                continue;
            }

            $existingFolder = fetchOne($pdo, 'SELECT id, uid_validity, last_uid FROM folders WHERE account_id = ? AND name = ?', [$accountId, $folderName]);

            if ($existingFolder && (int) ($existingFolder['uid_validity'] ?? 0) !== ($status['uid_validity'] ?? 0)) {
                $messagesToDelete = fetchAll($pdo, 'SELECT id FROM messages WHERE account_id = ? AND folder_name = ?', [$accountId, $folderName]);
                foreach ($messagesToDelete as $msg) {
                    deleteAttachmentFiles($accountId, (int) $msg['id']);
                }
                $pdo->prepare('DELETE FROM messages WHERE account_id = ? AND folder_name = ?')
                    ->execute([$accountId, $folderName]);
                $pdo->prepare('UPDATE folders SET uid_validity = ?, last_uid = 0, unread_count = 0, total_count = 0 WHERE id = ?')
                    ->execute([$status['uid_validity'], $existingFolder['id']]);
                $existingFolder['last_uid'] = 0;
            }

            if ($existingFolder) {
                $pdo->prepare('UPDATE folders SET role = ?, uid_validity = ?, unread_count = ?, total_count = ? WHERE id = ?')
                    ->execute([$role, $status['uid_validity'], $status['unseen'], $status['exists'], $existingFolder['id']]);
            } else {
                $pdo->prepare('INSERT INTO folders (account_id, name, role, uid_validity, unread_count, total_count) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$accountId, $folderName, $role, $status['uid_validity'], $status['unseen'], $status['exists']]);
            }

            $uidsToFetch = [];
            try {
                if ($sinceDate) {
                    $uidsToFetch = $imap->searchSince($sinceDate);
                } else {
                    $messages = $imap->fetchMessages();
                    foreach ($messages as $m) {
                        if ($m['uid'] > ($existingFolder['last_uid'] ?? 0)) {
                            $uidsToFetch[] = $m['uid'];
                        }
                    }
                }
            } catch (Throwable $e) {
                continue;
            }

            $lastUid = $existingFolder['last_uid'] ?? 0;
            $threadIds = [];
            try {
                $fetchedBatch = $imap->fetchBodies($uidsToFetch);
            } catch (Throwable $e) {
                $fetchedBatch = [];
            }
            foreach ($fetchedBatch as $fetched) {
                try {
                    $uid = $fetched['uid'];
                    $raw = $fetched['raw'];
                    if ($raw === '') continue;

                    $sepPos = strpos($raw, "\r\n\r\n");
                    if ($sepPos === false) $sepPos = strpos($raw, "\n\n");
                    $rawHeaders = $sepPos !== false ? substr($raw, 0, $sepPos) : $raw;

                    $parsed = parseMimeMessage($raw);
                    $flags = $fetched['flags'];

                    $messageId = trim($parsed['headers']['message-id'] ?? '', '<>');
                    $inReplyTo = trim($parsed['headers']['in-reply-to'] ?? '', '<>');
                    $refs = trim($parsed['headers']['references'] ?? '');
                    $subject = $parsed['headers']['subject'] ?? '(no subject)';
                    $date = normalizeDate($parsed['headers']['date'] ?? '');

                    $threadId = upsertThread($pdo, $accountId, $subject, $messageId, $inReplyTo, $refs, $date);
                    $msgId = storeMessage($pdo, $accountId, $folderName, $uid, $parsed, $flags, $rawHeaders);
                    $pdo->prepare('UPDATE messages SET thread_id = ? WHERE id = ?')->execute([$threadId, $msgId]);
                    $threadIds[$threadId] = true;

                    if ($uid > $lastUid) $lastUid = $uid;
                    $messagesSynced++;
                } catch (Throwable $e) {
                    continue;
                }
            }

            foreach (array_keys($threadIds) as $tid) {
                updateThreadAggregates($pdo, $tid);
            }

            $pdo->prepare('UPDATE folders SET last_uid = ? WHERE account_id = ? AND name = ?')
                ->execute([$lastUid, $accountId, $folderName]);
            $foldersSynced++;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $pdo->prepare('UPDATE accounts SET last_sync_at = ? WHERE id = ?')
            ->execute([$now, $accountId]);

        jsonResponse([
            'ok' => true,
            'data' => [
                'account_id' => $accountId,
                'folders_synced' => $foldersSynced,
                'messages_synced' => $messagesSynced,
                'last_sync_at' => $now,
            ],
        ]);
    } finally {
        $imap->logout();
    }
}

function actionCompose(): void
{
    $input = getInput();
    $accountId = currentAccountId();
    $to = $input['to'] ?? [];
    $cc = $input['cc'] ?? [];
    $bcc = $input['bcc'] ?? [];
    $subject = sanitizeHeader($input['subject'] ?? '');
    $body = $input['body'] ?? '';
    $bodyHtml = $input['body_html'] ?? '';
    $attachments = $input['attachments'] ?? [];
    $inReplyTo = sanitizeHeader($input['in_reply_to'] ?? '');
    $draft = $input['draft'] ?? false;

    if ($accountId <= 0) fail('No account found. Run auth first.');
    if (empty($to)) fail('Missing required field: to');
    if ($subject === '' && $body === '' && $bodyHtml === '') fail('Missing required field: subject, body, or body_html');

    $allAddresses = array_merge($to, $cc, $bcc);
    foreach ($allAddresses as $addr) {
        if (!validateEmail($addr)) fail('Invalid email address: ' . sanitizeHeader($addr));
    }

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$account) fail('Account not found.');

    $password = decryptPassword($account, getEncryptionKey());
    if ($password === '') fail('Could not decrypt account password.');

    if ($draft) {
        $pdo->prepare('INSERT INTO drafts (account_id, to_addresses, cc_addresses, bcc_addresses, subject, body, body_html, attachments, in_reply_to) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([
                $accountId,
                json_encode($to),
                json_encode($cc),
                json_encode($bcc),
                $subject,
                $body,
                $bodyHtml,
                json_encode($attachments),
                $inReplyTo !== '' ? $inReplyTo : null,
            ]);
        $draftId = (int) $pdo->lastInsertId();
        jsonResponse([
            'ok' => true,
            'data' => ['draft_id' => $draftId, 'saved' => true],
        ]);
        return;
    }

    $fromEmail = $account['email'];
    $fromName = $account['display_name'] ?? deriveDisplayName($fromEmail);
    $from = $fromName !== '' ? '"' . $fromName . '" <' . $fromEmail . '>' : $fromEmail;

    $allRecipients = array_merge($to, $cc, $bcc);
    $normalizedTo = [];
    foreach ($to as $addr) {
        $normalizedTo[] = str_contains($addr, '<') ? $addr : '<' . $addr . '>';
    }

    $message = buildMimeMessage([
        'from' => $from,
        'to' => $normalizedTo,
        'cc' => $cc,
        'bcc' => $bcc,
        'subject' => $subject,
        'body' => $body,
        'body_html' => $bodyHtml,
        'attachments' => $attachments,
        'in_reply_to' => $inReplyTo,
    ]);

    $smtp = new SmtpClient($account['smtp_host'], (int) $account['smtp_port'], (int) $account['smtp_tls'], $fromEmail, $password);
    try {
        $smtp->connect();
        $smtp->login();
        $smtp->send($fromEmail, $allRecipients, $message);
    } catch (Throwable $e) {
        fail('SMTP send failed: ' . $e->getMessage());
    } finally {
        $smtp->quit();
    }

    jsonResponse([
        'ok' => true,
        'data' => [
            'sent' => true,
            'to' => $to,
            'cc' => $cc,
            'bcc' => $bcc,
            'subject' => $subject,
        ],
    ]);
}

function actionListDrafts(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT id FROM accounts WHERE id = ?', [$accountId]);
    if (!$account) fail('Account not found.');

    $drafts = fetchAll($pdo, 'SELECT id, to_addresses, cc_addresses, subject, body, body_html, in_reply_to, created_at, updated_at FROM drafts WHERE account_id = ? ORDER BY updated_at DESC', [$accountId]);

    foreach ($drafts as &$d) {
        $d['to_addresses'] = json_decode($d['to_addresses'] ?? '[]', true);
        $d['cc_addresses'] = json_decode($d['cc_addresses'] ?? '[]', true);
        $d['attachments'] = json_decode($d['attachments'] ?? '[]', true);
    }

    jsonResponse([
        'ok' => true,
        'data' => ['drafts' => $drafts],
    ]);
}

function actionGetDraft(): void
{
    $input = getInput();
    $draftId = (int) ($input['draft_id'] ?? 0);
    if ($draftId <= 0) fail('Missing required field: draft_id');

    $accountId = currentAccountId();
    $pdo = db();
    $draft = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ? AND account_id = ?', [$draftId, $accountId]);
    if (!$draft) fail('Draft not found.');

    $draft['to_addresses'] = json_decode($draft['to_addresses'] ?? '[]', true);
    $draft['cc_addresses'] = json_decode($draft['cc_addresses'] ?? '[]', true);
    $draft['bcc_addresses'] = json_decode($draft['bcc_addresses'] ?? '[]', true);
    $draft['attachments'] = json_decode($draft['attachments'] ?? '[]', true);

    jsonResponse([
        'ok' => true,
        'data' => $draft,
    ]);
}

function actionUpdateDraft(): void
{
    $input = getInput();
    $draftId = (int) ($input['draft_id'] ?? 0);
    if ($draftId <= 0) fail('Missing required field: draft_id');

    $accountId = currentAccountId();
    $pdo = db();
    $draft = fetchOne($pdo, 'SELECT id FROM drafts WHERE id = ? AND account_id = ?', [$draftId, $accountId]);
    if (!$draft) fail('Draft not found.');

    $fields = [];
    $params = [];
    $updatable = ['to_addresses', 'cc_addresses', 'bcc_addresses', 'subject', 'body', 'body_html', 'attachments', 'in_reply_to'];
    foreach ($updatable as $f) {
        if (array_key_exists($f, $input)) {
            $fields[] = $f . ' = ?';
            $val = $input[$f];
            if (in_array($f, ['to_addresses', 'cc_addresses', 'bcc_addresses', 'attachments'])) {
                $val = json_encode($val);
            }
            $params[] = $val;
        }
    }
    if (empty($fields)) fail('No fields to update.');

    $fields[] = "updated_at = datetime('now')";
    $params[] = $draftId;
    $pdo->prepare('UPDATE drafts SET ' . implode(', ', $fields) . ' WHERE id = ? AND account_id = ?')->execute(array_merge($params, [$accountId]));

    jsonResponse([
        'ok' => true,
        'data' => ['draft_id' => $draftId, 'updated' => true],
    ]);
}

function actionDeleteDraft(): void
{
    $input = getInput();
    $draftId = (int) ($input['draft_id'] ?? 0);
    if ($draftId <= 0) fail('Missing required field: draft_id');

    $accountId = currentAccountId();
    $pdo = db();
    $draft = fetchOne($pdo, 'SELECT id FROM drafts WHERE id = ? AND account_id = ?', [$draftId, $accountId]);
    if (!$draft) fail('Draft not found.');

    $pdo->prepare('DELETE FROM drafts WHERE id = ? AND account_id = ?')->execute([$draftId, $accountId]);
    jsonResponse([
        'ok' => true,
        'data' => ['deleted' => true],
    ]);
}

function actionDownloadAttachment(): void
{
    $input = getInput();
    $attachmentId = (int) ($input['attachment_id'] ?? 0);
    if ($attachmentId <= 0) fail('Missing required field: attachment_id');

    $accountId = currentAccountId();
    $pdo = db();
    $att = fetchOne($pdo, 'SELECT * FROM attachments WHERE id = ? AND account_id = ?', [$attachmentId, $accountId]);
    if (!$att) fail('Attachment not found.');

    $filePath = $att['file_path'];
    $realPath = realpath($filePath);
    $attachDir = realpath(EMMMAIL_ATTACH_DIR);
    if ($realPath === false || $attachDir === false || !str_starts_with($realPath, $attachDir)) {
        fail('Attachment file not found on disk.');
    }
    if (!is_file($realPath)) fail('Attachment file not found on disk.');

    $filename = sanitizeHeader($att['filename']);
    $contentType = preg_replace('/[\r\n\0].*/', '', $att['content_type']);
    $size = (int) $att['size'];

    header('Content-Type: ' . $contentType);
    header('Content-Length: ' . $size);
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($realPath);
    exit;
}

function actionMessageAttachments(): void
{
    $input = getInput();
    $messageId = (int) ($input['message_id'] ?? 0);
    if ($messageId <= 0) fail('Missing required field: message_id');

    $accountId = currentAccountId();
    $pdo = db();
    $message = fetchOne($pdo, 'SELECT id FROM messages WHERE id = ? AND account_id = ?', [$messageId, $accountId]);
    if (!$message) fail('Message not found.');

    $attachments = fetchAll($pdo, 'SELECT id, filename, content_type, size FROM attachments WHERE message_id = ? ORDER BY id', [$messageId]);

    jsonResponse([
        'ok' => true,
        'data' => ['attachments' => $attachments],
    ]);
}

/* ---------------------------------------------------------------------------
 * Session Endpoints
 * ------------------------------------------------------------------------ */

function actionLogin(): void
{
    $input = getInput();
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if ($email === '' || $password === '') {
        fail('Missing required field: email, password');
    }

    if (!validateEmail($email)) {
        fail('Invalid email address.');
    }

    $ip = getClientIp();
    checkRateLimit($email, $ip);

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT * FROM accounts WHERE email = ? AND is_active = 1', [$email]);
    if (!$account) {
        recordLoginFailure($email, $ip);
        fail('Invalid email or password.', 401);
    }

    $decrypted = decryptPassword($account, getEncryptionKey());
    if ($decrypted === '' || $decrypted !== $password) {
        recordLoginFailure($email, $ip);
        fail('Invalid email or password.', 401);
    }

    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $token = createSession((int) $account['id'], $ip, $userAgent);

    jsonResponse([
        'ok' => true,
        'data' => [
            'session_token' => $token,
            'account_id' => (int) $account['id'],
            'email' => $account['email'],
            'display_name' => $account['display_name'],
        ],
    ]);
}

function actionLogout(): void
{
    $token = getSessionToken();
    if ($token) {
        destroySession($token);
    }
    jsonResponse([
        'ok' => true,
        'data' => ['logged_out' => true],
    ]);
}

function actionSession(): void
{
    $token = getSessionToken();
    if (!$token) {
        jsonResponse([
            'ok' => true,
            'data' => ['authenticated' => false],
        ]);
        return;
    }

    $session = validateSession($token);
    if (!$session) {
        jsonResponse([
            'ok' => true,
            'data' => ['authenticated' => false],
        ]);
        return;
    }

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT id, email, display_name FROM accounts WHERE id = ? AND is_active = 1', [$session['account_id']]);
    if (!$account) {
        jsonResponse([
            'ok' => true,
            'data' => ['authenticated' => false],
        ]);
        return;
    }

    jsonResponse([
        'ok' => true,
        'data' => [
            'authenticated' => true,
            'account_id' => (int) $account['id'],
            'email' => $account['email'],
            'display_name' => $account['display_name'],
        ],
    ]);
}

function actionListFolders(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $pdo = db();
    $folders = fetchAll($pdo, 'SELECT id, name, role, unread_count, total_count FROM folders WHERE account_id = ? ORDER BY CASE role WHEN \'inbox\' THEN 1 WHEN \'sent\' THEN 2 WHEN \'drafts\' THEN 3 WHEN \'archive\' THEN 4 WHEN \'junk\' THEN 5 WHEN \'trash\' THEN 6 ELSE 7 END, name', [$accountId]);

    jsonResponse(['ok' => true, 'data' => ['folders' => $folders]]);
}

function actionListMessages(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $folder = $input['folder'] ?? '';
    if ($folder === '') fail('Missing required field: folder');

    $limit = (int) ($input['limit'] ?? 50);
    $limit = max(1, min(100, $limit));

    $cursor = $input['cursor'] ?? null;
    $pdo = db();

    if ($cursor !== null && str_contains($cursor, '|')) {
        [$cursorDate, $cursorId] = explode('|', $cursor, 2);
        $cursorDate = (string) $cursorDate;
        $cursorId = (int) $cursorId;
        $rows = fetchAll($pdo, 'SELECT id, uid, message_id, subject, from_address, from_name, to_addresses, date, is_read, is_starred, has_attachments, thread_id, SUBSTR(text_body, 1, 200) AS preview FROM messages WHERE account_id = ? AND folder_name = ? AND (date < ? OR (date = ? AND id < ?)) ORDER BY date DESC, id DESC LIMIT ?', [$accountId, $folder, $cursorDate, $cursorDate, $cursorId, $limit + 1]);
    } else {
        $rows = fetchAll($pdo, 'SELECT id, uid, message_id, subject, from_address, from_name, to_addresses, date, is_read, is_starred, has_attachments, thread_id, SUBSTR(text_body, 1, 200) AS preview FROM messages WHERE account_id = ? AND folder_name = ? ORDER BY date DESC, id DESC LIMIT ?', [$accountId, $folder, $limit + 1]);
    }

    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);

    foreach ($rows as &$row) {
        $row['to_addresses'] = json_decode($row['to_addresses'] ?? '[]', true);
    }
    unset($row);

    $nextCursor = null;
    if ($hasMore && !empty($rows)) {
        $last = end($rows);
        $nextCursor = $last['date'] . '|' . $last['id'];
    }

    jsonResponse(['ok' => true, 'data' => ['messages' => $rows, 'has_more' => $hasMore, 'next_cursor' => $nextCursor]]);
}

function actionGetMessage(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $messageId = (int) ($input['message_id'] ?? 0);
    if ($messageId <= 0) fail('Missing required field: message_id');

    $pdo = db();
    $msg = fetchOne($pdo, 'SELECT id, account_id, folder_name, uid, message_id, in_reply_to, refs, subject, from_address, from_name, to_addresses, cc_addresses, date, text_body, html_body, thread_id, is_read, is_starred, has_attachments, size, created_at FROM messages WHERE id = ? AND account_id = ?', [$messageId, $accountId]);
    if (!$msg) fail('Message not found.');

    $msg['to_addresses'] = json_decode($msg['to_addresses'] ?? '[]', true);
    $msg['cc_addresses'] = json_decode($msg['cc_addresses'] ?? '[]', true);

    $attachments = fetchAll($pdo, 'SELECT id, filename, content_type, size FROM attachments WHERE message_id = ? AND account_id = ? ORDER BY id', [$messageId, $accountId]);

    jsonResponse(['ok' => true, 'data' => ['message' => $msg, 'attachments' => $attachments]]);
}

function actionGetThread(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $threadId = (int) ($input['thread_id'] ?? 0);
    if ($threadId <= 0) fail('Missing required field: thread_id');

    $pdo = db();
    $thread = fetchOne($pdo, 'SELECT id, subject, message_count, preview, is_read, is_starred, last_message_at FROM threads WHERE id = ? AND account_id = ?', [$threadId, $accountId]);
    if (!$thread) fail('Thread not found.');

    $messages = fetchAll($pdo, 'SELECT id, uid, folder_name, message_id, subject, from_address, from_name, to_addresses, cc_addresses, date, is_read, is_starred, has_attachments, text_body, in_reply_to, refs FROM messages WHERE thread_id = ? AND account_id = ? ORDER BY date ASC, id ASC', [$threadId, $accountId]);

    foreach ($messages as &$msg) {
        $msg['to_addresses'] = json_decode($msg['to_addresses'] ?? '[]', true);
        $msg['cc_addresses'] = json_decode($msg['cc_addresses'] ?? '[]', true);
    }
    unset($msg);

    jsonResponse(['ok' => true, 'data' => ['thread' => $thread, 'messages' => $messages]]);
}

function actionSetFlags(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $messageId = (int) ($input['message_id'] ?? 0);
    if ($messageId <= 0) fail('Missing required field: message_id');

    $pdo = db();
    $msg = fetchOne($pdo, 'SELECT id, thread_id, is_read, is_starred, folder_name FROM messages WHERE id = ? AND account_id = ?', [$messageId, $accountId]);
    if (!$msg) fail('Message not found.');

    $sets = [];
    $params = [];
    $whitelist = ['is_read', 'is_starred', 'folder_name'];
    foreach ($whitelist as $col) {
        if (array_key_exists($col, $input)) {
            $sets[] = "$col = ?";
            $params[] = $input[$col];
        }
    }

    if (empty($sets)) fail('No flags to update.');

    $params[] = $messageId;
    $params[] = $accountId;
    $pdo->prepare('UPDATE messages SET ' . implode(', ', $sets) . ' WHERE id = ? AND account_id = ?')->execute($params);

    if ($msg['thread_id']) updateThreadAggregates((int) $msg['thread_id']);

    $updated = fetchOne($pdo, 'SELECT id, is_read, is_starred, folder_name FROM messages WHERE id = ?', [$messageId]);
    jsonResponse(['ok' => true, 'data' => $updated]);
}

function actionBatchAction(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $messageIds = $input['message_ids'] ?? [];
    $action = $input['action'] ?? '';

    if (!is_array($messageIds) || empty($messageIds)) fail('Missing required field: message_ids');
    if ($action === '') fail('Missing required field: action');

    $validActions = ['read', 'unread', 'star', 'unstar', 'delete', 'archive', 'move'];
    if (!in_array($action, $validActions, true)) fail('Invalid action: ' . $action);

    $ids = array_unique(array_filter(array_map(fn($v) => (int) $v, $messageIds), fn($v) => $v > 0));
    if (count($ids) > 200) fail('Too many message IDs (max 200).');
    if (empty($ids)) fail('No valid message IDs.');

    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $params = array_merge($ids, [$accountId]);
    $existing = fetchAll($pdo, "SELECT id, thread_id FROM messages WHERE id IN ($placeholders) AND account_id = ?", $params);
    $existingIds = array_column($existing, 'id');
    $threadIds = array_unique(array_filter(array_column($existing, 'thread_id')));

    if (empty($existingIds)) {
        jsonResponse(['ok' => true, 'data' => ['affected' => 0]]);
        return;
    }

    $existingPlaceholders = implode(',', array_fill(0, count($existingIds), '?'));
    $updateParams = [];

    switch ($action) {
        case 'read':
            $pdo->prepare("UPDATE messages SET is_read = 1 WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge($existingIds, [$accountId]));
            break;
        case 'unread':
            $pdo->prepare("UPDATE messages SET is_read = 0 WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge($existingIds, [$accountId]));
            break;
        case 'star':
            $pdo->prepare("UPDATE messages SET is_starred = 1 WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge($existingIds, [$accountId]));
            break;
        case 'unstar':
            $pdo->prepare("UPDATE messages SET is_starred = 0 WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge($existingIds, [$accountId]));
            break;
        case 'delete':
            $trash = fetchOne($pdo, 'SELECT name FROM folders WHERE account_id = ? AND role = ?', [$accountId, 'trash']);
            if (!$trash) fail('Trash folder not found. Run sync first.');
            $pdo->prepare("UPDATE messages SET folder_name = ? WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge([$trash['name']], $existingIds, [$accountId]));
            break;
        case 'archive':
            $archive = fetchOne($pdo, 'SELECT name FROM folders WHERE account_id = ? AND role = ?', [$accountId, 'archive']);
            if (!$archive) fail('Archive folder not found. Run sync first.');
            $pdo->prepare("UPDATE messages SET folder_name = ? WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge([$archive['name']], $existingIds, [$accountId]));
            break;
        case 'move':
            $folderName = $input['folder_name'] ?? '';
            if ($folderName === '') fail('Missing required field: folder_name for move action.');
            $dest = fetchOne($pdo, 'SELECT name FROM folders WHERE account_id = ? AND name = ?', [$accountId, $folderName]);
            if (!$dest) fail('Destination folder not found: ' . $folderName);
            $pdo->prepare("UPDATE messages SET folder_name = ? WHERE id IN ($existingPlaceholders) AND account_id = ?")->execute(array_merge([$folderName], $existingIds, [$accountId]));
            break;
    }

    foreach ($threadIds as $tid) {
        updateThreadAggregates((int) $tid);
    }

    jsonResponse(['ok' => true, 'data' => ['affected' => count($existingIds)]]);
}

function escapeFtsQuery(string $query): string
{
    $query = trim($query);
    if ($query === '') return '';
    $tokens = preg_split('/\s+/', $query);
    $escaped = array_map(fn($t) => '"' . str_replace('"', '""', $t) . '"', $tokens);
    return implode(' ', $escaped);
}

function actionSearch(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $input = getInput();
    $query = trim($input['query'] ?? '');
    if ($query === '') fail('Missing required field: query');

    $folder = $input['folder'] ?? null;
    $limit = (int) ($input['limit'] ?? 50);
    $limit = max(1, min(100, $limit));

    $ftsQuery = escapeFtsQuery($query);
    if ($ftsQuery === '') fail('Invalid search query.');

    $pdo = db();

    try {
        if ($folder !== null && $folder !== '') {
            $rows = fetchAll($pdo, 'SELECT m.id, m.uid, m.folder_name, m.subject, m.from_address, m.from_name, m.to_addresses, m.date, m.is_read, m.is_starred, m.has_attachments, m.thread_id, SUBSTR(m.text_body, 1, 200) AS preview FROM messages_fts fts JOIN messages m ON m.id = fts.rowid WHERE messages_fts MATCH ? AND m.account_id = ? AND m.folder_name = ? ORDER BY rank LIMIT ?', [$ftsQuery, $accountId, $folder, $limit]);
        } else {
            $rows = fetchAll($pdo, 'SELECT m.id, m.uid, m.folder_name, m.subject, m.from_address, m.from_name, m.to_addresses, m.date, m.is_read, m.is_starred, m.has_attachments, m.thread_id, SUBSTR(m.text_body, 1, 200) AS preview FROM messages_fts fts JOIN messages m ON m.id = fts.rowid WHERE messages_fts MATCH ? AND m.account_id = ? ORDER BY rank LIMIT ?', [$ftsQuery, $accountId, $limit]);
        }
    } catch (Throwable $e) {
        fail('Search query error: ' . $e->getMessage());
    }

    foreach ($rows as &$row) {
        $row['to_addresses'] = json_decode($row['to_addresses'] ?? '[]', true);
    }
    unset($row);

    jsonResponse(['ok' => true, 'data' => ['messages' => $rows]]);
}

function validatePortAndTls(int $port, int $tls): void
{
    if ($port < 1 || $port > 65535) fail('Port must be between 1 and 65535.');
    if ($tls < 0 || $tls > 2) fail('TLS mode must be 0 (none), 1 (SSL), or 2 (STARTTLS).');
}

function testImapSmtp(string $imapHost, int $imapPort, int $imapTls, string $smtpHost, int $smtpPort, int $smtpTls, string $email, string $password): array
{
    $imapOk = false;
    $imapError = '';
    $imapFolders = [];
    try {
        $imap = new ImapClient($imapHost, $imapPort, $imapTls, $email, $password);
        $imap->connect();
        $imap->login();
        $imapOk = true;
        $imapFolders = $imap->listFolders();
        $imap->logout();
    } catch (Throwable $e) {
        $imapError = $e->getMessage();
    }

    $smtpOk = false;
    $smtpError = '';
    try {
        $smtp = new SmtpClient($smtpHost, $smtpPort, $smtpTls, $email, $password);
        $smtp->connect();
        $smtp->login();
        $smtpOk = true;
        $smtp->quit();
    } catch (Throwable $e) {
        $smtpError = $e->getMessage();
    }

    return [
        'imap' => ['ok' => $imapOk, 'error' => $imapError, 'folders' => $imapFolders],
        'smtp' => ['ok' => $smtpOk, 'error' => $smtpError],
        'imapOk' => $imapOk,
        'smtpOk' => $smtpOk,
    ];
}

function detectServersByEmail(string $email): array
{
    $domain = strtolower(trim(substr(strrchr($email, '@'), 1)));

    $domainMap = [
        'gmail.com' => 'gmail', 'googlemail.com' => 'gmail',
        'yahoo.com' => 'yahoo', 'yahoo.co.uk' => 'yahoo', 'yahoo.co.jp' => 'yahoo', 'ymail.com' => 'yahoo',
        'outlook.com' => 'outlook', 'hotmail.com' => 'outlook', 'live.com' => 'outlook', 'msn.com' => 'outlook',
        'icloud.com' => 'icloud', 'me.com' => 'icloud', 'mac.com' => 'icloud',
        'migadu.com' => 'migadu',
    ];

    $preset = $domainMap[$domain] ?? null;

    if ($preset === null) {
        $mxRecords = @dns_get_record($domain, DNS_MX);
        if (is_array($mxRecords)) {
            foreach ($mxRecords as $mx) {
                $target = strtolower(rtrim($mx['target'] ?? '', '.'));
                if (str_contains($target, 'google.com') || str_contains($target, 'googlemail.com')) { $preset = 'gmail'; break; }
                if (str_contains($target, 'outlook.com') || str_contains($target, 'office365.com')) { $preset = 'outlook'; break; }
                if (str_contains($target, 'yahoodns.net') || str_contains($target, 'yahoo.com')) { $preset = 'yahoo'; break; }
                if (str_contains($target, 'migadu.com')) { $preset = 'migadu'; break; }
            }
        }
    }

    if ($preset !== null) {
        $presets = getPresets();
        $p = $presets[$preset];
        return [
            'detected' => true,
            'preset' => $preset,
            'imap_host' => $p['imap_host'], 'imap_port' => $p['imap_port'], 'imap_tls' => $p['imap_tls'],
            'smtp_host' => $p['smtp_host'], 'smtp_port' => $p['smtp_port'], 'smtp_tls' => $p['smtp_tls'],
        ];
    }

    return [
        'detected' => false,
        'preset' => null,
        'imap_host' => 'imap.' . $domain, 'imap_port' => 993, 'imap_tls' => 1,
        'smtp_host' => 'smtp.' . $domain, 'smtp_port' => 587, 'smtp_tls' => 2,
    ];
}

function actionListAccounts(): void
{
    $pdo = db();
    $accounts = fetchAll($pdo, 'SELECT id, email, display_name, imap_host, smtp_host, is_active, last_sync_at, created_at FROM accounts WHERE is_active = 1 ORDER BY created_at DESC, id DESC', []);

    foreach ($accounts as &$acct) {
        if (!empty($acct['last_sync_at'])) {
            $acct['last_sync_at'] = str_replace(' ', 'T', $acct['last_sync_at']) . 'Z';
        }
        if (!empty($acct['created_at'])) {
            $acct['created_at'] = str_replace(' ', 'T', $acct['created_at']) . 'Z';
        }
    }
    unset($acct);

    jsonResponse(['ok' => true, 'data' => ['accounts' => $accounts]]);
}

function actionAddAccount(): void
{
    $input = getInput();

    if (empty($input['email']) || empty($input['password'])) {
        fail('Missing required field: email, password');
    }

    $email = strtolower(trim($input['email']));
    $password = $input['password'];
    $ip = getClientIp();

    if (!validateEmail($email)) {
        fail('Invalid email address.');
    }

    if (!empty($input['preset'])) {
        $presets = getPresets();
        $preset = strtolower(trim($input['preset']));
        if (!isset($presets[$preset])) {
            fail("Unknown preset: {$preset}. Available: " . implode(', ', array_keys($presets)));
        }
        $p = $presets[$preset];
        $imapHost = $p['imap_host']; $imapPort = $p['imap_port']; $imapTls = $p['imap_tls'];
        $smtpHost = $p['smtp_host']; $smtpPort = $p['smtp_port']; $smtpTls = $p['smtp_tls'];
    } else {
        $required = ['imap_host', 'imap_port', 'imap_tls', 'smtp_host', 'smtp_port', 'smtp_tls'];
        foreach ($required as $field) {
            if (!isset($input[$field])) fail("Missing required field: {$field}");
        }
        $imapHost = $input['imap_host']; $imapPort = (int) $input['imap_port']; $imapTls = (int) $input['imap_tls'];
        $smtpHost = $input['smtp_host']; $smtpPort = (int) $input['smtp_port']; $smtpTls = (int) $input['smtp_tls'];
        validatePortAndTls($imapPort, $imapTls);
        validatePortAndTls($smtpPort, $smtpTls);
    }

    checkRateLimit($email, $ip);

    $result = testImapSmtp($imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $email, $password);

    if (!$result['imapOk'] && !$result['smtpOk']) {
        recordLoginFailure($email, $ip);
        fail("IMAP: {$result['imap']['error']}; SMTP: {$result['smtp']['error']}");
    }

    $pdo = db();
    $existing = fetchOne($pdo, 'SELECT id FROM accounts WHERE email = ?', [$email]);
    if ($existing) fail('Account already exists. Use auth to update.', 409);

    $enc = encryptPassword($password, getEncryptionKey());
    $displayName = deriveDisplayName($email);
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$email, $displayName, $imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $enc['encrypted'], $enc['iv'], $enc['tag']]);
    $accountId = (int) $pdo->lastInsertId();

    $sessionToken = createSession($accountId, $ip, $_SERVER['HTTP_USER_AGENT'] ?? '');

    jsonResponse([
        'ok' => true,
        'data' => [
            'account_id' => $accountId,
            'session_token' => $sessionToken,
            'email' => $email,
            'imap' => $result['imap'],
            'smtp' => $result['smtp'],
        ],
    ]);
}

function actionDeleteAccount(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $pdo = db();
    $account = fetchOne($pdo, 'SELECT id FROM accounts WHERE id = ?', [$accountId]);
    if (!$account) fail('Account not found.');

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sessions WHERE account_id = ?')->execute([$accountId]);
        $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$accountId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        fail('Failed to delete account: ' . $e->getMessage(), 500);
    }

    deleteAccountAttachments($accountId);

    jsonResponse(['ok' => true, 'data' => ['deleted' => true, 'account_id' => $accountId]]);
}

function actionDetectServer(): void
{
    $input = getInput();
    $email = strtolower(trim($input['email'] ?? ''));
    if ($email === '') fail('Missing required field: email');
    if (!validateEmail($email)) fail('Invalid email address.');

    $result = detectServersByEmail($email);
    jsonResponse(['ok' => true, 'data' => $result]);
}

function actionTestConnection(): void
{
    $input = getInput();

    if (empty($input['email']) || empty($input['password'])) {
        fail('Missing required field: email, password');
    }

    $email = strtolower(trim($input['email']));
    $password = $input['password'];
    $ip = getClientIp();

    if (!validateEmail($email)) {
        fail('Invalid email address.');
    }

    if (!empty($input['preset'])) {
        $presets = getPresets();
        $preset = strtolower(trim($input['preset']));
        if (!isset($presets[$preset])) {
            fail("Unknown preset: {$preset}. Available: " . implode(', ', array_keys($presets)));
        }
        $p = $presets[$preset];
        $imapHost = $p['imap_host']; $imapPort = $p['imap_port']; $imapTls = $p['imap_tls'];
        $smtpHost = $p['smtp_host']; $smtpPort = $p['smtp_port']; $smtpTls = $p['smtp_tls'];
    } else {
        $required = ['imap_host', 'imap_port', 'imap_tls', 'smtp_host', 'smtp_port', 'smtp_tls'];
        foreach ($required as $field) {
            if (!isset($input[$field])) fail("Missing required field: {$field}");
        }
        $imapHost = $input['imap_host']; $imapPort = (int) $input['imap_port']; $imapTls = (int) $input['imap_tls'];
        $smtpHost = $input['smtp_host']; $smtpPort = (int) $input['smtp_port']; $smtpTls = (int) $input['smtp_tls'];
        validatePortAndTls($imapPort, $imapTls);
        validatePortAndTls($smtpPort, $smtpTls);
    }

    checkRateLimit($email, $ip);

    $result = testImapSmtp($imapHost, $imapPort, $imapTls, $smtpHost, $smtpPort, $smtpTls, $email, $password);

    if (!$result['imapOk'] && !$result['smtpOk']) {
        recordLoginFailure($email, $ip);
        fail("IMAP: {$result['imap']['error']}; SMTP: {$result['smtp']['error']}");
    }

    jsonResponse([
        'ok' => true,
        'data' => [
            'email' => $email,
            'imap' => $result['imap'],
            'smtp' => $result['smtp'],
        ],
    ]);
}

function sanitizeContactName(string $name): string
{
    $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    if (mb_strlen($name) > 200) $name = mb_substr($name, 0, 200);
    return $name;
}

function updateContacts(PDO $pdo, int $accountId, array $addresses): void
{
    $st = $pdo->prepare('INSERT INTO contacts (account_id, email, name) VALUES (?, ?, ?)
        ON CONFLICT(account_id, email) DO UPDATE SET
        use_count = use_count + 1,
        last_used_at = datetime("now"),
        name = CASE WHEN excluded.name != \'\' THEN excluded.name ELSE contacts.name END');
    $seen = [];
    foreach ($addresses as $a) {
        if (!is_array($a)) continue;
        $email = strtolower(trim((string) ($a['email'] ?? '')));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        if (isset($seen[$email])) continue;
        $seen[$email] = true;
        $name = sanitizeContactName((string) ($a['name'] ?? ''));
        $st->execute([$accountId, $email, $name]);
    }
}

function queryContacts(PDO $pdo, int $accountId, string $q, int $limit): array
{
    if ($q !== '') {
        $like = '%' . addcslashes($q, '\%_') . '%';
        return fetchAll($pdo, "SELECT email, name, use_count FROM contacts WHERE account_id = ? AND (LOWER(email) LIKE LOWER(?) ESCAPE '\\' OR name LIKE ? ESCAPE '\\' COLLATE NOCASE) ORDER BY use_count DESC, email LIMIT ?", [$accountId, $like, $like, $limit]);
    }
    return fetchAll($pdo, 'SELECT email, name, use_count FROM contacts WHERE account_id = ? ORDER BY use_count DESC, email LIMIT ?', [$accountId, $limit]);
}

function actionListContacts(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $q = trim((string) ($input['q'] ?? ''));
    $limit = max(1, min(500, (int) ($input['limit'] ?? 300)));
    $pdo = db();
    $contacts = queryContacts($pdo, $accountId, $q, $limit);
    foreach ($contacts as &$c) {
        $c['use_count'] = (int) $c['use_count'];
    }
    unset($c);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}

function actionSearchContacts(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $q = trim((string) ($input['q'] ?? ''));
    if ($q === '') fail('Missing required field: q');
    $limit = max(1, min(20, (int) ($input['limit'] ?? 10)));
    $pdo = db();
    $like = addcslashes($q, '\%_') . '%';
    $contacts = fetchAll($pdo, 'SELECT email, name FROM contacts WHERE account_id = ? AND (LOWER(email) LIKE LOWER(?) OR name LIKE ? COLLATE NOCASE) ORDER BY use_count DESC, email LIMIT ?', [$accountId, $like, $like, $limit]);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}

function actionListTags(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $pdo = db();
    $tags = fetchAll($pdo, 'SELECT id, name, color FROM tags WHERE account_id = ? ORDER BY name COLLATE NOCASE', [$accountId]);
    foreach ($tags as &$t) {
        $t['id'] = (int) $t['id'];
    }
    unset($t);
    jsonResponse(['ok' => true, 'data' => ['tags' => $tags]]);
}

function actionCreateTag(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $name = trim((string) ($input['name'] ?? ''));
    if ($name === '') fail('Missing required field: name');
    if (mb_strlen($name) > 100) $name = mb_substr($name, 0, 100);
    $color = trim((string) ($input['color'] ?? '#6366f1'));
    if (!preg_match('/^#[0-9a-fA-F]{3}$|^#[0-9a-fA-F]{4}$|^#[0-9a-fA-F]{6}$|^#[0-9a-fA-F]{8}$/', $color)) $color = '#6366f1';
    $pdo = db();
    try {
        $pdo->prepare('INSERT INTO tags (account_id, name, color) VALUES (?, ?, ?)')->execute([$accountId, $name, $color]);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), 'UNIQUE')) fail('A tag with this name already exists', 409);
        throw $e;
    }
    jsonResponse(['ok' => true, 'data' => ['id' => (int) $pdo->lastInsertId(), 'name' => $name, 'color' => $color]]);
}

function getSettingsArray(PDO $pdo, int $accountId): array
{
    $defaults = [
        'signature' => '',
        'theme' => 'dark',
        'show_remote_images' => false,
        'sync_on_load' => true,
    ];
    $rows = fetchAll($pdo, 'SELECT name, value FROM settings WHERE account_id = ?', [$accountId]);
    foreach ($rows as $r) {
        $k = $r['name'];
        if (!array_key_exists($k, $defaults)) continue;
        $v = $r['value'];
        if (in_array($k, ['show_remote_images', 'sync_on_load'], true)) {
            $v = $v === '1';
        }
        $defaults[$k] = $v;
    }
    return $defaults;
}

function actionGetSettings(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $pdo = db();
    jsonResponse(['ok' => true, 'data' => ['settings' => getSettingsArray($pdo, $accountId)]]);
}

function actionUpdateSettings(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();
    $pdo = db();

    $allowed = ['theme', 'show_remote_images', 'sync_on_load'];
    $applied = false;

    foreach ($allowed as $k) {
        if (!array_key_exists($k, $input)) continue;
        $v = $input[$k];
        if ($k === 'theme') {
            $v = strtolower(trim((string) $v));
            if (!in_array($v, ['dark', 'light'], true)) fail('Invalid theme value. Must be dark or light.');
        } else {
            $v = in_array($v, [true, 1, '1', 'true', 'on'], true) ? '1' : '0';
        }
        $pdo->prepare('INSERT INTO settings (account_id, name, value) VALUES (?, ?, ?) ON CONFLICT(account_id, name) DO UPDATE SET value = excluded.value')
            ->execute([$accountId, $k, (string) $v]);
        $applied = true;
    }

    if (!$applied) fail('No fields to update.');

    jsonResponse(['ok' => true, 'data' => ['settings' => getSettingsArray($pdo, $accountId)]]);
}

function actionUpdateSignature(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    if (!array_key_exists('signature', $input)) fail('Missing required field: signature.');
    $signature = (string) ($input['signature'] ?? '');
    if (mb_strlen($signature) > 10000) fail('Signature too long (max 10000 characters).');

    $pdo = db();
    $pdo->prepare('INSERT INTO settings (account_id, name, value) VALUES (?, ?, ?) ON CONFLICT(account_id, name) DO UPDATE SET value = excluded.value')
        ->execute([$accountId, 'signature', $signature]);

    jsonResponse(['ok' => true, 'data' => ['signature' => $signature]]);
}

function actionPing(): void
{
    jsonResponse(['ok' => true, 'data' => ['pong' => true, 'version' => EMMMAIL_VERSION, 'time' => gmdate('c')]]);
}

function actionSetup(): void
{
    $pdo = db();
    $count = fetchOne($pdo, 'SELECT COUNT(*) AS c FROM accounts');
    $setup = ((int) ($count['c'] ?? 0)) === 0;
    jsonResponse(['ok' => true, 'data' => ['setup' => $setup]]);
}

function actionRenameTag(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $id = (int) ($input['id'] ?? 0);
    $name = trim((string) ($input['name'] ?? ''));
    if ($id <= 0) fail('Missing required field: id.');
    if ($name === '') fail('Missing required field: name.');

    $pdo = db();
    $tag = fetchOne($pdo, 'SELECT id FROM tags WHERE id = ? AND account_id = ?', [$id, $accountId]);
    if (!$tag) fail('Tag not found.', 404);

    try {
        $pdo->prepare('UPDATE tags SET name = ? WHERE id = ? AND account_id = ?')->execute([$name, $id, $accountId]);
    } catch (Throwable $e) {
        fail('A tag with this name already exists.');
    }

    jsonResponse(['ok' => true, 'data' => ['id' => $id, 'name' => $name]]);
}

function actionDeleteTag(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $id = (int) ($input['id'] ?? 0);
    if ($id <= 0) fail('Missing required field: id.');

    $pdo = db();
    $tag = fetchOne($pdo, 'SELECT id FROM tags WHERE id = ? AND account_id = ?', [$id, $accountId]);
    if (!$tag) fail('Tag not found.', 404);

    $pdo->prepare('DELETE FROM tags WHERE id = ? AND account_id = ?')->execute([$id, $accountId]);
    jsonResponse(['ok' => true, 'data' => ['deleted' => true]]);
}

function actionTagMessage(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $messageId = (int) ($input['message_id'] ?? 0);
    $tagId = (int) ($input['tag_id'] ?? 0);
    if ($messageId <= 0) fail('Missing required field: message_id.');
    if ($tagId <= 0) fail('Missing required field: tag_id.');

    $pdo = db();
    $msg = fetchOne($pdo, 'SELECT id FROM messages WHERE id = ? AND account_id = ?', [$messageId, $accountId]);
    if (!$msg) fail('Message not found.', 404);
    $tag = fetchOne($pdo, 'SELECT id FROM tags WHERE id = ? AND account_id = ?', [$tagId, $accountId]);
    if (!$tag) fail('Tag not found.', 404);

    $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$messageId, $tagId]);
    jsonResponse(['ok' => true, 'data' => ['tagged' => true]]);
}

function actionUntagMessage(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $messageId = (int) ($input['message_id'] ?? 0);
    $tagId = (int) ($input['tag_id'] ?? 0);
    if ($messageId <= 0) fail('Missing required field: message_id.');
    if ($tagId <= 0) fail('Missing required field: tag_id.');

    $pdo = db();
    $msg = fetchOne($pdo, 'SELECT id FROM messages WHERE id = ? AND account_id = ?', [$messageId, $accountId]);
    if (!$msg) fail('Message not found.', 404);
    $tag = fetchOne($pdo, 'SELECT id FROM tags WHERE id = ? AND account_id = ?', [$tagId, $accountId]);
    if (!$tag) fail('Tag not found.', 404);

    $pdo->prepare('DELETE FROM message_tags WHERE message_id = ? AND tag_id = ?')->execute([$messageId, $tagId]);
    jsonResponse(['ok' => true, 'data' => ['untagged' => true]]);
}

function actionBatchTag(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $messageIds = array_values(array_unique(array_map('intval', $input['message_ids'] ?? [])));
    $tagIds = array_values(array_unique(array_map('intval', $input['tag_ids'] ?? [])));
    $remove = !empty($input['remove']);
    if (!$messageIds) fail('Missing required field: message_ids.');
    if (!$tagIds) fail('Missing required field: tag_ids.');

    $pdo = db();
    $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
    $msgs = fetchAll($pdo, "SELECT id FROM messages WHERE id IN ($placeholders) AND account_id = ?", [...$messageIds, $accountId]);
    $validMsgIds = array_map(fn($m) => (int) $m['id'], $msgs);
    if (!$validMsgIds) fail('No valid messages found.', 404);

    $pdo->beginTransaction();
    try {
        foreach ($validMsgIds as $mid) {
            foreach ($tagIds as $tagId) {
                if ($remove) {
                    $pdo->prepare('DELETE FROM message_tags WHERE message_id = ? AND tag_id = ?')->execute([$mid, $tagId]);
                } else {
                    $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$mid, $tagId]);
                }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function actionExpungeFolder(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');
    $input = getInput();

    $folder = trim((string) ($input['folder_name'] ?? ''));
    if ($folder === '') fail('Missing required field: folder_name.');

    $pdo = db();
    $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$acct) fail('Account not found.', 404);

    $key = getEncryptionKey();
    $password = decryptPassword($acct, $key);
    if ($password === '') fail('Could not decrypt stored password.', 500);

    try {
        $imap = new ImapClient($acct['imap_host'], (int) $acct['imap_port'], (int) $acct['imap_tls'], $acct['email'], $password, 20);
        $imap->connect();
        $imap->login();
        $imap->selectFolder($folder);
        $imap->expunge();
        $imap->logout();
    } catch (Throwable $e) {
        fail('Expunge failed: ' . $e->getMessage(), 500);
    }

    jsonResponse(['ok' => true, 'data' => ['expunged' => true]]);
}

function actionUploadAttachment(): void
{
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    if (empty($_FILES['file'])) fail('No file uploaded.');
    $f = $_FILES['file'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) fail('Upload failed.');
    if ((int) $f['size'] > EMMMAIL_MAX_ATTACH_SIZE) fail('File too large (max 25MB).');

    $filename = sanitizeFilename((string) ($f['name'] ?? 'attachment'));
    if ($filename === '') $filename = 'attachment.bin';
    if (isBlockedAttachment($filename)) $filename .= '.txt';

    $tmpDir = EMMMAIL_ATTACH_DIR . '/tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(16));
    if (!move_uploaded_file($f['tmp_name'], $tmpPath)) fail('Could not store upload.');

    $ct = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($tmpPath);
        if ($detected !== false && $detected !== '') $ct = $detected;
    } elseif (!empty($f['type'])) {
        $ct = $f['type'];
    }

    jsonResponse(['ok' => true, 'data' => ['filename' => $filename, 'content_type' => $ct, 'size' => (int) $f['size'], 'tmp_path' => $tmpPath]]);
}

function actionCheckUpdate(): void
{
    $pdo = db();
    $accountId = currentAccountId();
    $updateUrl = '';

    if ($accountId > 0) {
        $row = fetchOne($pdo, 'SELECT value FROM settings WHERE account_id = ? AND name = ?', [$accountId, 'update_url']);
        $updateUrl = $row ? trim((string) $row['value']) : '';
    }

    jsonResponse(['ok' => true, 'data' => [
        'current' => EMMMAIL_VERSION,
        'latest' => EMMMAIL_VERSION,
        'update_available' => false,
        'update_url' => $updateUrl,
    ]]);
}

function actionApplyUpdate(): void
{
    $pdo = db();
    $accountId = currentAccountId();
    if ($accountId <= 0) fail('No account found. Run auth first.');

    $row = fetchOne($pdo, 'SELECT value FROM settings WHERE account_id = ? AND name = ?', [$accountId, 'update_url']);
    $url = $row ? trim((string) $row['value']) : '';
    if ($url === '') fail('No update source configured. Set the "update_url" setting first.');

    $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'user_agent' => 'Emmmail/' . EMMMAIL_VERSION]]);
    $newCode = @file_get_contents($url, false, $ctx);
    if ($newCode === false || $newCode === '') fail('Could not download update.');

    $tmp = EMMMAIL_ROOT . '/.update-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($tmp, $newCode, LOCK_EX);

    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        @unlink($tmp);
        fail('Downloaded file failed PHP syntax check: ' . implode(' ', $out));
    }

    if (!str_contains($newCode, 'EMMMAIL_VERSION')) {
        @unlink($tmp);
        fail('Downloaded file does not look like an Emmmail build.');
    }

    $backup = EMMMAIL_ROOT . '/index.php.bak';
    @copy(EMMMAIL_ROOT . '/index.php', $backup);
    if (!@rename($tmp, EMMMAIL_ROOT . '/index.php')) {
        @unlink($tmp);
        fail('Could not replace index.php.', 500);
    }

    jsonResponse(['ok' => true, 'data' => ['updated' => true, 'version' => EMMMAIL_VERSION, 'backup' => basename($backup)]]);
}

function serveFrontend(): string
{
    $version = EMMMAIL_VERSION;
    return <<<'HTML'
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0d12">
<title>Emmmail</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%234f8cff'/%3E%3Ctext x='16' y='22' font-size='17' font-family='Arial' font-weight='bold' text-anchor='middle' fill='white'%3E✉%3C/text%3E%3C/svg%3E">
<script>
(function(){try{var t=localStorage.getItem('emmmail-theme')||'dark';document.documentElement.setAttribute('data-theme',t);var m=document.querySelector('meta[name="theme-color"]');if(m)m.setAttribute('content',t==='light'?'#ffffff':'#0b0d12');}catch(e){}})();
</script>
<style>
*,*::before,*::after{box-sizing:border-box}
:root,[data-theme="dark"]{--bg:#0b0d12;--surface:#141720;--surface-hover:#1c2030;--border:#262b3a;--text:#e6e8ef;--text-secondary:#9aa1b5;--primary:#4f8cff;--primary-hover:#6b9dff;--green:#34d399;--red:#f87171;--yellow:#fbbf24;--blue:#60a5fa;--radius:10px;--shadow:0 8px 28px rgba(0,0,0,.45);--shadow-sm:0 2px 10px rgba(0,0,0,.3);--transition:.18s ease;color-scheme:dark}
[data-theme="light"]{--bg:#f6f8fc;--surface:#ffffff;--surface-hover:#f0f3f9;--border:#dde3ee;--text:#1f2430;--text-secondary:#5b6478;--primary:#1a73e8;--primary-hover:#1765cc;--green:#188038;--red:#d93025;--yellow:#f29900;--blue:#1a73e8;--shadow:0 8px 28px rgba(15,23,42,.14);--shadow-sm:0 2px 10px rgba(15,23,42,.08);color-scheme:light}
html,body{height:100%}
body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;font-size:15px;line-height:1.5;background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;overscroll-behavior-y:none}
:focus-visible{outline:2px solid var(--primary);outline-offset:2px}
button,input,textarea,select{-webkit-tap-highlight-color:transparent}
a{color:var(--primary);text-decoration:none}a:hover{text-decoration:underline}
button{font-family:inherit}input,textarea,select{font-family:inherit}
::-webkit-scrollbar{width:10px;height:10px}::-webkit-scrollbar-thumb{background:var(--border);border-radius:6px}::-webkit-scrollbar-track{background:transparent}
.hidden{display:none!important}
#app{position:relative;display:grid;grid-template-columns:240px 1fr;grid-template-rows:56px minmax(0,1fr);grid-template-areas:"topbar topbar" "sidebar list";height:100vh;height:100dvh;width:100%;overflow:hidden}
body.detail-view #app{grid-template-columns:240px 360px minmax(0,1fr);grid-template-areas:"topbar topbar topbar" "sidebar list detail"}
.pane-detail{display:none}body.detail-view .pane-detail{display:block}
.topbar{grid-area:topbar;display:flex;align-items:center;gap:10px;padding:0 12px;background:var(--surface);border-bottom:1px solid var(--border);z-index:30}
.topbar-left{display:flex;align-items:center;gap:8px;min-width:0}
.topbar-icon,.topbar-avatar{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border:none;background:transparent;color:var(--text-secondary);font-size:18px;border-radius:50%;cursor:pointer;transition:background var(--transition),color var(--transition)}
.topbar-icon:hover,.topbar-avatar:hover{background:var(--surface-hover);color:var(--text)}
.topbar-avatar{width:36px;height:36px;font-size:14px;font-weight:600;color:#fff}
.logo{display:flex;align-items:center;gap:8px;font-weight:700;font-size:17px;color:var(--text);white-space:nowrap}.logo:hover{text-decoration:none}
.logo-mark{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:8px;background:var(--primary);color:#fff;font-size:16px}
.topbar-right{display:flex;align-items:center;gap:4px;margin-left:auto}
.avatar-menu-wrap,.notif-menu-wrap{position:relative}
.search-bar{display:flex;align-items:center;gap:8px;flex:0 1 520px;height:40px;padding:0 14px;margin:0 8px;background:var(--bg);border:1px solid var(--border);border-radius:999px;transition:border-color var(--transition),background var(--transition)}
.search-bar:focus-within{border-color:var(--primary);background:var(--surface)}
.search-bar .search-icon{background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:15px;padding:4px}
.search-bar input{flex:1;min-width:0;border:none;outline:none;background:transparent;color:var(--text);font-size:14px}
.search-close{background:none;border:none;color:var(--text-secondary);cursor:pointer;font-size:14px;padding:4px}
#btn-search{display:none}
.sidebar{grid-area:sidebar;background:var(--surface);border-right:1px solid var(--border);overflow-y:auto;z-index:20}
.sidebar-inner{display:flex;flex-direction:column;height:100%;padding:12px 10px}
.account-switcher{display:flex;align-items:center;gap:10px;padding:10px;border-radius:var(--radius);cursor:pointer;margin-bottom:10px;transition:background var(--transition)}
.account-switcher:hover{background:var(--surface-hover)}
.as-info{flex:1;min-width:0}
.as-email{font-size:14px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.as-name{font-size:12px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.as-chev{color:var(--text-secondary);font-size:12px}
.sidebar-title{padding:14px 10px 6px;font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:var(--text-secondary)}
.folder-list,.tag-list{display:flex;flex-direction:column;gap:2px}
.nav-item{display:flex;align-items:center;gap:12px;padding:9px 12px;border-radius:8px;color:var(--text);font-size:14px;border:none;background:none;cursor:pointer;text-align:left;transition:background var(--transition)}
.nav-item:hover{background:var(--surface-hover);text-decoration:none}
.nav-item.active{background:color-mix(in srgb,var(--primary) 18%,transparent);color:var(--primary);font-weight:600}
.nav-icon{width:20px;text-align:center;font-size:15px;flex-shrink:0}
.nav-item .badge{margin-left:auto}
.tag{display:flex;align-items:center;gap:10px;padding:8px 12px;border-radius:8px;font-size:14px;color:var(--text)}.tag::before{content:"#";opacity:.7}
.tag:hover{background:var(--surface-hover);text-decoration:none}
.tag.active{background:color-mix(in srgb,var(--tag-color,var(--primary)) 20%,transparent);color:var(--tag-color,var(--primary));font-weight:600}
.add-tag{color:var(--text-secondary)}
.sidebar-footer{margin-top:auto;padding-top:12px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:2px}
.sidebar-app-footer{padding:12px 16px;font-size:11px;color:var(--text-secondary);text-align:center;line-height:1.4}
.sidebar-app-footer a{color:var(--text-secondary)}.sidebar-app-footer a:hover{color:var(--primary)}
.sidebar-app-footer p+p{margin-top:4px}
.sidebar-backdrop{display:none}
.pane{overflow-y:auto;min-width:0}
.pane-list{grid-area:list;border-right:1px solid var(--border);background:var(--bg)}
.pane-detail{grid-area:detail;background:var(--bg)}
.list-header{position:sticky;top:0;z-index:10;background:var(--bg);padding:14px 16px 10px;border-bottom:1px solid var(--border)}
.list-header-top{display:flex;align-items:center;justify-content:space-between;gap:10px}
.list-header h1{margin:0;font-size:20px;font-weight:700}
.list-subtitle{margin-top:2px;font-size:12.5px;color:var(--text-secondary)}
.list-actions{display:flex;align-items:center;gap:2px}
.message-list{padding-bottom:12px}
.message-row{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);cursor:pointer;position:relative;transition:background var(--transition)}
.message-row:hover{background:var(--surface-hover)}
.message-row:active{background:color-mix(in srgb,var(--primary) 8%,transparent)}
.message-row.selected{background:color-mix(in srgb,var(--primary) 12%,transparent)}
.message-row.focused{background:var(--surface-hover);box-shadow:inset 3px 0 0 var(--primary)}
.message-row .row-main{flex:1;min-width:0}
.message-row.unread .row-sender,.message-row.unread .row-subject{font-weight:700}
.message-row.unread .row-subject::after{content:"";display:inline-block;width:8px;height:8px;margin-left:8px;border-radius:50%;background:var(--primary);vertical-align:1px}
.row-top{display:flex;align-items:center;justify-content:space-between;gap:8px}
.row-sender{font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-date{font-size:12px;color:var(--text-secondary);white-space:nowrap;flex-shrink:0}
.row-subject{font-size:14px;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.row-count{color:var(--text-secondary);font-size:12px;font-weight:400}
.row-preview{font-size:13px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px}
.star-btn{border:none;background:none;cursor:pointer;font-size:17px;color:var(--border);padding:6px;line-height:1;border-radius:50%;transition:color var(--transition),transform var(--transition);flex-shrink:0}
.star-btn:hover{transform:scale(1.15)}.star-btn.on{color:var(--yellow)}
.avatar{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border-radius:50%;color:#fff;font-size:14px;font-weight:600;flex-shrink:0;user-select:none}
.badge{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:10px;background:var(--primary);color:#fff;font-size:11.5px;font-weight:700}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:9px 18px;border-radius:999px;border:1px solid var(--border);background:var(--surface);color:var(--text);font-size:14px;font-weight:600;cursor:pointer;transition:background var(--transition),border-color var(--transition);min-height:40px}
.btn:hover{background:var(--surface-hover)}.btn:disabled{opacity:.55;cursor:not-allowed}
.btn-primary{background:var(--primary);border-color:var(--primary);color:#fff}.btn-primary:hover{background:var(--primary-hover);border-color:var(--primary-hover)}
.btn-ghost{background:none;border:none;color:var(--text-secondary);padding:8px 12px;min-height:36px}.btn-ghost:hover{background:var(--surface-hover);color:var(--text)}
.btn-ghost.active{color:var(--primary);background:color-mix(in srgb,var(--primary) 14%,transparent)}
.btn-ghost.danger{color:var(--red)}.btn-block{width:100%}
.btn-danger{background:var(--red);border-color:var(--red);color:#fff}
.input,.textarea,select.input{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg);color:var(--text);font-size:14px;outline:none;transition:border-color var(--transition)}
.input:focus,.textarea:focus{border-color:var(--primary)}
.textarea{resize:vertical;min-height:90px;line-height:1.5}
label.field{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--text-secondary);margin-bottom:12px;text-align:left}
.checkbox{position:relative;display:inline-flex;width:20px;height:20px;cursor:pointer;flex-shrink:0}
.checkbox input{position:absolute;opacity:0;width:100%;height:100%;margin:0;cursor:pointer}
.checkbox span{width:20px;height:20px;border-radius:5px;border:2px solid var(--text-secondary);display:inline-flex;align-items:center;justify-content:center;font-size:13px;color:#fff;transition:background var(--transition),border-color var(--transition)}
.checkbox input:checked+span{background:var(--primary);border-color:var(--primary)}.checkbox input:checked+span::after{content:"✓"}
.empty-state{padding:60px 24px;text-align:center}
.empty-icon{font-size:44px;margin-bottom:12px;opacity:.9}
.empty-state h3{margin:0 0 6px;font-size:18px}.empty-state p{margin:0 0 16px;color:var(--text-secondary);font-size:14px}
.skeleton-row{display:flex;gap:12px;padding:14px;align-items:center}
.skeleton{display:block;background:var(--surface-hover);border-radius:6px;animation:pulse 1.2s ease-in-out infinite}
.skeleton.circle{width:38px;height:38px;border-radius:50%;flex-shrink:0}
.skeleton-lines{flex:1;display:flex;flex-direction:column;gap:8px}
.skeleton.line{height:12px}.skeleton.line.w60{width:60%}.skeleton.line.w90{width:90%}.skeleton.line.w40{width:40%}
@keyframes pulse{0%,100%{opacity:.5}50%{opacity:1}}
.compose-overlay{position:fixed;inset:0;z-index:60;pointer-events:none;display:flex;align-items:flex-end;justify-content:flex-end}
.compose-window{position:fixed;right:24px;bottom:24px;width:520px;max-width:calc(100vw - 40px);height:560px;max-height:calc(100dvh - 120px);background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;pointer-events:auto;opacity:0;transform:translateY(24px) scale(.98);transition:opacity .2s ease,transform .25s ease,height .25s ease}
.compose-overlay.open .compose-window{opacity:1;transform:none}
.compose-window.minimized{height:46px}.compose-window.minimized .compose-body{display:none}
.compose-header{display:flex;align-items:center;justify-content:space-between;padding:0 12px 0 16px;height:46px;background:var(--surface);border-bottom:1px solid var(--border);cursor:default}
.compose-header-actions{display:flex;gap:2px}
.compose-title{font-size:14px;font-weight:700;cursor:pointer}
.compose-body{flex:1;overflow-y:auto;padding:10px 0}
.compose-row{display:flex;align-items:flex-start;gap:10px;padding:4px 14px;border-bottom:1px solid var(--border);min-height:44px}
.compose-label{width:34px;font-size:13px;color:var(--text-secondary);padding-top:10px;flex-shrink:0}
.compose-from{flex:1;border:none;background:none;color:var(--text);font-size:14px;outline:none;height:44px}
.compose-subject{flex:1;border:none;background:none;color:var(--text);font-size:14px;outline:none;height:44px}
.compose-toggle{border:none;background:none;color:var(--primary);font-size:12.5px;padding:12px 14px;cursor:pointer}
.chips-wrap{display:flex;flex-wrap:wrap;align-items:center;gap:6px;padding:8px 0;flex:1;position:relative}
.recipient-input{border:none;outline:none;background:none;color:var(--text);font-size:14px;flex:1;min-width:120px;padding:2px 0}
.recipient-suggest{position:absolute;top:calc(100% + 4px);left:0;right:0;z-index:30;background:var(--surface);border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow);overflow:hidden;max-height:220px;overflow-y:auto}
.suggest-item{display:block;width:100%;text-align:left;padding:9px 12px;border:none;background:none;color:var(--text);font-size:13.5px;cursor:pointer}
.suggest-item:hover{background:var(--surface-hover)}.suggest-item span{color:var(--text-secondary);font-size:12.5px}
.compose-toolbar{display:flex;gap:2px;padding:6px 14px;border-bottom:1px solid var(--border)}
.compose-toolbar button{width:34px;height:32px;border:none;background:none;color:var(--text-secondary);font-size:14px;border-radius:6px;cursor:pointer}
.compose-toolbar button:hover{background:var(--surface-hover);color:var(--text)}
.compose-editor{min-height:160px;padding:12px 14px;font-size:14.5px;line-height:1.55;outline:none;overflow-y:auto;max-height:240px}
.compose-editor:empty::before{content:attr(data-placeholder);color:var(--text-secondary)}
.compose-footer{display:flex;align-items:center;gap:12px;padding:12px 14px}
.send-hint{font-size:12px;color:var(--text-secondary);flex:1}
.compose-draftsaved{font-size:12px;color:var(--green)}
.chip{display:inline-flex;align-items:center;gap:6px;background:color-mix(in srgb,var(--primary) 16%,transparent);color:var(--primary);border-radius:999px;padding:3px 8px 3px 10px;font-size:13px}
.chip-email{font-size:12px;opacity:.8}
.chip-remove{border:none;background:none;color:inherit;cursor:pointer;font-size:13px;padding:0 2px;line-height:1;border-radius:50%}.chip-remove:hover{color:var(--red)}.chip:hover{filter:brightness(1.1)}
.toast-container{position:fixed;left:50%;transform:translateX(-50%);bottom:calc(24px + env(safe-area-inset-bottom));z-index:120;display:flex;flex-direction:column;gap:8px;align-items:center;max-width:calc(100vw - 32px)}
.toast{display:flex;align-items:center;gap:10px;background:var(--surface);border:1px solid var(--border);border-radius:999px;padding:10px 16px;box-shadow:var(--shadow);font-size:13.5px;animation:toast-in .25s ease;max-width:100%}
.toast.success{border-color:var(--green)}.toast.success .toast-icon{color:var(--green)}
.toast.error{border-color:var(--red)}.toast.error .toast-icon{color:var(--red)}
.toast-icon{display:inline-flex;width:18px;height:18px;align-items:center;justify-content:center;font-size:13px;color:var(--text-secondary);flex-shrink:0}
.toast.out{opacity:0;transform:translateY(8px);transition:opacity .25s,transform .25s}
.toast-msg{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.toast-action{border:none;background:none;color:var(--primary);font-weight:700;cursor:pointer;font-size:13px;padding:0 4px}.toast-action:hover{text-decoration:underline}
@keyframes toast-in{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:none}}
.bottom-tabs{display:none}
.modal-overlay{position:fixed;inset:0;z-index:70;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,.55);padding:20px;opacity:0;pointer-events:none;transition:opacity .18s ease;backdrop-filter:blur(4px);-webkit-backdrop-filter:blur(4px)}
.modal-overlay.open{opacity:1;pointer-events:auto}
.modal{width:100%;max-width:480px;max-height:86dvh;display:flex;flex-direction:column;background:var(--surface);border:1px solid var(--border);border-radius:14px;box-shadow:var(--shadow);transform:translateY(12px) scale(.98);transition:transform .2s ease;overflow:hidden}
.modal-overlay.open .modal{transform:none}
.modal-header{display:flex;align-items:center;justify-content:space-between;padding:14px 16px 10px}.modal-header h3{margin:0;font-size:16px}
.modal-body{padding:6px 16px 12px;overflow-y:auto;font-size:14px}
.modal-footer{display:flex;justify-content:flex-end;gap:8px;padding:12px 16px;border-top:1px solid var(--border)}
.modal-close{border:none;background:none;color:var(--text-secondary);font-size:18px;cursor:pointer;padding:4px 8px}
.auth-view{min-height:100dvh;display:flex;flex-direction:column;align-items:center;padding:24px}
.auth-view .auth-card{margin:auto;width:100%}
.auth-view .app-footer{width:100%;max-width:560px}
.auth-view .auth-card{animation:menu-in .3s ease}
@keyframes menu-in{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:none}}
.auth-card{width:100%;max-width:560px;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:40px 36px;box-shadow:var(--shadow);text-align:center}
.auth-logo{width:52px;height:52px;margin:0 auto 12px;display:flex;align-items:center;justify-content:center;background:var(--primary);border-radius:14px;font-size:26px;color:#fff}
.auth-card h1{margin:0 0 4px;font-size:22px}.auth-sub{margin:0 0 20px;color:var(--text-secondary);font-size:14px}
.auth-card form{text-align:left}
.field-row{display:grid;grid-template-columns:1fr 70px 92px;gap:10px}
.field-row .field{margin-bottom:10px}
.setup-server-title{display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:var(--text-secondary);margin:16px 0 8px}
.setup-server-title .ti{font-size:15px}
.auth-hint{font-size:12px;color:var(--text-secondary);margin:14px 0 0;text-align:center}
.auth-error{color:var(--red);font-size:13px;margin:0 0 10px;display:none}
.app-footer{max-width:1200px;margin-left:auto;margin-right:auto;padding:1.5rem 1rem;font-size:0.75rem;color:var(--text-secondary);margin-top:auto;width:100%;text-align:center}
.app-footer a{color:var(--text)}.app-footer a:hover{color:var(--primary)}.app-footer p+p{margin-top:0.5rem}
.theme-toggle-btn{position:fixed;top:16px;right:16px;z-index:100;width:40px;height:40px;border-radius:50%;border:1px solid var(--border);background:var(--surface);color:var(--text);cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:18px;transition:all var(--transition)}
.theme-toggle-btn:hover{background:var(--surface-hover);transform:scale(1.05)}
body.auth #app>.topbar,body.auth #app>.sidebar,body.auth #app>.pane,body.auth #app>.bottom-tabs{display:none}
.fullscreen{position:fixed;inset:0;z-index:100;overflow-y:auto;background:var(--bg)}
.sync-status{padding:10px 16px;font-size:12px;color:var(--text-secondary);text-align:center}.sync-status.error{color:var(--red)}
.thread-view{max-width:860px;margin:0 auto;padding-bottom:80px}
.thread-header{display:flex;align-items:flex-start;gap:12px;padding:16px 20px 12px;border-bottom:1px solid var(--border);position:sticky;top:0;background:var(--bg);z-index:10}
.thread-title-block{flex:1;min-width:0}
.thread-subject{margin:0;font-size:20px;font-weight:700;line-height:1.3;word-break:break-word}
.thread-participants{font-size:13px;color:var(--text-secondary);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.thread-actions{display:flex;gap:4px;flex-shrink:0}
.back-btn{display:inline-flex;align-items:center;justify-content:center;width:38px;height:38px;border:none;background:none;color:var(--text);font-size:20px;border-radius:50%;cursor:pointer;flex-shrink:0}.back-btn:hover{background:var(--surface-hover)}
.message-stack{padding:10px 16px;display:flex;flex-direction:column;gap:12px}
.message-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:box-shadow var(--transition)}
.message-card:hover{box-shadow:var(--shadow-sm)}
.message-card.collapsed .msg-body,.message-card.collapsed .attachment-strip,.message-card.collapsed .msg-toolbar{display:none}
.message-card.collapsed .msg-collapsed-bar{display:flex}
.msg-collapsed-bar{display:none;align-items:center;gap:10px;padding:10px 14px;font-size:13px;cursor:pointer;color:var(--text-secondary)}
.msg-header{display:flex;gap:10px;padding:12px 14px 0;align-items:flex-start}
.msg-id{flex:1;min-width:0}
.msg-from{font-size:14px;font-weight:600}.msg-email{font-size:12.5px;color:var(--text-secondary);font-weight:400}
.msg-to{font-size:12.5px;color:var(--text-secondary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.msg-date{font-size:12.5px;color:var(--text-secondary)}
.msg-actions{flex-shrink:0}
.msg-collapse-btn{border:none;background:none;color:var(--text-secondary);cursor:pointer;font-size:14px;padding:4px 6px;border-radius:6px}.msg-collapse-btn:hover{background:var(--surface-hover)}
.msg-body{padding:10px 14px 4px;font-size:14.5px;word-break:break-word;overflow-wrap:anywhere}
.msg-body a{color:var(--blue)}.msg-body img{max-width:100%;height:auto;border-radius:6px}
.text-body>div{margin:0 0 6px}
.text-body blockquote,.quoted{border-left:3px solid var(--border);margin:8px 0 8px 6px;padding:2px 0 2px 12px;color:var(--text-secondary);font-size:13.5px}
.quote-toggle{border:none;background:none;color:var(--primary);cursor:pointer;font-size:12.5px;padding:2px 0}
.quote-body{display:block}blockquote .quote-body.hidden{display:none}
.html-body-wrap{position:relative}.html-body{width:100%;height:360px;border:1px solid var(--border);border-radius:8px;background:#fff}
.image-block{padding:4px 0 8px}
.attachment-strip{display:flex;flex-wrap:wrap;gap:8px;padding:6px 14px 4px}
.attachment-chip{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;background:var(--bg);border:1px solid var(--border);font-size:13px;color:var(--text)}.attachment-chip:hover{background:var(--surface-hover);text-decoration:none}
.msg-toolbar{display:flex;gap:6px;padding:8px 14px 12px;flex-wrap:wrap}
.msg-toolbar button{border:1px solid var(--border);background:var(--bg);color:var(--text);padding:6px 14px;border-radius:999px;font-size:13px;cursor:pointer;transition:background var(--transition)}.msg-toolbar button:hover{background:var(--surface-hover)}
.thread-reply-bar{position:sticky;bottom:0;display:flex;gap:10px;padding:14px 20px 18px;background:linear-gradient(transparent,var(--bg) 40%);z-index:5}
.overlay-view{min-height:100dvh;display:flex;align-items:flex-start;justify-content:center;padding:40px 20px}
.overlay-card{width:100%;max-width:85vw;max-height:90vh;overflow-y:auto;background:var(--surface);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow);display:flex;flex-direction:column}
.overlay-card .settings-header{display:flex;align-items:center;gap:12px;padding:20px 28px 16px;border-bottom:1px solid var(--border);flex-shrink:0}.overlay-card .settings-header h1{margin:0}
.overlay-card .settings-body{padding:20px 28px 28px;text-align:left;flex:1;overflow-y:auto}
.overlay-card .settings-section{margin-bottom:28px}.overlay-card .settings-section:last-child{margin-bottom:0}
.overlay-card .settings-section h2{font-size:12px;text-transform:uppercase;letter-spacing:0.08em;color:var(--text-secondary);margin:0 0 12px}
.overlay-card .account-card{display:flex;align-items:center;gap:12px;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:10px;margin-bottom:8px}
.overlay-card .setting-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border)}
.overlay-card .back-btn{background:none;border:none;color:var(--text);cursor:pointer;font-size:20px;padding:4px;display:flex}
.contacts-view{max-width:640px;margin:0 auto;padding:0 20px 80px}
.contact-search{margin-bottom:14px}
.contact-row{display:flex;align-items:center;gap:12px;padding:10px 12px;border-radius:8px;cursor:pointer;border-bottom:1px solid var(--border)}.contact-row:hover{background:var(--surface-hover)}
.c-name{font-size:14px;font-weight:600}.c-email{font-size:13px;color:var(--text-secondary)}.c-count{margin-left:auto;color:var(--text-secondary);font-size:12px}
@media(max-width:1023px){#app{grid-template-columns:minmax(300px,360px) minmax(0,1fr);grid-template-areas:"topbar topbar" "list detail"}.sidebar{position:fixed;top:56px;bottom:0;left:0;width:280px;z-index:45;transform:translateX(-105%);transition:transform .25s ease;box-shadow:var(--shadow)}body.sidebar-open .sidebar{transform:none}.sidebar-backdrop{display:block;position:fixed;inset:0;z-index:44;background:rgba(0,0,0,.45);opacity:0;pointer-events:none;transition:opacity .2s ease}body.sidebar-open .sidebar-backdrop{opacity:1;pointer-events:auto}.search-bar{flex:0 1 340px}}
@media(max-width:640px){#app{grid-template-columns:1fr;grid-template-rows:56px minmax(0,1fr);grid-template-areas:"topbar" "content"}.pane-list,.pane-detail{grid-area:content}.pane-detail{display:none}body.detail-view .pane-list{display:none}body.detail-view .pane-detail{display:block}.pane-list,.pane-detail{padding-bottom:calc(56px + env(safe-area-inset-bottom))}.topbar{padding:0 8px;gap:6px}.logo-text{display:none}#btn-search{display:inline-flex}.search-bar{position:absolute;top:8px;left:12px;right:12px;z-index:20;background:var(--surface);margin:0;opacity:0;pointer-events:none;transform:translateY(-8px);transition:opacity .18s ease,transform .18s ease}.search-bar.active{opacity:1;pointer-events:auto;transform:none}body.search-open .topbar-left,body.search-open .topbar-right{opacity:0;pointer-events:none}.search-bar input{font-size:16px}.bottom-tabs{display:grid;grid-template-columns:1fr 1fr 72px 1fr;position:fixed;left:0;right:0;bottom:0;z-index:50;height:calc(56px + env(safe-area-inset-bottom));padding-bottom:env(safe-area-inset-bottom);background:var(--surface);border-top:1px solid var(--border)}.tab-item{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:11px;color:var(--text-secondary);min-height:44px;transition:color var(--transition)}.tab-item:hover{color:var(--text)}.tab-item:active{opacity:.7}.tab-item.active{color:var(--primary)}.tab-item .tab-ico{font-size:19px;line-height:1}.tab-fab{position:relative;align-self:center;justify-self:center;width:56px;height:56px;border-radius:50%;border:none;background:var(--primary);color:#fff;font-size:22px;cursor:pointer;box-shadow:0 4px 14px color-mix(in srgb,var(--primary) 45%,transparent);margin-top:-22px;transition:transform var(--transition)}.tab-fab:hover{transform:scale(1.06)}.tab-fab:active{transform:scale(.96)}.toast-container{bottom:calc(68px + env(safe-area-inset-bottom))}.compose-window{right:0;bottom:0;width:100vw;max-width:none;height:100dvh;max-height:100dvh;border-radius:0}.compose-window.minimized{height:46px;width:calc(100vw - 24px);right:12px;bottom:calc(60px + env(safe-area-inset-bottom));border-radius:10px}.thread-header{position:static}.modal{max-width:100%}.message-row{padding-top:12px;padding-bottom:12px}.list-header h1{font-size:18px}.thread-subject{font-size:17px}.thread-header{gap:8px;padding:12px 14px 10px}.msg-toolbar button{padding:8px 16px}.compose-editor{max-height:none;min-height:200px}}
@media(max-width:640px){.auth-card{max-width:100%;padding:28px 20px}}
@media(min-width:641px){body.detail-view .pane-list{display:block}}
@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;transition-duration:.01ms!important}}
</style>
</head>
<body>
<div id="app">
  <header class="topbar">
    <div class="topbar-left">
      <button class="topbar-icon" id="btn-hamburger" aria-label="Menu" title="Menu"><i class="ti ti-menu-2"></i></button>
      <a class="logo" href="#inbox"><span class="logo-mark"><i class="ti ti-mail"></i></span><span class="logo-text">Emmmail</span></a>
      <button class="topbar-icon" id="btn-search" aria-label="Search" title="Search"><i class="ti ti-search"></i></button>
    </div>
    <div class="search-bar" id="search-bar">
      <button class="search-icon" id="search-submit" aria-label="Search"><i class="ti ti-search"></i></button>
      <input type="search" placeholder="Search mail  ( / )" aria-label="Search mail" autocomplete="off">
      <button class="search-close" id="search-close" hidden><i class="ti ti-x"></i></button>
    </div>
    <div class="topbar-right">
      <div class="notif-menu-wrap">
        <button class="topbar-icon" id="btn-notifications" aria-label="Notifications"><i class="ti ti-bell"></i></button>
        <div class="menu-dropdown" id="notif-menu"></div>
      </div>
      <div class="avatar-menu-wrap">
        <button class="topbar-avatar" id="btn-avatar" aria-label="Account menu"></button>
        <div class="menu-dropdown" id="avatar-menu"></div>
      </div>
    </div>
  </header>
  <div class="sidebar-backdrop" id="sidebar-backdrop"></div>
  <aside class="sidebar" id="sidebar"></aside>
  <section class="pane pane-list" id="pane-list"></section>
  <section class="pane pane-detail" id="pane-detail"></section>
  <div class="compose-overlay" id="compose-overlay"></div>
  <nav class="bottom-tabs" id="bottom-tabs">
    <a class="tab-item" data-tab="inbox" href="#inbox"><span class="tab-ico"><i class="ti ti-inbox"></i></span>Inbox</a>
    <a class="tab-item" data-tab="search" href="#search"><span class="tab-ico"><i class="ti ti-search"></i></span>Search</a>
    <button class="tab-fab" data-tab="compose" aria-label="Compose" title="New message"><i class="ti ti-pencil"></i></button>
    <a class="tab-item" data-tab="settings" href="#settings"><span class="tab-ico"><i class="ti ti-settings"></i></span>Settings</a>
  </nav>
  <div class="toast-container" id="toast-container"></div>
  <div class="modal-overlay" id="modal-overlay"></div>
  <div class="fullscreen" id="fullscreen" hidden></div>
</div>
<script>
'use strict';
const PROVIDERS={gmail:{name:'Gmail',imap_host:'imap.gmail.com',imap_port:993,imap_tls:1,smtp_host:'smtp.gmail.com',smtp_port:465,smtp_tls:1},yahoo:{name:'Yahoo',imap_host:'imap.mail.yahoo.com',imap_port:993,imap_tls:1,smtp_host:'smtp.mail.yahoo.com',smtp_port:465,smtp_tls:1},outlook:{name:'Outlook',imap_host:'outlook.office365.com',imap_port:993,imap_tls:1,smtp_host:'smtp.office365.com',smtp_port:587,smtp_tls:2},migadu:{name:'Migadu',imap_host:'imap.migadu.com',imap_port:993,imap_tls:1,smtp_host:'smtp.migadu.com',smtp_port:465,smtp_tls:1},icloud:{name:'iCloud',imap_host:'imap.mail.me.com',imap_port:993,imap_tls:1,smtp_host:'smtp.mail.me.com',smtp_port:587,smtp_tls:2}};
const App={currentView:'#login',currentFolder:'inbox',currentAccountId:null,accounts:[],threads:[],tags:[],settings:{},sessionToken:null,el:(s)=>document.querySelector(s),els:(s)=>Array.from(document.querySelectorAll(s)),api:async function(a,p={},m='POST'){const o={method:m,headers:{}};if(this.sessionToken)o.headers['X-Session-Token']=this.sessionToken;let u='index.php?action='+encodeURIComponent(a);if(m==='GET'){const q=new URLSearchParams();for(const[k,v]of Object.entries(p)){if(v==null||v==='')continue;q.set(k,Array.isArray(v)?JSON.stringify(v):String(v))}const s=q.toString();if(s)u+='&'+s}else{o.headers['Content-Type']='application/json';o.body=JSON.stringify(p)}let r;try{r=await fetch(u,o)}catch(e){throw new Error('Network error')}let j;try{j=await r.json()}catch(e){throw new Error('Invalid response')}if(j&&j.ok===false)throw new Error(j.error||'Failed');if(!r.ok)throw new Error((j&&j.error)||'HTTP '+r.status);return(j&&j.data!==undefined)?j.data:j},init:async function(){this.applyTheme();this.bindGlobal();this.sessionToken=localStorage.getItem('emmmail-session');try{const s=await this.api('session');if(s.authenticated){this.currentAccountId=s.account_id;await this.loadBaseData();this.syncOnLoad();this.navigate(location.hash||'#inbox');return}else{this.sessionToken=null;localStorage.removeItem('emmmail-session')}}catch(e){this.sessionToken=null;localStorage.removeItem('emmmail-session')}try{const s=await this.api('setup');this.navigate(s.setup?'#setup':'#login')}catch(e){this.renderLogin()}},loadBaseData:async function(){const[a,s]=await Promise.all([this.api('list_accounts').catch(()=>({accounts:[]})),this.api('get_settings').catch(()=>({settings:{}}))]);this.accounts=a.accounts||a||[];this.settings=s.settings||s||{};if(this.currentAccountId==null&&this.accounts.length)this.currentAccountId=this.accounts[0].id;await this.loadTags()},loadTags:async function(){const d=await this.api('list_tags').catch(()=>({tags:[]}));this.tags=d.tags||d||[]},syncOnLoad:async function(){if(this._syncing)return;if(!this.settings.sync_on_load||this.currentAccountId==null)return;this._syncing=true;try{await this.api('sync',{account_id:this.currentAccountId});await this.loadBaseData();if(this.currentFolder&&['inbox','sent','drafts','starred','all'].indexOf(this.currentFolder)>-1)await this.loadThreads(this.currentFolder)}catch(err){}finally{this._syncing=false}},navigate:function(h){if(!h)h=location.hash;if(!h||h==='#')h='#inbox';if(location.hash!==h)history.replaceState(null,'',h);this.currentView=h;const[path,q]=h.slice(1).split('?');const parts=path.split('/').filter(Boolean);const v=parts[0]||'inbox';const isDetail=['thread','reply','forward'].includes(v);const isOverlay=['settings','contacts'].includes(v);const isAuth=['login','setup'].includes(v);document.body.classList.toggle('detail-view',isDetail);document.body.classList.toggle('auth',isAuth);if(!isAuth&&!isOverlay){const fs=this.el('#fullscreen');if(fs){fs.hidden=true;fs.innerHTML=''}}this.closeSearch();this.closeMenus();if(v!=='thread'&&v!=='reply'&&v!=='forward'&&!isOverlay)this._prevListHash=h;switch(v){case'login':this.renderLogin();break;case'setup':this.renderSetup();break;case'inbox':case'sent':case'drafts':case'starred':case'all':this.currentFolder=v;this.currentTagId=null;this.loadThreads(v);break;case'tag':this.currentFolder='tag';this.currentTagId=parts[1];this.loadTagThreads(parts[1]);break;case'thread':this.openThread(parts[1]);break;case'compose':this.openCompose();break;case'reply':this.openCompose({mode:'reply',messageId:parts[1]});break;case'forward':this.openCompose({mode:'forward',messageId:parts[1]});break;case'search':this.renderSearch(new URLSearchParams(q).get('q')||'');break;case'settings':this.renderSettings();break;case'contacts':this.renderContacts();break;default:this.currentFolder='inbox';this.loadThreads('inbox');break}this.renderSidebar();this.updateBottomTabs();this.updateAvatar()},renderLogin:function(){const fs=this.el('#fullscreen');fs.hidden=false;document.body.classList.add('auth');fs.innerHTML=`<div class="auth-view"><form class="auth-card" data-login-form><div class="auth-logo"><i class="ti ti-mail"></i></div><h1>Emmmail</h1><p class="auth-sub">Sign in to your email</p><div class="auth-error" data-login-error></div><label class="field"><span>Email</span><input class="input" type="email" name="email" placeholder="you@example.com" autocomplete="username" required></label><label class="field"><span>Password</span><input class="input" type="password" name="password" placeholder="App password" autocomplete="current-password" required></label><button class="btn btn-primary btn-block" type="submit">Sign in</button><p class="auth-hint">Credentials are validated against your IMAP server and stored encrypted (AES-256-GCM).</p></form><button class="theme-toggle-btn" data-theme-toggle><i class="ti ti-${this.settings.theme==='light'?'moon':'sun'}"></i></button><footer class="app-footer"><p>Designed and built by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co?ref=Emmmail" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p><p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a></p></footer></div>`;const form=this.el('[data-login-form]');form.addEventListener('submit',(e)=>{e.preventDefault();this.doLogin(form)});fs.querySelector('[data-theme-toggle]')?.addEventListener('click',()=>{this.toggleTheme();this.renderLogin()})},doLogin:async function(form){const btn=form.querySelector('button[type=submit]');const errBox=this.el('[data-login-error]');btn.disabled=true;btn.textContent='Signing in...';errBox.style.display='none';try{const r=await this.api('login',{email:form.email.value.trim(),password:form.password.value});this.sessionToken=r.session_token;localStorage.setItem('emmmail-session',this.sessionToken);this.showToast('Signed in');this.currentAccountId=r.account_id;await this.loadBaseData();this.syncOnLoad();this.navigate('#inbox')}catch(err){errBox.textContent=err.message;errBox.style.display='block';btn.disabled=false;btn.textContent='Sign in'}},renderSetup:function(){const fs=this.el('#fullscreen');fs.hidden=false;document.body.classList.add('auth');fs.innerHTML=`<div class="auth-view"><form class="auth-card" data-setup-form><div class="auth-logo"><i class="ti ti-mail"></i></div><h1>Set up your email</h1><p class="auth-sub">Connect your first account</p><div class="auth-error" data-setup-error></div><label class="field"><span>Provider</span><select class="input" data-spreset><option value="" disabled selected>Select a provider or configure manually</option><option value="gmail">Gmail</option><option value="yahoo">Yahoo</option><option value="outlook">Outlook</option><option value="migadu">Migadu</option><option value="icloud">iCloud</option><option value="custom">Custom</option></select></label><div class="setup-server"><div class="setup-server-title"><i class="ti ti-inbox"></i>IMAP (incoming)</div><div class="field-row"><label class="field f-host"><span>Server</span><input class="input" data-simap-host placeholder="imap.example.com"></label><label class="field"><span>Port</span><input class="input" type="number" data-simap-port value="993" required></label><label class="field"><span>TLS</span><select class="input" data-simap-tls><option value="1" selected>SSL/TLS</option><option value="2">STARTTLS</option><option value="0">None</option></select></label></div><div class="setup-server-title"><i class="ti ti-send"></i>SMTP (outgoing)</div><div class="field-row"><label class="field f-host"><span>Server</span><input class="input" data-ssmtp-host placeholder="smtp.example.com"></label><label class="field"><span>Port</span><input class="input" type="number" data-ssmtp-port value="465" required></label><label class="field"><span>TLS</span><select class="input" data-ssmtp-tls><option value="1" selected>SSL/TLS</option><option value="2">STARTTLS</option><option value="0">None</option></select></label></div></div><label class="field"><span>Email</span><input class="input" type="email" data-semail placeholder="you@example.com" required></label><label class="field"><span>Password</span><input class="input" type="password" data-spassword placeholder="App password" required></label><div style="display:flex;gap:10px;margin-top:18px;justify-content:center"><button class="btn btn-primary" type="button" data-test>Test connection</button><button class="btn btn-ghost" type="submit" disabled>Finish setup</button></div></form><button class="theme-toggle-btn" data-theme-toggle><i class="ti ti-${this.settings.theme==='light'?'moon':'sun'}"></i></button><footer class="app-footer"><p>Designed and built by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co?ref=Emmmail" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p><p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a></p></footer></div>`;const form=this.el('[data-setup-form]');form.addEventListener('submit',(e)=>{e.preventDefault();this.submitSetup(form)});const test=form.querySelector('[data-test]');if(test)test.addEventListener('click',()=>this.testConnection(form));const presetSelect=form.querySelector('[data-spreset]');if(presetSelect)presetSelect.addEventListener('change',()=>{const p=PROVIDERS[presetSelect.value]||null;if(p){form.querySelector('[data-simap-host]').value=p.imap_host;form.querySelector('[data-simap-port]').value=p.imap_port;form.querySelector('[data-simap-tls]').value=p.imap_tls;form.querySelector('[data-ssmtp-host]').value=p.smtp_host;form.querySelector('[data-ssmtp-port]').value=p.smtp_port;form.querySelector('[data-ssmtp-tls]').value=p.smtp_tls;form.querySelector('[data-semail]').focus()}});fs.querySelector('[data-theme-toggle]')?.addEventListener('click',()=>{this.toggleTheme();this.renderSetup()})},testConnection:async function(form){const testBtn=form.querySelector('[data-test]');const submitBtn=form.querySelector('button[type=submit]');testBtn.disabled=true;testBtn.textContent='Testing\u2026';try{const r=await this.api('test_connection',{email:form.querySelector('[data-semail]').value.trim(),password:form.querySelector('[data-spassword]').value,imap_host:form.querySelector('[data-simap-host]').value.trim(),imap_port:+form.querySelector('[data-simap-port]').value,imap_tls:+form.querySelector('[data-simap-tls]').value,smtp_host:form.querySelector('[data-ssmtp-host]').value.trim(),smtp_port:+form.querySelector('[data-ssmtp-port]').value,smtp_tls:+form.querySelector('[data-ssmtp-tls]').value});this.showToast('Connection successful','success');testBtn.textContent='Connected';submitBtn.disabled=false;submitBtn.classList.remove('btn-ghost');submitBtn.classList.add('btn-primary')}catch(err){this.showToast(err.message,'error');testBtn.disabled=false;testBtn.textContent='Test connection'}},submitSetup:async function(form){const btn=form.querySelector('button[type=submit]');btn.disabled=true;btn.textContent='Setting up\u2026';try{const payload={email:form.querySelector('[data-semail]').value.trim(),password:form.querySelector('[data-spassword]').value,imap_host:form.querySelector('[data-simap-host]').value.trim(),imap_port:+form.querySelector('[data-simap-port]').value,imap_tls:+form.querySelector('[data-simap-tls]').value,smtp_host:form.querySelector('[data-ssmtp-host]').value.trim(),smtp_port:+form.querySelector('[data-ssmtp-port]').value,smtp_tls:+form.querySelector('[data-ssmtp-tls]').value};await this.api('auth',payload);this.showToast('Welcome to Emmmail!');const l=await this.api('login',{email:form.querySelector('[data-semail]').value.trim(),password:form.querySelector('[data-spassword]').value});this.sessionToken=l.session_token;localStorage.setItem('emmmail-session',this.sessionToken);await this.init();this.navigate('#inbox')}catch(err){this.showToast(err.message,'error');btn.disabled=false;btn.textContent='Finish setup'}},renderSidebar:function(){const sb=this.el('#sidebar');const acct=this.getAccount();const unread=this.unreadCounts();const folder=this.currentFolder;sb.innerHTML=`<div class="sidebar-inner"><div class="account-switcher" data-account-switch>${acct?this.avatarHtml(acct.display_name,acct.email):''}<div class="as-info"><div class="as-email">${this.escapeHtml(acct?acct.email:'No account')}</div><div class="as-name">${this.escapeHtml(acct&&acct.display_name?acct.display_name:'')}</div></div><span class="as-chev"><i class="ti ti-chevron-down"></i></span></div><nav class="folder-list"><a class="nav-item ${folder==='inbox'?'active':''}" href="#inbox"><span class="nav-icon"><i class="ti ti-inbox"></i></span>Inbox${unread.inbox?`<span class="badge">${unread.inbox}</span>`:''}</a><a class="nav-item ${folder==='starred'?'active':''}" href="#starred"><span class="nav-icon"><i class="ti ti-star"></i></span>Starred</a><a class="nav-item ${folder==='sent'?'active':''}" href="#sent"><span class="nav-icon"><i class="ti ti-send"></i></span>Sent</a><a class="nav-item ${folder==='drafts'?'active':''}" href="#drafts"><span class="nav-icon"><i class="ti ti-file-text"></i></span>Drafts</a><a class="nav-item ${folder==='all'?'active':''}" href="#all"><span class="nav-icon"><i class="ti ti-folder"></i></span>All mail</a></nav><div class="sidebar-title">Tags</div><nav class="tag-list">${this.tags.length?this.tags.map(t=>`<a class="tag ${this.currentTagId==t.id?'active':''}" href="#tag/${t.id}" style="--tag-color:${this.escapeHtml(t.color)}">${this.escapeHtml(t.name)}</a>`).join(''):''}<button class="nav-item add-tag" data-add-tag><span class="nav-icon"><i class="ti ti-plus"></i></span>New tag</button></nav><div class="sidebar-footer"><a class="nav-item ${this.currentView==='#contacts'?'active':''}" href="#contacts"><span class="nav-icon"><i class="ti ti-users"></i></span>Contacts</a><a class="nav-item ${this.currentView==='#settings'?'active':''}" href="#settings"><span class="nav-icon"><i class="ti ti-settings"></i></span>Settings</a></div><div class="sidebar-app-footer"><p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a></p></div></div>`;this.bindSidebarEvents()},bindSidebarEvents:function(){const switcher=this.el('[data-account-switch]');if(switcher)switcher.addEventListener('click',()=>this.showAccountSwitcher());const addTag=this.el('[data-add-tag]');if(addTag)addTag.addEventListener('click',()=>this.showNewTagModal())},showAccountSwitcher:function(){this.showModal('Switch account',this.accounts.map(a=>`<button class="menu-item" data-switch="${a.id}">${this.avatarHtml(a.display_name,a.email)}<span class="mi-main">${this.escapeHtml(a.email)}<span class="mi-sub">${this.escapeHtml(a.display_name||'')}</span></span>${a.id===this.currentAccountId?'<span class="mi-check"><i class="ti ti-check"></i></span>':''}</button>`).join(''),'<button class="btn btn-ghost" data-add-acct>+ Add account</button>');const ov=this.el('.modal-overlay');ov.querySelectorAll('[data-switch]').forEach(b=>b.addEventListener('click',()=>{this.switchAccount(+b.dataset.switch);this.closeModal()}));const add=ov.querySelector('[data-add-acct]');if(add)add.addEventListener('click',()=>{this.closeModal();this.openAddAccount()})},switchAccount:function(id){this.currentAccountId=id;this.selectedThreadIds?.clear();this.currentTagId=null;this.loadTags().then(()=>{this.renderSidebar();this.navigate(this.currentView)})},showNewTagModal:function(){this.showModal('New tag',`<label class="field"><span>Name</span><input class="input" data-tag-name placeholder="e.g. Work"></label><label class="field"><span>Color</span><input class="input" type="color" data-tag-color value="#4f8cff" style="height:44px;padding:4px"></label>`,'<button class="btn btn-primary" data-tag-create>Create</button>');const ov=this.el('.modal-overlay');const create=ov.querySelector('[data-tag-create]');const doCreate=async()=>{const name=ov.querySelector('[data-tag-name]').value.trim();if(!name){this.showToast('Tag needs a name','error');return}try{await this.api('create_tag',{name,color:ov.querySelector('[data-tag-color]').value});this.closeModal();await this.loadTags();this.renderSidebar()}catch(err){this.showToast(err.message,'error')}};create.addEventListener('click',doCreate);ov.querySelector('[data-tag-name]').addEventListener('keydown',(e)=>{if(e.key==='Enter')doCreate()})},loadThreads:async function(folder){this.currentFolder=folder;this.currentTagId=null;this.showSkeleton('list');try{const d=await this.api('list_messages',{folder});this.threads=d.messages||d.threads||[];this.renderMessageList()}catch(err){this.threads=[];this.renderMessageList();this.showToast(err.message,'error')}},loadTagThreads:async function(tagId){this.currentFolder='tag';this.showSkeleton('list');try{const d=await this.api('list_messages',{tag_id:tagId});this.threads=d.messages||d.threads||[];this.renderMessageList()}catch(err){this.threads=[];this.renderMessageList();this.showToast(err.message,'error')}},folderTitle:function(f){if(f==='tag'){const t=this.tags.find(tag=>tag.id==this.currentTagId);return t?'#'+t.name:'Tag'}return{inbox:'Inbox',sent:'Sent',drafts:'Drafts',starred:'Starred',all:'All mail'}[f]||'Mail'},showSkeleton:function(w){if(w==='list'){this.el('#pane-list').innerHTML=`<div class="message-list">${this.skeletonRows(8)}</div>`}else{this.el('#pane-detail').innerHTML=`<div style="padding:24px;display:flex;flex-direction:column;gap:14px"><span class="skeleton line" style="width:40%"></span><span class="skeleton" style="height:120px"></span><span class="skeleton" style="height:120px"></span></div>`}},skeletonRows:function(n){let s='';for(let i=0;i<n;i++)s+=`<div class="skeleton-row"><span class="skeleton circle"></span><div class="skeleton-lines"><span class="skeleton line w60"></span><span class="skeleton line w90"></span></div></div>`;return s},renderMessageList:function(){const list=this.el('#pane-list');const title=this.folderTitle(this.currentFolder);const empty=!this.threads.length;list.innerHTML=`${this.listHeaderHtml(title)}${empty?this.emptyStateHtml(this.emptyStateFor(this.currentFolder)):`<div class="message-list">${this.threads.map((t,i)=>this.messageRowHtml(t,i)).join('')}</div>`}${this.syncStatusHtml()}`},listHeaderHtml:function(title){const n=this.threads.length;return`<div class="list-header"><div class="list-header-top"><h1>${this.escapeHtml(title)}</h1><div class="list-actions"><button class="btn-ghost" data-refresh title="Sync"><i class="ti ti-refresh"></i></button></div></div><div class="list-subtitle">${n} message${n===1?'':'s'}</div></div>`},messageRowHtml:function(t,idx){return`<div class="message-row ${t.is_read?'':'unread'} ${idx===this._focusedIdx?'focused':''}" data-thread data-id="${t.id}"><button class="star-btn ${t.is_starred?'on':''}" data-star title="Star"><i class="ti ti-star"></i></button>${this.avatarHtml(t.from_name,t.from_address)}<div class="row-main" data-open><div class="row-top"><span class="row-sender">${this.escapeHtml(t.from_name||t.from_address||'(no sender)')}</span><span class="row-date">${this.formatDate(t.date)}</span></div><div class="row-subject">${this.escapeHtml(t.subject||'(no subject)')}</div><div class="row-preview">${this.escapeHtml(t.preview||'')}${t.has_attachments?' <i class="ti ti-paperclip"></i>':''}</div></div></div>`},emptyStateFor:function(f){const m={inbox:{icon:'<i class="ti ti-circle-check"></i>',title:'Inbox zero',text:'You\u2019re all caught up.'},sent:{icon:'<i class="ti ti-send"></i>',title:'No sent mail',text:'Messages you send will appear here.'},drafts:{icon:'<i class="ti ti-file-text"></i>',title:'No drafts',text:'Your saved drafts will appear here.'},starred:{icon:'<i class="ti ti-star"></i>',title:'No starred messages',text:'Star important messages to find them here.'},all:{icon:'<i class="ti ti-mail"></i>',title:'No messages',text:'Your mailbox is empty.'}};return m[f]||m.all},emptyStateHtml:function(o){return`<div class="empty-state"><div class="empty-icon">${o.icon||'<i class="ti ti-mail"></i>'}</div><h3>${o.title||'Nothing here'}</h3><p>${o.text||''}</p></div>`},syncStatusHtml:function(){const acct=this.getAccount();if(!acct)return'';if(acct.sync_error)return`<div class="sync-status error"><i class="ti ti-alert-triangle"></i> ${this.escapeHtml(acct.sync_error)}</div>`;return`<div class="sync-status">Last synced ${this.formatDate(acct.last_sync_at)||'never'} &middot; <a href="#" data-sync-now>Sync now</a></div>`},openThread:async function(id){document.body.classList.add('detail-view');this.showSkeleton('detail');try{const d=await this.api('get_thread',{thread_id:+id});this.currentThread=d.thread||d;this.threadMessages=d.messages||[];this.renderThread();const th=this.threads.find(t=>t.id==id);if(th&&!th.is_read){th.is_read=1;this.api('set_flags',{message_id:+id,is_read:1}).catch(()=>{})}}catch(err){this.el('#pane-detail').innerHTML=this.emptyStateHtml({icon:'<i class="ti ti-alert-triangle"></i>',title:'Could not load message',text:err.message})}},renderThread:function(){const detail=this.el('#pane-detail');const th=this.currentThread||{};const msgs=this.threadMessages||[];const last=msgs[msgs.length-1];const participants=Array.from(new Set(msgs.map(m=>m.from_name||m.from_address).filter(Boolean))).join(', ');detail.innerHTML=`<div class="thread-view"><div class="thread-header"><button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button><div class="thread-title-block"><h1 class="thread-subject">${this.escapeHtml(th.subject||'(no subject)')}</h1><div class="thread-participants">${this.escapeHtml(participants)}</div></div><div class="thread-actions"><button class="btn-ghost" data-archive title="Archive">Archive</button><button class="btn-ghost" data-star-thread title="Star"><i class="ti ti-star${th.is_starred?'':'-off'}"></i></button><button class="btn-ghost" data-delete title="Delete">Delete</button></div></div><div class="message-stack">${msgs.map((m,i)=>this.messageCardHtml(m,i)).join('')}</div>${last?`<div class="thread-reply-bar"><button class="btn btn-primary" data-reply="${last.id}"><i class="ti ti-arrow-back"></i> Reply</button><button class="btn" data-reply-all="${last.id}"><i class="ti ti-arrow-back-up"></i> Reply all</button><button class="btn btn-ghost" data-forward="${last.id}"><i class="ti ti-arrow-forward-right"></i> Forward</button></div>`:''}</div>`;this.bindThreadEvents()},messageCardHtml:function(m,idx){const collapsed=idx>0?'collapsed':'';const toList=Array.isArray(m.to_addresses)?m.to_addresses.map(a=>a.name||a.email).join(', '):(m.to_addresses||'');const body=m.html_body?this.renderHtmlBody(m):this.renderTextBody(m.text_body);const atts=Array.isArray(m.attachments)?m.attachments:[];return`<div class="message-card ${collapsed}" data-msg-id="${m.id}"><div class="msg-collapsed-bar" data-expand>${this.escapeHtml(m.from_name||m.from_address)} &mdash; ${this.escapeHtml(m.subject||'(no subject)')}</div><div class="msg-header">${this.avatarHtml(m.from_name,m.from_address)}<div class="msg-id"><div class="msg-from">${this.escapeHtml(m.from_name||m.from_address)} <span class="msg-email">&lt;${this.escapeHtml(m.from_address||'')}&gt;</span></div><div class="msg-to">to ${this.escapeHtml(toList)}</div><div class="msg-date">${this.formatDate(m.date)}</div></div><div class="msg-actions"><button class="msg-collapse-btn" data-collapse title="Toggle"><i class="ti ti-chevron-${collapsed?'right':'down'}"></i></button></div></div><div class="msg-body">${body}</div>${atts.length?`<div class="attachment-strip">${atts.map(a=>`<a class="attachment-chip" href="index.php?action=download_attachment&attachment_id=${a.id}"><i class="ti ti-paperclip"></i> ${this.escapeHtml(a.filename||'attachment')} <span>(${this.formatSize(a.size)})</span></a>`).join('')}</div>`:''}<div class="msg-toolbar"><button data-reply="${m.id}">Reply</button><button data-reply-all="${m.id}">Reply all</button><button data-forward="${m.id}">Forward</button></div></div>`},renderTextBody:function(text){if(!text)return'<div class="text-body"><span style="color:var(--text-secondary)">No content</span></div>';const lines=String(text).replace(/\r\n/g,'\n').split('\n');let html='<div class="text-body">';let inQ=false;for(const line of lines){if(/^\s*>/.test(line)){if(!inQ){html+='<blockquote class="quoted-text"><button type="button" class="quote-toggle"><i class="ti ti-chevron-right"></i> Show quoted text</button><div class="quote-body hidden">';inQ=true}html+=`<div>${this.escapeHtml(line)}</div>`}else{if(inQ){html+='</div></blockquote>';inQ=false}if(line==='')html+='<br>';else html+=`<div>${this.escapeHtml(line)}</div>`}}if(inQ)html+='</div></blockquote>';html+='</div>';return html},renderHtmlBody:function(m){let html=m.html_body||m.text_body||'';html=this.sanitizeHtml(html);const blockImages=!this.settings.show_remote_images&&!this._showImages?.has(m.id);const style=blockImages?'img{max-width:100%;height:auto}img[src^="http"]{display:none!important}':'img{max-width:100%;height:auto}';const doc='<!doctype html><html><head><meta charset="utf-8"><style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2430;margin:8px}'+style+'</style></head><body>'+html+'</body></html>';return`<div class="html-body-wrap">${blockImages?'<div class="image-block"><button class="btn btn-ghost" data-show-images="'+m.id+'">Show images</button></div>':''}<iframe class="html-body" sandbox="allow-same-origin" srcdoc="${this.escapeHtml(doc)}"></iframe></div>`},sanitizeHtml:function(html){return String(html).replace(/<script[\s\S]*?<\/script>/gi,'').replace(/<iframe[\s\S]*?<\/iframe>/gi,'').replace(/<object[\s\S]*?<\/object>/gi,'').replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi,'').replace(/(href|src)\s*=\s*("|')?\s*javascript:[^"'>]*("|')?/gi,'$1=""')},bindThreadEvents:function(){const view=this.el('.thread-view');if(!view)return;view.querySelectorAll('[data-back]').forEach(b=>b.addEventListener('click',()=>{const prev=this._prevListHash||'#inbox';this._prevListHash=null;this.navigate(prev)}));view.querySelectorAll('[data-reply]').forEach(b=>b.addEventListener('click',()=>this.replyTo(b.dataset.reply,false)));view.querySelectorAll('[data-reply-all]').forEach(b=>b.addEventListener('click',()=>this.replyTo(b.dataset.replyAll,true)));view.querySelectorAll('[data-forward]').forEach(b=>b.addEventListener('click',()=>this.forwardMessage(b.dataset.forward)));view.querySelectorAll('[data-archive]').forEach(b=>b.addEventListener('click',()=>this.batchAction('archive')));view.querySelectorAll('[data-delete]').forEach(b=>b.addEventListener('click',()=>this.showConfirm('Delete?','Move to Trash?').then(ok=>{if(ok)this.batchAction('delete')})));view.querySelectorAll('[data-star-thread]').forEach(b=>b.addEventListener('click',()=>this.toggleStarSelected()));view.querySelectorAll('[data-show-images]').forEach(b=>b.addEventListener('click',()=>{if(!this._showImages)this._showImages=new Set();this._showImages.add(Number(b.dataset.showImages));this.renderThread()}));view.querySelectorAll('.quote-toggle').forEach(b=>b.addEventListener('click',()=>{const qb=b.parentElement.querySelector('.quote-body');if(qb)qb.classList.toggle('hidden');b.textContent=qb.classList.contains('hidden')?'\u25B6 Show quoted text':'\u25BC Hide quoted text'}));view.querySelectorAll('.msg-collapse-btn').forEach(b=>b.addEventListener('click',()=>{b.closest('.message-card').classList.toggle('collapsed')}));view.querySelectorAll('[data-expand]').forEach(b=>b.addEventListener('click',()=>{b.closest('.message-card').classList.remove('collapsed')}))},openCompose:async function(opts={}){if(opts.mode&&(opts.mode==='reply'||opts.mode==='forward')&&opts.messageId&&!opts.message){try{opts.message=await this.api('get_message',{message_id:+opts.messageId})}catch(err){this.showToast(err.message,'error');return}}const cs={to:[],cc:[],bcc:[],subject:'',body:'',attachments:[],inReplyTo:null,accountId:this.currentAccountId,draftId:null,showCc:false,showBcc:false,dirty:false,mode:opts.mode||'new',original:opts.message||null};if(opts.mode==='reply'){const m=opts.message;const me=this.getAccount();cs.to=[{name:m.from_name,email:m.from_address}].filter(r=>r.email!==(me&&me.email));if(!cs.to.length)cs.to=[];if(opts.all)cs.cc=(m.to_addresses||[]).filter(a=>a.email!==(me&&me.email));cs.subject=m.subject&&!/^re:/i.test(m.subject)?'Re: '+m.subject:(m.subject||'');cs.inReplyTo=m.message_id;cs.body=this.buildQuote(m,'replied')}else if(opts.mode==='forward'){const m=opts.message;cs.subject=m.subject&&!/^(fwd|fw):/i.test(m.subject)?'Fwd: '+m.subject:(m.subject||'');cs.body=this.buildQuote(m,'forwarded')}this.composeState=cs;this.renderCompose();this.el('#compose-overlay').classList.add('open');this.el('.compose-window')?.classList.remove('minimized');this.autosaveDraft();const to=this.el('[data-recipient-input="to"]');if(to)to.focus()},buildQuote:function(m,verb){const from=m.from_name||m.from_address||'unknown';const date=this.formatDate(m.date);let body=m.html_body||m.text_body||'';body=this.sanitizeHtml(body).slice(0,20000);if(!m.html_body)body=String(body).replace(/\n/g,'<br>');return`<br><br><div class="gmail_quote"><div>On ${this.escapeHtml(date)}, ${this.escapeHtml(from)} wrote:</div><blockquote class="quoted">${body}</blockquote></div>`},renderCompose:function(){const overlay=this.el('#compose-overlay');const cs=this.composeState;if(!cs)return;const title=cs.mode==='reply'?'Reply':cs.mode==='forward'?'Forward':'New Message';overlay.innerHTML=`<div class="compose-window"><div class="compose-header"><span class="compose-title">${title}</span><div class="compose-header-actions"><button class="topbar-icon" data-minimize title="Minimize" style="width:32px;height:32px;font-size:14px"><i class="ti ti-minus"></i></button><button class="topbar-icon" data-close title="Close" style="width:32px;height:32px;font-size:14px"><i class="ti ti-x"></i></button></div></div><div class="compose-body"><div class="compose-row"><span class="compose-label">From</span><select class="compose-from" data-from>${this.accounts.map(a=>`<option value="${a.id}" ${a.id===cs.accountId?'selected':''}>${this.escapeHtml(a.email)}</option>`).join('')}</select></div>${this.recipientFieldHtml('to')}${cs.showCc?this.recipientFieldHtml('cc'):'<button class="compose-toggle" data-toggle-cc>Add Cc</button>'}${cs.showBcc?this.recipientFieldHtml('bcc'):'<button class="compose-toggle" data-toggle-bcc>Add Bcc</button>'}<div class="compose-row"><input class="compose-subject" data-subject placeholder="Subject" value="${this.escapeHtml(cs.subject)}" autocomplete="off"></div><div class="compose-toolbar"><button data-cmd="bold" title="Bold"><b>B</b></button><button data-cmd="italic" title="Italic"><i>I</i></button><button data-cmd="createLink" title="Link"><i class="ti ti-link"></i></button></div><div class="compose-editor" contenteditable="true" data-editor data-placeholder="Write your message\u2026"></div><div class="compose-footer"><button class="btn btn-primary" data-send>Send</button><span class="send-hint">Ctrl/\u2318+Enter to send</span><span class="compose-draftsaved"></span><button class="btn btn-ghost" data-discard>Discard</button></div></div></div>`;this.bindComposeEvents();const editor=this.el('[data-editor]');if(editor&&cs.body)editor.innerHTML=cs.body;if(editor&&!cs.body&&this.settings.signature)editor.innerHTML='<br><br><div>'+this.escapeHtml(this.settings.signature).replace(/\n/g,'<br>')+'</div>';this.refreshRecipientChips()},recipientFieldHtml:function(field){const label={to:'To',cc:'Cc',bcc:'Bcc'}[field];const remove=field==='cc'?'<button class="recipient-remove" data-remove-cc title="Remove Cc"><i class="ti ti-x"></i></button>':field==='bcc'?'<button class="recipient-remove" data-remove-bcc title="Remove Bcc"><i class="ti ti-x"></i></button>':'';return`<div class="compose-row" data-recipient-field="${field}"><span class="compose-label">${label}</span><div class="chips-wrap"><span class="chips" data-chips="${field}"></span><input class="recipient-input" data-recipient-input="${field}" placeholder="Email address" autocomplete="off" spellcheck="false"><div class="recipient-suggest hidden" data-suggest="${field}"></div></div>${remove}</div>`},refreshRecipientChips:function(){for(const field of['to','cc','bcc']){const chips=this.el(`[data-chips="${field}"]`);if(!chips)continue;chips.innerHTML=this.composeState[field].map(r=>`<span class="chip">${this.escapeHtml(r.name||r.email)}${r.email&&r.email!==r.name?`<span class="chip-email">${this.escapeHtml(r.email)}</span>`:''}<button class="chip-remove" data-chip-remove="${field}" data-email="${this.escapeHtml(r.email)}"><i class="ti ti-x"></i></button></span>`).join('')}},bindComposeEvents:function(){this.el('[data-close]')?.addEventListener('click',()=>this.closeCompose(false));this.el('[data-minimize]')?.addEventListener('click',()=>this.el('.compose-window')?.classList.add('minimized'));this.el('.compose-title')?.addEventListener('click',()=>this.el('.compose-window')?.classList.remove('minimized'));this.el('[data-discard]')?.addEventListener('click',()=>this.discardDraft());this.el('[data-toggle-cc]')?.addEventListener('click',()=>{this.composeState.showCc=true;this.renderCompose()});this.el('[data-toggle-bcc]')?.addEventListener('click',()=>{this.composeState.showBcc=true;this.renderCompose()});this.el('[data-remove-cc]')?.addEventListener('click',()=>{this.composeState.showCc=false;this.composeState.cc=[];this.renderCompose()});this.el('[data-remove-bcc]')?.addEventListener('click',()=>{this.composeState.showBcc=false;this.composeState.bcc=[];this.renderCompose()});this.el('[data-from]')?.addEventListener('change',(e)=>{this.composeState.accountId=+e.target.value});this.el('[data-subject]')?.addEventListener('input',(e)=>{this.composeState.subject=e.target.value;this.markDirty()});this.el('[data-editor]')?.addEventListener('input',()=>this.markDirty());this.el('[data-send]')?.addEventListener('click',()=>this.sendMessage());for(const field of['to','cc','bcc']){const input=this.el(`[data-recipient-input="${field}"]`);if(!input)continue;input.addEventListener('keydown',(e)=>this.handleRecipientKeydown(e,field));input.addEventListener('input',(e)=>this.handleRecipientInput(e,field));input.addEventListener('blur',()=>setTimeout(()=>this.hideSuggest(field),150))}},markDirty:function(){if(this.composeState)this.composeState.dirty=true},handleRecipientKeydown:function(e,field){const input=e.target;if(e.key==='Enter'||e.key===','||e.key===';'){e.preventDefault();const val=input.value.trim().replace(/[,;]$/,'');if(val){this.addRecipientChip(field,val);input.value='';this.hideSuggest(field)}}else if(e.key==='Backspace'&&!input.value&&this.composeState[field].length){this.composeState[field].pop();this.refreshRecipientChips();this.markDirty()}},handleRecipientInput:async function(e,field){const q=e.target.value.trim();const suggest=this.el(`[data-suggest="${field}"]`);if(!suggest)return;if(!q){this.hideSuggest(field);return}if(this.isValidEmail(q)){suggest.innerHTML=`<button type="button" class="suggest-item" data-pick="${this.escapeHtml(q)}">${this.escapeHtml(q)}</button>`;suggest.classList.remove('hidden')}else{const contacts=await this.api('search_contacts',{q}).catch(()=>[]);const list=contacts.contacts||contacts||[];suggest.innerHTML=list.slice(0,6).map(c=>`<button type="button" class="suggest-item" data-pick="${this.escapeHtml(c.email)}" data-pick-name="${this.escapeHtml(c.name||'')}"><b>${this.escapeHtml(c.name||c.email)}</b> <span>&lt;${this.escapeHtml(c.email)}&gt;</span></button>`).join('')||'';suggest.classList.toggle('hidden',!suggest.innerHTML)}suggest.querySelectorAll('[data-pick]').forEach(b=>b.addEventListener('click',()=>{const email=b.dataset.pick;const name=b.dataset.pickName||email;this.addRecipientChip(field,email,name);const input=this.el(`[data-recipient-input="${field}"]`);if(input)input.value='';this.hideSuggest(field)}))},hideSuggest:function(field){const s=this.el(`[data-suggest="${field}"]`);if(s)s.classList.add('hidden')},addRecipientChip:function(field,email,name){email=String(email||'').trim().toLowerCase();if(!this.isValidEmail(email)){this.showToast('Invalid email address','error');return}if(this.composeState[field].some(r=>r.email.toLowerCase()===email))return;this.composeState[field].push({name:String(name||'').trim()||email,email});this.refreshRecipientChips();this.markDirty()},isValidEmail:function(s){return/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s||'').trim())},closeCompose:function(force){const overlay=this.el('#compose-overlay');if(!overlay||!overlay.classList.contains('open'))return;const doClose=()=>{overlay.classList.remove('open');if(this._draftTimer){clearInterval(this._draftTimer);this._draftTimer=null}this.composeState=null;overlay.innerHTML=''};if(force||!this.composeState||!this.composeState.dirty){doClose();return}this.showConfirm('Save draft?','Keep your unsaved changes as a draft?').then(keep=>{if(keep)this.saveDraft(false).finally(()=>doClose());else{this.discardDraft();doClose()}})},discardDraft:function(){if(!this.composeState)return;const draftId=this.composeState.draftId;if(draftId)this.api('delete_draft',{draft_id:draftId}).catch(()=>{});this.composeState=null;this.el('#compose-overlay')?.classList.remove('open');if(this.el('#compose-overlay'))this.el('#compose-overlay').innerHTML='';this.showToast('Draft discarded')},autosaveDraft:function(){if(this._draftTimer)clearInterval(this._draftTimer);this._draftTimer=setInterval(()=>this.saveDraft(true),30000)},saveDraft:async function(silent){if(!this.composeState)return;const cs=this.composeState;const editor=this.el('[data-editor]');if(editor)cs.body=editor.innerHTML;const empty=!cs.to.length&&!cs.cc.length&&!cs.bcc.length&&!cs.subject&&!(cs.body||'').replace(/<[^>]*>/g,'').trim();if(empty)return;const payload={to:cs.to.map(r=>r.email),cc:cs.cc.map(r=>r.email),bcc:cs.bcc.map(r=>r.email),subject:cs.subject,body:cs.body,draft:true};try{const r=await this.api('compose',payload);cs.draftId=r.draft_id||cs.draftId;const el=this.el('.compose-draftsaved');if(el)el.textContent='\u2713 Draft saved '+this.formatDate(new Date().toISOString());if(!silent)this.showToast('Draft saved')}catch(err){if(!silent)this.showToast('Could not save draft: '+err.message,'error')}},sendMessage:async function(){const cs=this.composeState;if(!cs)return;const editor=this.el('[data-editor]');if(editor)cs.body=editor.innerHTML;if(!cs.to.length&&!cs.cc.length&&!cs.bcc.length){this.showToast('Add at least one recipient','error');return}const payload={to:cs.to.map(r=>r.email),cc:cs.cc.map(r=>r.email),bcc:cs.bcc.map(r=>r.email),subject:cs.subject||'(no subject)',body:cs.body};try{await this.api('compose',payload);this.closeCompose(true);if(cs.draftId)this.api('delete_draft',{draft_id:cs.draftId}).catch(()=>{});this.showToast('Message sent','success');this.loadThreads(this.currentFolder)}catch(err){this.showToast('Send failed: '+err.message,'error')}},replyTo:async function(id,all){if(!id){this.showToast('No message selected','error');return}await this.openCompose({mode:'reply',messageId:id,all})},forwardMessage:async function(id){if(!id){this.showToast('No message selected','error');return}await this.openCompose({mode:'forward',messageId:id})},batchAction:async function(action){const ids=[...this.selectedThreadIds];if(!ids.length&&this.currentThread)ids.push(this.currentThread.id);if(!ids.length){this.showToast('No messages selected','error');return}try{await this.api('batch_action',{action,message_ids:ids});this.showToast(action+' done');this.selectedThreadIds.clear();if(this.currentThread){this.currentThread=null;this.threadMessages=[]}if(this.currentView.startsWith('#thread')){const prev=this._prevListHash||'#inbox';this._prevListHash=null;this.navigate(prev)}else{this.loadThreads(this.currentFolder)}}catch(err){this.showToast(err.message,'error')}},toggleStarSelected:async function(){let ids;if(this.selectedThreadIds?.size)ids=[...this.selectedThreadIds];else if(this.currentThread)ids=[this.currentThread.id];else return;const target=this.threads.find(t=>ids.includes(t.id));const starred=!(target&&target.is_starred);try{await this.api('batch_action',{action:starred?'star':'unstar',message_ids:ids});ids.forEach(id=>{const t=this.threads.find(x=>x.id===id);if(t)t.is_starred=starred?1:0});if(this.currentThread&&ids.includes(this.currentThread.id))this.currentThread.is_starred=starred?1:0;this.renderMessageList();if(this.currentView.startsWith('#thread'))this.renderThread()}catch(err){this.showToast(err.message,'error')}},renderSearch:function(query){const list=this.el('#pane-list');list.innerHTML=`<div class="list-header"><div class="list-header-top"><h1>Search</h1></div><div style="display:flex;gap:8px;margin-top:10px"><input class="input" data-search-input placeholder="from:, to:, subject:" value="${this.escapeHtml(query)}"><button class="btn btn-primary" data-search-go>Search</button></div></div><div data-search-results></div>`;const input=this.el('[data-search-input]');const go=()=>{const q=input.value.trim();if(!q)return;this.threads=[];this.runSearch(q)};this.el('[data-search-go]').addEventListener('click',go);input.addEventListener('keydown',(e)=>{if(e.key==='Enter')go()});if(query)this.runSearch(query);else this.el('[data-search-results]').innerHTML=this.emptyStateHtml({icon:'<i class="ti ti-search"></i>',title:'Search mail',text:'Find messages by sender, subject, or content.'})},runSearch:async function(query){const box=this.el('[data-search-results]');if(!box)return;box.innerHTML=`<div class="message-list">${this.skeletonRows(5)}</div>`;try{const d=await this.api('search',{query});this.threads=d.messages||d.results||[];box.innerHTML=this.threads.length?`<div class="message-list">${this.threads.map(t=>this.messageRowHtml(t,0)).join('')}</div>`:this.emptyStateHtml({icon:'<i class="ti ti-search"></i>',title:'No results',text:'Nothing found for "'+this.escapeHtml(query)+'".'})}catch(err){box.innerHTML=this.emptyStateHtml({icon:'<i class="ti ti-alert-triangle"></i>',title:'Search failed',text:err.message})}},renderSettings:function(){const fs=this.el('#fullscreen');fs.hidden=false;fs.innerHTML=`<div class="overlay-view"><div class="overlay-card"><div class="settings-header"><button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button><h1>Settings</h1></div><div class="settings-body"><div class="settings-section"><h2>Accounts</h2>${this.accounts.map(a=>`<div class="account-card">${this.avatarHtml(a.display_name,a.email)}<div class="acct-info"><div class="acct-email">${this.escapeHtml(a.email)}</div><div class="acct-sync">${a.sync_error?`<span class="sync-error"><i class="ti ti-alert-triangle"></i> ${this.escapeHtml(a.sync_error)}</span>`:`Last synced ${this.formatDate(a.last_sync_at)||'never'}`}</div></div><button class="btn-ghost" data-sync="${a.id}" title="Sync now"><i class="ti ti-refresh"></i></button><button class="btn-ghost danger" data-delete-acct="${a.id}">Remove</button></div>`).join('')}<button class="btn btn-ghost" data-add-account>+ Add account</button></div><div class="settings-section"><h2>Signature</h2><textarea class="textarea" data-signature placeholder="Your signature\u2026">${this.escapeHtml(this.settings.signature||'')}</textarea><button class="btn btn-primary" data-save-signature>Save signature</button></div><div class="settings-section"><h2>Preferences</h2><div class="setting-row"><span>Theme</span><button class="btn-ghost" data-theme-toggle>${this.settings.theme==='light'?'Switch to dark':'Switch to light'}</button></div><div class="setting-row"><span>Show remote images</span><label class="checkbox"><input type="checkbox" data-remote-images ${this.settings.show_remote_images?'checked':''}><span></span></label></div><div class="setting-row"><span>Sync on load</span><label class="checkbox"><input type="checkbox" data-sync-on-load ${this.settings.sync_on_load?'checked':''}><span></span></label></div></div><button class="btn btn-ghost danger" data-logout>Log out</button></div></div></div>`;this.bindSettingsEvents()},bindSettingsEvents:function(){this.el('[data-back]')?.addEventListener('click',()=>{const prev=this._prevListHash||'#inbox';this._prevListHash=null;this.navigate(prev)});this.el('[data-sync]')?.addEventListener('click',async(e)=>{const btn=e.target.closest('[data-sync]');if(this._syncing){this.showToast('Sync already in progress','info');return}btn.innerHTML='<i class="ti ti-loader"></i>';this._syncing=true;try{await this.api('sync',{account_id:+btn.dataset.sync});this.showToast('Synced');await this.loadBaseData();this.renderSettings()}catch(err){this.showToast(err.message,'error');btn.innerHTML='<i class="ti ti-refresh"></i>'}finally{this._syncing=false}});this.el('[data-delete-acct]')?.addEventListener('click',async(e)=>{const id=+e.target.dataset.deleteAcct;const acct=this.getAccount(id);const ok=await this.showConfirm('Remove account','Remove '+this.escapeHtml(acct?acct.email:'this account')+'?');if(!ok)return;try{await this.api('delete_account');this.showToast('Account removed');await this.loadBaseData();this.renderSettings();this.renderSidebar()}catch(err){this.showToast(err.message,'error')}});this.el('[data-add-account]')?.addEventListener('click',()=>this.openAddAccount());this.el('[data-save-signature]')?.addEventListener('click',async()=>{const btn=this.el('[data-save-signature]');const sig=this.el('[data-signature]').value;this.setBtnState(btn,true,'Saving\u2026');try{await this.api('update_signature',{signature:sig});this.settings.signature=sig;this.setBtnState(btn,false);this.showToast('Signature saved')}catch(err){this.setBtnState(btn,false);this.showToast(err.message,'error')}});this.el('[data-theme-toggle]')?.addEventListener('click',()=>{this.toggleTheme();this.renderSettings()});this.el('[data-remote-images]')?.addEventListener('change',async(e)=>{this.settings.show_remote_images=e.target.checked;await this.api('update_settings',{show_remote_images:e.target.checked}).catch(()=>{})});this.el('[data-sync-on-load]')?.addEventListener('change',async(e)=>{await this.api('update_settings',{sync_on_load:e.target.checked}).catch(()=>{});this.settings.sync_on_load=e.target.checked;this.showToast('Auto-sync '+(e.target.checked?'on':'off'))});this.el('[data-logout]')?.addEventListener('click',async()=>{await this.api('logout').catch(()=>{});this.sessionToken=null;localStorage.removeItem('emmmail-session');this.accounts=[];this.navigate('#login');this.renderSidebar()})},openAddAccount:function(){this.showModal('Add account',`<form data-add-form><label class="field"><span>Email</span><input class="input" type="email" data-semail placeholder="you@example.com" required></label><label class="field"><span>Password</span><input class="input" type="password" data-spassword placeholder="App password" required></label></form>`,'<button class="btn btn-primary" data-add-now>Add account</button>');const ov=this.el('.modal-overlay');const form=ov.querySelector('[data-add-form]');ov.querySelector('[data-add-now]').addEventListener('click',async()=>{const btn=ov.querySelector('[data-add-now]');this.setBtnState(btn,true,'Adding\u2026');try{await this.api('auth',{email:form.querySelector('[data-semail]').value.trim(),password:form.querySelector('[data-spassword]').value});this.closeModal();this.showToast('Account added');await this.loadBaseData();this.renderSettings();this.renderSidebar()}catch(err){this.setBtnState(btn,false);this.showToast(err.message,'error')}})},renderContacts:function(){const fs=this.el('#fullscreen');fs.hidden=false;fs.innerHTML=`<div class="overlay-view"><div class="overlay-card"><div class="settings-header"><button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button><h1>Contacts</h1></div><div class="settings-body"><input class="input contact-search" placeholder="Search contacts" data-contact-search><div class="contact-list" data-contact-list></div></div></div></div>`;this.el('[data-back]')?.addEventListener('click',()=>{const prev=this._prevListHash||'#inbox';this._prevListHash=null;this.navigate(prev)});let timer=null;this.el('[data-contact-search]').addEventListener('input',(e)=>{clearTimeout(timer);timer=setTimeout(()=>this.loadContacts(e.target.value.trim()),200)});this.loadContacts('')},loadContacts:async function(q){const list=this.el('[data-contact-list]');if(!list)return;list.innerHTML=`<div class="message-list">${this.skeletonRows(5)}</div>`;try{const d=await this.api('list_contacts',{q});const contacts=d.contacts||d||[];if(!contacts.length){list.innerHTML=this.emptyStateHtml({icon:'<i class="ti ti-users"></i>',title:'No contacts',text:'Contacts appear as you send and receive mail.'});return}list.innerHTML=contacts.map(c=>`<div class="contact-row" data-contact="${this.escapeHtml(c.email)}" data-name="${this.escapeHtml(c.name||'')}">${this.avatarHtml(c.name,c.email)}<div><div class="c-name">${this.escapeHtml(c.name||'(no name)')}</div><div class="c-email">${this.escapeHtml(c.email)}</div></div><span class="c-count">${c.use_count||1}</span></div>`).join('');list.querySelectorAll('.contact-row').forEach(row=>row.addEventListener('click',()=>{this.navigate(this._prevListHash||'#inbox');this.openCompose();setTimeout(()=>this.addRecipientChip('to',row.dataset.contact,row.dataset.name),50)}))}catch(err){list.innerHTML=this.emptyStateHtml({icon:'<i class="ti ti-alert-triangle"></i>',title:'Could not load contacts',text:err.message})}},setBtnState:function(btn,on,label){if(on){btn.dataset.orig=btn.textContent;btn.disabled=true;btn.textContent=label}else{btn.disabled=false;if(btn.dataset.orig)btn.textContent=btn.dataset.orig}},showToast:function(message,type,action,opts){type=type||'info';opts=opts||{};const container=this.el('#toast-container');const toast=document.createElement('div');toast.className='toast '+type;const icons={success:'\u2713',error:'\u2717',info:'\u2139'};toast.innerHTML=`<span class="toast-icon">${icons[type]||''}</span><span class="toast-msg"></span>`;toast.querySelector('.toast-msg').textContent=message;if(action){const btn=document.createElement('button');btn.className='toast-action';btn.textContent=action.label;btn.addEventListener('click',()=>{if(action.onClick)action.onClick();this.dismissToast(toast)});toast.appendChild(btn)}container.appendChild(toast);const handle={el:toast,update:(msg,type2)=>{toast.querySelector('.toast-msg').textContent=msg;if(type2)toast.className='toast '+type2},dismiss:()=>this.dismissToast(toast),clear:null};if(opts.ttl!==0){const ttl=opts.ttl||4000;handle.clear=setTimeout(()=>this.dismissToast(toast),ttl)}return handle},dismissToast:function(toast){if(!toast||toast.classList.contains('out'))return;toast.classList.add('out');setTimeout(()=>toast.remove(),260)},showModal:function(title,bodyHtml,footerHtml,opts){opts=opts||{};const ov=this.el('#modal-overlay');ov.innerHTML=`<div class="modal" role="dialog" aria-modal="true"><div class="modal-header"><h3>${this.escapeHtml(title)}</h3><button class="modal-close" data-close-modal><i class="ti ti-x"></i></button></div><div class="modal-body">${bodyHtml}</div>${footerHtml?`<div class="modal-footer">${footerHtml}</div>`:''}</div>`;ov.classList.add('open');this.modalOpen=true;ov.querySelector('[data-close-modal]').addEventListener('click',()=>this.closeModal());ov.addEventListener('click',(e)=>{if(e.target===ov&&!opts.denyBackdrop)this.closeModal()});return ov},closeModal:function(){const ov=this.el('.modal-overlay');ov.classList.remove('open');this.modalOpen=false;setTimeout(()=>{ov.innerHTML=''},200)},showConfirm:function(title,message){return new Promise((resolve)=>{this.showModal(title,`<p style="margin:6px 0">${message}</p>`,'<button class="btn btn-ghost" data-confirm-no>Cancel</button><button class="btn btn-primary" data-confirm-yes>OK</button>');const ov=this.el('.modal-overlay');ov.querySelector('[data-confirm-yes]').addEventListener('click',()=>{this.closeModal();resolve(true)});ov.querySelector('[data-confirm-no]').addEventListener('click',()=>{this.closeModal();resolve(false)});ov.addEventListener('click',(e)=>{if(e.target===ov){this.closeModal();resolve(false)}})})},escapeHtml:function(s){return String(s??'').replace(/[&<>"']/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))},formatDate:function(iso){if(!iso)return'';const d=new Date(iso);if(isNaN(d))return'';const now=new Date();const diff=now-d;const min=Math.floor(diff/60000);if(min<1)return'now';if(min<60)return min+'m ago';const h=Math.floor(min/60);if(h<24&&d.getDate()===now.getDate())return h+'h ago';const yest=new Date(now);yest.setDate(now.getDate()-1);if(d.toDateString()===yest.toDateString())return'yesterday';if(diff<7*86400000)return d.toLocaleDateString(undefined,{weekday:'short'});return d.toLocaleDateString(undefined,d.getFullYear()===now.getFullYear()?{month:'short',day:'numeric'}:{month:'short',day:'numeric',year:'numeric'})},formatSize:function(bytes){const n=Number(bytes)||0;if(n<1024)return n+' B';const units=['KB','MB','GB','TB'];let v=n;let i=-1;do{v/=1024;i++}while(v>=1024&&i<units.length-1);return v.toFixed(v>=100?0:1)+' '+units[i]},getInitials:function(name){const parts=String(name||'').trim().split(/\s+/).filter(Boolean);if(!parts.length)return'?';if(parts.length===1)return parts[0].slice(0,2).toUpperCase();return(parts[0][0]+parts[parts.length-1][0]).toUpperCase()},getAvatarColor:function(name){const s=String(name||'');let hash=0;for(let i=0;i<s.length;i++)hash=(hash*31+s.charCodeAt(i))>>>0;const palette=['#4f8cff','#8b5cf6','#ec4899','#f59e0b','#10b981','#06b6d4','#ef4444','#14b8a6','#6366f1','#f97316'];return palette[hash%palette.length]},avatarHtml:function(name,email){const display=name||email||'?';return`<span class="avatar" style="background:${this.getAvatarColor(display)}">${this.escapeHtml(this.getInitials(display))}</span>`},getAccount:function(id){return this.accounts.find(a=>a.id==(id??this.currentAccountId))},unreadCounts:function(){const acct=this.getAccount();if(!acct)return{};return{inbox:acct.unread_count||0}},toggleTheme:function(){const next=document.documentElement.getAttribute('data-theme')==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',next);try{localStorage.setItem('emmmail-theme',next)}catch(e){}this.settings.theme=next;const meta=document.querySelector('meta[name="theme-color"]');if(meta)meta.setAttribute('content',next==='light'?'#ffffff':'#0b0d12');this.updateAvatar()},applyTheme:function(){const t=(()=>{try{return localStorage.getItem('emmmail-theme')||'dark'}catch(e){return'dark'}})();document.documentElement.setAttribute('data-theme',t);if(this.settings)this.settings.theme=t},updateAvatar:function(){const acct=this.getAccount();const btn=this.el('#btn-avatar');if(!btn)return;if(acct){const display=acct.display_name||acct.email||'?';btn.textContent=this.getInitials(display);btn.style.background=this.getAvatarColor(display)}else{btn.textContent='?';btn.style.background='var(--text-secondary)'}},updateBottomTabs:function(){const map={inbox:'inbox',sent:'inbox',drafts:'inbox',starred:'inbox',all:'inbox',search:'search',settings:'settings',contacts:'settings'};const view=this.currentView.split('?')[0].split('/')[0]||'inbox';const active=map[view]||'inbox';this.els('.tab-item').forEach(t=>t.classList.toggle('active',t.dataset.tab===active))},toggleSidebar:function(){this.isSidebarOpen=!this.isSidebarOpen;document.body.classList.toggle('sidebar-open',this.isSidebarOpen)},closeSidebar:function(){this.isSidebarOpen=false;document.body.classList.remove('sidebar-open')},toggleMultiSelect:function(force){this.isMultiSelectMode=(force===undefined)?!this.isMultiSelectMode:force;if(!this.isMultiSelectMode)this.selectedThreadIds?.clear();this.renderMessageList()},focusSearch:function(){if(this.composeOpen())return;const bar=this.el('#search-bar');bar.classList.add('active');document.body.classList.add('search-open');const input=bar.querySelector('input');input.focus();input.select()},closeSearch:function(){this.el('#search-bar')?.classList.remove('active');document.body.classList.remove('search-open')},composeOpen:function(){const ov=this.el('#compose-overlay');return ov&&ov.classList.contains('open')&&!!this.composeState},inInput:function(e){if(this.modalOpen)return true;const t=e.target;if(!t)return false;const tag=t.tagName;if(tag==='INPUT'||tag==='TEXTAREA'||tag==='SELECT')return true;if(t.isContentEditable)return true;return false},handleKeydown:function(e){const k=e.key;const mod=e.metaKey||e.ctrlKey;if(k==='Escape'){if(this.composeOpen()){this.closeCompose(false);e.preventDefault();return}if(this.modalOpen){this.closeModal();return}const fs=this.el('#fullscreen');if(fs&&!fs.hidden){this.navigate(this._prevListHash||'#inbox');return}if(this.isSidebarOpen){this.closeSidebar();return}if(document.body.classList.contains('search-open')){this.closeSearch();return}return}if(mod&&k==='Enter'){if(this.composeOpen()){e.preventDefault();this.sendMessage()}return}if(mod&&k.toLowerCase()==='k'){e.preventDefault();this.focusSearch();return}if(k==='/'&&!this.inInput(e)){e.preventDefault();this.focusSearch();return}if(this.inInput(e))return;if(this.composeOpen())return;if(k==='g'){this._gPending=true;setTimeout(()=>{this._gPending=false},1500);return}if(this._gPending){this._gPending=false;const m={i:'#inbox',a:'#all',s:'#sent',d:'#drafts'};if(m[k.toLowerCase()]){this.navigate(m[k.toLowerCase()]);return}}switch(k){case'c':this.openCompose();break;case'r':this.replyTo(this.currentThread&&this.currentThread.id,false);break;case'a':this.replyTo(this.currentThread&&this.currentThread.id,true);break;case'f':this.forwardMessage(this.currentThread&&this.currentThread.id);break;case'e':this.batchAction('archive');break;case'#':e.preventDefault();this.showConfirm('Delete?','Move to Trash?').then(ok=>{if(ok)this.batchAction('delete')});break;case's':this.toggleStarSelected();break;case'j':this.moveSelection(1);break;case'k':this.moveSelection(-1);break;case'o':case'Enter':this.openSelected();break}},moveSelection:function(delta){if(!this.threads.length)return;if(this._focusedIdx==null)this._focusedIdx=0;this._focusedIdx=Math.min(Math.max(0,this._focusedIdx+delta),this.threads.length-1);this.els('[data-thread]').forEach((r,i)=>r.classList.toggle('focused',i===this._focusedIdx));const row=this.els('[data-thread]')[this._focusedIdx];if(row)row.scrollIntoView({block:'nearest'})},openSelected:function(){const t=this.threads[this._focusedIdx==null?0:this._focusedIdx];if(t)this.openThread(t.id)},bindGlobal:function(){document.addEventListener('keydown',(e)=>this.handleKeydown(e));window.addEventListener('hashchange',()=>this.navigate(location.hash));document.addEventListener('click',(e)=>{const star=e.target.closest('[data-star]');if(star){e.preventDefault();e.stopPropagation();const row=star.closest('[data-thread]');if(row)this.toggleStar(+row.dataset.id);return}const multi=e.target.closest('[data-multi]');if(multi){this.toggleMultiSelect();return}const refresh=e.target.closest('[data-refresh]');if(refresh){const btn=refresh;btn.innerHTML='<i class="ti ti-loader"></i>';this.api('sync',{account_id:this.currentAccountId}).then(()=>{this.loadThreads(this.currentFolder);this.showToast('Synced')}).catch(err=>{btn.innerHTML='<i class="ti ti-refresh"></i>';this.showToast(err.message,'error')});return}const syncNow=e.target.closest('[data-sync-now]');if(syncNow){e.preventDefault();syncNow.innerHTML='<i class="ti ti-loader"></i> Syncing\u2026';this.api('sync',{account_id:this.currentAccountId}).then(()=>this.loadThreads(this.currentFolder)).catch(err=>this.showToast(err.message,'error'));return}const row=e.target.closest('[data-thread]');if(row&&e.target.closest('[data-open]')){this.openThread(row.dataset.id);return}const tab=e.target.closest('[data-tab]');if(tab){if(tab.dataset.tab==='compose'){e.preventDefault();this.openCompose()}else if(tab.dataset.tab==='search'){e.preventDefault();this.focusSearch()}}});this.el('#btn-hamburger')?.addEventListener('click',()=>this.toggleSidebar());this.el('#sidebar-backdrop')?.addEventListener('click',()=>this.closeSidebar());this.el('#btn-avatar')?.addEventListener('click',(e)=>{e.stopPropagation();this.toggleAvatarMenu()});this.el('#btn-notifications')?.addEventListener('click',(e)=>{e.stopPropagation();this.toggleNotifMenu()});document.addEventListener('click',()=>this.closeMenus());const bar=this.el('#search-bar');if(bar){bar.querySelector('input').addEventListener('focus',()=>{bar.classList.add('active');document.body.classList.add('search-open')});bar.querySelector('input').addEventListener('input',(e)=>{this.el('#search-close').hidden=!e.target.value});bar.querySelector('input').addEventListener('keydown',(e)=>{if(e.key==='Enter'){e.preventDefault();const q=e.target.value.trim();if(q)this.runSearch(q)}});this.el('#search-submit').addEventListener('click',()=>{const q=bar.querySelector('input').value.trim();if(q)this.runSearch(q)});this.el('#search-close').addEventListener('click',()=>{bar.querySelector('input').value='';this.el('#search-close').hidden=true;this.closeSearch()});this.el('#btn-search').addEventListener('click',()=>this.focusSearch());}},toggleAvatarMenu:function(){const menu=this.el('#avatar-menu');const isOpen=menu.classList.contains('open');this.closeMenus();if(isOpen)return;const acct=this.getAccount();menu.innerHTML=`${this.accounts.length?`<div class="menu-title" style="padding:8px 12px 4px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Accounts</div>${this.accounts.map(a=>`<button class="menu-item" data-switch="${a.id}" style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer">${this.avatarHtml(a.display_name,a.email)}<span class="mi-main">${this.escapeHtml(a.email)}<span class="mi-sub" style="display:block;font-size:12px;color:var(--text-secondary)">${this.escapeHtml(a.display_name||'')}</span></span>${a.id===this.currentAccountId?'<span class="mi-check" style="color:var(--primary)"><i class="ti ti-check"></i></span>':''}</button>`).join('')}<div style="height:1px;background:var(--border);margin:6px 8px"></div>`:''}<button class="menu-item" data-theme-toggle style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span style="width:22px;text-align:center"><i class="ti ti-${this.settings.theme==='light'?'moon':'sun'}"></i></span>${this.settings.theme==='light'?'Dark mode':'Light mode'}</button><div style="height:1px;background:var(--border);margin:6px 8px"></div><button class="menu-item" data-settings style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span style="width:22px;text-align:center"><i class="ti ti-settings"></i></span>Settings</button><button class="menu-item" data-contacts style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span style="width:22px;text-align:center"><i class="ti ti-users"></i></span>Contacts</button><div style="height:1px;background:var(--border);margin:6px 8px"></div><button class="menu-item" data-logout style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span style="width:22px;text-align:center"><i class="ti ti-logout"></i></span>Log out</button>`;menu.classList.add('open');menu.querySelectorAll('[data-switch]').forEach(b=>b.addEventListener('click',()=>{this.switchAccount(+b.dataset.switch);this.closeMenus()}));menu.querySelector('[data-theme-toggle]')?.addEventListener('click',()=>{this.toggleTheme();this.closeMenus()});menu.querySelector('[data-settings]')?.addEventListener('click',()=>{this.closeMenus();this.navigate('#settings')});menu.querySelector('[data-contacts]')?.addEventListener('click',()=>{this.closeMenus();this.navigate('#contacts')});menu.querySelector('[data-logout]')?.addEventListener('click',async()=>{await this.api('logout').catch(()=>{});this.sessionToken=null;localStorage.removeItem('emmmail-session');this.accounts=[];this.navigate('#login');this.closeMenus()})},toggleNotifMenu:function(){const menu=this.el('#notif-menu');const isOpen=menu.classList.contains('open');this.closeMenus();if(isOpen)return;menu.classList.add('open');this.renderNotifMenu()},renderNotifMenu:function(){const menu=this.el('#notif-menu');if(!menu.classList.contains('open'))return;const items=this.accounts.map(a=>{const state=a.sync_error?'<span class="mi-sub" style="color:var(--red)">Sync error: '+this.escapeHtml(a.sync_error)+'</span>':'<span class="mi-sub">Last synced '+(this.formatDate(a.last_sync_at)||'never')+'</span>';return`<button class="menu-item" data-notif-settings style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span class="mi-main">${this.escapeHtml(a.email)}${state}</span></button>`}).join('');menu.innerHTML=`<div class="menu-title" style="padding:8px 12px 4px;font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:var(--text-secondary)">Sync status</div>${items||'<div class="menu-item">No accounts</div>'}<div style="height:1px;background:var(--border);margin:6px 8px"></div><button class="menu-item" data-notif-settings style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;border:none;background:none;color:var(--text);font-size:14px;text-align:left;border-radius:8px;cursor:pointer"><span class="mi-main">Manage in Settings</span></button>`;menu.querySelectorAll('[data-notif-settings]').forEach(b=>b.addEventListener('click',()=>{this.closeMenus();this.navigate('#settings')}))},closeMenus:function(){this.el('#avatar-menu')?.classList.remove('open');this.el('#notif-menu')?.classList.remove('open')},updateIsMobile:function(){this.isMobile=window.matchMedia('(max-width: 640px)').matches}};
window.App=App;
document.addEventListener('DOMContentLoaded',()=>{App.init()});
</script>
</body>
</html>
HTML;
}

/* ---------------------------------------------------------------------------
 * Front Controller
 * ------------------------------------------------------------------------ */

function handleRequest(): void
{
    cleanupExpiredSessions();

    $action = $_GET['action'] ?? '';

    if ($action === '') {
        echo serveFrontend();
        return;
    }

    $publicActions = ['login', 'session', 'auth', 'detect_server', 'test_connection', 'ping', 'setup'];
    if (!in_array($action, $publicActions, true)) {
        requireSession();
    }

    switch ($action) {
        case 'auth':
            actionAuth();
            break;
        case 'login':
            actionLogin();
            break;
        case 'logout':
            actionLogout();
            break;
        case 'session':
            actionSession();
            break;
        case 'sync':
            actionSync();
            break;
        case 'compose':
            actionCompose();
            break;
        case 'drafts':
            actionListDrafts();
            break;
        case 'draft':
            actionGetDraft();
            break;
        case 'update_draft':
            actionUpdateDraft();
            break;
        case 'delete_draft':
            actionDeleteDraft();
            break;
        case 'download_attachment':
            actionDownloadAttachment();
            break;
        case 'message_attachments':
            actionMessageAttachments();
            break;
        case 'list_folders':
            actionListFolders();
            break;
        case 'list_messages':
            actionListMessages();
            break;
        case 'get_message':
            actionGetMessage();
            break;
        case 'get_thread':
            actionGetThread();
            break;
        case 'set_flags':
            actionSetFlags();
            break;
        case 'batch_action':
            actionBatchAction();
            break;
        case 'search':
            actionSearch();
            break;
        case 'list_accounts':
            actionListAccounts();
            break;
        case 'add_account':
            actionAddAccount();
            break;
        case 'delete_account':
            actionDeleteAccount();
            break;
        case 'detect_server':
            actionDetectServer();
            break;
        case 'test_connection':
            actionTestConnection();
            break;
        case 'list_contacts':
            actionListContacts();
            break;
        case 'search_contacts':
            actionSearchContacts();
            break;
        case 'list_tags':
            actionListTags();
            break;
        case 'create_tag':
            actionCreateTag();
            break;
        case 'get_settings':
            actionGetSettings();
            break;
        case 'update_settings':
            actionUpdateSettings();
            break;
        case 'update_signature':
            actionUpdateSignature();
            break;
        case 'ping':
            actionPing();
            break;
        case 'setup':
            actionSetup();
            break;
        case 'rename_tag':
            actionRenameTag();
            break;
        case 'delete_tag':
            actionDeleteTag();
            break;
        case 'tag_message':
            actionTagMessage();
            break;
        case 'untag_message':
            actionUntagMessage();
            break;
        case 'batch_tag':
            actionBatchTag();
            break;
        case 'expunge_folder':
            actionExpungeFolder();
            break;
        case 'upload_attachment':
            actionUploadAttachment();
            break;
        case 'check_update':
            actionCheckUpdate();
            break;
        case 'apply_update':
            actionApplyUpdate();
            break;
        default:
            fail('Unknown action: ' . ($action ?: '(none)'));
    }
}

// Run front controller for web requests or when action is explicitly set (CLI helpers).
if (php_sapi_name() !== 'cli' || isset($_GET['action'])) {
    handleRequest();
}
