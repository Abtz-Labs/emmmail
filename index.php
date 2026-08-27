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

final class ImapClient
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
        $imap->connect();
        $imap->login();
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

            $lastUid = $existingFolder['last_uid'] ?? 0;
            $threadIds = [];
            foreach ($uidsToFetch as $uid) {
                try {
                    $fetched = $imap->fetchMessageBody($uid);
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

/* ---------------------------------------------------------------------------
 * Front Controller
 * ------------------------------------------------------------------------ */

function handleRequest(): void
{
    cleanupExpiredSessions();

    $action = $_GET['action'] ?? '';

    $publicActions = ['login', 'session', 'auth', 'detect_server', 'test_connection'];
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
        default:
            fail('Unknown action: ' . ($action ?: '(none)'));
    }
}

// Run front controller for web requests or when action is explicitly set (CLI helpers).
if (php_sapi_name() !== 'cli' || isset($_GET['action'])) {
    handleRequest();
}
