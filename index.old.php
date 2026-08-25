<?php
declare(strict_types=1);

/**
 * Emmmail — single-file PHP email client (IMAP + SMTP).
 * Backend: routing, auth, IMAP/SMTP clients, MIME parsing, SQLite cache, sync.
 * Frontend: serveFrontend() (see below).
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
const EMMMAIL_SEND_DELAY = 10;
const EMMMAIL_MAX_ATTACH_SIZE = 25 * 1024 * 1024;
const EMMMAIL_SYNC_BUDGET = 60;
const EMMMAIL_LOCK_TTL = 120;

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
 * 2. .env Parser + Encryption (AES-256-GCM)
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
 * 3. Database schema + migrations
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
    sync_enabled INTEGER NOT NULL DEFAULT 1,
    last_sync_at TEXT,
    sync_error TEXT,
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
CREATE INDEX IF NOT EXISTS idx_messages_account_read ON messages(account_id, is_read);
CREATE INDEX IF NOT EXISTS idx_messages_account_starred ON messages(account_id, is_starred);
CREATE INDEX IF NOT EXISTS idx_messages_account_date ON messages(account_id, date DESC);
CREATE INDEX IF NOT EXISTS idx_messages_message_id ON messages(message_id);

CREATE VIRTUAL TABLE IF NOT EXISTS messages_fts USING fts5(
    subject, from_name, from_address, to_addresses, text_body,
    content=messages,
    content_rowid=id,
    tokenize='porter unicode61'
);
CREATE TRIGGER IF NOT EXISTS messages_ai AFTER INSERT ON messages BEGIN
    INSERT INTO messages_fts(rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES (new.id, new.subject, new.from_name, new.from_address, new.to_addresses, new.text_body);
END;
CREATE TRIGGER IF NOT EXISTS messages_ad AFTER DELETE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES ('delete', old.id, old.subject, old.from_name, old.from_address, old.to_addresses, old.text_body);
END;
CREATE TRIGGER IF NOT EXISTS messages_au AFTER UPDATE ON messages BEGIN
    INSERT INTO messages_fts(messages_fts, rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES ('delete', old.id, old.subject, old.from_name, old.from_address, old.to_addresses, old.text_body);
    INSERT INTO messages_fts(rowid, subject, from_name, from_address, to_addresses, text_body)
    VALUES (new.id, new.subject, new.from_name, new.from_address, new.to_addresses, new.text_body);
END;

CREATE TABLE IF NOT EXISTS attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    filename TEXT NOT NULL,
    content_type TEXT,
    size INTEGER,
    file_path TEXT NOT NULL,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_attachments_message ON attachments(message_id);

CREATE TABLE IF NOT EXISTS contacts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    email TEXT NOT NULL,
    name TEXT,
    use_count INTEGER NOT NULL DEFAULT 1,
    last_used_at TEXT NOT NULL DEFAULT (datetime('now')),
    UNIQUE(account_id, email)
);
CREATE INDEX IF NOT EXISTS idx_contacts_account ON contacts(account_id);
CREATE INDEX IF NOT EXISTS idx_contacts_account_email ON contacts(account_id, email);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#6366f1',
    UNIQUE(account_id, name)
);
CREATE INDEX IF NOT EXISTS idx_tags_account ON tags(account_id);

CREATE TABLE IF NOT EXISTS message_tags (
    message_id INTEGER NOT NULL REFERENCES messages(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (message_id, tag_id)
);
CREATE INDEX IF NOT EXISTS idx_message_tags_tag ON message_tags(tag_id);

CREATE TABLE IF NOT EXISTS drafts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    to_addresses TEXT,
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject TEXT,
    body TEXT,
    html_body TEXT,
    in_reply_to_message_id TEXT,
    attachments_json TEXT,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_drafts_account ON drafts(account_id);

CREATE TABLE IF NOT EXISTS outbox (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    from_address TEXT,
    to_addresses TEXT NOT NULL,
    cc_addresses TEXT,
    bcc_addresses TEXT,
    subject TEXT,
    body TEXT,
    html_body TEXT,
    in_reply_to_message_id TEXT,
    attachments_json TEXT,
    send_at TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    error_message TEXT,
    retry_count INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_outbox_status ON outbox(status, send_at);
CREATE INDEX IF NOT EXISTS idx_outbox_account ON outbox(account_id);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
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
    $depth = 0;
    foreach (explode("\n", EMMMAIL_SCHEMA) as $line) {
        $current .= $line . "\n";
        $upper = strtoupper($line);
        $depth += substr_count($upper, 'BEGIN');
        $depth -= substr_count($upper, 'END');
        if (str_ends_with(rtrim($line), ';') && $depth <= 0) {
            $statements[] = trim($current);
            $current = '';
            $depth = 0;
        }
    }
    foreach ($statements as $statement) {
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
    $pdo->exec('PRAGMA user_version = 1');
    $done = true;
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
 * 4. Pure PHP IMAP client
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

    public function __construct(string $host, int $port, int $tlsMode, string $user, string $pass, int $timeout = 30)
    {
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

    /** Returns [ ['uid' => int, 'flags' => array], ... ] for all messages. */
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

    public function fetchFlags(): array
    {
        return $this->fetchMessages();
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

    /** Alias of fetchMessageBody(). */
    public function fetchMessage(int $uid): array
    {
        return $this->fetchMessageBody($uid);
    }

    public function setFlags(int $uid, array $add = [], array $remove = []): void
    {
        $cmds = [];
        if ($add) $cmds[] = '+FLAGS.SILENT (' . implode(' ', $add) . ')';
        if ($remove) $cmds[] = '-FLAGS.SILENT (' . implode(' ', $remove) . ')';
        if (!$cmds) return;
        $res = $this->command('UID STORE ' . $uid . ' ' . implode(' ', $cmds));
        if ($res['status'] !== 'OK') throw new RuntimeException('IMAP STORE failed: ' . $res['message']);
    }

    public function copyMessages(array $uids, string $targetFolder): void
    {
        if (!$uids) return;
        $res = $this->command('UID COPY ' . implode(',', $uids) . ' ' . $this->quote($targetFolder));
        if ($res['status'] !== 'OK') throw new RuntimeException('IMAP COPY failed: ' . $res['message']);
    }

    public function expunge(): void
    {
        $res = $this->command('EXPUNGE');
        if ($res['status'] !== 'OK') throw new RuntimeException('IMAP EXPUNGE failed');
    }

    public function append(string $folder, string $raw): void
    {
        $tag = 'A' . (++$this->tagCounter);
        $this->sendLine($tag . ' APPEND ' . $this->quote($folder) . ' {' . strlen($raw) . '}');
        while (true) {
            $line = $this->readLine();
            if (str_starts_with($line, '+')) break;
        }
        $this->sendLine($raw);
        while (true) {
            $line = $this->readLine();
            if (preg_match('/^' . preg_quote($tag, '/') . ' (OK|NO|BAD)/i', $line, $m)) {
                if (strtoupper($m[1]) !== 'OK') throw new RuntimeException('IMAP APPEND failed');
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
 * 5. Pure PHP SMTP client
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

    public function __construct(string $host, int $port, int $tlsMode, string $user, string $pass, int $timeout = 30)
    {
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

    public function send(string $from, array $recipients, string $rawMessage): void
    {
        $this->expect(250, $this->cmd('MAIL FROM:<' . $from . '>'));
        foreach ($recipients as $r) {
            $this->expect(250, $this->cmd('RCPT TO:<' . $r . '>'));
        }
        $this->expect(354, $this->cmd('DATA'));
        $data = preg_replace('/^\./m', '..', $rawMessage);
        if (!str_ends_with($data, "\r\n")) $data .= "\r\n";
        $this->sendLine($data . '.');
        $this->expect(250, $this->readReply());
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

    private function expect(int $code, array $reply): void
    {
        if ($reply['code'] !== $code) {
            throw new RuntimeException('SMTP error ' . $reply['code'] . ': ' . implode(' | ', $reply['lines']));
        }
    }
}

/* ---------------------------------------------------------------------------
 * 6. MIME parser
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
    if (stripos($contentType, 'multipart/') === 0) {
        $boundary = extractBoundary($contentType);
        if ($boundary !== null) {
            foreach (splitMimeParts($body, $boundary) as $part) {
                parseMimePart($part, $result);
            }
        } else {
            parseBodyAsSingle($body, $contentType, $result);
        }
    } else {
        parseBodyAsSingle($body, $contentType, $result);
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
    if (count($parts) > 1) {
        array_shift($parts); // preamble
        if (!str_ends_with($body, '--' . $boundary . '--')) {
            // if no closing delimiter, keep all; else last is epilogue
        } else {
            array_pop($parts); // epilogue
        }
    }
    return array_map(fn($p) => rtrim($p, "\r\n"), $parts);
}

function parseBodyAsSingle(string $body, string $contentType, array &$result): void
{
    parseMimePartRaw('' . $body, $contentType, '', '', $result);
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

function decodeRfc2231(string $s): string
{
    $s = trim($s, " \t\n\r");
    $parts = explode("'", $s, 3);
    if (count($parts) === 3) return rawurldecode($parts[2]);
    return rawurldecode($s);
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

function cleanHeaderValue(string $s): string
{
    $s = str_replace(["\r", "\n"], ' ', $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
    return trim((string) $s);
}

function encodeHeader(string $s): string
{
    if (preg_match('/[^\x20-\x7E]/', $s)) {
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }
    return $s;
}

/* ---------------------------------------------------------------------------
 * 7. Utility functions
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

function ensureSession(): void
{
    if (PHP_SAPI === 'cli') {
        if (session_status() !== PHP_SESSION_ACTIVE) $_SESSION = [];
        return;
    }
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(EMMMAIL_SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'httponly' => true,
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    if (!empty($_SESSION['created_at']) && (time() - (int) $_SESSION['created_at']) > EMMMAIL_SESSION_ABSOLUTE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    if (!empty($_SESSION['last_active']) && (time() - (int) $_SESSION['last_active']) > EMMMAIL_SESSION_IDLE) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['last_active'] = time();
    if (empty($_SESSION['created_at'])) $_SESSION['created_at'] = time();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function requireAuth(): void
{
    ensureSession();
    if (empty($_SESSION['authenticated'])) {
        jsonResponse(['ok' => false, 'error' => 'Unauthorized'], 401);
    }
}

function verifyCsrf(): void
{
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($token === '' || empty($_SESSION['csrf_token']) || !hash_equals((string) $_SESSION['csrf_token'], $token)) {
        jsonResponse(['ok' => false, 'error' => 'Invalid or missing CSRF token'], 403);
    }
}

function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

function escapeHtml(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function escapeAttr(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function generateToken(): string
{
    return bin2hex(random_bytes(32));
}

function hashToken(string $token): string
{
    return hash('sha256', $token);
}

function parseJsonArray(?string $json): array
{
    if ($json === null || $json === '') return [];
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
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

function checkIpRateLimit(string $ip, int $max = 20): void
{
    $pdo = db();
    $st = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at >= datetime("now", "-900 seconds")');
    $st->execute([$ip]);
    if ((int) $st->fetchColumn() >= $max) {
        fail('Too many attempts. Try again later.', 429);
    }
}

function recordLoginFailure(string $email, string $ip): void
{
    $pdo = db();
    $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute([$email, $ip]);
    $pdo->exec('DELETE FROM login_attempts WHERE attempted_at < datetime("now", "-1 day")');
}

function deriveDisplayName(string $email): string
{
    $local = explode('@', $email, 2)[0] ?? '';
    $name = preg_replace('/[._-]+/', ' ', $local);
    $name = preg_replace_callback('/\b\w/', fn($c) => strtoupper($c[0]), (string) $name);
    return trim((string) $name);
}

function activeAccountId(?int $preferred = null): int
{
    if ($preferred !== null && $preferred > 0) return $preferred;
    if (!empty($_SESSION['account_id'])) return (int) $_SESSION['account_id'];
    $row = fetchOne(db(), 'SELECT id FROM accounts ORDER BY id LIMIT 1');
    return $row ? (int) $row['id'] : 0;
}

function getSettingsArray(PDO $pdo): array
{
    $settings = [
        'signature' => '',
        'theme' => 'dark',
        'show_remote_images' => false,
        'sync_on_load' => true,
        'update_notifications' => true,
        'update_url' => '',
    ];
    foreach (fetchAll($pdo, 'SELECT key, value FROM settings') as $r) {
        $k = $r['key'];
        $v = $r['value'];
        if (in_array($k, ['show_remote_images', 'sync_on_load', 'update_notifications'], true)) {
            $v = (bool) $v;
        }
        $settings[$k] = $v;
    }
    return $settings;
}

function detectServerForEmail(string $email): array
{
    $domain = strtolower(substr(strrchr($email, '@') ?: '', 1));

    if (in_array($domain, ['gmail.com', 'googlemail.com'], true)) {
        return ['imap_host' => 'imap.gmail.com', 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.gmail.com', 'smtp_port' => 465, 'smtp_tls' => TlsMode::Ssl->value];
    }
    if (in_array($domain, ['outlook.com', 'hotmail.com', 'live.com', 'msn.com', 'office365.com'], true)) {
        return ['imap_host' => 'outlook.office365.com', 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.office365.com', 'smtp_port' => 587, 'smtp_tls' => TlsMode::StartTls->value];
    }
    if (in_array($domain, ['icloud.com', 'me.com', 'mac.com'], true)) {
        return ['imap_host' => 'imap.mail.me.com', 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.mail.me.com', 'smtp_port' => 587, 'smtp_tls' => TlsMode::StartTls->value];
    }
    if (in_array($domain, ['yahoo.com', 'ymail.com'], true)) {
        return ['imap_host' => 'imap.mail.yahoo.com', 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.mail.yahoo.com', 'smtp_port' => 465, 'smtp_tls' => TlsMode::Ssl->value];
    }

    $recs = @dns_get_record($domain, DNS_MX);
    $mx = [];
    if (is_array($recs)) {
        foreach ($recs as $r) {
            if (!empty($r['target'])) $mx[] = ltrim((string) $r['target'], '.');
        }
    }
    sort($mx, SORT_STRING);
    if ($mx) {
        $mxHost = $mx[0];
        return ['imap_host' => $mxHost, 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.' . $domain, 'smtp_port' => 587, 'smtp_tls' => TlsMode::StartTls->value];
    }
    return ['imap_host' => 'imap.' . $domain, 'imap_port' => 993, 'imap_tls' => TlsMode::Ssl->value, 'smtp_host' => 'smtp.' . $domain, 'smtp_port' => 587, 'smtp_tls' => TlsMode::StartTls->value];
}

function validateImapLogin(array $detected, string $email, string $password): void
{
    $imap = new ImapClient($detected['imap_host'], (int) $detected['imap_port'], (int) $detected['imap_tls'], $email, $password, 20);
    $imap->connect();
    $imap->login();
    $imap->logout();
}

function testAccountCredentials(array $form): void
{
    $email = strtolower(trim((string) ($form['email'] ?? '')));
    $pass = (string) ($form['password'] ?? '');
    $imapHost = trim((string) ($form['imap_host'] ?? ''));
    $imapPort = (int) ($form['imap_port'] ?? 993);
    $imapTls = (int) ($form['imap_tls'] ?? TlsMode::Ssl->value);
    $smtpHost = trim((string) ($form['smtp_host'] ?? ''));
    $smtpPort = (int) ($form['smtp_port'] ?? 587);
    $smtpTls = (int) ($form['smtp_tls'] ?? TlsMode::StartTls->value);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Valid email required');
    if ($pass === '') throw new RuntimeException('Password required');
    if ($imapHost === '' || $smtpHost === '') throw new RuntimeException('IMAP and SMTP hosts are required');

    $imap = new ImapClient($imapHost, $imapPort, $imapTls, $email, $pass, 20);
    $imap->connect();
    $imap->login();
    $imap->logout();

    $smtp = new SmtpClient($smtpHost, $smtpPort, $smtpTls, $email, $pass, 20);
    $smtp->connect();
    $smtp->login();
    $smtp->quit();
}

function sanitizeRecipients(array $list): array
{
    $out = [];
    foreach ($list as $r) {
        if (is_array($r)) {
            $email = strtolower(trim((string) ($r['email'] ?? '')));
            $name = trim((string) ($r['name'] ?? ''));
        } else {
            $email = strtolower(trim((string) $r));
            $name = '';
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) continue;
        $out[] = ['name' => cleanHeaderValue($name), 'email' => $email];
    }
    return $out;
}

function formatAddressList(array $list): string
{
    $parts = [];
    foreach ($list as $r) {
        $name = $r['name'] ?? '';
        $email = $r['email'] ?? '';
        if ($email === '') continue;
        $parts[] = $name !== '' ? encodeHeader($name) . ' <' . $email . '>' : '<' . $email . '>';
    }
    return implode(', ', $parts);
}

/* ---------------------------------------------------------------------------
 * Thread + message helpers (used by sync and API)
 * ------------------------------------------------------------------------ */

function normalizeSubject(?string $subject): string
{
    $s = mb_strtolower(trim((string) $subject));
    for ($i = 0; $i < 5; $i++) {
        $new = preg_replace('/^(re|fwd|fw|aw|sv|vs|antw|rif|res|enc|答复|回复|回覆|转发)(\s*:\s*|\s+)/iu', '', $s);
        if ($new === $s) break;
        $s = $new;
    }
    $s = preg_replace('/\s+/', ' ', $s);
    return trim((string) $s);
}

function computeThreadKey(PDO $pdo, int $accountId, array $headers): string
{
    $messageId = trim((string) headerValue($headers, 'message-id'));
    $refs = [];
    $inReplyTo = headerValue($headers, 'in-reply-to');
    if ($inReplyTo !== '') $refs[] = $inReplyTo;
    $references = headerValue($headers, 'references');
    if ($references !== '') $refs[] = $references;

    $seen = [];
    foreach ($refs as $refStr) {
        foreach (preg_split('/[\s,<>]+/', $refStr) as $ref) {
            $ref = trim($ref);
            if ($ref === '' || isset($seen[$ref])) continue;
            $seen[$ref] = true;
            $row = fetchOne($pdo, 'SELECT t.thread_key FROM messages mm JOIN threads t ON t.id = mm.thread_id WHERE mm.account_id = ? AND mm.message_id = ? LIMIT 1', [$accountId, $ref]);
            if ($row) return (string) $row['thread_key'];
        }
    }

    $subject = normalizeSubject(headerValue($headers, 'subject'));
    if ($subject !== '') {
        return hash('sha1', $subject);
    }
    return 'msg-' . ($messageId !== '' ? hash('sha1', $messageId) : bin2hex(random_bytes(8)));
}

function findOrCreateThread(PDO $pdo, int $accountId, array $headers, string $subject, string $date): int
{
    $key = computeThreadKey($pdo, $accountId, $headers);
    $row = fetchOne($pdo, 'SELECT id FROM threads WHERE account_id = ? AND thread_key = ?', [$accountId, $key]);
    if ($row) return (int) $row['id'];

    $pdo->prepare('INSERT INTO threads (account_id, thread_key, subject, last_message_at, message_count) VALUES (?, ?, ?, ?, 1)')
        ->execute([$accountId, $key, $subject, $date]);
    return (int) $pdo->lastInsertId();
}

function updateThreadAggregates(PDO $pdo, int $threadId): void
{
    $agg = fetchOne($pdo, 'SELECT COUNT(*) AS c, MAX(date) AS maxdate, SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END) AS unread, COALESCE(SUM(is_starred), 0) AS starred FROM messages WHERE thread_id = ?', [$threadId]);
    if (!$agg) return;
    $last = fetchOne($pdo, 'SELECT text_body, date FROM messages WHERE thread_id = ? ORDER BY date DESC, id DESC LIMIT 1', [$threadId]);
    $preview = $last ? mb_substr((string) preg_replace('/\s+/', ' ', (string) ($last['text_body'] ?? '')), 0, 160) : '';
    $pdo->prepare('UPDATE threads SET message_count = ?, last_message_at = COALESCE(?, last_message_at), is_read = CASE WHEN COALESCE(?, 0) > 0 THEN 0 ELSE 1 END, is_starred = ?, preview = ? WHERE id = ?')
        ->execute([(int) $agg['c'], $agg['maxdate'], (int) $agg['unread'], (int) $agg['starred'] ? 1 : 0, $preview, $threadId]);
}

function updateContacts(PDO $pdo, int $accountId, array $addresses): void
{
    $st = $pdo->prepare('INSERT INTO contacts (account_id, email, name) VALUES (?, ?, ?) ON CONFLICT(account_id, email) DO UPDATE SET use_count = use_count + 1, last_used_at = datetime("now"), name = COALESCE(excluded.name, contacts.name)');
    foreach ($addresses as $a) {
        if (is_array($a) && !empty($a['email']) && filter_var($a['email'], FILTER_VALIDATE_EMAIL)) {
            $st->execute([$accountId, $a['email'], trim((string) ($a['name'] ?? ''))]);
        }
    }
}

function saveAttachment(PDO $pdo, int $messageId, int $accountId, array $att): void
{
    $filename = sanitizeFilename((string) ($att['filename'] ?? 'attachment'));
    if ($filename === '' || $filename === '.') $filename = 'attachment.bin';
    if (isBlockedAttachment($filename)) $filename .= '.txt';
    if ($filename === '') $filename = 'attachment.bin';

    $dir = EMMMAIL_ATTACH_DIR . '/' . $accountId . '/' . $messageId;
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $path = $dir . '/' . $filename;
    $i = 1;
    while (is_file($path)) {
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $path = $dir . '/' . $base . '-' . $i++ . ($ext !== '' ? '.' . $ext : '');
    }
    file_put_contents($path, (string) ($att['data'] ?? ''), LOCK_EX);
    $rel = $accountId . '/' . $messageId . '/' . basename($path);
    $pdo->prepare('INSERT INTO attachments (message_id, filename, content_type, size, file_path) VALUES (?, ?, ?, ?, ?)')
        ->execute([$messageId, $filename, $att['content_type'] ?? 'application/octet-stream', (int) ($att['size'] ?? strlen((string) ($att['data'] ?? ''))), $rel]);
}

function deleteMessageFiles(PDO $pdo, int $messageId): void
{
    $rows = fetchAll($pdo, 'SELECT file_path FROM attachments WHERE message_id = ?', [$messageId]);
    $root = realpath(EMMMAIL_ATTACH_DIR);
    foreach ($rows as $r) {
        $abs = EMMMAIL_ATTACH_DIR . '/' . $r['file_path'];
        $real = realpath($abs);
        if ($real !== false && $root !== false && str_starts_with($real, $root)) {
            @unlink($real);
        }
    }
    $pdo->prepare('DELETE FROM attachments WHERE message_id = ?')->execute([$messageId]);
    $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([$messageId]);
}

function storeMessage(PDO $pdo, int $accountId, string $folderName, int $uid, array $parsed, array $flags, string $raw): int
{
    $existing = fetchOne($pdo, 'SELECT id FROM messages WHERE account_id = ? AND folder_name = ? AND uid = ?', [$accountId, $folderName, $uid]);
    $headers = $parsed['headers'];
    $subject = decodeMimeHeader(headerValue($headers, 'subject'));
    $from = parseAddresses(headerValue($headers, 'from'));
    $fromAddr = $from[0]['email'] ?? '';
    $fromName = $from[0]['name'] ?? '';
    $to = parseAddresses(headerValue($headers, 'to'));
    $cc = parseAddresses(headerValue($headers, 'cc'));
    $date = normalizeDate(headerValue($headers, 'date'));
    $messageId = cleanHeaderValue(headerValue($headers, 'message-id'));
    $inReplyTo = cleanHeaderValue(headerValue($headers, 'in-reply-to'));
    $refs = cleanHeaderValue(headerValue($headers, 'references'));
    $isRead = in_array('\\Seen', $flags, true) ? 1 : 0;
    $isStarred = in_array('\\Flagged', $flags, true) ? 1 : 0;
    $hasAttachments = $parsed['attachments'] ? 1 : 0;

    if ($existing) {
        $pdo->prepare('UPDATE messages SET is_read = ?, is_starred = ?, has_attachments = ? WHERE id = ?')
            ->execute([$isRead, $isStarred, $hasAttachments, (int) $existing['id']]);
        return (int) $existing['id'];
    }

    $threadId = findOrCreateThread($pdo, $accountId, $headers, $subject, $date);
    $pdo->prepare('INSERT INTO messages (account_id, folder_name, uid, message_id, in_reply_to, refs, subject, from_address, from_name, to_addresses, cc_addresses, date, text_body, html_body, thread_id, is_read, is_starred, has_attachments, size, raw_headers) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $accountId, $folderName, $uid, $messageId, $inReplyTo, $refs, $subject, $fromAddr, $fromName,
            json_encode($to), json_encode($cc), $date, $parsed['text_body'], $parsed['html_body'], $threadId,
            $isRead, $isStarred, $hasAttachments, strlen($raw), $parsed['raw_headers'],
        ]);
    $messageIdDb = (int) $pdo->lastInsertId();

    foreach ($parsed['attachments'] as $att) {
        saveAttachment($pdo, $messageIdDb, $accountId, $att);
    }

    updateThreadAggregates($pdo, $threadId);
    $contactAddresses = [];
    if ($fromAddr !== '') $contactAddresses[] = ['email' => $fromAddr, 'name' => $fromName];
    foreach (array_merge($to, $cc) as $a) {
        $contactAddresses[] = ['email' => $a['email'], 'name' => $a['name']];
    }
    updateContacts($pdo, $accountId, $contactAddresses);
    return $messageIdDb;
}

function folderNameByRole(PDO $pdo, int $accountId, string $role, string $fallback): string
{
    $row = fetchOne($pdo, 'SELECT name FROM folders WHERE account_id = ? AND role = ? ORDER BY name LIMIT 1', [$accountId, $role]);
    return $row ? (string) $row['name'] : $fallback;
}

function moveMessageFolder(PDO $pdo, int $messageId, string $target): void
{
    $m = fetchOne($pdo, 'SELECT folder_name, account_id, uid FROM messages WHERE id = ?', [$messageId]);
    if (!$m || $m['folder_name'] === $target) return;
    $conflict = fetchOne($pdo, 'SELECT id FROM messages WHERE account_id = ? AND folder_name = ? AND uid = ?', [$m['account_id'], $target, $m['uid']]);
    if ($conflict) {
        $pdo->prepare('DELETE FROM messages WHERE id = ?')->execute([(int) $conflict['id']]);
    }
    $pdo->prepare('UPDATE messages SET folder_name = ? WHERE id = ?')->execute([$target, $messageId]);
}

function attachmentList(PDO $pdo, int $messageId): array
{
    $rows = fetchAll($pdo, 'SELECT id, filename, content_type, size FROM attachments WHERE message_id = ? ORDER BY id', [$messageId]);
    return array_map(fn($r) => [
        'id' => (int) $r['id'],
        'filename' => $r['filename'],
        'content_type' => $r['content_type'],
        'size' => (int) $r['size'],
    ], $rows);
}

function messageToClient(PDO $pdo, array $m): array
{
    return [
        'id' => (int) $m['id'],
        'message_id' => $m['message_id'],
        'subject' => $m['subject'] ?: '(no subject)',
        'from_name' => $m['from_name'],
        'from_address' => $m['from_address'],
        'to_addresses' => parseJsonArray($m['to_addresses']),
        'cc_addresses' => parseJsonArray($m['cc_addresses']),
        'date' => $m['date'],
        'text_body' => $m['text_body'],
        'html_body' => $m['html_body'],
        'is_read' => (bool) $m['is_read'],
        'is_starred' => (bool) $m['is_starred'],
        'has_attachments' => (bool) $m['has_attachments'],
        'size' => (int) $m['size'],
        'attachments' => attachmentList($pdo, (int) $m['id']),
    ];
}

function selectThreads(PDO $pdo, string $where, array $params, int $limit = 200): array
{
    $sql = "SELECT t.id, t.subject, t.last_message_at AS date, t.message_count AS count, t.preview,
        t.is_read, t.is_starred,
        (SELECT m.from_name FROM messages m WHERE m.thread_id = t.id ORDER BY m.date DESC, m.id DESC LIMIT 1) AS sender_name,
        (SELECT m.from_address FROM messages m WHERE m.thread_id = t.id ORDER BY m.date DESC, m.id DESC LIMIT 1) AS sender_email,
        (SELECT m.has_attachments FROM messages m WHERE m.thread_id = t.id ORDER BY m.date DESC, m.id DESC LIMIT 1) AS has_attachments,
        (SELECT GROUP_CONCAT(mt.tag_id) FROM message_tags mt JOIN messages m ON m.id = mt.message_id WHERE m.thread_id = t.id) AS tag_ids
        FROM threads t WHERE " . $where . " ORDER BY t.last_message_at DESC, t.id DESC LIMIT " . (int) $limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $threads = [];
    foreach ($st->fetchAll() as $r) {
        $threads[] = [
            'id' => (int) $r['id'],
            'subject' => $r['subject'] ?: '(no subject)',
            'date' => $r['date'],
            'count' => max(1, (int) ($r['count'] ?? 1)),
            'preview' => $r['preview'] ?? '',
            'unread' => (int) ($r['is_read'] ?? 1) === 0,
            'starred' => (bool) ($r['is_starred'] ?? 0),
            'sender_name' => $r['sender_name'] ?? '',
            'sender_email' => $r['sender_email'] ?? '',
            'has_attachments' => (bool) ($r['has_attachments'] ?? 0),
            'tag_ids' => $r['tag_ids'] ? array_map('intval', explode(',', $r['tag_ids'])) : [],
        ];
    }
    return $threads;
}

function applyFlagChanges(PDO $pdo, int $threadId, array $changes): void
{
    if (!$changes) return;
    $cols = array_keys($changes);
    $setSql = implode('=?, ', $cols) . '=?';
    $values = array_values($changes);
    $pdo->prepare('UPDATE messages SET ' . $setSql . ' WHERE thread_id = ?')->execute([...$values, $threadId]);
    $pdo->prepare('UPDATE threads SET ' . $setSql . ' WHERE id = ?')->execute([...$values, $threadId]);
}

function pushFlagsToImap(PDO $pdo, array $messages, array $changes): void
{
    if (!$messages) return;
    try {
        $byAccount = [];
        foreach ($messages as $m) {
            $byAccount[$m['account_id']][$m['folder_name']][] = (int) $m['uid'];
        }
        foreach ($byAccount as $accountId => $folders) {
            $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
            if (!$acct || (int) $acct['is_active'] !== 1) continue;
            $pass = decryptPassword($acct, getEncryptionKey());
            if ($pass === '') continue;
            $imap = new ImapClient($acct['imap_host'], (int) $acct['imap_port'], (int) $acct['imap_tls'], $acct['email'], $pass, 8);
            $imap->connect();
            $imap->login();
            foreach ($folders as $folder => $uids) {
                try {
                    $imap->selectFolder($folder);
                } catch (Throwable $e) {
                    continue;
                }
                $add = [];
                $rem = [];
                if (isset($changes['is_read'])) {
                    if ($changes['is_read']) $add[] = '\\Seen';
                    else $rem[] = '\\Seen';
                }
                if (isset($changes['is_starred'])) {
                    if ($changes['is_starred']) $add[] = '\\Flagged';
                    else $rem[] = '\\Flagged';
                }
                foreach ($uids as $uid) {
                    $imap->setFlags($uid, $add, $rem);
                }
            }
            $imap->logout();
        }
    } catch (Throwable $e) {
        // non-fatal: server flags resync on next sync
    }
}

/* ---------------------------------------------------------------------------
 * MIME builder + outbox
 * ------------------------------------------------------------------------ */

function buildMimeMessage(array $out, array $acct): string
{
    $boundary = '=_emmmail_' . bin2hex(random_bytes(8));
    $altBoundary = '=_emmmail_alt_' . bin2hex(random_bytes(8));

    $headers = [];
    $fromName = $acct['display_name'] ? encodeHeader($acct['display_name']) . ' ' : '';
    $headers[] = 'From: ' . $fromName . '<' . $acct['email'] . '>';
    $to = formatAddressList(parseJsonArray($out['to_addresses']));
    $headers[] = 'To: ' . ($to !== '' ? $to : '<undisclosed-recipients:;>');
    $cc = formatAddressList(parseJsonArray($out['cc_addresses']));
    if ($cc !== '') $headers[] = 'Cc: ' . $cc;
    $headers[] = 'Subject: ' . encodeHeader((string) $out['subject']);
    $headers[] = 'Date: ' . gmdate('D, d M Y H:i:s O');
    $headers[] = 'Message-ID: <' . bin2hex(random_bytes(16)) . '@emmmail.local>';
    if (!empty($out['in_reply_to_message_id'])) {
        $headers[] = 'In-Reply-To: ' . cleanHeaderValue((string) $out['in_reply_to_message_id']);
    }
    $headers[] = 'MIME-Version: 1.0';

    $parts = [];
    $alternatives = [];
    $textBody = (string) ($out['body'] ?? '');
    $htmlBody = (string) ($out['html_body'] ?? '');
    if ($textBody !== '') {
        $alternatives[] = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($textBody));
    }
    if ($htmlBody !== '') {
        $alternatives[] = "Content-Type: text/html; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($htmlBody));
    }
    if ($alternatives) {
        $alt = "Content-Type: multipart/alternative; boundary=\"" . $altBoundary . "\"\r\n\r\n" . implode("\r\n\r\n--" . $altBoundary . "\r\n", $alternatives) . "\r\n\r\n--" . $altBoundary . '--';
        $parts[] = $alt;
    }

    foreach (parseJsonArray($out['attachments_json']) as $a) {
        $path = $a['tmp_path'] ?? '';
        if ($path === '' || !is_file($path)) continue;
        $data = file_get_contents($path);
        if ($data === false) continue;
        $filename = sanitizeFilename((string) ($a['filename'] ?? 'attachment'));
        if ($filename === '') $filename = 'attachment.bin';
        $safe = str_replace('"', '', $filename);
        $ct = cleanHeaderValue((string) ($a['content_type'] ?? 'application/octet-stream'));
        if ($ct === '') $ct = 'application/octet-stream';
        $parts[] = "Content-Type: " . $ct . "; name=\"" . $safe . "\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"" . $safe . "\"\r\n\r\n"
            . chunk_split(base64_encode($data));
    }

    if (!$parts) {
        $parts[] = "Content-Type: text/plain; charset=utf-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($textBody !== '' ? $textBody : ' '));
    }

    $body = "Content-Type: multipart/mixed; boundary=\"" . $boundary . "\"\r\n\r\n" . implode("\r\n\r\n--" . $boundary . "\r\n", $parts) . "\r\n\r\n--" . $boundary . '--';
    return implode("\r\n", $headers) . "\r\n\r\n" . $body;
}

function cleanupAttachmentFiles(PDO $pdo, array $row): void
{
    foreach (parseJsonArray($row['attachments_json'] ?? null) as $a) {
        $path = $a['tmp_path'] ?? '';
        if ($path !== '' && is_file($path)) @unlink($path);
    }
}

function sendOutboxItem(PDO $pdo, int $id): array
{
    $claimed = $pdo->prepare("UPDATE outbox SET status = 'sending' WHERE id = ? AND status = 'pending'");
    $claimed->execute([$id]);
    if ($claimed->rowCount() === 0) {
        $row = fetchOne($pdo, 'SELECT status FROM outbox WHERE id = ?', [$id]);
        if ($row && $row['status'] === 'sending') return ['status' => 'busy'];
        return ['status' => 'not_pending'];
    }

    $out = fetchOne($pdo, 'SELECT * FROM outbox WHERE id = ?', [$id]);
    if (!$out) return ['status' => 'not_pending'];

    try {
        $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [(int) $out['account_id']]);
        if (!$acct) throw new RuntimeException('Account not found');
        $pass = decryptPassword($acct, getEncryptionKey());
        if ($pass === '') throw new RuntimeException('Could not decrypt password');

        $recipients = [];
        foreach (['to_addresses', 'cc_addresses', 'bcc_addresses'] as $field) {
            foreach (parseJsonArray($out[$field]) as $r) {
                $email = strtolower(trim((string) ($r['email'] ?? '')));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $recipients[] = $email;
            }
        }
        $recipients = array_values(array_unique($recipients));
        if (!$recipients) throw new RuntimeException('No recipients');

        $raw = buildMimeMessage($out, $acct);
        $smtp = new SmtpClient($acct['smtp_host'], (int) $acct['smtp_port'], (int) $acct['smtp_tls'], $acct['email'], $pass, 30);
        $smtp->connect();
        $smtp->login();
        $smtp->send($acct['email'], $recipients, $raw);
        $smtp->quit();

        cleanupAttachmentFiles($pdo, $out);
        $pdo->prepare('DELETE FROM outbox WHERE id = ?')->execute([$id]);
        copyToSentFolder($pdo, $acct, $raw);
        return ['status' => 'sent'];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE outbox SET status = 'failed', error_message = ?, retry_count = retry_count + 1 WHERE id = ?")
            ->execute([substr($e->getMessage(), 0, 500), $id]);
        return ['status' => 'failed', 'error' => $e->getMessage()];
    }
}

function processOutboxDue(PDO $pdo): void
{
    $rows = fetchAll($pdo, "SELECT id FROM outbox WHERE status = 'pending' AND send_at <= datetime('now') ORDER BY send_at");
    foreach ($rows as $r) {
        sendOutboxItem($pdo, (int) $r['id']);
    }
}

function copyToSentFolder(PDO $pdo, array $acct, string $raw): void
{
    try {
        $folder = folderNameByRole($pdo, (int) $acct['id'], 'sent', 'Sent');
        $pass = decryptPassword($acct, getEncryptionKey());
        if ($pass === '') return;
        $imap = new ImapClient($acct['imap_host'], (int) $acct['imap_port'], (int) $acct['imap_tls'], $acct['email'], $pass, 20);
        $imap->connect();
        $imap->login();
        $imap->append($folder, $raw);
        $imap->logout();
    } catch (Throwable $e) {
        // non-fatal
    }
}

/* ---------------------------------------------------------------------------
 * 9. Sync engine
 * ------------------------------------------------------------------------ */

function syncAccountById(int $accountId, PDO $pdo, int $budgetSeconds = EMMMAIL_SYNC_BUDGET, ?int $fetchLimit = null): array
{
    $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$acct || (int) $acct['is_active'] !== 1) return ['skipped' => true];

    $lockKey = 'sync_lock_' . $accountId;
    $lock = fetchOne($pdo, 'SELECT value FROM settings WHERE key = ?', [$lockKey]);
    if ($lock && time() - (int) $lock['value'] < EMMMAIL_LOCK_TTL) {
        return ['skipped' => true];
    }
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$lockKey, (string) time()]);

    $deadline = microtime(true) + max(1, $budgetSeconds);
    $totalFetched = 0;

    try {
        $pass = decryptPassword($acct, getEncryptionKey());
        if ($pass === '') throw new RuntimeException('Could not decrypt stored password');

        $imap = new ImapClient($acct['imap_host'], (int) $acct['imap_port'], (int) $acct['imap_tls'], $acct['email'], $pass, 30);
        $imap->connect();
        $imap->login();

        $folders = $imap->listFolders();
        foreach ($folders as $f) {
            if (microtime(true) > $deadline) break;
            $name = (string) $f['name'];
            if ($name === '') continue;
            $role = ImapClient::detectRole($name, $f['flags']);

            $pdo->prepare('INSERT INTO folders (account_id, name, role) VALUES (?, ?, ?) ON CONFLICT(account_id, name) DO UPDATE SET role = COALESCE(excluded.role, folders.role)')
                ->execute([$accountId, $name, $role]);
            $folder = fetchOne($pdo, 'SELECT * FROM folders WHERE account_id = ? AND name = ?', [$accountId, $name]);
            if (!$folder) continue;
            $folderId = (int) $folder['id'];

            try {
                $sel = $imap->selectFolder($name);
            } catch (Throwable $e) {
                continue; // not selectable (e.g. \Noselect)
            }

            $uidValidity = $sel['uid_validity'];
            $lastUid = (int) ($folder['last_uid'] ?? 0);
            if ($uidValidity !== null && $folder['uid_validity'] !== null && (int) $folder['uid_validity'] !== $uidValidity) {
                foreach (fetchAll($pdo, 'SELECT id FROM messages WHERE account_id = ? AND folder_name = ?', [$accountId, $name]) as $mid) {
                    deleteMessageFiles($pdo, (int) $mid['id']);
                }
                $pdo->prepare('UPDATE folders SET last_uid = 0, uid_validity = NULL WHERE id = ?')->execute([$folderId]);
                $lastUid = 0;
            }

            $messages = $imap->fetchMessages();
            $serverUids = [];
            $newUids = [];
            $maxUid = 0;
            foreach ($messages as $m) {
                $uid = (int) $m['uid'];
                $serverUids[$uid] = $m['flags'];
                if ($uid > $maxUid) $maxUid = $uid;
                if ($uid > $lastUid) $newUids[] = $uid;
            }

            // expunge reconciliation: cached UIDs no longer on the server
            $cached = fetchAll($pdo, 'SELECT uid, id FROM messages WHERE account_id = ? AND folder_name = ?', [$accountId, $name]);
            foreach ($cached as $c) {
                if (!isset($serverUids[(int) $c['uid']])) {
                    deleteMessageFiles($pdo, (int) $c['id']);
                }
            }

            // flag re-sync for all cached messages
            $flagStmt = $pdo->prepare('UPDATE messages SET is_read = ?, is_starred = ? WHERE account_id = ? AND folder_name = ? AND uid = ?');
            foreach ($serverUids as $uid => $flags) {
                $flagStmt->execute([
                    in_array('\\Seen', $flags, true) ? 1 : 0,
                    in_array('\\Flagged', $flags, true) ? 1 : 0,
                    $accountId, $name, $uid,
                ]);
            }

            // fetch bodies for new messages
            foreach ($newUids as $uid) {
                if (microtime(true) > $deadline) break;
                if ($fetchLimit !== null && $totalFetched >= $fetchLimit) break;
                try {
                    $data = $imap->fetchMessageBody($uid);
                } catch (Throwable $e) {
                    break;
                }
                if ($data['raw'] === '') continue;
                $parsed = parseMimeMessage($data['raw']);
                storeMessage($pdo, $accountId, $name, $uid, $parsed, $data['flags'], $data['raw']);
                $totalFetched++;
            }

            if ($maxUid > 0) {
                $unseen = 0;
                foreach ($serverUids as $flags) {
                    if (!in_array('\\Seen', $flags, true)) $unseen++;
                }
                $pdo->prepare('UPDATE folders SET last_uid = ?, uid_validity = COALESCE(?, uid_validity), total_count = ?, unread_count = ? WHERE id = ?')
                    ->execute([$maxUid, $uidValidity, count($serverUids), $unseen, $folderId]);
            }
        }
        $imap->logout();
        $pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([$lockKey]);
        return ['synced' => true, 'fetched' => $totalFetched];
    } catch (Throwable $e) {
        $pdo->prepare('DELETE FROM settings WHERE key = ?')->execute([$lockKey]);
        throw $e;
    }
}

function cleanupTmpAttachments(): void
{
    $dir = EMMMAIL_ATTACH_DIR . '/tmp';
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && time() - filemtime($f) > 86400) @unlink($f);
    }
}

function cliSync(): void
{
    $start = microtime(true);
    $pdo = db();
    cleanupTmpAttachments();
    processOutboxDue($pdo);

    $accounts = fetchAll($pdo, 'SELECT * FROM accounts WHERE is_active = 1');
    foreach ($accounts as $acct) {
        $remaining = (int) floor(EMMMAIL_SYNC_BUDGET - (microtime(true) - $start));
        if ($remaining <= 0) {
            fwrite(STDERR, "Emmmail sync: budget exhausted\n");
            break;
        }
        try {
            $res = syncAccountById((int) $acct['id'], $pdo, $remaining);
            if (empty($res['skipped'])) {
                $pdo->prepare('UPDATE accounts SET sync_error = NULL, last_sync_at = datetime("now") WHERE id = ?')->execute([$acct['id']]);
            }
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE accounts SET sync_error = ? WHERE id = ?')->execute([substr($e->getMessage(), 0, 500), (int) $acct['id']]);
            fwrite(STDERR, 'Emmmail sync failed for ' . $acct['email'] . ': ' . $e->getMessage() . "\n");
        }
    }
    processOutboxDue($pdo);
}

/* ---------------------------------------------------------------------------
 * 8. API endpoints
 * ------------------------------------------------------------------------ */

function apiPing(): void
{
    jsonResponse(['ok' => true, 'data' => ['pong' => true, 'version' => EMMMAIL_VERSION, 'time' => gmdate('c')]]);
}

function apiAuthStatus(): void
{
    ensureSession();
    $pdo = db();
    $setup = ((int) fetchOne($pdo, 'SELECT COUNT(*) AS c FROM accounts')['c']) === 0;
    $data = [
        'authenticated' => !empty($_SESSION['authenticated']),
        'csrf' => $_SESSION['csrf_token'] ?? null,
        'setup' => $setup,
        'version' => EMMMAIL_VERSION,
    ];
    if (!empty($_SESSION['authenticated'])) {
        $data['account_id'] = (int) $_SESSION['account_id'];
    }
    jsonResponse(['ok' => true, 'data' => $data]);
}

function apiAuthSetupStatus(): void
{
    $pdo = db();
    $setup = ((int) fetchOne($pdo, 'SELECT COUNT(*) AS c FROM accounts')['c']) === 0;
    jsonResponse(['ok' => true, 'data' => ['setup' => $setup]]);
}

function apiAuthLogin(): void
{
    ensureSession();
    $in = getInput();
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $password = (string) ($in['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Valid email required');
    if ($password === '') fail('Password required');

    $ip = getClientIp();
    checkRateLimit($email, $ip);

    try {
        validateImapLogin(detectServerForEmail($email), $email, $password);
    } catch (Throwable $e) {
        recordLoginFailure($email, $ip);
        fail('IMAP login failed: ' . $e->getMessage(), 401);
    }

    $pdo = db();
    $key = getEncryptionKey();
    $enc = encryptPassword($password, $key);
    $detected = detectServerForEmail($email);

    $existing = fetchOne($pdo, 'SELECT id FROM accounts WHERE email = ?', [$email]);
    if ($existing) {
        $accountId = (int) $existing['id'];
        $pdo->prepare('UPDATE accounts SET imap_host = ?, imap_port = ?, imap_tls = ?, smtp_host = ?, smtp_port = ?, smtp_tls = ?, password_encrypted = ?, password_iv = ?, password_tag = ?, is_active = 1 WHERE id = ?')
            ->execute([$detected['imap_host'], $detected['imap_port'], $detected['imap_tls'], $detected['smtp_host'], $detected['smtp_port'], $detected['smtp_tls'], $enc['encrypted'], $enc['iv'], $enc['tag'], $accountId]);
    } else {
        $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$email, deriveDisplayName($email), $detected['imap_host'], $detected['imap_port'], $detected['imap_tls'], $detected['smtp_host'], $detected['smtp_port'], $detected['smtp_tls'], $enc['encrypted'], $enc['iv'], $enc['tag']]);
        $accountId = (int) $pdo->lastInsertId();
    }

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['account_id'] = $accountId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['created_at'] = time();

    try {
        syncAccountById($accountId, $pdo, 20, 25);
    } catch (Throwable $e) {
        // non-fatal; sync happens on demand
    }

    jsonResponse(['ok' => true, 'data' => ['account_id' => $accountId, 'csrf' => $_SESSION['csrf_token']]]);
}

function apiAuthSetup(): void
{
    ensureSession();
    $pdo = db();
    if (((int) fetchOne($pdo, 'SELECT COUNT(*) AS c FROM accounts')['c']) > 0) {
        fail('Setup already complete', 403);
    }
    $in = getInput();
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    $ip = getClientIp();
    checkRateLimit($email, $ip);

    try {
        testAccountCredentials($in);
    } catch (Throwable $e) {
        recordLoginFailure($email, $ip);
        fail($e->getMessage(), 401);
    }

    $key = getEncryptionKey();
    $enc = encryptPassword((string) ($in['password'] ?? ''), $key);
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $email,
            trim((string) ($in['display_name'] ?? '')) !== '' ? trim((string) $in['display_name']) : deriveDisplayName($email),
            trim((string) $in['imap_host']),
            (int) ($in['imap_port'] ?? 993),
            (int) ($in['imap_tls'] ?? TlsMode::Ssl->value),
            trim((string) $in['smtp_host']),
            (int) ($in['smtp_port'] ?? 587),
            (int) ($in['smtp_tls'] ?? TlsMode::StartTls->value),
            $enc['encrypted'],
            $enc['iv'],
            $enc['tag'],
        ]);
    $accountId = (int) $pdo->lastInsertId();

    session_regenerate_id(true);
    $_SESSION['authenticated'] = true;
    $_SESSION['account_id'] = $accountId;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['created_at'] = time();

    try {
        syncAccountById($accountId, $pdo, 20, 25);
    } catch (Throwable $e) {
        // non-fatal
    }

    jsonResponse(['ok' => true, 'data' => ['account_id' => $accountId, 'csrf' => $_SESSION['csrf_token']]]);
}

function apiAuthLogout(): void
{
    ensureSession();
    $_SESSION = [];
    session_regenerate_id(true);
    jsonResponse(['ok' => true, 'data' => ['logged_out' => true]]);
}

function apiListAccounts(): void
{
    $pdo = db();
    $rows = fetchAll($pdo, 'SELECT * FROM accounts ORDER BY id');
    $accounts = [];
    foreach ($rows as $r) {
        $counts = [];
        $inboxUnread = 0;
        foreach (fetchAll($pdo, 'SELECT role, unread_count FROM folders WHERE account_id = ?', [(int) $r['id']]) as $f) {
            $role = $f['role'] ?? 'other';
            $counts[$role] = (int) ($counts[$role] ?? 0) + (int) $f['unread_count'];
            if ($role === 'inbox') $inboxUnread += (int) $f['unread_count'];
        }
        $accounts[] = [
            'id' => (int) $r['id'],
            'email' => $r['email'],
            'display_name' => $r['display_name'],
            'last_sync_at' => $r['last_sync_at'],
            'sync_error' => $r['sync_error'],
            'sync_enabled' => (bool) $r['sync_enabled'],
            'unread_counts' => $counts,
            'unread_count' => $inboxUnread,
        ];
    }
    jsonResponse(['ok' => true, 'data' => ['accounts' => $accounts]]);
}

function apiAddAccount(): void
{
    $in = getInput();
    $ip = getClientIp();
    checkIpRateLimit($ip, 20);
    $pdo = db();
    $email = strtolower(trim((string) ($in['email'] ?? '')));
    if (fetchOne($pdo, 'SELECT id FROM accounts WHERE email = ?', [$email])) {
        fail('An account with this email already exists');
    }
    try {
        testAccountCredentials($in);
    } catch (Throwable $e) {
        fail($e->getMessage(), 401);
    }
    $key = getEncryptionKey();
    $enc = encryptPassword((string) ($in['password'] ?? ''), $key);
    $pdo->prepare('INSERT INTO accounts (email, display_name, imap_host, imap_port, imap_tls, smtp_host, smtp_port, smtp_tls, password_encrypted, password_iv, password_tag) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([
            $email,
            trim((string) ($in['display_name'] ?? '')) !== '' ? trim((string) $in['display_name']) : deriveDisplayName($email),
            trim((string) $in['imap_host']),
            (int) ($in['imap_port'] ?? 993),
            (int) ($in['imap_tls'] ?? TlsMode::Ssl->value),
            trim((string) $in['smtp_host']),
            (int) ($in['smtp_port'] ?? 587),
            (int) ($in['smtp_tls'] ?? TlsMode::StartTls->value),
            $enc['encrypted'],
            $enc['iv'],
            $enc['tag'],
        ]);
    $accountId = (int) $pdo->lastInsertId();
    try {
        syncAccountById($accountId, $pdo, 20, 25);
    } catch (Throwable $e) {
        // non-fatal
    }
    jsonResponse(['ok' => true, 'data' => ['id' => $accountId]]);
}

function apiDeleteAccount(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['account_id'] ?? 0);
    if ($id <= 0) fail('account_id required');
    $dir = EMMMAIL_ATTACH_DIR . '/' . $id;
    if (is_dir($dir)) {
        foreach (glob($dir . '/*') ?: [] as $sub) {
            if (is_dir($sub)) {
                foreach (glob($sub . '/*') ?: [] as $f) @unlink($f);
                @rmdir($sub);
            } else {
                @unlink($sub);
            }
        }
        @rmdir($dir);
    }
    $pdo->prepare('DELETE FROM accounts WHERE id = ?')->execute([$id]);
    if (isset($_SESSION['account_id']) && (int) $_SESSION['account_id'] === $id) {
        unset($_SESSION['account_id']);
    }
    jsonResponse(['ok' => true, 'data' => ['deleted' => true]]);
}

function apiTestConnection(): void
{
    $in = getInput();
    checkIpRateLimit(getClientIp(), 20);
    try {
        testAccountCredentials($in);
    } catch (Throwable $e) {
        fail($e->getMessage(), 401);
    }
    jsonResponse(['ok' => true, 'data' => ['connected' => true]]);
}

function apiListMessages(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['threads' => []]]);
    }
    $folder = (string) ($_GET['folder'] ?? '');
    $tagId = isset($_GET['tag_id']) ? (int) $_GET['tag_id'] : null;

    if ($folder === 'drafts') {
        $rows = fetchAll($pdo, 'SELECT * FROM drafts WHERE account_id = ? ORDER BY updated_at DESC', [$accountId]);
        $threads = [];
        foreach ($rows as $d) {
            $threads[] = [
                'id' => (int) $d['id'],
                'subject' => ($d['subject'] !== null && $d['subject'] !== '') ? $d['subject'] : '(no subject)',
                'date' => $d['updated_at'],
                'count' => 1,
                'preview' => strip_tags((string) ($d['body'] ?? '')),
                'unread' => false,
                'starred' => false,
                'sender_name' => 'Draft',
                'sender_email' => '',
                'has_attachments' => (bool) ($d['attachments_json'] && $d['attachments_json'] !== '[]'),
                'tag_ids' => [],
                'is_draft' => true,
            ];
        }
        jsonResponse(['ok' => true, 'data' => ['threads' => $threads]]);
    }

    $where = 't.account_id = ?';
    $params = [$accountId];
    switch ($folder) {
        case 'inbox':
            $where .= ' AND EXISTS (SELECT 1 FROM messages mm JOIN folders f ON f.account_id = mm.account_id AND f.name = mm.folder_name WHERE mm.thread_id = t.id AND f.role = "inbox")';
            break;
        case 'sent':
            $where .= ' AND EXISTS (SELECT 1 FROM messages mm JOIN folders f ON f.account_id = mm.account_id AND f.name = mm.folder_name WHERE mm.thread_id = t.id AND f.role = "sent")';
            break;
        case 'starred':
            $where .= ' AND t.is_starred = 1';
            break;
        case 'all':
            break;
        default:
            if ($tagId) {
                $where .= ' AND EXISTS (SELECT 1 FROM message_tags mt JOIN messages mm ON mm.id = mt.message_id WHERE mm.thread_id = t.id AND mt.tag_id = ?)';
                $params[] = $tagId;
            }
            break;
    }
    $threads = selectThreads($pdo, $where, $params);
    jsonResponse(['ok' => true, 'data' => ['threads' => $threads]]);
}

function apiGetThread(): void
{
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');

    $t = fetchOne($pdo, 'SELECT * FROM threads WHERE id = ?', [$id]);
    if ($t) {
        $msgs = fetchAll($pdo, 'SELECT * FROM messages WHERE thread_id = ? ORDER BY date ASC, id ASC', [$id]);
        $messages = array_map(fn($m) => messageToClient($pdo, $m), $msgs);
        $thread = [
            'id' => (int) $t['id'],
            'account_id' => (int) $t['account_id'],
            'subject' => $t['subject'] ?: '(no subject)',
            'starred' => (bool) $t['is_starred'],
            'unread' => (int) $t['is_read'] === 0,
            'message_count' => (int) $t['message_count'],
        ];
        jsonResponse(['ok' => true, 'data' => ['thread' => $thread, 'messages' => $messages]]);
    }

    $d = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ?', [$id]);
    if ($d) {
        $thread = ['id' => (int) $d['id'], 'subject' => ($d['subject'] ?: '(no subject)'), 'starred' => false, 'unread' => false, 'message_count' => 1];
        $message = [
            'id' => (int) $d['id'],
            'message_id' => null,
            'subject' => $d['subject'] ?: '(no subject)',
            'from_name' => 'Draft',
            'from_address' => '',
            'to_addresses' => parseJsonArray($d['to_addresses']),
            'cc_addresses' => parseJsonArray($d['cc_addresses']),
            'date' => $d['updated_at'],
            'text_body' => $d['body'],
            'html_body' => $d['html_body'],
            'is_read' => true,
            'is_starred' => false,
            'has_attachments' => (bool) ($d['attachments_json'] && $d['attachments_json'] !== '[]'),
            'size' => 0,
            'attachments' => [],
        ];
        jsonResponse(['ok' => true, 'data' => ['thread' => $thread, 'messages' => [$message]]]);
    }
    fail('Message not found', 404);
}

function apiGetMessage(): void
{
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $m = fetchOne($pdo, 'SELECT * FROM messages WHERE id = ?', [$id]);
    if ($m) {
        jsonResponse(['ok' => true, 'data' => messageToClient($pdo, $m)]);
    }
    $d = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ?', [$id]);
    if ($d) {
        jsonResponse(['ok' => true, 'data' => [
            'id' => (int) $d['id'],
            'subject' => $d['subject'] ?: '(no subject)',
            'from_name' => 'Draft',
            'from_address' => '',
            'to_addresses' => parseJsonArray($d['to_addresses']),
            'cc_addresses' => parseJsonArray($d['cc_addresses']),
            'date' => $d['updated_at'],
            'text_body' => $d['body'],
            'html_body' => $d['html_body'],
            'attachments' => [],
        ]]);
    }
    fail('Message not found', 404);
}

function apiSyncAccount(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    if ($accountId <= 0) fail('account_id required');
    try {
        syncAccountById($accountId, $pdo, 45);
        $pdo->prepare('UPDATE accounts SET sync_error = NULL, last_sync_at = datetime("now") WHERE id = ?')->execute([$accountId]);
    } catch (Throwable $e) {
        $pdo->prepare('UPDATE accounts SET sync_error = ? WHERE id = ?')->execute([substr($e->getMessage(), 0, 500), $accountId]);
        fail('Sync failed: ' . $e->getMessage(), 500);
    }
    $acct = fetchOne($pdo, 'SELECT last_sync_at, sync_error FROM accounts WHERE id = ?', [$accountId]);
    jsonResponse(['ok' => true, 'data' => ['account_id' => $accountId, 'last_sync_at' => $acct['last_sync_at'] ?? null, 'sync_error' => $acct['sync_error'] ?? null]]);
}

function apiMoveMessage(): void
{
    $in = getInput();
    $pdo = db();
    $messageId = (int) ($in['message_id'] ?? 0);
    $threadId = (int) ($in['thread_id'] ?? 0);
    $toFolder = trim((string) ($in['to_folder'] ?? ''));
    if ($toFolder === '') fail('to_folder required');
    $moved = 0;
    if ($threadId) {
        foreach (fetchAll($pdo, 'SELECT id FROM messages WHERE thread_id = ?', [$threadId]) as $m) {
            moveMessageFolder($pdo, (int) $m['id'], $toFolder);
            $moved++;
        }
    }
    if ($messageId) {
        moveMessageFolder($pdo, $messageId, $toFolder);
        $moved++;
    }
    if (!$moved) fail('No messages to move');
    jsonResponse(['ok' => true, 'data' => ['moved' => $moved]]);
}

function apiDeleteMessage(): void
{
    $in = getInput();
    $pdo = db();
    $threadId = (int) ($in['thread_id'] ?? 0);
    $messageId = (int) ($in['message_id'] ?? 0);
    $targets = [];
    if ($threadId) {
        foreach (fetchAll($pdo, 'SELECT * FROM messages WHERE thread_id = ?', [$threadId]) as $m) $targets[] = $m;
    }
    if ($messageId) {
        $m = fetchOne($pdo, 'SELECT * FROM messages WHERE id = ?', [$messageId]);
        if ($m) $targets[] = $m;
    }
    if (!$targets) fail('Message not found', 404);
    $pdo->beginTransaction();
    foreach ($targets as $m) {
        $trash = folderNameByRole($pdo, (int) $m['account_id'], 'trash', 'Trash');
        moveMessageFolder($pdo, (int) $m['id'], $trash);
    }
    $pdo->commit();
    jsonResponse(['ok' => true, 'data' => ['deleted' => count($targets)]]);
}

function apiExpungeFolder(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    $folder = trim((string) ($in['folder_name'] ?? ''));
    if ($folder === '') fail('folder_name required');
    $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$acct) fail('Account not found', 404);
    $pass = decryptPassword($acct, getEncryptionKey());
    if ($pass === '') fail('Could not decrypt password', 500);
    try {
        $imap = new ImapClient($acct['imap_host'], (int) $acct['imap_port'], (int) $acct['imap_tls'], $acct['email'], $pass, 20);
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

function apiBatchAction(): void
{
    $in = getInput();
    $pdo = db();
    $action = (string) ($in['action'] ?? '');
    $ids = array_values(array_unique(array_map('intval', $in['thread_ids'] ?? [])));
    if (!$ids) fail('No messages selected');
    $valid = ['archive', 'unarchive', 'delete', 'undelete', 'star', 'unstar', 'read', 'unread'];
    if (!in_array($action, $valid, true)) fail('Unknown action');

    $pdo->beginTransaction();
    try {
        foreach ($ids as $tid) {
            $msgs = fetchAll($pdo, 'SELECT * FROM messages WHERE thread_id = ?', [$tid]);
            if (!$msgs) continue;
            $accountId = (int) $msgs[0]['account_id'];
            switch ($action) {
                case 'archive':
                    $target = folderNameByRole($pdo, $accountId, 'archive', 'Archive');
                    foreach ($msgs as $m) moveMessageFolder($pdo, (int) $m['id'], $target);
                    break;
                case 'unarchive':
                case 'undelete':
                    $target = folderNameByRole($pdo, $accountId, 'inbox', 'INBOX');
                    foreach ($msgs as $m) moveMessageFolder($pdo, (int) $m['id'], $target);
                    break;
                case 'delete':
                    $target = folderNameByRole($pdo, $accountId, 'trash', 'Trash');
                    foreach ($msgs as $m) moveMessageFolder($pdo, (int) $m['id'], $target);
                    break;
                case 'star':
                    $pdo->prepare('UPDATE messages SET is_starred = 1 WHERE thread_id = ?')->execute([$tid]);
                    $pdo->prepare('UPDATE threads SET is_starred = 1 WHERE id = ?')->execute([$tid]);
                    break;
                case 'unstar':
                    $pdo->prepare('UPDATE messages SET is_starred = 0 WHERE thread_id = ?')->execute([$tid]);
                    $pdo->prepare('UPDATE threads SET is_starred = 0 WHERE id = ?')->execute([$tid]);
                    break;
                case 'read':
                    $pdo->prepare('UPDATE messages SET is_read = 1 WHERE thread_id = ?')->execute([$tid]);
                    $pdo->prepare('UPDATE threads SET is_read = 1 WHERE id = ?')->execute([$tid]);
                    break;
                case 'unread':
                    $pdo->prepare('UPDATE messages SET is_read = 0 WHERE thread_id = ?')->execute([$tid]);
                    $pdo->prepare('UPDATE threads SET is_read = 0 WHERE id = ?')->execute([$tid]);
                    break;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function apiSetFlags(): void
{
    $in = getInput();
    $pdo = db();
    $threadId = (int) ($in['thread_id'] ?? 0);
    $messageId = (int) ($in['message_id'] ?? 0);
    $changes = [];
    foreach (['read' => 'is_read', 'starred' => 'is_starred'] as $k => $col) {
        if (array_key_exists($k, $in)) $changes[$col] = $in[$k] ? 1 : 0;
    }
    if (!$changes) fail('No flags specified');
    if (!$threadId && !$messageId) fail('thread_id or message_id required');

    $all = [];
    if ($threadId) {
        applyFlagChanges($pdo, $threadId, $changes);
        $all = fetchAll($pdo, 'SELECT * FROM messages WHERE thread_id = ?', [$threadId]);
    }
    if ($messageId) {
        $m = fetchOne($pdo, 'SELECT * FROM messages WHERE id = ?', [$messageId]);
        if ($m) {
            if ((int) $m['thread_id'] > 0) applyFlagChanges($pdo, (int) $m['thread_id'], $changes);
            else {
                $setSql = implode('=?, ', array_keys($changes)) . '=?';
                $pdo->prepare('UPDATE messages SET ' . $setSql . ' WHERE id = ?')->execute([...array_values($changes), $messageId]);
            }
            $all[] = $m;
        }
    }
    pushFlagsToImap($pdo, $all, $changes);
    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function apiBatchFlags(): void
{
    $in = getInput();
    $pdo = db();
    $ids = array_values(array_unique(array_map('intval', $in['thread_ids'] ?? [])));
    if (!$ids) fail('thread_ids required');
    $changes = [];
    foreach (['read' => 'is_read', 'starred' => 'is_starred'] as $k => $col) {
        if (array_key_exists($k, $in)) $changes[$col] = $in[$k] ? 1 : 0;
    }
    if (!$changes) fail('No flags specified');

    $all = [];
    $cols = array_keys($changes);
    $setSql = implode('=?, ', $cols) . '=?';
    foreach ($ids as $tid) {
        $pdo->prepare('UPDATE messages SET ' . $setSql . ' WHERE thread_id = ?')->execute([...array_values($changes), $tid]);
        $pdo->prepare('UPDATE threads SET ' . $setSql . ' WHERE id = ?')->execute([...array_values($changes), $tid]);
        foreach (fetchAll($pdo, 'SELECT * FROM messages WHERE thread_id = ?', [$tid]) as $m) $all[] = $m;
    }
    pushFlagsToImap($pdo, $all, $changes);
    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function apiListTags(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['tags' => []]]);
    }
    $rows = fetchAll($pdo, 'SELECT id, name, color FROM tags WHERE account_id = ? ORDER BY name COLLATE NOCASE', [$accountId]);
    $tags = array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'color' => $r['color']], $rows);
    jsonResponse(['ok' => true, 'data' => ['tags' => $tags]]);
}

function apiCreateTag(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    if ($accountId <= 0) fail('No account selected');
    $name = trim((string) ($in['name'] ?? ''));
    if ($name === '') fail('Tag name required');
    $color = trim((string) ($in['color'] ?? '#4f8cff'));
    if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $color)) $color = '#4f8cff';
    try {
        $pdo->prepare('INSERT INTO tags (account_id, name, color) VALUES (?, ?, ?)')->execute([$accountId, $name, $color]);
    } catch (Throwable $e) {
        fail('A tag with this name already exists');
    }
    jsonResponse(['ok' => true, 'data' => ['id' => (int) $pdo->lastInsertId()]]);
}

function apiRenameTag(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    $name = trim((string) ($in['name'] ?? ''));
    if ($id <= 0 || $name === '') fail('id and name required');
    try {
        $pdo->prepare('UPDATE tags SET name = ? WHERE id = ?')->execute([$name, $id]);
    } catch (Throwable $e) {
        fail('A tag with this name already exists');
    }
    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function apiDeleteTag(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $pdo->prepare('DELETE FROM tags WHERE id = ?')->execute([$id]);
    jsonResponse(['ok' => true, 'data' => ['deleted' => true]]);
}

function apiTagMessage(): void
{
    $in = getInput();
    $pdo = db();
    $messageId = (int) ($in['message_id'] ?? 0);
    $tagId = (int) ($in['tag_id'] ?? 0);
    if ($messageId <= 0 || $tagId <= 0) fail('message_id and tag_id required');
    $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([$messageId, $tagId]);
    jsonResponse(['ok' => true, 'data' => ['tagged' => true]]);
}

function apiUntagMessage(): void
{
    $in = getInput();
    $pdo = db();
    $messageId = (int) ($in['message_id'] ?? 0);
    $tagId = (int) ($in['tag_id'] ?? 0);
    if ($messageId <= 0 || $tagId <= 0) fail('message_id and tag_id required');
    $pdo->prepare('DELETE FROM message_tags WHERE message_id = ? AND tag_id = ?')->execute([$messageId, $tagId]);
    jsonResponse(['ok' => true, 'data' => ['untagged' => true]]);
}

function apiBatchTag(): void
{
    $in = getInput();
    $pdo = db();
    $ids = array_values(array_unique(array_map('intval', $in['thread_ids'] ?? [])));
    $tagIds = array_values(array_unique(array_map('intval', $in['tag_ids'] ?? [])));
    if (!$ids || !$tagIds) fail('thread_ids and tag_ids required');
    $remove = !empty($in['remove']);

    $pdo->beginTransaction();
    foreach ($ids as $tid) {
        $rows = fetchAll($pdo, 'SELECT id FROM messages WHERE thread_id = ?', [$tid]);
        foreach ($rows as $mr) {
            foreach ($tagIds as $tagId) {
                if ($remove) {
                    $pdo->prepare('DELETE FROM message_tags WHERE message_id = ? AND tag_id = ?')->execute([(int) $mr['id'], $tagId]);
                } else {
                    $pdo->prepare('INSERT OR IGNORE INTO message_tags (message_id, tag_id) VALUES (?, ?)')->execute([(int) $mr['id'], $tagId]);
                }
            }
        }
    }
    $pdo->commit();
    jsonResponse(['ok' => true, 'data' => ['updated' => true]]);
}

function apiListContacts(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['contacts' => []]]);
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q !== '') {
        $rows = fetchAll($pdo, 'SELECT * FROM contacts WHERE account_id = ? AND (email LIKE ? OR name LIKE ?) ORDER BY use_count DESC, email LIMIT 300', [$accountId, '%' . $q . '%', '%' . $q . '%']);
    } else {
        $rows = fetchAll($pdo, 'SELECT * FROM contacts WHERE account_id = ? ORDER BY use_count DESC, email LIMIT 300', [$accountId]);
    }
    $contacts = array_map(fn($r) => ['name' => $r['name'], 'email' => $r['email'], 'use_count' => (int) $r['use_count']], $rows);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}

function apiSearchContacts(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['contacts' => []]]);
    }
    $q = trim((string) ($_GET['q'] ?? ''));
    if ($q === '') {
        jsonResponse(['ok' => true, 'data' => ['contacts' => []]]);
    }
    $rows = fetchAll($pdo, 'SELECT * FROM contacts WHERE account_id = ? AND (email LIKE ? OR name LIKE ?) ORDER BY use_count DESC LIMIT 10', [$accountId, '%' . $q . '%', '%' . $q . '%']);
    $contacts = array_map(fn($r) => ['name' => $r['name'], 'email' => $r['email']], $rows);
    jsonResponse(['ok' => true, 'data' => ['contacts' => $contacts]]);
}

function apiSearch(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['threads' => []]]);
    }

    $terms = [];
    $text = trim((string) ($_GET['text'] ?? ''));
    if ($text !== '') $terms[] = '"' . str_replace('"', ' ', $text) . '"';
    foreach (['from' => 'from_address', 'to' => 'to_addresses', 'subject' => 'subject'] as $param => $col) {
        $v = trim((string) ($_GET[$param] ?? ''));
        if ($v !== '') $terms[] = $col . ':"' . str_replace('"', ' ', $v) . '"';
    }
    if (!$terms) {
        $q = trim((string) ($_GET['q'] ?? ''));
        if ($q !== '') $terms[] = '"' . str_replace('"', ' ', $q) . '"';
    }
    $has = strtolower((string) ($_GET['has'] ?? ''));
    $date = trim((string) ($_GET['date'] ?? ''));

    if (!$terms && $has !== 'attachment' && $date === '') {
        fail('Search query required');
    }

    $where = 't.account_id = ?';
    $params = [$accountId];
    if ($has === 'attachment') $where .= ' AND m.has_attachments = 1';
    if ($date !== '') {
        $where .= ' AND m.date LIKE ?';
        $params[] = $date . '%';
    }

    try {
        if ($terms) {
            $match = implode(' AND ', $terms);
            $st = $pdo->prepare("SELECT DISTINCT t.id FROM messages_fts f JOIN messages m ON m.id = f.rowid JOIN threads t ON t.id = m.thread_id WHERE " . $where . " AND messages_fts MATCH ?");
            $st->execute([...$params, $match]);
        } else {
            $st = $pdo->prepare("SELECT DISTINCT t.id FROM messages m JOIN threads t ON t.id = m.thread_id WHERE " . $where);
            $st->execute($params);
        }
        $ids = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        jsonResponse(['ok' => true, 'data' => ['threads' => []]]);
    }

    if (!$ids) {
        jsonResponse(['ok' => true, 'data' => ['threads' => []]]);
    }
    $in = implode(',', array_map('intval', $ids));
    $threads = selectThreads($pdo, 't.id IN (' . $in . ')', []);
    jsonResponse(['ok' => true, 'data' => ['threads' => $threads]]);
}

function apiGetAttachment(): void
{
    $pdo = db();
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $row = fetchOne($pdo, 'SELECT a.* FROM attachments a JOIN messages m ON m.id = a.message_id WHERE a.id = ?', [$id]);
    if (!$row) fail('Attachment not found', 404);

    $root = realpath(EMMMAIL_ATTACH_DIR);
    $abs = EMMMAIL_ATTACH_DIR . '/' . $row['file_path'];
    $real = realpath($abs);
    if ($real === false || $root === false || !str_starts_with($real, $root) || !is_file($real)) {
        fail('File not found', 404);
    }
    $filename = sanitizeFilename((string) $row['filename']);
    if ($filename === '') $filename = 'attachment';

    http_response_code(200);
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename*=UTF-8\'\'' . rawurlencode($filename));
    header('Content-Length: ' . (string) filesize($real));
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Cache-Control: private, no-store');
    header('Pragma: no-cache');
    readfile($real);
    exit;
}

function apiUploadAttachment(): void
{
    if (empty($_FILES['file'])) fail('No file uploaded');
    $f = $_FILES['file'];
    if ((int) $f['error'] !== UPLOAD_ERR_OK) fail('Upload failed');
    if ((int) $f['size'] > EMMMAIL_MAX_ATTACH_SIZE) fail('File too large (max 25MB)');
    $filename = sanitizeFilename((string) ($f['name'] ?? 'attachment'));
    if ($filename === '') $filename = 'attachment.bin';
    if (isBlockedAttachment($filename)) $filename .= '.txt';

    $tmpDir = EMMMAIL_ATTACH_DIR . '/tmp';
    if (!is_dir($tmpDir)) @mkdir($tmpDir, 0755, true);
    $tmpPath = $tmpDir . '/' . bin2hex(random_bytes(16));
    if (!move_uploaded_file($f['tmp_name'], $tmpPath)) fail('Could not store upload');

    $ct = 'application/octet-stream';
    if (function_exists('mime_content_type')) {
        $detected = @mime_content_type($tmpPath);
        if ($detected !== false && $detected !== '') $ct = $detected;
    } elseif (!empty($f['type'])) {
        $ct = $f['type'];
    }
    jsonResponse(['ok' => true, 'data' => ['filename' => $filename, 'content_type' => $ct, 'size' => (int) $f['size'], 'tmp_path' => $tmpPath]]);
}

function apiGetSettings(): void
{
    $pdo = db();
    jsonResponse(['ok' => true, 'data' => ['settings' => getSettingsArray($pdo)]]);
}

function apiUpdateSettings(): void
{
    $in = getInput();
    $pdo = db();
    $allowed = ['theme', 'show_remote_images', 'sync_on_load', 'update_notifications', 'update_url'];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $in)) {
            $v = $in[$k];
            if (is_bool($v)) $v = $v ? '1' : '0';
            $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
                ->execute([$k, (string) $v]);
        }
    }
    jsonResponse(['ok' => true, 'data' => ['settings' => getSettingsArray($pdo)]]);
}

function apiGetSignature(): void
{
    $pdo = db();
    $settings = getSettingsArray($pdo);
    jsonResponse(['ok' => true, 'data' => ['signature' => $settings['signature'] ?? '']]);
}

function apiUpdateSignature(): void
{
    $in = getInput();
    $pdo = db();
    $signature = (string) ($in['signature'] ?? '');
    $pdo->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute(['signature', $signature]);
    jsonResponse(['ok' => true, 'data' => ['signature' => $signature]]);
}

function apiSendMessage(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$acct) fail('Account not found');

    $to = sanitizeRecipients($in['to_addresses'] ?? []);
    $cc = sanitizeRecipients($in['cc_addresses'] ?? []);
    $bcc = sanitizeRecipients($in['bcc_addresses'] ?? []);
    if (!$to && !$cc && !$bcc) fail('No valid recipients');

    $pdo->prepare('INSERT INTO outbox (account_id, from_address, to_addresses, cc_addresses, bcc_addresses, subject, body, html_body, in_reply_to_message_id, attachments_json, send_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, datetime("now"))')
        ->execute([
            $accountId, $acct['email'],
            json_encode($to), json_encode($cc), json_encode($bcc),
            cleanHeaderValue((string) ($in['subject'] ?? '(no subject)')),
            (string) ($in['text_body'] ?? ''),
            (string) ($in['html_body'] ?? ''),
            cleanHeaderValue((string) ($in['in_reply_to_message_id'] ?? '')),
            json_encode($in['attachments_json'] ?? []),
        ]);
    $id = (int) $pdo->lastInsertId();

    $result = sendOutboxItem($pdo, $id);
    if ($result['status'] === 'sent') {
        jsonResponse(['ok' => true, 'data' => ['sent' => true]]);
    }
    fail($result['error'] ?? 'Send failed', 500);
}

function apiScheduleSend(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    $acct = fetchOne($pdo, 'SELECT * FROM accounts WHERE id = ?', [$accountId]);
    if (!$acct) fail('Account not found');

    $to = sanitizeRecipients($in['to_addresses'] ?? []);
    $cc = sanitizeRecipients($in['cc_addresses'] ?? []);
    $bcc = sanitizeRecipients($in['bcc_addresses'] ?? []);
    if (!$to && !$cc && !$bcc) fail('No valid recipients');

    $pdo->prepare('INSERT INTO outbox (account_id, from_address, to_addresses, cc_addresses, bcc_addresses, subject, body, html_body, in_reply_to_message_id, attachments_json, send_at, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "pending")')
        ->execute([
            $accountId, $acct['email'],
            json_encode($to), json_encode($cc), json_encode($bcc),
            cleanHeaderValue((string) ($in['subject'] ?? '(no subject)')),
            (string) ($in['text_body'] ?? ''),
            (string) ($in['html_body'] ?? ''),
            cleanHeaderValue((string) ($in['in_reply_to_message_id'] ?? '')),
            json_encode($in['attachments_json'] ?? []),
            gmdate('Y-m-d H:i:s', time() + EMMMAIL_SEND_DELAY),
        ]);
    $id = (int) $pdo->lastInsertId();
    jsonResponse(['ok' => true, 'data' => ['id' => $id, 'delay' => EMMMAIL_SEND_DELAY]]);
}

function apiCancelSend(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $st = $pdo->prepare("UPDATE outbox SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
    $st->execute([$id]);
    if ($st->rowCount() === 0) {
        $row = fetchOne($pdo, 'SELECT status FROM outbox WHERE id = ?', [$id]);
        if ($row && $row['status'] === 'sent') fail('Message already sent', 409);
        fail('Message cannot be cancelled', 409);
    }
    jsonResponse(['ok' => true, 'data' => ['cancelled' => true]]);
}

function apiProcessOutbox(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    if (!$id) {
        processOutboxDue($pdo);
        jsonResponse(['ok' => true, 'data' => ['status' => 'processed']]);
    }
    $result = sendOutboxItem($pdo, $id);
    if ($result['status'] === 'sent') {
        jsonResponse(['ok' => true, 'data' => ['status' => 'sent']]);
    }
    if ($result['status'] === 'failed') {
        fail($result['error'] ?? 'Send failed', 500);
    }
    if ($result['status'] === 'busy') {
        fail('Message is already being sent', 409);
    }
    fail('Message cannot be sent', 409);
}

function apiSaveDraft(): void
{
    $in = getInput();
    $pdo = db();
    $accountId = activeAccountId((int) ($in['account_id'] ?? 0));
    if (!fetchOne($pdo, 'SELECT id FROM accounts WHERE id = ?', [$accountId])) fail('Account not found');

    $draftId = (int) ($in['draft_id'] ?? 0);
    $to = json_encode(sanitizeRecipients($in['to_addresses'] ?? []));
    $cc = json_encode(sanitizeRecipients($in['cc_addresses'] ?? []));
    $bcc = json_encode(sanitizeRecipients($in['bcc_addresses'] ?? []));
    $subject = cleanHeaderValue((string) ($in['subject'] ?? ''));
    $body = (string) ($in['text_body'] ?? '');
    $html = (string) ($in['html_body'] ?? '');
    $inReply = cleanHeaderValue((string) ($in['in_reply_to_message_id'] ?? ''));
    $atts = json_encode($in['attachments_json'] ?? []);

    if ($draftId > 0) {
        $pdo->prepare('UPDATE drafts SET account_id = ?, to_addresses = ?, cc_addresses = ?, bcc_addresses = ?, subject = ?, body = ?, html_body = ?, in_reply_to_message_id = ?, attachments_json = ?, updated_at = datetime("now") WHERE id = ?')
            ->execute([$accountId, $to, $cc, $bcc, $subject, $body, $html, $inReply, $atts, $draftId]);
    } else {
        $pdo->prepare('INSERT INTO drafts (account_id, to_addresses, cc_addresses, bcc_addresses, subject, body, html_body, in_reply_to_message_id, attachments_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$accountId, $to, $cc, $bcc, $subject, $body, $html, $inReply, $atts]);
        $draftId = (int) $pdo->lastInsertId();
    }
    jsonResponse(['ok' => true, 'data' => ['id' => $draftId]]);
}

function apiListDrafts(): void
{
    $pdo = db();
    $accountId = activeAccountId((int) ($_GET['account_id'] ?? 0));
    if ($accountId <= 0) {
        jsonResponse(['ok' => true, 'data' => ['drafts' => []]]);
    }
    $rows = fetchAll($pdo, 'SELECT * FROM drafts WHERE account_id = ? ORDER BY updated_at DESC', [$accountId]);
    $drafts = array_map(fn($d) => [
        'id' => (int) $d['id'],
        'subject' => $d['subject'],
        'to_addresses' => parseJsonArray($d['to_addresses']),
        'cc_addresses' => parseJsonArray($d['cc_addresses']),
        'bcc_addresses' => parseJsonArray($d['bcc_addresses']),
        'text_body' => $d['body'],
        'html_body' => $d['html_body'],
        'in_reply_to_message_id' => $d['in_reply_to_message_id'],
        'attachments_json' => parseJsonArray($d['attachments_json']),
        'updated_at' => $d['updated_at'],
    ], $rows);
    jsonResponse(['ok' => true, 'data' => ['drafts' => $drafts]]);
}

function apiDeleteDraft(): void
{
    $in = getInput();
    $pdo = db();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) fail('id required');
    $d = fetchOne($pdo, 'SELECT * FROM drafts WHERE id = ?', [$id]);
    if ($d) {
        cleanupAttachmentFiles($pdo, $d);
        $pdo->prepare('DELETE FROM drafts WHERE id = ?')->execute([$id]);
    }
    jsonResponse(['ok' => true, 'data' => ['deleted' => true]]);
}

function apiCheckUpdate(): void
{
    $pdo = db();
    $settings = getSettingsArray($pdo);
    jsonResponse(['ok' => true, 'data' => [
        'current' => EMMMAIL_VERSION,
        'latest' => EMMMAIL_VERSION,
        'update_available' => false,
        'update_url' => $settings['update_url'] ?? '',
    ]]);
}

function apiApplyUpdate(): void
{
    $pdo = db();
    $url = trim((string) (getSettingsArray($pdo)['update_url'] ?? ''));
    if ($url === '') fail('No update source configured. Set the "update_url" setting first.', 400);

    $ctx = stream_context_create(['http' => ['timeout' => 20, 'follow_location' => 1, 'user_agent' => 'Emmmail/' . EMMMAIL_VERSION]]);
    $newCode = @file_get_contents($url, false, $ctx);
    if ($newCode === false || $newCode === '') fail('Could not download update');

    $tmp = EMMMAIL_ROOT . '/.update-' . bin2hex(random_bytes(8)) . '.php';
    file_put_contents($tmp, $newCode, LOCK_EX);
    exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    if ($code !== 0) {
        @unlink($tmp);
        fail('Downloaded file failed PHP syntax check: ' . implode(' ', $out));
    }
    if (!str_contains($newCode, 'serveFrontend')) {
        @unlink($tmp);
        fail('Downloaded file does not look like an Emmmail build', 400);
    }
    $backup = EMMMAIL_ROOT . '/index.php.bak';
    @copy(EMMMAIL_ROOT . '/index.php', $backup);
    if (!@rename($tmp, EMMMAIL_ROOT . '/index.php')) {
        @unlink($tmp);
        fail('Could not replace index.php', 500);
    }
    jsonResponse(['ok' => true, 'data' => ['updated' => true, 'version' => EMMMAIL_VERSION, 'backup' => basename($backup)]]);
}

function serveFrontendRoute(): void
{
    header('Content-Type: text/html; charset=utf-8');
    echo serveFrontend();
}function serveFrontend(): string
{
    $html = <<<'EMMMAIL_HTML'
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0b0d12">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline' https://cdn.jsdelivr.net; font-src https://cdn.jsdelivr.net; img-src 'self' data: blob:; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'">
<title>Emmmail</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/dist/tabler-icons.min.css">
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='8' fill='%234f8cff'/%3E%3Ctext x='16' y='22' font-size='17' font-family='Arial' font-weight='bold' text-anchor='middle' fill='white'%3E✉%3C/text%3E%3C/svg%3E">
<script>
(function () {
  try {
    var t = localStorage.getItem('emmmail-theme') || 'dark';
    document.documentElement.setAttribute('data-theme', t);
    var m = document.querySelector('meta[name="theme-color"]');
    if (m) m.setAttribute('content', t === 'light' ? '#ffffff' : '#0b0d12');
  } catch (e) {}
})();
</script>
<style>
*, *::before, *::after { box-sizing: border-box; }
:root, [data-theme="dark"] {
  --bg: #0b0d12;
  --surface: #141720;
  --surface-hover: #1c2030;
  --border: #262b3a;
  --text: #e6e8ef;
  --text-secondary: #9aa1b5;
  --primary: #4f8cff;
  --primary-hover: #6b9dff;
  --green: #34d399;
  --red: #f87171;
  --yellow: #fbbf24;
  --blue: #60a5fa;
  --radius: 10px;
  --shadow: 0 8px 28px rgba(0, 0, 0, .45);
  --shadow-sm: 0 2px 10px rgba(0, 0, 0, .3);
  --transition: .18s ease;
  color-scheme: dark;
}
[data-theme="light"] {
  --bg: #f6f8fc;
  --surface: #ffffff;
  --surface-hover: #f0f3f9;
  --border: #dde3ee;
  --text: #1f2430;
  --text-secondary: #5b6478;
  --primary: #1a73e8;
  --primary-hover: #1765cc;
  --green: #188038;
  --red: #d93025;
  --yellow: #f29900;
  --blue: #1a73e8;
  --shadow: 0 8px 28px rgba(15, 23, 42, .14);
  --shadow-sm: 0 2px 10px rgba(15, 23, 42, .08);
  color-scheme: light;
}
html, body { height: 100%; }
body {
  margin: 0;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
  font-size: 15px;
  line-height: 1.5;
  background: var(--bg);
  color: var(--text);
  -webkit-font-smoothing: antialiased;
  overscroll-behavior-y: none;
}
body, .topbar, .sidebar, .pane, .message-card, .compose-window, .modal, .toast,
.account-card, .auth-card, .nav-item, .tag, .chip, .search-bar, .bottom-tabs {
  transition: background-color .25s ease, color .25s ease, border-color .25s ease;
}
:focus-visible {
  outline: 2px solid var(--primary);
  outline-offset: 2px;
}
button, input, textarea, select { -webkit-tap-highlight-color: transparent; }
a { color: var(--primary); text-decoration: none; }
a:hover { text-decoration: underline; }
button { font-family: inherit; }
input, textarea, select { font-family: inherit; }
::-webkit-scrollbar { width: 10px; height: 10px; }
::-webkit-scrollbar-thumb { background: var(--border); border-radius: 6px; }
::-webkit-scrollbar-track { background: transparent; }
.hidden { display: none !important; }

#app {
  position: relative;
  display: grid;
  grid-template-columns: 240px 1fr;
  grid-template-rows: 56px minmax(0, 1fr);
  grid-template-areas:
    "topbar topbar"
    "sidebar list";
  height: 100vh;
  height: 100dvh;
  width: 100%;
  overflow: hidden;
}
body.detail-view #app {
  grid-template-columns: 240px 360px minmax(0, 1fr);
  grid-template-areas:
    "topbar topbar topbar"
    "sidebar list detail";
}
.pane-detail { display: none; }
body.detail-view .pane-detail { display: block; }

.topbar {
  grid-area: topbar;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 0 12px;
  background: var(--surface);
  border-bottom: 1px solid var(--border);
  z-index: 30;
}
.topbar-left { display: flex; align-items: center; gap: 8px; min-width: 0; }
.topbar-icon, .topbar-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 40px;
  height: 40px;
  border: none;
  background: transparent;
  color: var(--text-secondary);
  font-size: 18px;
  border-radius: 50%;
  cursor: pointer;
  transition: background var(--transition), color var(--transition);
}
.topbar-icon:hover, .topbar-avatar:hover { background: var(--surface-hover); color: var(--text); }
.topbar-avatar { width: 36px; height: 36px; font-size: 14px; font-weight: 600; color: #fff; }
.logo { display: flex; align-items: center; gap: 8px; font-weight: 700; font-size: 17px; color: var(--text); white-space: nowrap; }
.logo:hover { text-decoration: none; }
.logo-mark {
  display: inline-flex; align-items: center; justify-content: center;
  width: 30px; height: 30px; border-radius: 8px; background: var(--primary); color: #fff; font-size: 16px;
}
.topbar-right { display: flex; align-items: center; gap: 4px; margin-left: auto; }
.avatar-menu-wrap, .notif-menu-wrap { position: relative; }

.search-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 0 1 520px;
  height: 40px;
  padding: 0 14px;
  margin: 0 8px;
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 999px;
  transition: border-color var(--transition), background var(--transition);
}
.search-bar:focus-within { border-color: var(--primary); background: var(--surface); }
.search-bar .search-icon { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 15px; padding: 4px; }
.search-bar input {
  flex: 1;
  min-width: 0;
  border: none;
  outline: none;
  background: transparent;
  color: var(--text);
  font-size: 14px;
}
.search-close { background: none; border: none; color: var(--text-secondary); cursor: pointer; font-size: 14px; padding: 4px; }
#btn-search { display: none; }

.menu-dropdown {
  position: absolute;
  top: calc(100% + 8px);
  right: 0;
  min-width: 280px;
  max-height: 420px;
  overflow-y: auto;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 12px;
  box-shadow: var(--shadow);
  z-index: 80;
  padding: 6px;
  display: none;
}
.menu-dropdown.open { display: block; animation: menu-in .15s ease; }
@keyframes menu-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
.menu-item {
  display: flex;
  align-items: center;
  gap: 10px;
  width: 100%;
  padding: 10px 12px;
  border: none;
  background: none;
  color: var(--text);
  font-size: 14px;
  text-align: left;
  border-radius: 8px;
  cursor: pointer;
}
.menu-item:hover { background: var(--surface-hover); }
.menu-item .avatar { width: 30px; height: 30px; font-size: 12px; flex-shrink: 0; }
.menu-item .mi-main { flex: 1; min-width: 0; }
.menu-item .mi-sub { display: block; font-size: 12px; color: var(--text-secondary); }
.menu-item .mi-check { color: var(--primary); }
.menu-divider { height: 1px; background: var(--border); margin: 6px 8px; }
.menu-title { padding: 8px 12px 4px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: var(--text-secondary); }

.sidebar {
  grid-area: sidebar;
  background: var(--surface);
  border-right: 1px solid var(--border);
  overflow-y: auto;
  z-index: 20;
}
.sidebar-inner { display: flex; flex-direction: column; height: 100%; padding: 12px 10px; }
.account-switcher {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px;
  border-radius: var(--radius);
  cursor: pointer;
  margin-bottom: 10px;
  transition: background var(--transition);
}
.account-switcher:hover { background: var(--surface-hover); }
.as-info { flex: 1; min-width: 0; }
.as-email { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.as-name { font-size: 12px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.as-chev { color: var(--text-secondary); font-size: 12px; }
.sidebar-title {
  padding: 14px 10px 6px;
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: .08em;
  color: var(--text-secondary);
}
.folder-list, .tag-list { display: flex; flex-direction: column; gap: 2px; }
.nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 9px 12px;
  border-radius: 8px;
  color: var(--text);
  font-size: 14px;
  border: none;
  background: none;
  cursor: pointer;
  text-align: left;
  transition: background var(--transition);
}
.nav-item:hover { background: var(--surface-hover); text-decoration: none; }
.nav-item.active { background: color-mix(in srgb, var(--primary) 18%, transparent); color: var(--primary); font-weight: 600; }
.nav-icon { width: 20px; text-align: center; font-size: 15px; flex-shrink: 0; }
.nav-item .badge { margin-left: auto; }
.tag { display: flex; align-items: center; gap: 10px; padding: 8px 12px; border-radius: 8px; font-size: 14px; color: var(--text); }
.tag::before { content: "#"; opacity: .7; }
.tag:hover { background: var(--surface-hover); text-decoration: none; }
.tag.active { background: color-mix(in srgb, var(--tag-color, var(--primary)) 20%, transparent); color: var(--tag-color, var(--primary)); font-weight: 600; }
.add-tag { color: var(--text-secondary); }
.sidebar-footer { margin-top: auto; padding-top: 12px; border-top: 1px solid var(--border); display: flex; flex-direction: column; gap: 2px; }
.sidebar-app-footer { padding: 12px 16px; font-size: 11px; color: var(--text-secondary); text-align: center; line-height: 1.4; }
.sidebar-app-footer a { color: var(--text-secondary); }
.sidebar-app-footer a:hover { color: var(--primary); }
.sidebar-app-footer p + p { margin-top: 4px; }
.sidebar-backdrop { display: none; }

.pane { overflow-y: auto; min-width: 0; }
.pane-list { grid-area: list; border-right: 1px solid var(--border); background: var(--bg); }
.pane-detail { grid-area: detail; background: var(--bg); }

.list-header { position: sticky; top: 0; z-index: 10; background: var(--bg); padding: 14px 16px 10px; border-bottom: 1px solid var(--border); }
.list-header-top { display: flex; align-items: center; justify-content: space-between; gap: 10px; }
.list-header h1 { margin: 0; font-size: 20px; font-weight: 700; }
.list-subtitle { margin-top: 2px; font-size: 12.5px; color: var(--text-secondary); }
.list-actions { display: flex; align-items: center; gap: 2px; }
.list-select-bar { display: flex; align-items: center; gap: 12px; margin-top: 10px; padding: 8px 10px; background: var(--surface); border-radius: 8px; }
.sel-count { font-size: 13px; color: var(--text-secondary); flex: 1; }

.message-list { padding-bottom: 12px; }
.message-row {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--border);
  cursor: pointer;
  position: relative;
  transition: background var(--transition);
}
.message-row:hover { background: var(--surface-hover); }
.message-row:active { background: color-mix(in srgb, var(--primary) 8%, transparent); }
.message-row.selected { background: color-mix(in srgb, var(--primary) 12%, transparent); }
.message-row.focused { background: var(--surface-hover); box-shadow: inset 3px 0 0 var(--primary); }
.message-row .row-main { flex: 1; min-width: 0; }
.message-row.unread .row-sender, .message-row.unread .row-subject { font-weight: 700; }
.message-row.unread .row-subject::after {
  content: ""; display: inline-block; width: 8px; height: 8px; margin-left: 8px;
  border-radius: 50%; background: var(--primary); vertical-align: 1px;
}
.row-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.row-sender { font-size: 14px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.row-date { font-size: 12px; color: var(--text-secondary); white-space: nowrap; flex-shrink: 0; }
.row-subject { font-size: 14px; margin-top: 1px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.row-count { color: var(--text-secondary); font-size: 12px; font-weight: 400; }
.row-preview { font-size: 13px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 1px; }
.star-btn {
  border: none; background: none; cursor: pointer; font-size: 17px; color: var(--border);
  padding: 6px; line-height: 1; border-radius: 50%; transition: color var(--transition), transform var(--transition);
  flex-shrink: 0;
}
.star-btn:hover { transform: scale(1.15); }
.star-btn.on { color: var(--yellow); }
.tag-dots { display: flex; gap: 4px; flex-shrink: 0; align-items: center; }
.tag-dot { width: 8px; height: 8px; border-radius: 50%; }

.avatar {
  display: inline-flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border-radius: 50%; color: #fff;
  font-size: 14px; font-weight: 600; flex-shrink: 0; user-select: none;
}
.avatar-sm { width: 28px; height: 28px; font-size: 11px; }
.avatar-lg { width: 46px; height: 46px; font-size: 17px; }
.badge {
  display: inline-flex; align-items: center; justify-content: center;
  min-width: 20px; height: 20px; padding: 0 6px; border-radius: 10px;
  background: var(--primary); color: #fff; font-size: 11.5px; font-weight: 700;
}
.badge.green { background: var(--green); }
.badge.red { background: var(--red); }
.badge.blue { background: var(--blue); }
.btn-sm { padding: 6px 12px; min-height: 32px; font-size: 13px; }
.text-secondary { color: var(--text-secondary); }
.divider { height: 1px; background: var(--border); margin: 10px 0; }
.sync-status { padding: 10px 16px; font-size: 12px; color: var(--text-secondary); text-align: center; }
.sync-status.error { color: var(--red); }

.thread-view { max-width: 860px; margin: 0 auto; padding-bottom: 80px; }
.thread-header {
  display: flex; align-items: flex-start; gap: 12px;
  padding: 16px 20px 12px; border-bottom: 1px solid var(--border);
  position: sticky; top: 0; background: var(--bg); z-index: 10;
}
.thread-title-block { flex: 1; min-width: 0; }
.thread-subject { margin: 0; font-size: 20px; font-weight: 700; line-height: 1.3; word-break: break-word; }
.thread-participants { font-size: 13px; color: var(--text-secondary); margin-top: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.thread-actions { display: flex; gap: 4px; flex-shrink: 0; }
.back-btn {
  display: inline-flex; align-items: center; justify-content: center;
  width: 38px; height: 38px; border: none; background: none; color: var(--text);
  font-size: 20px; border-radius: 50%; cursor: pointer; flex-shrink: 0;
}
.back-btn:hover { background: var(--surface-hover); }
.message-stack { padding: 10px 16px; display: flex; flex-direction: column; gap: 12px; }
.message-card {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  overflow: hidden; transition: box-shadow var(--transition);
}
.message-card:hover { box-shadow: var(--shadow-sm); }
.message-card.collapsed .msg-body, .message-card.collapsed .attachment-strip, .message-card.collapsed .msg-toolbar { display: none; }
.message-card.collapsed .msg-collapsed-bar { display: flex; }
.msg-collapsed-bar { display: none; align-items: center; gap: 10px; padding: 10px 14px; font-size: 13px; cursor: pointer; color: var(--text-secondary); }
.msg-header { display: flex; gap: 10px; padding: 12px 14px 0; align-items: flex-start; }
.msg-id { flex: 1; min-width: 0; }
.msg-from { font-size: 14px; font-weight: 600; }
.msg-email { font-size: 12.5px; color: var(--text-secondary); font-weight: 400; }
.msg-to { font-size: 12.5px; color: var(--text-secondary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.msg-date { font-size: 12.5px; color: var(--text-secondary); }
.msg-actions { flex-shrink: 0; }
.msg-collapse-btn { border: none; background: none; color: var(--text-secondary); cursor: pointer; font-size: 14px; padding: 4px 6px; border-radius: 6px; }
.msg-collapse-btn:hover { background: var(--surface-hover); }
.msg-body { padding: 10px 14px 4px; font-size: 14.5px; word-break: break-word; overflow-wrap: anywhere; }
.msg-body a { color: var(--blue); }
.msg-body img { max-width: 100%; height: auto; border-radius: 6px; }
.text-body > div { margin: 0 0 6px; }
.text-body blockquote, .quoted {
  border-left: 3px solid var(--border); margin: 8px 0 8px 6px; padding: 2px 0 2px 12px;
  color: var(--text-secondary); font-size: 13.5px;
}
.quote-toggle { border: none; background: none; color: var(--primary); cursor: pointer; font-size: 12.5px; padding: 2px 0; }
.quote-body { display: block; }
blockquote .quote-body.hidden { display: none; }
.html-body-wrap { position: relative; }
.html-body { width: 100%; height: 360px; border: 1px solid var(--border); border-radius: 8px; background: #fff; }
.image-block { padding: 4px 0 8px; }
.attachment-strip { display: flex; flex-wrap: wrap; gap: 8px; padding: 6px 14px 4px; }
.attachment-chip {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 12px; border-radius: 999px; background: var(--bg);
  border: 1px solid var(--border); font-size: 13px; color: var(--text);
}
.attachment-chip:hover { background: var(--surface-hover); text-decoration: none; }
.msg-toolbar { display: flex; gap: 6px; padding: 8px 14px 12px; flex-wrap: wrap; }
.msg-toolbar button {
  border: 1px solid var(--border); background: var(--bg); color: var(--text);
  padding: 6px 14px; border-radius: 999px; font-size: 13px; cursor: pointer; transition: background var(--transition);
}
.msg-toolbar button:hover { background: var(--surface-hover); }
.thread-reply-bar {
  position: sticky; bottom: 0; display: flex; gap: 10px; padding: 14px 20px 18px;
  background: linear-gradient(transparent, var(--bg) 40%); z-index: 5;
}

.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 8px;
  padding: 9px 18px; border-radius: 999px; border: 1px solid var(--border);
  background: var(--surface); color: var(--text); font-size: 14px; font-weight: 600;
  cursor: pointer; transition: background var(--transition), border-color var(--transition);
  min-height: 40px;
}
.btn:hover { background: var(--surface-hover); }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.btn-primary { background: var(--primary); border-color: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-hover); border-color: var(--primary-hover); }
.btn-ghost { background: none; border: none; color: var(--text-secondary); padding: 8px 12px; min-height: 36px; }
.btn-ghost:hover { background: var(--surface-hover); color: var(--text); }
.btn-ghost.active { color: var(--primary); background: color-mix(in srgb, var(--primary) 14%, transparent); }
.btn-ghost.danger { color: var(--red); }
.btn-block { width: 100%; }
.btn-danger { background: var(--red); border-color: var(--red); color: #fff; }
.btn-danger:hover { background: color-mix(in srgb, var(--red) 85%, #000); }

.input, .textarea, select.input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border);
  border-radius: 8px;
  background: var(--bg);
  color: var(--text);
  font-size: 14px;
  outline: none;
  transition: border-color var(--transition);
}
.input:focus, .textarea:focus { border-color: var(--primary); }
.textarea { resize: vertical; min-height: 90px; line-height: 1.5; }
label.field { display: flex; flex-direction: column; gap: 6px; font-size: 13px; color: var(--text-secondary); margin-bottom: 12px; text-align: left; }

.checkbox { position: relative; display: inline-flex; width: 20px; height: 20px; cursor: pointer; flex-shrink: 0; }
.checkbox input { position: absolute; opacity: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
.checkbox span {
  width: 20px; height: 20px; border-radius: 5px; border: 2px solid var(--text-secondary);
  display: inline-flex; align-items: center; justify-content: center; font-size: 13px; color: #fff;
  transition: background var(--transition), border-color var(--transition);
}
.checkbox input:checked + span { background: var(--primary); border-color: var(--primary); }
.checkbox input:checked + span::after { content: "✓"; }
.checkbox input:focus-visible + span { box-shadow: 0 0 0 3px color-mix(in srgb, var(--primary) 35%, transparent); }

.chip {
  display: inline-flex; align-items: center; gap: 6px;
  background: color-mix(in srgb, var(--primary) 16%, transparent);
  color: var(--primary); border-radius: 999px; padding: 3px 8px 3px 10px; font-size: 13px;
}
.chip-email { font-size: 12px; opacity: .8; }
.chip-remove {
  border: none; background: none; color: inherit; cursor: pointer; font-size: 13px;
  padding: 0 2px; line-height: 1; border-radius: 50%;
}
.chip-remove:hover { color: var(--red); }
.chip:hover { filter: brightness(1.1); }
.tag { transition: background var(--transition), color var(--transition); }
.toast-msg { max-width: 380px; }
.nav-item .badge { margin-left: auto; }

.empty-state { padding: 60px 24px; text-align: center; }
.empty-icon { font-size: 44px; margin-bottom: 12px; opacity: .9; }
.empty-state h3 { margin: 0 0 6px; font-size: 18px; }
.empty-state p { margin: 0 0 16px; color: var(--text-secondary); font-size: 14px; }

.skeleton-row { display: flex; gap: 12px; padding: 14px; align-items: center; }
.skeleton { display: block; background: var(--surface-hover); border-radius: 6px; animation: pulse 1.2s ease-in-out infinite; }
.skeleton.circle { width: 38px; height: 38px; border-radius: 50%; flex-shrink: 0; }
.skeleton-lines { flex: 1; display: flex; flex-direction: column; gap: 8px; }
.skeleton.line { height: 12px; }
.skeleton.line.w60 { width: 60%; }
.skeleton.line.w90 { width: 90%; }
.skeleton.line.w40 { width: 40%; }
@keyframes pulse { 0%, 100% { opacity: .5; } 50% { opacity: 1; } }
.thread-skeleton { padding: 24px; display: flex; flex-direction: column; gap: 14px; }
.thread-skeleton .skeleton.block { height: 120px; }

.compose-overlay {
  position: fixed; inset: 0; z-index: 60; pointer-events: none;
  display: flex; align-items: flex-end; justify-content: flex-end;
}
.compose-window {
  position: fixed; right: 24px; bottom: 24px; width: 520px; max-width: calc(100vw - 40px);
  height: 560px; max-height: calc(100dvh - 120px);
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  box-shadow: var(--shadow); display: flex; flex-direction: column; overflow: hidden;
  pointer-events: auto;
  opacity: 0; transform: translateY(24px) scale(.98);
  transition: opacity .2s ease, transform .25s ease, height .25s ease;
}
.compose-overlay.open .compose-window { opacity: 1; transform: none; }
.compose-window.minimized { height: 46px; }
.compose-window.minimized .compose-body { display: none; }
.compose-header {
  display: flex; align-items: center; justify-content: space-between;
  padding: 0 12px 0 16px; height: 46px; background: var(--surface); border-bottom: 1px solid var(--border);
  cursor: default;
}
.compose-header-actions { display: flex; gap: 2px; }
.compose-title { font-size: 14px; font-weight: 700; cursor: pointer; }
.compose-body { flex: 1; overflow-y: auto; padding: 10px 0; }
.compose-row { display: flex; align-items: flex-start; gap: 10px; padding: 4px 14px; border-bottom: 1px solid var(--border); min-height: 44px; }
.compose-label { width: 34px; font-size: 13px; color: var(--text-secondary); padding-top: 10px; flex-shrink: 0; }
.compose-from { flex: 1; border: none; background: none; color: var(--text); font-size: 14px; outline: none; height: 44px; }
.compose-subject { flex: 1; border: none; background: none; color: var(--text); font-size: 14px; outline: none; height: 44px; }
.compose-toggle { border: none; background: none; color: var(--primary); font-size: 12.5px; padding: 12px 14px; cursor: pointer; }
.chips-wrap { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 8px 0; flex: 1; position: relative; }
.recipient-input { border: none; outline: none; background: none; color: var(--text); font-size: 14px; flex: 1; min-width: 120px; padding: 2px 0; }
.recipient-suggest {
  position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 30;
  background: var(--surface); border: 1px solid var(--border); border-radius: 8px; box-shadow: var(--shadow);
  overflow: hidden; max-height: 220px; overflow-y: auto;
}
.suggest-item {
  display: block; width: 100%; text-align: left; padding: 9px 12px; border: none; background: none;
  color: var(--text); font-size: 13.5px; cursor: pointer;
}
.suggest-item:hover { background: var(--surface-hover); }
.suggest-item span { color: var(--text-secondary); font-size: 12.5px; }
.recipient-remove { border: none; background: none; color: var(--text-secondary); cursor: pointer; font-size: 12px; padding-top: 14px; }
.compose-toolbar {
  display: flex; gap: 2px; padding: 6px 14px; border-bottom: 1px solid var(--border);
}
.compose-toolbar button {
  width: 34px; height: 32px; border: none; background: none; color: var(--text-secondary);
  font-size: 14px; border-radius: 6px; cursor: pointer;
}
.compose-toolbar button:hover { background: var(--surface-hover); color: var(--text); }
.compose-editor {
  min-height: 160px; padding: 12px 14px; font-size: 14.5px; line-height: 1.55;
  outline: none; overflow-y: auto; max-height: 240px;
}
.compose-editor:empty::before { content: attr(data-placeholder); color: var(--text-secondary); }
.attach-dropzone {
  margin: 10px 14px 0; border: 2px dashed var(--border); border-radius: 8px; padding: 18px;
  text-align: center; font-size: 13px; color: var(--text-secondary); transition: border-color var(--transition), background var(--transition);
}
.attach-dropzone.dragover { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 10%, transparent); }
.attach-dropzone button { border: none; background: none; color: var(--primary); cursor: pointer; font-size: 13px; }
.attach-list { display: flex; flex-wrap: wrap; gap: 8px; padding: 8px 14px 0; }
.attach-item {
  display: inline-flex; align-items: center; gap: 6px; padding: 5px 10px; border-radius: 999px;
  background: var(--bg); border: 1px solid var(--border); font-size: 12.5px; max-width: 200px;
}
.attach-item .fn { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.attach-remove { border: none; background: none; color: var(--text-secondary); cursor: pointer; font-size: 13px; }
.compose-footer { display: flex; align-items: center; gap: 12px; padding: 12px 14px; }
.send-hint { font-size: 12px; color: var(--text-secondary); flex: 1; }
.compose-draftsaved { font-size: 12px; color: var(--green); }

.batch-bar {
  position: sticky; top: 0; z-index: 12; display: flex; align-items: center; gap: 10px;
  padding: 10px 14px; background: var(--primary); color: #fff; box-shadow: var(--shadow-sm);
}
.batch-bar .checkbox span { border-color: #fff; }
.batch-count { font-size: 13px; font-weight: 600; flex: 1; }
.batch-actions { display: flex; gap: 4px; overflow-x: auto; }
.batch-actions button {
  border: none; background: rgba(255,255,255,.16); color: #fff; padding: 6px 12px;
  border-radius: 999px; font-size: 12.5px; cursor: pointer; white-space: nowrap;
}
.batch-actions button:hover { background: rgba(255,255,255,.28); }
.batch-close { border: none; background: none; color: #fff; cursor: pointer; font-size: 16px; padding: 4px; }

.modal-overlay {
  position: fixed; inset: 0; z-index: 70; display: flex; align-items: center; justify-content: center;
  background: rgba(0, 0, 0, .55); padding: 20px; opacity: 0; pointer-events: none; transition: opacity .18s ease;
  backdrop-filter: blur(4px); -webkit-backdrop-filter: blur(4px);
}
.modal-overlay.open { opacity: 1; pointer-events: auto; }
.modal {
  width: 100%; max-width: 480px; max-height: 86dvh; display: flex; flex-direction: column;
  background: var(--surface); border: 1px solid var(--border); border-radius: 14px;
  box-shadow: var(--shadow); transform: translateY(12px) scale(.98); transition: transform .2s ease;
  overflow: hidden;
}
.modal-overlay.open .modal { transform: none; }
.modal-header { display: flex; align-items: center; justify-content: space-between; padding: 14px 16px 10px; }
.modal-header h3 { margin: 0; font-size: 16px; }
.modal-body { padding: 6px 16px 12px; overflow-y: auto; font-size: 14px; }
.modal-footer { display: flex; justify-content: flex-end; gap: 8px; padding: 12px 16px; border-top: 1px solid var(--border); }
.modal-close { border: none; background: none; color: var(--text-secondary); font-size: 18px; cursor: pointer; padding: 4px 8px; }
.tag-picker { display: flex; flex-direction: column; gap: 4px; }
.tag-option { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; cursor: pointer; font-size: 14px; }
.tag-option:hover { background: var(--surface-hover); }

.toast-container {
  position: fixed; left: 50%; transform: translateX(-50%);
  bottom: calc(24px + env(safe-area-inset-bottom)); z-index: 90;
  display: flex; flex-direction: column; gap: 8px; align-items: center; max-width: calc(100vw - 32px);
}
.toast {
  display: flex; align-items: center; gap: 10px;
  background: var(--surface); border: 1px solid var(--border); border-radius: 999px;
  padding: 10px 16px; box-shadow: var(--shadow); font-size: 13.5px;
  animation: toast-in .25s ease; max-width: 100%;
}
.toast.success { border-color: var(--green); }
.toast.success .toast-icon { color: var(--green); }
.toast.error { border-color: var(--red); }
.toast.error .toast-icon { color: var(--red); }
.toast-icon { display: inline-flex; width: 18px; height: 18px; align-items: center; justify-content: center; font-size: 13px; color: var(--text-secondary); flex-shrink: 0; }
.toast.out { opacity: 0; transform: translateY(8px); transition: opacity .25s, transform .25s; }
.toast-msg { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.toast-action { border: none; background: none; color: var(--primary); font-weight: 700; cursor: pointer; font-size: 13px; padding: 0 4px; }
.toast-action:hover { text-decoration: underline; }
@keyframes toast-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: none; } }

.bottom-tabs { display: none; }

.app-footer {
  max-width: 1200px;
  margin-left: auto;
  margin-right: auto;
  padding: 1.5rem 1rem;
  font-size: 0.75rem;
  color: var(--text-secondary);
  margin-top: auto;
  width: 100%;
  text-align: center;
}
.app-footer a { color: var(--text); }
.app-footer a:hover { color: var(--primary); }
.app-footer p + p { margin-top: 0.5rem; }

.shortcut-grid { display: grid; grid-template-columns: auto 1fr; gap: 4px 18px; font-size: 13px; align-items: baseline; }
.shortcut-grid code { background: var(--bg); border: 1px solid var(--border); border-radius: 5px; padding: 1px 7px; font-size: 12px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
.shortcut-grid span { color: var(--text-secondary); }

.auth-view { min-height: 100dvh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 24px; }
.auth-view .app-footer { width: 100%; max-width: 380px; }
.auth-view .auth-card { animation: menu-in .3s ease; }

.overlay-view { min-height: 100dvh; display: flex; align-items: flex-start; justify-content: center; padding: 40px 20px; }
.overlay-card {
  width: 100%; max-width: 85vw; max-height: 90vh; overflow-y: auto;
  background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; box-shadow: var(--shadow);
  display: flex; flex-direction: column;
}
.overlay-card .settings-header {
  display: flex; align-items: center; gap: 12px; padding: 20px 28px 16px;
  border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.overlay-card .settings-header h1 { margin: 0; }
.overlay-card .settings-body { padding: 20px 28px 28px; text-align: left; flex: 1; overflow-y: auto; }
.overlay-card .settings-section { margin-bottom: 28px; }
.overlay-card .settings-section:last-child { margin-bottom: 0; }
.overlay-card .settings-section h2 { font-size: 12px; text-transform: uppercase; letter-spacing: 0.08em; color: var(--text-secondary); margin: 0 0 12px; }
.overlay-card .account-card { display: flex; align-items: center; gap: 12px; padding: 12px; background: var(--bg); border: 1px solid var(--border); border-radius: 10px; margin-bottom: 8px; }
.overlay-card .setting-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid var(--border); }
.overlay-card .contact-search { position: sticky; top: 0; z-index: 1; margin-bottom: 12px; }
.overlay-card .back-btn { background: none; border: none; color: var(--text); cursor: pointer; font-size: 20px; padding: 4px; display: flex; }
.auth-card {
  width: 100%; max-width: 380px; background: var(--surface); border: 1px solid var(--border);
  border-radius: 16px; padding: 32px 28px; box-shadow: var(--shadow); text-align: center;
}
.auth-logo {
  width: 52px; height: 52px; margin: 0 auto 12px; display: flex; align-items: center; justify-content: center;
  background: var(--primary); border-radius: 14px; font-size: 26px; color: #fff;
}
.auth-card h1 { margin: 0 0 4px; font-size: 22px; }
.auth-sub { margin: 0 0 20px; color: var(--text-secondary); font-size: 14px; }
.auth-card form { text-align: left; }
.auth-hint { font-size: 12px; color: var(--text-secondary); margin: 14px 0 0; text-align: center; }
.auth-error { color: var(--red); font-size: 13px; margin: 0 0 10px; display: none; }

.theme-toggle-btn {
  position: fixed; top: 16px; right: 16px; z-index: 100;
  width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--border);
  background: var(--surface); color: var(--text); cursor: pointer;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; transition: all var(--transition);
}
.theme-toggle-btn:hover { background: var(--surface-hover); transform: scale(1.05); }

.empty-state.compact { padding: 36px 20px; }
.empty-state.compact .empty-icon { font-size: 32px; }
.search-query-bar { display: flex; gap: 8px; margin-top: 10px; }
.search-query-bar .input { flex: 1; min-width: 0; }
.search-hints { font-size: 12px; color: var(--text-secondary); margin-top: 8px; }
.search-hints code {
  background: var(--surface); border: 1px solid var(--border); border-radius: 5px;
  padding: 1px 6px; font-size: 11.5px; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
}
.recipient-input::placeholder, .compose-subject::placeholder, .compose-from::placeholder {
  color: var(--text-secondary); opacity: .7;
}
.message-card { animation: card-in .2s ease; }
@keyframes card-in { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: none; } }
.message-card:not(.collapsed) { border-color: color-mix(in srgb, var(--primary) 30%, var(--border)); }
.msg-collapsed-bar:hover { background: var(--surface-hover); }
.account-card:hover { background: var(--surface-hover); }
.setting-row:last-child { border-bottom: none; }
.contact-row:active { background: var(--surface-hover); }
.toast-container { pointer-events: none; }
.toast { pointer-events: auto; }
.menu-dropdown::-webkit-scrollbar { width: 8px; }
.thread-reply-bar .btn { min-height: 44px; }
.thread-view .message-stack .message-card:first-child { border-top: 2px solid var(--primary); }
.compose-editor { scrollbar-width: thin; }
.compose-editor::-webkit-scrollbar { width: 8px; }
.provider-card .p-icon { font-size: 24px; line-height: 1; }
@media print {
  .topbar, .sidebar, .bottom-tabs, .compose-overlay, .thread-reply-bar, .msg-toolbar, .thread-actions { display: none !important; }
  .pane-list { display: none !important; }
  .pane-detail { display: block !important; width: 100% !important; }
  #app { display: block; height: auto; }
  .thread-header { position: static; }
  .message-card { break-inside: avoid; box-shadow: none; }
}

.setup-view { min-height: 100dvh; display: flex; flex-direction: column; align-items: center; justify-content: flex-start; padding: 32px 20px 60px; }
.setup-view .app-footer { width: 100%; max-width: 560px; }
.setup-card { width: 100%; max-width: 560px; margin: 0 auto; padding-top: 24px; }
.setup-card .auth-card { max-width: none; }
.setup-steps { display: flex; gap: 6px; margin: 0 0 22px; }
.step { flex: 1; text-align: center; font-size: 12px; color: var(--text-secondary); padding: 8px 6px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); }
.step.active { color: var(--primary); border-color: var(--primary); font-weight: 600; }
.step.done { color: var(--green); }
.provider-grid { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 18px; justify-content: center; }
.provider-card {
  display: flex; flex-direction: column; align-items: center; gap: 8px; padding: 14px 8px;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  color: var(--text); font-size: 13px; cursor: pointer; transition: border-color var(--transition), background var(--transition);
  width: calc(33.333% - 7px); min-width: 90px;
}
.provider-card:hover { background: var(--surface-hover); }
.provider-card.active { border-color: var(--primary); background: color-mix(in srgb, var(--primary) 12%, transparent); }
.provider-card .p-icon { font-size: 24px; }
.field-row { display: grid; grid-template-columns: 1fr 90px 100px; gap: 10px; }
.setup-progress { margin: 12px 0; padding: 10px 14px; border-radius: 8px; background: var(--surface); border: 1px solid var(--border); font-size: 13px; color: var(--text-secondary); }
.setup-progress.error { color: var(--red); border-color: var(--red); }
.setup-actions { display: flex; gap: 10px; margin-top: 18px; justify-content: center; align-items: center; }

.settings-view { max-width: 720px; margin: 0 auto; padding: 0 20px 80px; }
.settings-header { display: flex; align-items: center; gap: 8px; padding: 16px 0; position: sticky; top: 0; background: var(--bg); z-index: 10; }
.settings-header h1 { margin: 0; font-size: 20px; }
.settings-section { margin-bottom: 28px; }
.settings-section h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .07em; color: var(--text-secondary); margin: 0 0 12px; }
.account-card {
  display: flex; align-items: center; gap: 12px; padding: 12px 14px; margin-bottom: 8px;
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
}
.acct-info { flex: 1; min-width: 0; }
.acct-email { font-size: 14px; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.acct-sync { font-size: 12.5px; color: var(--text-secondary); }
.sync-error { color: var(--red); font-size: 12.5px; }
.setting-row { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 12px 4px; border-bottom: 1px solid var(--border); font-size: 14px; }
.setting-row .checkbox { width: 40px; }
.setting-row .checkbox span { border-radius: 999px; width: 44px; height: 24px; position: relative; background: var(--border); border: none; transition: background var(--transition); }
.setting-row .checkbox input:checked + span { background: var(--primary); }
.setting-row .checkbox span::after { content: ""; position: absolute; top: 3px; left: 3px; width: 18px; height: 18px; border-radius: 50%; background: #fff; transition: transform var(--transition); }
.setting-row .checkbox input:checked + span::after { transform: translateX(20px); background: #fff; }
.setting-row .checkbox input:checked + span::after { content: ""; }
.signature-editor { margin-bottom: 10px; }

.contacts-view { max-width: 640px; margin: 0 auto; padding: 0 20px 80px; }
.contact-search { margin-bottom: 14px; }
.contact-row {
  display: flex; align-items: center; gap: 12px; padding: 10px 12px; border-radius: 8px; cursor: pointer;
  border-bottom: 1px solid var(--border);
}
.contact-row:hover { background: var(--surface-hover); }
.c-name { font-size: 14px; font-weight: 600; }
.c-email { font-size: 13px; color: var(--text-secondary); }
.c-count { margin-left: auto; color: var(--text-secondary); font-size: 12px; }

body.auth #app > .topbar,
body.auth #app > .sidebar,
body.auth #app > .pane,
body.auth #app > .bottom-tabs { display: none; }
.fullscreen { position: fixed; inset: 0; z-index: 100; overflow-y: auto; background: var(--bg); }

@media (max-width: 1023px) {
  #app {
    grid-template-columns: minmax(300px, 360px) minmax(0, 1fr);
    grid-template-areas:
      "topbar topbar"
      "list detail";
  }
  .sidebar {
    position: fixed; top: 56px; bottom: 0; left: 0; width: 280px; z-index: 45;
    transform: translateX(-105%); transition: transform .25s ease; box-shadow: var(--shadow);
  }
  body.sidebar-open .sidebar { transform: none; }
  .sidebar-backdrop {
    display: block; position: fixed; inset: 0; z-index: 44; background: rgba(0, 0, 0, .45);
    opacity: 0; pointer-events: none; transition: opacity .2s ease;
  }
  body.sidebar-open .sidebar-backdrop { opacity: 1; pointer-events: auto; }
  .search-bar { flex: 0 1 340px; }
}

@media (max-width: 640px) {
  #app {
    grid-template-columns: 1fr;
    grid-template-rows: 56px minmax(0, 1fr);
    grid-template-areas:
      "topbar"
      "content";
  }
  .pane-list, .pane-detail { grid-area: content; }
  .pane-detail { display: none; }
  body.detail-view .pane-list { display: none; }
  body.detail-view .pane-detail { display: block; }
  .pane-list, .pane-detail { padding-bottom: calc(56px + env(safe-area-inset-bottom)); }
  .topbar { padding: 0 8px; gap: 6px; }
  .logo-text { display: none; }
  #btn-search { display: inline-flex; }
  .search-bar {
    position: absolute; top: 8px; left: 12px; right: 12px; z-index: 20;
    background: var(--surface); margin: 0;
    opacity: 0; pointer-events: none; transform: translateY(-8px);
    transition: opacity .18s ease, transform .18s ease;
  }
  .search-bar.active { opacity: 1; pointer-events: auto; transform: none; }
  body.search-open .topbar-left, body.search-open .topbar-right { opacity: 0; pointer-events: none; }
  .search-bar input { font-size: 16px; }
  .bottom-tabs {
    display: grid;
    grid-template-columns: 1fr 1fr 72px 1fr;
    position: fixed; left: 0; right: 0; bottom: 0; z-index: 50;
    height: calc(56px + env(safe-area-inset-bottom));
    padding-bottom: env(safe-area-inset-bottom);
    background: var(--surface); border-top: 1px solid var(--border);
  }
  .tab-item {
    display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 2px;
    font-size: 11px; color: var(--text-secondary); min-height: 44px;
    transition: color var(--transition);
  }
  .tab-item:hover { color: var(--text); }
  .tab-item:active { opacity: .7; }
  .tab-item.active { color: var(--primary); }
  .tab-item .tab-ico { font-size: 19px; line-height: 1; }
  .tab-fab {
    position: relative; align-self: center; justify-self: center;
    width: 56px; height: 56px; border-radius: 50%; border: none;
    background: var(--primary); color: #fff; font-size: 22px; cursor: pointer;
    box-shadow: 0 4px 14px color-mix(in srgb, var(--primary) 45%, transparent);
    margin-top: -22px; transition: transform var(--transition);
  }
  .tab-fab:hover { transform: scale(1.06); }
  .tab-fab:active { transform: scale(.96); }
  .toast-container { bottom: calc(68px + env(safe-area-inset-bottom)); }
  .compose-window { right: 0; bottom: 0; width: 100vw; max-width: none; height: 100dvh; max-height: 100dvh; border-radius: 0; }
  .compose-window.minimized { height: 46px; width: calc(100vw - 24px); right: 12px; bottom: calc(60px + env(safe-area-inset-bottom)); border-radius: 10px; }
  .thread-header { position: static; }
  .modal { max-width: 100%; }
  .message-row { padding-top: 12px; padding-bottom: 12px; }
  .list-header h1 { font-size: 18px; }
  .thread-subject { font-size: 17px; }
  .field-row { grid-template-columns: 1fr; }
  .provider-card { width: calc(50% - 5px); }
  .thread-header { gap: 8px; padding: 12px 14px 10px; }
  .msg-toolbar button { padding: 8px 16px; }
  .compose-editor { max-height: none; min-height: 200px; }
}

@media (min-width: 641px) {
  body.detail-view .pane-list { display: block; }
}

@media (prefers-reduced-motion: reduce) {
  *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; }
}
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

const PROVIDERS = {
  gmail:   { name: 'Gmail',   icon: '<i class="ti ti-brand-gmail"></i>', imap_host: 'imap.gmail.com',        imap_port: 993, imap_tls: 1, smtp_host: 'smtp.gmail.com',        smtp_port: 465, smtp_tls: 1 },
  outlook: { name: 'Outlook', icon: '<i class="ti ti-brand-windows"></i>', imap_host: 'outlook.office365.com', imap_port: 993, imap_tls: 1, smtp_host: 'smtp.office365.com',   smtp_port: 587, smtp_tls: 2 },
  icloud:  { name: 'iCloud',  icon: '<i class="ti ti-brand-apple"></i>', imap_host: 'imap.mail.me.com',      imap_port: 993, imap_tls: 1, smtp_host: 'smtp.mail.me.com',      smtp_port: 587, smtp_tls: 2 },
  yahoo:   { name: 'Yahoo',   icon: '<i class="ti ti-brand-yahoo"></i>', imap_host: 'imap.mail.yahoo.com',   imap_port: 993, imap_tls: 1, smtp_host: 'smtp.mail.yahoo.com',   smtp_port: 465, smtp_tls: 1 },
  migadu:  { name: 'Migadu',  icon: '<i class="ti ti-mail"></i>', imap_host: 'imap.migadu.com',       imap_port: 993, imap_tls: 1, smtp_host: 'smtp.migadu.com',       smtp_port: 465, smtp_tls: 1 },
  custom:  { name: 'Custom',  icon: '<i class="ti ti-settings"></i>', imap_host: '',                      imap_port: 993, imap_tls: 1, smtp_host: '',                      smtp_port: 587, smtp_tls: 2 }
};

const App = {
  currentView: '#login',
  currentFolder: 'inbox',
  currentTagId: null,
  currentAccountId: null,
  accounts: [],
  threads: [],
  contacts: [],
  tags: [],
  settings: {},
  selectedThreadIds: new Set(),
  composeState: null,
  isMultiSelectMode: false,
  isSidebarOpen: false,
  isMobile: false,
  csrfToken: null,
  undoStack: [],
  _showImages: new Set(),
  _focusedIdx: 0,
  _gPending: false,
  _prevListHash: null,
  _draftTimer: null,
  _sendToasts: {},

  el: (sel) => document.querySelector(sel),
  els: (sel) => Array.from(document.querySelectorAll(sel)),

  api: async function (action, params = {}, method = 'GET') {
    const opts = { method, headers: {} };
    if (this.csrfToken) opts.headers['X-CSRF-Token'] = this.csrfToken;
    let url = 'index.php?action=' + encodeURIComponent(action);
    if (method === 'GET' || method === 'HEAD') {
      const qs = new URLSearchParams();
      for (const [k, v] of Object.entries(params)) {
        if (v === undefined || v === null || v === '') continue;
        qs.set(k, Array.isArray(v) ? JSON.stringify(v) : String(v));
      }
      const s = qs.toString();
      if (s) url += '&' + s;
    } else {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(params);
    }
    let res;
    try {
      res = await fetch(url, opts);
    } catch (e) {
      throw new Error('Network error: cannot reach server');
    }
    let json;
    try {
      json = await res.json();
    } catch (e) {
      throw new Error('Invalid server response (HTTP ' + res.status + ')');
    }
    if (json && json.ok === false) throw new Error(json.error || 'Request failed');
    if (!res.ok) throw new Error((json && json.error) || 'HTTP ' + res.status);
    return (json && json.data !== undefined) ? json.data : json;
  },

  init: async function () {
    this.applyTheme();
    this.updateIsMobile();
    this.bindGlobal();
    try {
      const st = await this.api('auth_status');
      this.csrfToken = st.csrf || this.csrfToken || null;
      this.authStatus = st;
      if (!st.authenticated) {
        this.navigate(st.setup ? '#setup' : '#login');
        return;
      }
      await this.loadBaseData();
      this.navigate(location.hash || '#inbox');
      if (this.accounts.length) {
        this.api('sync_account', { account_id: this.currentAccountId }, 'POST')
          .then(() => { this.loadThreads(this.currentFolder); })
          .catch(() => {});
      }
    } catch (err) {
      this.renderLogin();
      this.showToast(err.message, 'error');
    }
  },

  loadBaseData: async function () {
    const [accts, settings] = await Promise.all([
      this.api('list_accounts').catch(() => ({ accounts: [] })),
      this.api('get_settings').catch(() => ({ settings: {} }))
    ]);
    this.accounts = accts.accounts || accts || [];
    this.settings = settings.settings || settings || {};
    if (this.currentAccountId == null && this.accounts.length) {
      this.currentAccountId = this.accounts[0].id;
    }
    await this.loadTags();
  },

  loadTags: async function () {
    const data = await this.api('list_tags', { account_id: this.currentAccountId }).catch(() => ({ tags: [] }));
    this.tags = data.tags || data || [];
  },

  navigate: function (hash) {
    if (!hash) hash = location.hash;
    if (!hash || hash === '#') hash = '#inbox';
    this.currentView = hash;
    const [path, query] = hash.slice(1).split('?');
    const parts = path.split('/').filter(Boolean);
    const view = parts[0] || 'inbox';
    const isDetail = ['thread', 'reply', 'forward'].includes(view);
    const isOverlay = ['settings', 'contacts'].includes(view);
    const isAuth = ['login', 'setup'].includes(view);
    document.body.classList.toggle('detail-view', isDetail);
    document.body.classList.toggle('auth', isAuth);
    if (!isAuth && !isOverlay) {
      const fs = this.el('#fullscreen');
      if (fs) { fs.hidden = true; fs.innerHTML = ''; }
    }
    this.closeSearch();
    this.closeMenus();
    if (view !== 'thread' && view !== 'reply' && view !== 'forward' && !isOverlay) this._prevListHash = hash;
    switch (view) {
      case 'login': this.renderLogin(); break;
      case 'setup': this.renderSetup(); break;
      case 'inbox': case 'sent': case 'drafts': case 'starred': case 'all':
        this.currentFolder = view;
        this.currentTagId = null;
        this.loadThreads(view);
        break;
      case 'tag':
        this.currentFolder = 'tag';
        this.currentTagId = parts[1];
        this.loadTagThreads(parts[1]);
        break;
      case 'thread': this.openThread(parts[1]); break;
      case 'compose': this.openCompose(); break;
      case 'reply': this.openCompose({ mode: 'reply', messageId: parts[1] }); break;
      case 'forward': this.openCompose({ mode: 'forward', messageId: parts[1] }); break;
      case 'search': this.renderSearch(new URLSearchParams(query).get('q') || ''); break;
      case 'settings': this.renderSettings(); break;
      case 'contacts': this.renderContacts(); break;
      default: this.currentFolder = 'inbox'; this.loadThreads('inbox'); break;
    }
    this.renderSidebar();
    this.updateBottomTabs();
    this.updateAvatar();
    if (window.scrollTo) window.scrollTo(0, 0);
  },

  renderLogin: function () {
    const fs = this.el('#fullscreen');
    fs.hidden = false;
    document.body.classList.add('auth');
    fs.innerHTML = `
      <div class="auth-view">
        <form class="auth-card" data-login-form>
          <div class="auth-logo"><i class="ti ti-mail"></i></div>
          <h1>Emmmail</h1>
          <p class="auth-sub">Sign in to your email</p>
          <div class="auth-error" data-login-error></div>
          <label class="field"><span>Email</span><input class="input" type="email" name="email" placeholder="you@example.com" autocomplete="username" required></label>
          <label class="field"><span>Password</span><input class="input" type="password" name="password" placeholder="App password" autocomplete="current-password" required></label>
          <button class="btn btn-primary btn-block" type="submit">Sign in</button>
          <p class="auth-hint">Credentials are validated against your IMAP server and stored encrypted (AES-256-GCM).</p>
        </form>
        <button class="theme-toggle-btn" data-theme-toggle><i class="ti ti-${this.settings.theme === 'light' ? 'moon' : 'sun'}"></i></button>
        <footer class="app-footer">
          <p>Designed and built by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co?ref=Emmmail&utm_source=Emmmail&utm_media=Instance" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p>
          <p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a> &mdash; <a href="https://github.com/Abtz-Labs/Emmmail/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">O'Saasy Licensed</a></p>
          <p>{{VERSION}} &copy; Abtz Labs.</p>
        </footer>
      </div>`;
    const form = this.el('[data-login-form]');
    form.addEventListener('submit', (e) => { e.preventDefault(); this.doLogin(form); });
    fs.querySelector('[data-theme-toggle]')?.addEventListener('click', () => { this.toggleTheme(); this.renderLogin(); });
  },

  doLogin: async function (form) {
    const btn = form.querySelector('button[type=submit]');
    const errBox = this.el('[data-login-error]');
    btn.disabled = true; btn.textContent = 'Signing in...';
    errBox.style.display = 'none';
    try {
      await this.api('auth_login', { email: form.email.value.trim(), password: form.password.value }, 'POST');
      this.showToast('Signed in');
      await this.init();
    } catch (err) {
      errBox.textContent = err.message;
      errBox.style.display = 'block';
      btn.disabled = false; btn.textContent = 'Sign in';
    }
  },

  renderSetup: function () {
    const fs = this.el('#fullscreen');
    fs.hidden = false;
    document.body.classList.add('auth');
    fs.innerHTML = `
      <div class="setup-view">
        <div class="setup-card">
          <div class="auth-logo"><i class="ti ti-mail"></i></div>
          <h1 style="text-align:center;margin:0 0 4px">Set up your email</h1>
          <p class="auth-sub" style="text-align:center">Connect your first account</p>
          <form class="auth-card" data-setup-form>
            ${this.providerFormHtml('gmail')}
            <div class="setup-actions">
              <button class="btn btn-primary" type="button" data-test>Test connection</button>
              <button class="btn btn-ghost" type="submit" disabled>Finish setup</button>
            </div>
          </form>
        </div>
        <button class="theme-toggle-btn" data-theme-toggle><i class="ti ti-${this.settings.theme === 'light' ? 'moon' : 'sun'}"></i></button>
        <footer class="app-footer">
          <p>Designed and built by <a href="https://x.com/rogeriotaques" target="_blank" rel="noopener noreferrer">Rogerio Taques</a>, the guy behind <a href="https://abtz.co?ref=Emmmail&utm_source=Emmmail&utm_media=Instance" target="_blank" rel="noopener noreferrer">Abtz Labs</a>.</p>
          <p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a> &mdash; <a href="https://github.com/Abtz-Labs/Emmmail/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">O'Saasy Licensed</a></p>
          <p>{{VERSION}} &copy; Abtz Labs.</p>
        </footer>
      </div>`;
    const form = this.el('[data-setup-form]');
    this.bindProviderForm(form);
    form.addEventListener('submit', (e) => { e.preventDefault(); this.submitSetup(form); });
    fs.querySelector('[data-theme-toggle]')?.addEventListener('click', () => { this.toggleTheme(); this.renderSetup(); });
  },

  providerFormHtml: function (preset) {
    const p = PROVIDERS[preset] || PROVIDERS.gmail;
    return `
      <div class="provider-grid">
        ${Object.entries(PROVIDERS).map(([key, pr]) =>
          `<button type="button" class="provider-card ${key === preset ? 'active' : ''}" data-provider="${key}">
            <span class="p-icon">${pr.icon}</span><span>${pr.name}</span>
          </button>`).join('')}
      </div>
      <div class="field-row">
        <label class="field"><span>IMAP host</span><input class="input" data-imap-host value="${p.imap_host}" required></label>
        <label class="field"><span>Port</span><input class="input" type="number" data-imap-port value="${p.imap_port}" required></label>
        <label class="field"><span>TLS</span><select class="input" data-imap-tls>
          <option value="1" ${p.imap_tls == 1 ? 'selected' : ''}>SSL/TLS</option>
          <option value="2" ${p.imap_tls == 2 ? 'selected' : ''}>STARTTLS</option>
          <option value="0" ${p.imap_tls == 0 ? 'selected' : ''}>None</option>
        </select></label>
      </div>
      <div class="field-row">
        <label class="field"><span>SMTP host</span><input class="input" data-smtp-host value="${p.smtp_host}" required></label>
        <label class="field"><span>Port</span><input class="input" type="number" data-smtp-port value="${p.smtp_port}" required></label>
        <label class="field"><span>TLS</span><select class="input" data-smtp-tls>
          <option value="1" ${p.smtp_tls == 1 ? 'selected' : ''}>SSL/TLS</option>
          <option value="2" ${p.smtp_tls == 2 ? 'selected' : ''}>STARTTLS</option>
          <option value="0" ${p.smtp_tls == 0 ? 'selected' : ''}>None</option>
        </select></label>
      </div>
      <label class="field"><span>Email</span><input class="input" type="email" data-semail placeholder="you@example.com" required></label>
      <label class="field"><span>Password</span><input class="input" type="password" data-spassword placeholder="YoUr S3cReT PasSW0rD" autocomplete="new-password" required></label>`;
  },

  bindProviderForm: function (form) {
    form.querySelectorAll('[data-provider]').forEach((btn) => {
      btn.addEventListener('click', () => {
        form.querySelectorAll('[data-provider]').forEach(b => b.classList.toggle('active', b === btn));
        const key = btn.dataset.provider;
        const p = PROVIDERS[key] || PROVIDERS.custom;
        form.querySelector('[data-imap-host]').value = p.imap_host;
        form.querySelector('[data-imap-port]').value = p.imap_port;
        form.querySelector('[data-imap-tls]').value = p.imap_tls;
        form.querySelector('[data-smtp-host]').value = p.smtp_host;
        form.querySelector('[data-smtp-port]').value = p.smtp_port;
        form.querySelector('[data-smtp-tls]').value = p.smtp_tls;
      });
    });
    const test = form.querySelector('[data-test]');
    if (test) test.addEventListener('click', () => this.testConnection(form));
  },

  readAccountForm: function (form) {
    return {
      email: form.querySelector('[data-semail]').value.trim(),
      password: form.querySelector('[data-spassword]').value,
      display_name: this.deriveName(form.querySelector('[data-semail]').value.trim()),
      imap_host: form.querySelector('[data-imap-host]').value.trim(),
      imap_port: +form.querySelector('[data-imap-port]').value,
      imap_tls: +form.querySelector('[data-imap-tls]').value,
      smtp_host: form.querySelector('[data-smtp-host]').value.trim(),
      smtp_port: +form.querySelector('[data-smtp-port]').value,
      smtp_tls: +form.querySelector('[data-smtp-tls]').value
    };
  },

  setProgress: function (form, msg, isError) {
    const p = form.querySelector('[data-progress]');
    if (!p) return;
    p.classList.remove('hidden', 'error');
    if (isError) p.classList.add('error');
    p.textContent = msg;
  },

  testConnection: async function (form) {
    const testBtn = form.querySelector('[data-test]');
    const submitBtn = form.querySelector('button[type=submit]');
    testBtn.disabled = true;
    testBtn.textContent = 'Testing\u2026';
    this.showToast('Testing connection\u2026', 'info', null, { ttl: 0 });
    try {
      await this.api('test_connection', this.readAccountForm(form), 'POST');
      this.showToast('Connection successful', 'success');
      testBtn.classList.remove('btn-primary');
      testBtn.classList.add('btn-ghost');
      submitBtn.disabled = false;
      submitBtn.classList.remove('btn-ghost');
      submitBtn.classList.add('btn-primary');
    } catch (err) {
      this.showToast(err.message, 'error');
    } finally {
      testBtn.disabled = false;
      testBtn.textContent = 'Test connection';
    }
  },

  submitSetup: async function (form) {
    const btn = form.querySelector('button[type=submit]');
    const testBtn = form.querySelector('[data-test]');
    btn.disabled = true;
    btn.textContent = 'Setting up\u2026';
    testBtn.disabled = true;
    form.querySelectorAll('input, select, button').forEach(el => el.disabled = true);
    this.showToast('Creating account\u2026', 'info', null, { ttl: 0 });
    try {
      await this.api('auth_setup', this.readAccountForm(form), 'POST');
      this.showToast('Welcome to Emmmail!');
      await this.init();
      this.navigate('#inbox');
    } catch (err) {
      this.showToast(err.message, 'error');
      form.querySelectorAll('input, select, button').forEach(el => el.disabled = false);
      btn.textContent = 'Finish setup';
    }
  },

  deriveName: function (email) {
    const local = String(email || '').split('@')[0];
    return local.replace(/[._-]+/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()).trim();
  },

  renderSidebar: function () {
    const sb = this.el('#sidebar');
    const acct = this.getAccount();
    const unread = this.unreadCounts();
    const folder = this.currentFolder;
    const isFolder = (f) => folder === f || (this.currentView === '#tag/' + this.currentTagId ? false : this.currentView.split('/')[0] === f);
    sb.innerHTML = `
      <div class="sidebar-inner">
        <div class="account-switcher" data-account-switch>
          ${acct ? this.avatarHtml(acct.display_name, acct.email) : ''}
          <div class="as-info">
            <div class="as-email">${this.escapeHtml(acct ? acct.email : 'No account')}</div>
            <div class="as-name">${this.escapeHtml(acct && acct.display_name ? acct.display_name : '')}</div>
          </div>
          <span class="as-chev"><i class="ti ti-chevron-down"></i></span>
        </div>
        <nav class="folder-list">
          <a class="nav-item ${isFolder('inbox') ? 'active' : ''}" href="#inbox"><span class="nav-icon"><i class="ti ti-inbox"></i></span>Inbox${unread.inbox ? `<span class="badge">${unread.inbox}</span>` : ''}</a>
          <a class="nav-item ${isFolder('starred') ? 'active' : ''}" href="#starred"><span class="nav-icon"><i class="ti ti-star"></i></span>Starred</a>
          <a class="nav-item ${isFolder('sent') ? 'active' : ''}" href="#sent"><span class="nav-icon"><i class="ti ti-send"></i></span>Sent</a>
          <a class="nav-item ${isFolder('drafts') ? 'active' : ''}" href="#drafts"><span class="nav-icon"><i class="ti ti-file-text"></i></span>Drafts</a>
          <a class="nav-item ${isFolder('all') ? 'active' : ''}" href="#all"><span class="nav-icon"><i class="ti ti-folder"></i></span>All mail</a>
        </nav>
        <div class="sidebar-title">Tags</div>
        <nav class="tag-list">
          ${this.tags.length ? this.tags.map(t =>
            `<a class="tag ${this.currentTagId == t.id ? 'active' : ''}" href="#tag/${t.id}" style="--tag-color:${this.escapeHtml(t.color)}">${this.escapeHtml(t.name)}</a>`
          ).join('') : ''}
          <button class="nav-item add-tag" data-add-tag><span class="nav-icon"><i class="ti ti-plus"></i></span>New tag</button>
        </nav>
        <div class="sidebar-footer">
          <a class="nav-item ${this.currentView === '#contacts' ? 'active' : ''}" href="#contacts"><span class="nav-icon"><i class="ti ti-users"></i></span>Contacts</a>
          <a class="nav-item ${this.currentView === '#settings' ? 'active' : ''}" href="#settings"><span class="nav-icon"><i class="ti ti-settings"></i></span>Settings</a>
        </div>
        <div class="sidebar-app-footer">
          <p>Powered by <a href="https://github.com/Abtz-Labs/Emmmail" target="_blank" rel="noopener noreferrer">Emmmail</a> &mdash; <a href="https://github.com/Abtz-Labs/Emmmail/blob/main/LICENSE" target="_blank" rel="noopener noreferrer">O'Saasy Licensed</a> &bull; #{{VERSION}} &copy; Abtz Labs.</p>
        </div>
      </div>`;
    this.bindSidebarEvents();
  },

  bindSidebarEvents: function () {
    const switcher = this.el('[data-account-switch]');
    if (switcher) switcher.addEventListener('click', () => this.showAccountSwitcher());
    const addTag = this.el('[data-add-tag]');
    if (addTag) addTag.addEventListener('click', () => this.showNewTagModal());
  },

  showAccountSwitcher: function () {
    this.showModal('Switch account',
      this.accounts.map(a =>
        `<button class="menu-item" data-switch="${a.id}">${this.avatarHtml(a.display_name, a.email)}
          <span class="mi-main">${this.escapeHtml(a.email)}<span class="mi-sub">${this.escapeHtml(a.display_name || '')}</span></span>
          ${a.id === this.currentAccountId ? '<span class="mi-check"><i class="ti ti-check"></i></span>' : ''}</button>`
      ).join(''),
      '<button class="btn btn-ghost" data-add-acct>＋ Add account</button>');
    const ov = this.el('.modal-overlay');
    ov.querySelectorAll('[data-switch]').forEach(b => b.addEventListener('click', () => {
      this.switchAccount(+b.dataset.switch);
      this.closeModal();
    }));
    const add = ov.querySelector('[data-add-acct]');
    if (add) add.addEventListener('click', () => { this.closeModal(); this.openAddAccount(); });
  },

  switchAccount: function (id) {
    this.currentAccountId = id;
    this.selectedThreadIds.clear();
    this.currentTagId = null;
    this.loadTags().then(() => {
      this.renderSidebar();
      this.navigate(this.currentView);
    });
  },

  showNewTagModal: function () {
    this.showModal('New tag',
      `<label class="field"><span>Name</span><input class="input" data-tag-name placeholder="e.g. Work"></label>
       <label class="field"><span>Color</span><input class="input" type="color" data-tag-color value="#4f8cff" style="height:44px;padding:4px"></label>`,
      '<button class="btn btn-primary" data-tag-create>Create</button>');
    const ov = this.el('.modal-overlay');
    const create = ov.querySelector('[data-tag-create]');
    const doCreate = async () => {
      const name = ov.querySelector('[data-tag-name]').value.trim();
      if (!name) { this.showToast('Tag needs a name', 'error'); return; }
      try {
        await this.api('create_tag', { name, color: ov.querySelector('[data-tag-color]').value }, 'POST');
        this.closeModal();
        await this.loadTags();
        this.renderSidebar();
      } catch (err) { this.showToast(err.message, 'error'); }
    };
    create.addEventListener('click', doCreate);
    ov.querySelector('[data-tag-name]').addEventListener('keydown', (e) => { if (e.key === 'Enter') doCreate(); });
  },

  loadThreads: async function (folder) {
    this.currentFolder = folder;
    this.currentTagId = null;
    this.showSkeleton('list');
    try {
      const data = await this.api('list_messages', { folder, account_id: this.currentAccountId });
      this.threads = data.threads || data.messages || [];
      this.renderMessageList();
    } catch (err) {
      this.threads = [];
      this.renderMessageList();
      this.showToast(err.message, 'error');
    }
  },

  loadTagThreads: async function (tagId) {
    this.currentFolder = 'tag';
    this.showSkeleton('list');
    try {
      const data = await this.api('list_messages', { tag_id: tagId, account_id: this.currentAccountId });
      this.threads = data.threads || data.messages || [];
      this.renderMessageList();
    } catch (err) {
      this.threads = [];
      this.renderMessageList();
      this.showToast(err.message, 'error');
    }
  },

  folderTitle: function (f) {
    if (f === 'tag') {
      const t = this.tags.find(tag => tag.id == this.currentTagId);
      return t ? '#' + t.name : 'Tag';
    }
    return { inbox: 'Inbox', sent: 'Sent', drafts: 'Drafts', starred: 'Starred', all: 'All mail' }[f] || 'Mail';
  },

  showSkeleton: function (which) {
    if (which === 'list') {
      this.el('#pane-list').innerHTML = `<div class="message-list">${this.skeletonRows(8)}</div>`;
    } else {
      this.el('#pane-detail').innerHTML = `<div class="thread-skeleton">
        <span class="skeleton line w40"></span>
        <span class="skeleton block"></span>
        <span class="skeleton block"></span>
      </div>`;
    }
  },

  skeletonRows: function (n) {
    let s = '';
    for (let i = 0; i < n; i++) {
      s += `<div class="skeleton-row"><span class="skeleton circle"></span><div class="skeleton-lines"><span class="skeleton line w60"></span><span class="skeleton line w90"></span></div></div>`;
    }
    return s;
  },

  renderMessageList: function () {
    const list = this.el('#pane-list');
    const title = this.folderTitle(this.currentFolder);
    const empty = !this.threads.length;
    list.innerHTML = `
      ${this.listHeaderHtml(title)}
      ${empty
        ? this.emptyStateHtml(this.emptyStateFor(this.currentFolder))
        : `<div class="message-list">${this.threads.map((t, i) => this.messageRowHtml(t, i)).join('')}</div>`}
      ${this.syncStatusHtml()}`;
  },

  listHeaderHtml: function (title) {
    const unread = this.unreadCounts();
    const n = this.threads.length;
    return `
      <div class="list-header">
        <div class="list-header-top">
          <h1>${this.escapeHtml(title)}</h1>
          <div class="list-actions">
            <button class="btn-ghost" data-refresh title="Sync"><i class="ti ti-refresh"></i></button>
            <button class="btn-ghost ${this.isMultiSelectMode ? 'active' : ''}" data-multi title="Multi-select"><i class="ti ti-checkbox"></i></button>
          </div>
        </div>
        ${this.isMultiSelectMode ? `
          <div class="list-select-bar">
            <label class="checkbox"><input type="checkbox" data-select-all ${this.allSelected() ? 'checked' : ''}><span></span></label>
            <span class="sel-count" data-sel-count>${this.selectedThreadIds.size} selected</span>
            <button class="btn-ghost" data-deselect>Clear</button>
          </div>`
        : `<div class="list-subtitle">${n} message${n === 1 ? '' : 's'}${unread.inbox ? ` &middot; ${unread.inbox} unread` : ''}</div>`}
      </div>`;
  },

  messageRowHtml: function (t, idx) {
    const sel = this.selectedThreadIds.has(t.id);
    return `
      <div class="message-row ${t.unread ? 'unread' : ''} ${sel ? 'selected' : ''} ${idx === this._focusedIdx ? 'focused' : ''}" data-thread data-id="${t.id}">
        ${this.isMultiSelectMode ? `<label class="checkbox"><input type="checkbox" data-select ${sel ? 'checked' : ''}><span></span></label>` : ''}
        <button class="star-btn ${t.starred ? 'on' : ''}" data-star title="Star"><i class="ti ti-star"></i></button>
        ${this.avatarHtml(t.sender_name, t.sender_email)}
        <div class="row-main" data-open>
          <div class="row-top">
            <span class="row-sender">${this.escapeHtml(t.sender_name || t.sender_email || '(no sender)')}</span>
            <span class="row-date">${this.formatDate(t.date)}</span>
          </div>
          <div class="row-subject">${this.escapeHtml(t.subject || '(no subject)')}${t.count > 1 ? `<span class="row-count"> (${t.count})</span>` : ''}</div>
          <div class="row-preview">${this.escapeHtml(t.preview || '')}${t.has_attachments ? ' <i class="ti ti-paperclip"></i>' : ''}</div>
        </div>
        ${this.tagDotsHtml(t.tag_ids)}
      </div>`;
  },

  tagDotsHtml: function (tagIds) {
    if (!tagIds || !tagIds.length) return '';
    const tags = this.tags.filter(t => tagIds.includes(t.id));
    if (!tags.length) return '';
    return `<div class="tag-dots">${tags.map(t => `<span class="tag-dot" style="background:${this.escapeHtml(t.color)}" title="${this.escapeHtml(t.name)}"></span>`).join('')}</div>`;
  },

  emptyStateFor: function (folder) {
    const map = {
      inbox: { icon: '<i class="ti ti-circle-check"></i>', title: 'Inbox zero', text: 'You\u2019re all caught up. New mail will appear here.' },
      sent: { icon: '<i class="ti ti-send"></i>', title: 'No sent mail', text: 'Messages you send will appear here.' },
      drafts: { icon: '<i class="ti ti-file-text"></i>', title: 'No drafts', text: 'Your saved drafts will appear here.' },
      starred: { icon: '<i class="ti ti-star"></i>', title: 'No starred messages', text: 'Star important messages to find them here.' },
      all: { icon: '<i class="ti ti-mail"></i>', title: 'No messages', text: 'Your mailbox is empty.' }
    };
    return map[folder] || map.all;
  },

  emptyStateHtml: function (o) {
    return `<div class="empty-state">
      <div class="empty-icon">${o.icon || '<i class="ti ti-mail"></i>'}</div>
      <h3>${o.title || 'Nothing here'}</h3>
      <p>${o.text || ''}</p>
      ${o.action ? `<button class="btn btn-primary" data-empty-action="${o.action}">${o.actionLabel || 'Continue'}</button>` : ''}
    </div>`;
  },

  syncStatusHtml: function () {
    const acct = this.getAccount();
    if (!acct) return '';
    if (acct.sync_error) return `<div class="sync-status error"><i class="ti ti-alert-triangle"></i> ${this.escapeHtml(acct.sync_error)}</div>`;
    return `<div class="sync-status">Last synced ${this.formatDate(acct.last_sync_at) || 'never'} &middot; <a href="#" data-sync-now>Sync now</a></div>`;
  },

  openThread: async function (id) {
    document.body.classList.add('detail-view');
    this.showSkeleton('detail');
    try {
      const data = await this.api('get_thread', { id });
      this.currentThread = data.thread || data;
      this.threadMessages = data.messages || (data.thread && data.thread.messages) || [];
      this.renderThread();
      const th = this.threads.find(t => t.id == id);
      if (th && th.unread) {
        th.unread = false;
        this.api('set_flags', { thread_id: id, read: true }, 'POST').catch(() => {});
      }
    } catch (err) {
      this.el('#pane-detail').innerHTML = this.emptyStateHtml({ icon: '<i class="ti ti-alert-triangle"></i>', title: 'Could not load message', text: err.message, action: this._prevListHash, actionLabel: 'Go back' });
    }
  },

  openThreadById: function (id) {
    if (!id) return;
    if (!this._prevListHash) this._prevListHash = location.hash || '#inbox';
    this.navigate('#thread/' + id);
  },

  renderThread: function () {
    const detail = this.el('#pane-detail');
    const th = this.currentThread || {};
    const msgs = this.threadMessages || [];
    const last = msgs[msgs.length - 1];
    const participants = Array.from(new Set(msgs.map(m => m.from_name || m.from_address).filter(Boolean))).join(', ');
    detail.innerHTML = `
      <div class="thread-view">
        <div class="thread-header">
          <button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button>
          <div class="thread-title-block">
            <h1 class="thread-subject">${this.escapeHtml(th.subject || '(no subject)')}</h1>
            <div class="thread-participants">${this.escapeHtml(participants)}</div>
          </div>
          <div class="thread-actions">
            <button class="btn-ghost" data-archive title="Archive">Archive</button>
            <button class="btn-ghost" data-star-thread title="Star"><i class="ti ti-star${th.starred ? '' : '-off'}"></i></button>
            <button class="btn-ghost" data-delete title="Delete">Delete</button>
          </div>
        </div>
        <div class="message-stack">
          ${msgs.map((m, i) => this.messageCardHtml(m, i)).join('')}
        </div>
        ${last ? `<div class="thread-reply-bar">
          <button class="btn btn-primary" data-reply="${last.id}"><i class="ti ti-arrow-back"></i> Reply</button>
          <button class="btn" data-reply-all="${last.id}"><i class="ti ti-arrow-back-up"></i> Reply all</button>
          <button class="btn btn-ghost" data-forward="${last.id}"><i class="ti ti-arrow-forward-right"></i> Forward</button>
        </div>` : ''}
      </div>`;
    this.bindThreadEvents();
  },

  messageCardHtml: function (m, idx) {
    const collapsed = idx > 0 ? 'collapsed' : '';
    const toList = Array.isArray(m.to_addresses) ? m.to_addresses.map(a => a.name || a.email).join(', ') : (m.to_addresses || '');
    const body = m.html_body ? this.renderHtmlBody(m) : this.renderTextBody(m.text_body);
    const atts = Array.isArray(m.attachments) ? m.attachments : [];
    return `
      <div class="message-card ${collapsed}" data-msg-id="${m.id}">
        <div class="msg-collapsed-bar" data-expand>${this.escapeHtml(m.from_name || m.from_address)} &mdash; ${this.escapeHtml(m.subject || '(no subject)')}</div>
        <div class="msg-header">
          ${this.avatarHtml(m.from_name, m.from_address)}
          <div class="msg-id">
            <div class="msg-from">${this.escapeHtml(m.from_name || m.from_address)} <span class="msg-email">&lt;${this.escapeHtml(m.from_address || '')}&gt;</span></div>
            <div class="msg-to">to ${this.escapeHtml(toList)}</div>
            <div class="msg-date">${this.formatDate(m.date)}</div>
          </div>
          <div class="msg-actions">
            <button class="msg-collapse-btn" data-collapse title="Toggle"><i class="ti ti-chevron-${collapsed ? 'right' : 'down'}"></i></button>
          </div>
        </div>
        <div class="msg-body">${body}</div>
        ${atts.length ? `<div class="attachment-strip">${atts.map(a =>
          `<a class="attachment-chip" href="index.php?action=get_attachment&amp;id=${a.id}"><i class="ti ti-paperclip"></i> ${this.escapeHtml(a.filename || 'attachment')} <span>(${this.formatSize(a.size)})</span></a>`
        ).join('')}</div>` : ''}
        <div class="msg-toolbar">
          <button data-reply="${m.id}">Reply</button>
          <button data-reply-all="${m.id}">Reply all</button>
          <button data-forward="${m.id}">Forward</button>
        </div>
      </div>`;
  },

  renderTextBody: function (text) {
    if (!text) return '<div class="text-body"><span style="color:var(--text-secondary)">No content</span></div>';
    const lines = String(text).replace(/\r\n/g, '\n').split('\n');
    let html = '<div class="text-body">';
    let inQuote = false;
    const closeQuote = () => {
      if (inQuote) { html += '</div></blockquote>'; inQuote = false; }
    };
    for (const line of lines) {
      if (/^\s*>/.test(line)) {
        if (!inQuote) {
          closeQuote();
          html += '<blockquote class="quoted-text"><button type="button" class="quote-toggle"><i class="ti ti-chevron-right"></i> Show quoted text</button><div class="quote-body hidden">';
          inQuote = true;
        }
        html += `<div>${this.escapeHtml(line)}</div>`;
      } else {
        closeQuote();
        if (line === '') html += '<br>';
        else html += `<div>${this.escapeHtml(line)}</div>`;
      }
    }
    closeQuote();
    html += '</div>';
    return html;
  },

  renderHtmlBody: function (m) {
    let html = m.html_body || m.text_body || '';
    html = this.sanitizeHtml(html);
    const blockImages = !this.settings.show_remote_images && !this._showImages.has(m.id);
    const style = blockImages
      ? 'img{max-width:100%;height:auto} img[src^="http"]{display:none!important}'
      : 'img{max-width:100%;height:auto}';
    const doc = '<!doctype html><html><head><meta charset="utf-8"><style>body{font:15px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1f2430;margin:8px}' + style + '</style></head><body>' + html + '</body></html>';
    return `<div class="html-body-wrap">
      ${blockImages ? '<div class="image-block"><button class="btn btn-ghost" data-show-images="' + m.id + '">Show images</button></div>' : ''}
      <iframe class="html-body" sandbox="allow-same-origin" srcdoc="${this.escapeHtml(doc)}"></iframe>
    </div>`;
  },

  sanitizeHtml: function (html) {
    return String(html)
      .replace(/<script[\s\S]*?<\/script>/gi, '')
      .replace(/<iframe[\s\S]*?<\/iframe>/gi, '')
      .replace(/<object[\s\S]*?<\/object>/gi, '')
      .replace(/\son\w+\s*=\s*("[^"]*"|'[^']*'|[^\s>]+)/gi, '')
      .replace(/(href|src)\s*=\s*("|')?\s*javascript:[^"'>]*("|')?/gi, '$1=""');
  },

  bindThreadEvents: function () {
    const view = this.el('.thread-view');
    if (!view) return;
    view.querySelectorAll('[data-back]').forEach(b => b.addEventListener('click', () => {
      const prev = this._prevListHash || '#inbox';
      this._prevListHash = null;
      this.navigate(prev);
    }));
    view.querySelectorAll('[data-reply]').forEach(b => b.addEventListener('click', () => this.replyTo(b.dataset.reply, false)));
    view.querySelectorAll('[data-reply-all]').forEach(b => b.addEventListener('click', () => this.replyTo(b.dataset.replyAll, true)));
    view.querySelectorAll('[data-forward]').forEach(b => b.addEventListener('click', () => this.forwardMessage(b.dataset.forward)));
    view.querySelectorAll('[data-archive]').forEach(b => b.addEventListener('click', () => this.archiveSelected()));
    view.querySelectorAll('[data-delete]').forEach(b => b.addEventListener('click', () => this.deleteSelected()));
    view.querySelectorAll('[data-star-thread]').forEach(b => b.addEventListener('click', () => this.toggleStarSelected()));
    view.querySelectorAll('[data-show-images]').forEach(b => b.addEventListener('click', () => {
      this._showImages.add(Number(b.dataset.showImages));
      this.renderThread();
    }));
    view.querySelectorAll('.quote-toggle').forEach(b => b.addEventListener('click', () => {
      const qb = b.parentElement.querySelector('.quote-body');
      if (qb) qb.classList.toggle('hidden');
      b.textContent = qb.classList.contains('hidden') ? '\u25B6 Show quoted text' : '\u25BC Hide quoted text';
    }));
    view.querySelectorAll('.msg-collapse-btn').forEach(b => b.addEventListener('click', () => {
      b.closest('.message-card').classList.toggle('collapsed');
      b.textContent = b.closest('.message-card').classList.contains('collapsed') ? '\u25B6' : '\u25BC';
    }));
    view.querySelectorAll('[data-expand]').forEach(b => b.addEventListener('click', () => {
      b.closest('.message-card').classList.remove('collapsed');
      b.closest('.message-card').querySelector('.msg-collapse-btn').textContent = '\u25BC';
    }));
  },

  renderCompose: function () {
    const overlay = this.el('#compose-overlay');
    const cs = this.composeState;
    if (!cs) return;
    const title = cs.mode === 'reply' ? 'Reply' : cs.mode === 'forward' ? 'Forward' : 'New Message';
    overlay.innerHTML = `
      <div class="compose-window">
        <div class="compose-header">
          <span class="compose-title">${title}</span>
          <div class="compose-header-actions">
            <button class="topbar-icon" data-minimize title="Minimize" style="width:32px;height:32px;font-size:14px"><i class="ti ti-minus"></i></button>
            <button class="topbar-icon" data-close title="Close" style="width:32px;height:32px;font-size:14px"><i class="ti ti-x"></i></button>
          </div>
        </div>
        <div class="compose-body">
          <div class="compose-row">
            <span class="compose-label">From</span>
            <select class="compose-from" data-from>
              ${this.accounts.map(a => `<option value="${a.id}" ${a.id === cs.accountId ? 'selected' : ''}>${this.escapeHtml(a.email)}</option>`).join('')}
            </select>
          </div>
          ${this.recipientFieldHtml('to')}
          ${cs.showCc ? this.recipientFieldHtml('cc') : '<button class="compose-toggle" data-toggle-cc>Add Cc</button>'}
          ${cs.showBcc ? this.recipientFieldHtml('bcc') : '<button class="compose-toggle" data-toggle-bcc>Add Bcc</button>'}
          <div class="compose-row">
            <input class="compose-subject" data-subject placeholder="Subject" value="${this.escapeHtml(cs.subject)}" autocomplete="off">
          </div>
          <div class="compose-toolbar">
            <button data-cmd="bold" title="Bold"><b>B</b></button>
            <button data-cmd="italic" title="Italic"><i>I</i></button>
            <button data-cmd="createLink" title="Link"><i class="ti ti-link"></i></button>
            <button data-cmd="insertUnorderedList" title="List"><i class="ti ti-list"></i></button>
            <button data-cmd="formatBlock" title="Quote" data-format="blockquote"><i class="ti ti-quote"></i></button>
          </div>
          <div class="compose-editor" contenteditable="true" data-editor data-placeholder="Write your message\u2026"></div>
          <div class="attach-dropzone" data-dropzone>
            <div><i class="ti ti-paperclip"></i> Drag files here or <button type="button" data-browse>Browse</button></div>
            <input type="file" multiple hidden data-file>
          </div>
          <div class="attach-list" data-attach-list></div>
          <div class="compose-footer">
            <button class="btn btn-primary" data-send>Send</button>
            <span class="send-hint">Ctrl/⌘+Enter to send</span>
            <span class="compose-draftsaved"></span>
            <button class="btn btn-ghost" data-discard>Discard</button>
          </div>
        </div>
      </div>`;
    this.bindComposeEvents();
    const editor = this.el('[data-editor]');
    if (editor && cs.body) editor.innerHTML = cs.body;
    if (editor && !cs.body && this.settings.signature) {
      editor.innerHTML = '<br><br><div>' + this.escapeHtml(this.settings.signature).replace(/\n/g, '<br>') + '</div>';
    }
    this.refreshRecipientChips();
    this.renderAttachList();
  },

  recipientFieldHtml: function (field) {
    const label = { to: 'To', cc: 'Cc', bcc: 'Bcc' }[field];
    const remove = field === 'cc' ? '<button class="recipient-remove" data-remove-cc title="Remove Cc"><i class="ti ti-x"></i></button>'
      : field === 'bcc' ? '<button class="recipient-remove" data-remove-bcc title="Remove Bcc"><i class="ti ti-x"></i></button>' : '';
    return `
      <div class="compose-row" data-recipient-field="${field}">
        <span class="compose-label">${label}</span>
        <div class="chips-wrap">
          <span class="chips" data-chips="${field}"></span>
          <input class="recipient-input" data-recipient-input="${field}" placeholder="Email address" autocomplete="off" spellcheck="false">
          <div class="recipient-suggest hidden" data-suggest="${field}"></div>
        </div>
        ${remove}
      </div>`;
  },

  refreshRecipientChips: function () {
    for (const field of ['to', 'cc', 'bcc']) {
      const chips = this.el(`[data-chips="${field}"]`);
      if (!chips) continue;
      chips.innerHTML = this.composeState[field].map(r =>
        `<span class="chip">${this.escapeHtml(r.name || r.email)}${r.email && r.email !== r.name ? `<span class="chip-email">${this.escapeHtml(r.email)}</span>` : ''}<button class="chip-remove" data-chip-remove="${field}" data-email="${this.escapeHtml(r.email)}"><i class="ti ti-x"></i></button></span>`
      ).join('');
    }
  },

  bindComposeEvents: function () {
    this.el('[data-close]')?.addEventListener('click', () => this.closeCompose(false));
    this.el('[data-minimize]')?.addEventListener('click', () => this.el('.compose-window')?.classList.add('minimized'));
    this.el('.compose-title')?.addEventListener('click', () => this.el('.compose-window')?.classList.remove('minimized'));
    this.el('[data-discard]')?.addEventListener('click', () => this.discardDraft());
    this.el('[data-toggle-cc]')?.addEventListener('click', () => { this.composeState.showCc = true; this.renderCompose(); });
    this.el('[data-toggle-bcc]')?.addEventListener('click', () => { this.composeState.showBcc = true; this.renderCompose(); });
    this.el('[data-remove-cc]')?.addEventListener('click', () => { this.composeState.showCc = false; this.composeState.cc = []; this.renderCompose(); });
    this.el('[data-remove-bcc]')?.addEventListener('click', () => { this.composeState.showBcc = false; this.composeState.bcc = []; this.renderCompose(); });
    this.el('[data-from]')?.addEventListener('change', (e) => { this.composeState.accountId = +e.target.value; });
    this.el('[data-subject]')?.addEventListener('input', (e) => { this.composeState.subject = e.target.value; this.markDirty(); });
    this.el('[data-editor]')?.addEventListener('input', () => this.markDirty());
    this.el('[data-send]')?.addEventListener('click', () => this.sendMessage());
    for (const field of ['to', 'cc', 'bcc']) {
      const input = this.el(`[data-recipient-input="${field}"]`);
      if (!input) continue;
      input.addEventListener('keydown', (e) => this.handleRecipientKeydown(e, field));
      input.addEventListener('input', (e) => this.handleRecipientInput(e, field));
      input.addEventListener('blur', () => setTimeout(() => this.hideSuggest(field), 150));
    }
    this.el('.compose-toolbar')?.addEventListener('click', (e) => {
      const btn = e.target.closest('[data-cmd]');
      if (!btn) return;
      e.preventDefault();
      const cmd = btn.dataset.cmd;
      const arg = btn.dataset.format || null;
      this.el('[data-editor]')?.focus();
      if (cmd === 'createLink') {
        const url = prompt('Link URL:', 'https://');
        if (url) document.execCommand(cmd, false, url);
      } else {
        document.execCommand(cmd, false, arg);
      }
      this.markDirty();
    });
    const dz = this.el('[data-dropzone]');
    if (dz) {
      dz.addEventListener('dragover', (e) => { e.preventDefault(); dz.classList.add('dragover'); });
      dz.addEventListener('dragleave', () => dz.classList.remove('dragover'));
      dz.addEventListener('drop', (e) => { e.preventDefault(); dz.classList.remove('dragover'); this.handleAttachmentDrop(e); });
      dz.querySelector('[data-browse]')?.addEventListener('click', () => dz.querySelector('[data-file]').click());
      dz.querySelector('[data-file]')?.addEventListener('change', (e) => {
        for (const f of e.target.files) this.addAttachment(f);
        e.target.value = '';
      });
    }
    this.el('[data-editor]')?.addEventListener('paste', (e) => {
      const items = [...(e.clipboardData?.items || [])];
      const files = items.filter(i => i.kind === 'file').map(i => i.getAsFile());
      if (files.length) { e.preventDefault(); files.forEach(f => this.addAttachment(f)); }
    });
  },

  markDirty: function () {
    if (this.composeState) this.composeState.dirty = true;
  },

  handleRecipientKeydown: function (e, field) {
    const input = e.target;
    if (e.key === 'Enter' || e.key === ',' || e.key === ';') {
      e.preventDefault();
      const val = input.value.trim().replace(/[,;]$/, '');
      if (val) {
        this.addRecipientChip(field, val);
        input.value = '';
        this.hideSuggest(field);
      }
    } else if (e.key === 'Backspace' && !input.value && this.composeState[field].length) {
      this.composeState[field].pop();
      this.refreshRecipientChips();
      this.markDirty();
    }
  },

  handleRecipientInput: async function (e, field) {
    const q = e.target.value.trim();
    const suggest = this.el(`[data-suggest="${field}"]`);
    if (!suggest) return;
    if (!q) { this.hideSuggest(field); return; }
    if (this.isValidEmail(q)) {
      suggest.innerHTML = `<button type="button" class="suggest-item" data-pick="${this.escapeHtml(q)}">${this.escapeHtml(q)}</button>`;
      suggest.classList.remove('hidden');
    } else {
      const contacts = await this.api('search_contacts', { q, account_id: this.currentAccountId }).catch(() => []);
      const list = contacts.contacts || contacts || [];
      suggest.innerHTML = list.slice(0, 6).map(c =>
        `<button type="button" class="suggest-item" data-pick="${this.escapeHtml(c.email)}" data-pick-name="${this.escapeHtml(c.name || '')}"><b>${this.escapeHtml(c.name || c.email)}</b> <span>&lt;${this.escapeHtml(c.email)}&gt;</span></button>`
      ).join('') || '';
      suggest.classList.toggle('hidden', !suggest.innerHTML);
    }
    suggest.querySelectorAll('[data-pick]').forEach(b => b.addEventListener('click', () => {
      const email = b.dataset.pick;
      const name = b.dataset.pickName || email;
      this.addRecipientChip(field, email, name);
      const input = this.el(`[data-recipient-input="${field}"]`);
      if (input) input.value = '';
      this.hideSuggest(field);
    }));
  },

  hideSuggest: function (field) {
    const s = this.el(`[data-suggest="${field}"]`);
    if (s) s.classList.add('hidden');
  },

  addRecipientChip: function (field, email, name) {
    email = String(email || '').trim().toLowerCase();
    if (!this.isValidEmail(email)) { this.showToast('Invalid email address', 'error'); return; }
    if (this.composeState[field].some(r => r.email.toLowerCase() === email)) return;
    this.composeState[field].push({ name: String(name || '').trim() || email, email });
    this.refreshRecipientChips();
    this.markDirty();
  },

  isValidEmail: function (s) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(s || '').trim());
  },

  renderAttachList: function () {
    const box = this.el('[data-attach-list]');
    if (!box) return;
    const atts = this.composeState.attachments || [];
    if (!atts.length) { box.innerHTML = ''; return; }
    box.innerHTML = atts.map((a, i) =>
      `<span class="attach-item"><span class="fn"><i class="ti ti-paperclip"></i> ${this.escapeHtml(a.filename)}</span> <span>(${this.formatSize(a.size)})</span><button class="attach-remove" data-remove-attach="${i}"><i class="ti ti-x"></i></button></span>`
    ).join('');
    box.querySelectorAll('[data-remove-attach]').forEach(b => b.addEventListener('click', () => {
      this.composeState.attachments.splice(+b.dataset.removeAttach, 1);
      this.renderAttachList();
    }));
  },

  handleAttachmentDrop: function (e) {
    const files = [...(e.dataTransfer ? e.dataTransfer.files : [])];
    files.forEach(f => this.addAttachment(f));
  },

  addAttachment: async function (file) {
    if (!file) return;
    const total = (this.composeState.attachments || []).reduce((s, a) => s + (a.size || 0), 0) + file.size;
    if (total > 25 * 1024 * 1024) { this.showToast('Attachment total exceeds 25MB', 'error'); return; }
    const t = this.showToast('Uploading ' + file.name + '\u2026', 'info', null, { ttl: 0 });
    try {
      const up = await this.uploadFile(file);
      t.update(file.name + ' attached');
      t.dismiss();
      this.composeState.attachments.push(up);
      this.renderAttachList();
    } catch (err) {
      t.update('Upload failed: ' + err.message);
      setTimeout(() => t.dismiss(), 3000);
    }
  },

  uploadFile: async function (file) {
    const fd = new FormData();
    fd.append('file', file);
    const opts = { method: 'POST', headers: {}, body: fd };
    if (this.csrfToken) opts.headers['X-CSRF-Token'] = this.csrfToken;
    const res = await fetch('index.php?action=upload_attachment', opts);
    const json = await res.json().catch(() => null);
    if (!json || json.ok === false) throw new Error((json && json.error) || 'Upload failed');
    return json.data || json;
  },

  openCompose: async function (opts = {}) {
    if (opts.mode && (opts.mode === 'reply' || opts.mode === 'forward') && opts.messageId && !opts.message) {
      try {
        opts.message = await this.api('get_message', { id: opts.messageId });
      } catch (err) {
        this.showToast(err.message, 'error');
        return;
      }
    }
    const cs = {
      to: [], cc: [], bcc: [], subject: '', body: '', plainText: '',
      attachments: [], inReplyTo: null, accountId: this.currentAccountId,
      draftId: null, showCc: false, showBcc: false, dirty: false,
      mode: opts.mode || 'new', original: opts.message || null
    };
    if (opts.mode === 'reply') {
      const m = opts.message;
      const me = this.getAccount();
      cs.to = [{ name: m.from_name, email: m.from_address }].filter(r => r.email !== (me && me.email));
      if (!cs.to.length) cs.to = [];
      if (opts.all) {
        cs.cc = (m.to_addresses || []).filter(a => a.email !== (me && me.email));
        const senders = (m.cc_addresses || []).filter(a => a.email !== (me && me.email));
        cs.cc = cs.cc.concat(senders);
      }
      cs.subject = m.subject && !/^re:/i.test(m.subject) ? 'Re: ' + m.subject : (m.subject || '');
      cs.inReplyTo = m.message_id;
      cs.body = this.buildQuote(m, 'replied');
    } else if (opts.mode === 'forward') {
      const m = opts.message;
      cs.subject = m.subject && !/^(fwd|fw):/i.test(m.subject) ? 'Fwd: ' + m.subject : (m.subject || '');
      cs.body = this.buildQuote(m, 'forwarded');
    }
    this.composeState = cs;
    this.renderCompose();
    this.el('#compose-overlay').classList.add('open');
    this.el('.compose-window')?.classList.remove('minimized');
    this.autosaveDraft();
    const to = this.el('[data-recipient-input="to"]');
    if (to) to.focus();
  },

  buildQuote: function (m, verb) {
    const from = m.from_name || m.from_address || 'unknown';
    const date = this.formatDate(m.date);
    let body = m.html_body || m.text_body || '';
    body = this.sanitizeHtml(body).slice(0, 20000);
    if (!m.html_body) body = String(body).replace(/\n/g, '<br>');
    return `<br><br><div class="gmail_quote"><div>On ${this.escapeHtml(date)}, ${this.escapeHtml(from)} wrote:</div><blockquote class="quoted">${body}</blockquote></div>`;
  },

  closeCompose: function (force) {
    const overlay = this.el('#compose-overlay');
    if (!overlay || !overlay.classList.contains('open')) return;
    const doClose = () => {
      overlay.classList.remove('open');
      if (this._draftTimer) { clearInterval(this._draftTimer); this._draftTimer = null; }
      this.composeState = null;
      overlay.innerHTML = '';
    };
    if (force || !this.composeState || !this.composeState.dirty) { doClose(); return; }
    this.showConfirm('Save draft?', 'Keep your unsaved changes as a draft?').then(keep => {
      if (keep) { this.saveDraft(false).finally(() => doClose()); }
      else { this.discardDraft(); doClose(); }
    });
  },

  discardDraft: function () {
    if (!this.composeState) return;
    const draftId = this.composeState.draftId;
    if (draftId) this.api('delete_draft', { id: draftId }, 'POST').catch(() => {});
    this.composeState = null;
    this.el('#compose-overlay')?.classList.remove('open');
    if (this.el('#compose-overlay')) this.el('#compose-overlay').innerHTML = '';
    this.showToast('Draft discarded');
  },

  autosaveDraft: function () {
    if (this._draftTimer) clearInterval(this._draftTimer);
    this._draftTimer = setInterval(() => this.saveDraft(true), 30000);
  },

  saveDraft: async function (silent) {
    if (!this.composeState) return;
    const cs = this.composeState;
    const editor = this.el('[data-editor]');
    if (editor) { cs.body = editor.innerHTML; cs.plainText = editor.innerText; }
    const empty = !cs.to.length && !cs.cc.length && !cs.bcc.length && !cs.subject && !(cs.body || '').replace(/<[^>]*>/g, '').trim();
    if (empty) return;
    const payload = {
      account_id: cs.accountId || this.currentAccountId,
      draft_id: cs.draftId,
      to_addresses: cs.to, cc_addresses: cs.cc, bcc_addresses: cs.bcc,
      subject: cs.subject, html_body: cs.body, text_body: cs.plainText,
      attachments_json: cs.attachments, in_reply_to_message_id: cs.inReplyTo
    };
    try {
      const res = await this.api('save_draft', payload, 'POST');
      cs.draftId = res.id || cs.draftId;
      const el = this.el('.compose-draftsaved');
      if (el) el.textContent = '✓ Draft saved ' + this.formatDate(new Date().toISOString());
      if (!silent) this.showToast('Draft saved');
    } catch (err) {
      if (!silent) this.showToast('Could not save draft: ' + err.message, 'error');
    }
  },

  sendMessage: async function () {
    const cs = this.composeState;
    if (!cs) return;
    const editor = this.el('[data-editor]');
    if (editor) { cs.body = editor.innerHTML; cs.plainText = editor.innerText; }
    if (!cs.to.length && !cs.cc.length && !cs.bcc.length) {
      this.showToast('Add at least one recipient', 'error');
      return;
    }
    const acct = this.getAccount(cs.accountId);
    const payload = {
      account_id: cs.accountId || this.currentAccountId,
      from_address: acct ? acct.email : null,
      to_addresses: cs.to, cc_addresses: cs.cc, bcc_addresses: cs.bcc,
      subject: cs.subject || '(no subject)',
      html_body: cs.body, text_body: cs.plainText || '',
      attachments_json: cs.attachments,
      in_reply_to_message_id: cs.inReplyTo
    };
    try {
      const res = await this.api('schedule_send', payload, 'POST');
      const id = res.id || res.outbox_id;
      const delay = res.delay || 10;
      const keepDraft = cs.draftId;
      this.closeCompose(true);
      if (keepDraft) this.api('delete_draft', { id: keepDraft }, 'POST').catch(() => {});
      this.showSendCountdown(id, delay);
    } catch (err) {
      this.showToast('Send failed: ' + err.message, 'error');
    }
  },

  showSendCountdown: function (id, seconds) {
    const start = Date.now();
    let remaining = seconds;
    const toast = this.showToast('Sending in ' + remaining + 's\u2026', 'info', {
      label: 'Undo',
      onClick: () => {
        this.cancelSend(id);
        clearInterval(timer);
        toast.dismiss();
      }
    }, { ttl: 0 });
    this._sendToasts[id] = toast;
    const timer = setInterval(() => {
      remaining = seconds - Math.floor((Date.now() - start) / 1000);
      if (remaining <= 0) {
        clearInterval(timer);
        delete this._sendToasts[id];
        toast.update('Sending\u2026');
        this.processOutbox(id, toast);
      } else {
        this.updateSendCountdown(id, remaining, toast);
      }
    }, 250);
  },

  updateSendCountdown: function (id, seconds, toast) {
    const t = toast || this._sendToasts[id];
    if (t) t.update('Sending in ' + seconds + 's\u2026');
  },

  processOutbox: async function (id, toast) {
    try {
      await this.api('process_outbox', { id }, 'POST');
      toast.update('Message sent ✓', 'success');
      setTimeout(() => toast.dismiss(), 3500);
      this.loadThreads(this.currentFolder);
    } catch (err) {
      toast.update('Send failed: ' + err.message, 'error');
      setTimeout(() => toast.dismiss(), 5000);
    }
  },

  cancelSend: async function (id) {
    try {
      await this.api('cancel_send', { id }, 'POST');
      this.showToast('Send cancelled');
    } catch (err) {
      this.showToast(err.message, 'error');
    }
  },

  renderSearch: function (query) {
    const list = this.el('#pane-list');
    list.innerHTML = `
      <div class="list-header">
        <div class="list-header-top"><h1>Search</h1></div>
        <div class="search-query-bar">
          <input class="input" data-search-input placeholder="from:, to:, subject:, has:attachment" value="${this.escapeHtml(query)}">
          <button class="btn btn-primary" data-search-go>Search</button>
        </div>
        <div class="search-hints">
          Try <code>from:alice</code> <code>has:attachment</code> <code>subject:invoice</code> <code>date:2026-01</code>
        </div>
      </div>
      <div data-search-results></div>`;
    const input = this.el('[data-search-input]');
    const go = () => {
      const q = input.value.trim();
      if (!q) return;
      this.threads = [];
      this.performSearch(q);
    };
    this.el('[data-search-go]').addEventListener('click', go);
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') go(); });
    if (query) this.runSearch(query);
    else this.el('[data-search-results]').innerHTML = this.emptyStateHtml({ icon: '<i class="ti ti-search"></i>', title: 'Search mail', text: 'Find messages by sender, subject, or content.' });
  },

  performSearch: function (query) {
    if (!query) return;
    location.hash = '#search?q=' + encodeURIComponent(query);
    if (location.hash !== '#search?q=' + encodeURIComponent(query)) {
      this.renderSearch(query);
    }
  },

  runSearch: async function (query) {
    const box = this.el('[data-search-results]');
    if (!box) return;
    box.innerHTML = `<div class="message-list">${this.skeletonRows(5)}</div>`;
    const filters = this.parseSearchQuery(query);
    const params = Object.assign({ q: query, account_id: this.currentAccountId }, filters);
    try {
      const data = await this.api('search', params);
      this.threads = data.threads || data.results || data.messages || [];
      box.innerHTML = this.threads.length
        ? `<div class="message-list">${this.threads.map(t => this.messageRowHtml(t, 0)).join('')}</div>`
        : this.emptyStateHtml({ icon: '<i class="ti ti-search"></i>', title: 'No results', text: 'Nothing found for "' + this.escapeHtml(query) + '".' });
    } catch (err) {
      box.innerHTML = this.emptyStateHtml({ icon: '<i class="ti ti-alert-triangle"></i>', title: 'Search failed', text: err.message });
    }
  },

  parseSearchQuery: function (q) {
    const filters = { from: '', to: '', subject: '', has: '', date: '' };
    const words = [];
    for (const p of String(q || '').split(/\s+/)) {
      const m = p.match(/^(from|to|subject|has|date):(.*)$/i);
      if (m) filters[m[1].toLowerCase()] = m[2];
      else words.push(p);
    }
    filters.text = words.join(' ');
    return filters;
  },

  renderSettings: function () {
    const fs = this.el('#fullscreen');
    fs.hidden = false;
    fs.innerHTML = `
      <div class="overlay-view">
        <div class="overlay-card">
          <div class="settings-header"><button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button><h1>Settings</h1></div>
          <div class="settings-body">
            <div class="settings-section">
              <h2>Accounts</h2>
              ${this.accounts.map(a => `
                <div class="account-card">
                  ${this.avatarHtml(a.display_name, a.email)}
                  <div class="acct-info">
                    <div class="acct-email">${this.escapeHtml(a.email)}</div>
                    <div class="acct-sync">${a.sync_error
                      ? `<span class="sync-error"><i class="ti ti-alert-triangle"></i> ${this.escapeHtml(a.sync_error)}</span>`
                      : `Last synced ${this.formatDate(a.last_sync_at) || 'never'}`}</div>
                  </div>
                  <button class="btn-ghost" data-sync="${a.id}" title="Sync now"><i class="ti ti-refresh"></i></button>
                  <button class="btn-ghost danger" data-delete-acct="${a.id}">Remove</button>
                </div>`).join('')}
              <button class="btn btn-ghost" data-add-account>＋ Add account</button>
            </div>
            <div class="settings-section">
              <h2>Signature</h2>
              <textarea class="textarea signature-editor" data-signature placeholder="Your signature\u2026">${this.escapeHtml(this.settings.signature || '')}</textarea>
              <button class="btn btn-primary" data-save-signature>Save signature</button>
            </div>
            <div class="settings-section">
              <h2>Preferences</h2>
              <div class="setting-row"><span>Theme</span><button class="btn-ghost" data-theme-toggle>${this.settings.theme === 'light' ? 'Switch to dark' : 'Switch to light'}</button></div>
              <div class="setting-row"><span>Show remote images</span><label class="checkbox"><input type="checkbox" data-remote-images ${this.settings.show_remote_images ? 'checked' : ''}><span></span></label></div>
              <div class="setting-row"><span>Sync on load</span><label class="checkbox"><input type="checkbox" data-sync-on-load ${this.settings.sync_on_load ? 'checked' : ''}><span></span></label></div>
            </div>
            <button class="btn btn-ghost danger" data-logout>Log out</button>
          </div>
        </div>
      </div>`;
    this.bindSettingsEvents();
  },

  bindSettingsEvents: function () {
    this.el('[data-back]')?.addEventListener('click', () => {
      const prev = this._prevListHash || '#inbox';
      this._prevListHash = null;
      this.navigate(prev);
    });
    this.el('[data-sync]')?.addEventListener('click', async (e) => {
      const btn = e.target.closest('[data-sync]');
        btn.innerHTML = '<i class="ti ti-loader"></i>';
      try {
        await this.api('sync_account', { account_id: +btn.dataset.sync }, 'POST');
        this.showToast('Synced');
        await this.loadBaseData();
        this.renderSettings();
      } catch (err) { this.showToast(err.message, 'error'); btn.innerHTML = '<i class="ti ti-refresh"></i>'; }
    });
    this.el('[data-delete-acct]')?.addEventListener('click', async (e) => {
      const id = +e.target.dataset.deleteAcct;
      const acct = this.getAccount(id);
      const ok = await this.showConfirm('Remove account', 'Remove ' + this.escapeHtml(acct ? acct.email : 'this account') + '? Stored credentials will be deleted.');
      if (!ok) return;
      try {
        await this.api('delete_account', { account_id: id }, 'POST');
        this.showToast('Account removed');
        await this.loadBaseData();
        this.renderSettings();
        this.renderSidebar();
      } catch (err) { this.showToast(err.message, 'error'); }
    });
    this.el('[data-add-account]')?.addEventListener('click', () => this.openAddAccount());
    this.el('[data-save-signature]')?.addEventListener('click', async () => {
      const sig = this.el('[data-signature]').value;
      try {
        await this.api('update_signature', { signature: sig }, 'POST');
        this.settings.signature = sig;
        this.showToast('Signature saved');
      } catch (err) { this.showToast(err.message, 'error'); }
    });
    this.el('[data-theme-toggle]')?.addEventListener('click', () => this.toggleTheme());
    this.el('[data-remote-images]')?.addEventListener('change', async (e) => {
      this.settings.show_remote_images = e.target.checked;
      await this.api('update_settings', { show_remote_images: e.target.checked }, 'POST').catch(() => {});
    });
    this.el('[data-sync-on-load]')?.addEventListener('change', async (e) => {
      await this.api('update_settings', { sync_on_load: e.target.checked }, 'POST').catch(() => {});
    });
    this.el('[data-logout]')?.addEventListener('click', async () => {
      await this.api('auth_logout', {}, 'POST').catch(() => {});
      this.accounts = [];
      this.navigate('#login');
      this.renderSidebar();
    });
  },

  openAddAccount: function () {
    this.showModal('Add account',
      `<form data-add-form>${this.providerFormHtml('gmail')}</form>`,
      '<button class="btn btn-primary" data-add-now>Add account</button>');
    const ov = this.el('.modal-overlay');
    const form = ov.querySelector('[data-add-form]');
    this.bindProviderForm(form);
    ov.querySelector('[data-add-now]').addEventListener('click', async () => {
      try {
        await this.api('add_account', this.readAccountForm(form), 'POST');
        this.closeModal();
        this.showToast('Account added');
        await this.loadBaseData();
        this.renderSettings();
        this.renderSidebar();
      } catch (err) { this.setProgress(form, '✕ ' + err.message, true); }
    });
  },

  renderContacts: function () {
    const fs = this.el('#fullscreen');
    fs.hidden = false;
    fs.innerHTML = `
      <div class="overlay-view">
        <div class="overlay-card">
          <div class="settings-header"><button class="back-btn" data-back aria-label="Back"><i class="ti ti-arrow-left"></i></button><h1>Contacts</h1></div>
          <div class="settings-body">
            <input class="input contact-search" placeholder="Search contacts" data-contact-search>
            <div class="contact-list" data-contact-list></div>
          </div>
        </div>
      </div>`;
    this.el('[data-back]')?.addEventListener('click', () => {
      const prev = this._prevListHash || '#inbox';
      this._prevListHash = null;
      this.navigate(prev);
    });
    let timer = null;
    this.el('[data-contact-search]').addEventListener('input', (e) => {
      clearTimeout(timer);
      timer = setTimeout(() => this.loadContacts(e.target.value.trim()), 200);
    });
    this.loadContacts('');
  },

  loadContacts: async function (q) {
    const list = this.el('[data-contact-list]');
    if (!list) return;
    list.innerHTML = `<div class="message-list">${this.skeletonRows(5)}</div>`;
    try {
      const data = await this.api('list_contacts', { q, account_id: this.currentAccountId });
      const contacts = data.contacts || data || [];
      if (!contacts.length) {
        list.innerHTML = this.emptyStateHtml({ icon: '<i class="ti ti-users"></i>', title: 'No contacts', text: 'Contacts appear as you send and receive mail.' });
        return;
      }
      list.innerHTML = contacts.map(c => `
        <div class="contact-row" data-contact="${this.escapeHtml(c.email)}" data-name="${this.escapeHtml(c.name || '')}">
          ${this.avatarHtml(c.name, c.email)}
          <div><div class="c-name">${this.escapeHtml(c.name || '(no name)')}</div><div class="c-email">${this.escapeHtml(c.email)}</div></div>
          <span class="c-count">${c.use_count || 1}</span>
        </div>`).join('');
      list.querySelectorAll('.contact-row').forEach(row => row.addEventListener('click', () => {
        this.navigate(this._prevListHash || '#inbox');
        this.openCompose();
        setTimeout(() => this.addRecipientChip('to', row.dataset.contact, row.dataset.name), 50);
      }));
    } catch (err) {
      list.innerHTML = this.emptyStateHtml({ icon: '<i class="ti ti-alert-triangle"></i>', title: 'Could not load contacts', text: err.message });
    }
  },

  showToast: function (message, type, action, opts) {
    type = type || 'info';
    opts = opts || {};
    const container = this.el('#toast-container');
    const toast = document.createElement('div');
    toast.className = 'toast ' + type;
    const icons = { success: '✓', error: '✕', info: 'ℹ' };
    toast.innerHTML = `<span class="toast-icon">${icons[type] || ''}</span><span class="toast-msg"></span>`;
    toast.querySelector('.toast-msg').textContent = message;
    if (action) {
      const btn = document.createElement('button');
      btn.className = 'toast-action';
      btn.textContent = action.label;
      btn.addEventListener('click', () => {
        if (action.onClick) action.onClick();
        this.dismissToast(toast);
      });
      toast.appendChild(btn);
    }
    container.appendChild(toast);
    const handle = {
      el: toast,
      update: (msg, type2) => {
        toast.querySelector('.toast-msg').textContent = msg;
        if (type2) toast.className = 'toast ' + type2;
      },
      dismiss: () => this.dismissToast(toast),
      clear: null
    };
    if (opts.ttl !== 0) {
      const ttl = opts.ttl || 4000;
      handle.clear = setTimeout(() => this.dismissToast(toast), ttl);
    }
    return handle;
  },

  dismissToast: function (toast) {
    if (!toast || toast.classList.contains('out')) return;
    toast.classList.add('out');
    setTimeout(() => toast.remove(), 260);
  },

  showModal: function (title, bodyHtml, footerHtml, opts) {
    opts = opts || {};
    const ov = this.el('#modal-overlay');
    ov.innerHTML = `
      <div class="modal" role="dialog" aria-modal="true">
        <div class="modal-header"><h3>${this.escapeHtml(title)}</h3><button class="modal-close" data-close-modal><i class="ti ti-x"></i></button></div>
        <div class="modal-body">${bodyHtml}</div>
        ${footerHtml ? `<div class="modal-footer">${footerHtml}</div>` : ''}
      </div>`;
    ov.classList.add('open');
    this.modalOpen = true;
    ov.querySelector('[data-close-modal]').addEventListener('click', () => this.closeModal());
    ov.addEventListener('click', (e) => { if (e.target === ov && !opts.denyBackdrop) this.closeModal(); });
    return ov;
  },

  closeModal: function () {
    const ov = this.el('#modal-overlay');
    ov.classList.remove('open');
    this.modalOpen = false;
    setTimeout(() => { ov.innerHTML = ''; }, 200);
  },

  showConfirm: function (title, message) {
    return new Promise((resolve) => {
      this.showModal(title, `<p style="margin:6px 0">${message}</p>`,
        '<button class="btn btn-ghost" data-confirm-no>Cancel</button><button class="btn btn-primary" data-confirm-yes>OK</button>');
      const ov = this.el('.modal-overlay');
      ov.querySelector('[data-confirm-yes]').addEventListener('click', () => { this.closeModal(); resolve(true); });
      ov.querySelector('[data-confirm-no]').addEventListener('click', () => { this.closeModal(); resolve(false); });
      ov.addEventListener('click', (e) => { if (e.target === ov) { this.closeModal(); resolve(false); } });
    });
  },

  showShortcutsHelp: function () {
    const rows = [
      ['c', 'Compose new message'],
      ['r / a / f', 'Reply / Reply all / Forward'],
      ['e', 'Archive'],
      ['#', 'Delete'],
      ['s', 'Star / unstar'],
      ['Shift+u', 'Mark read / unread'],
      ['/ or Cmd+k', 'Focus search'],
      ['Esc', 'Close / deselect'],
      ['j / k', 'Next / previous message'],
      ['Enter or o', 'Open message'],
      ['x', 'Select / deselect'],
      ['z', 'Undo'],
      ['g then i / a / s / d', 'Go to Inbox / All / Sent / Drafts'],
      ['Cmd+Enter', 'Send message'],
      ['?', 'Show this help']
    ];
    this.showModal('Keyboard shortcuts',
      `<div class="shortcut-grid">${rows.map(r => `<code>${r[0]}</code><span>${r[1]}</span>`).join('')}</div>`,
      '<button class="btn btn-ghost" data-close-modal>Close</button>');
  },

  escapeHtml: function (s) {
    return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  },

  formatDate: function (iso) {
    if (!iso) return '';
    const d = new Date(iso);
    if (isNaN(d)) return '';
    const now = new Date();
    const diff = now - d;
    const min = Math.floor(diff / 60000);
    if (min < 1) return 'now';
    if (min < 60) return min + 'm ago';
    const h = Math.floor(min / 60);
    if (h < 24 && d.getDate() === now.getDate()) return h + 'h ago';
    const yest = new Date(now); yest.setDate(now.getDate() - 1);
    if (d.toDateString() === yest.toDateString()) return 'yesterday';
    if (diff < 7 * 86400000) return d.toLocaleDateString(undefined, { weekday: 'short' });
    return d.toLocaleDateString(undefined, d.getFullYear() === now.getFullYear()
      ? { month: 'short', day: 'numeric' }
      : { month: 'short', day: 'numeric', year: 'numeric' });
  },

  formatSize: function (bytes) {
    const n = Number(bytes) || 0;
    if (n < 1024) return n + ' B';
    const units = ['KB', 'MB', 'GB', 'TB'];
    let v = n;
    let i = -1;
    do { v /= 1024; i++; } while (v >= 1024 && i < units.length - 1);
    return v.toFixed(v >= 100 ? 0 : 1) + ' ' + units[i];
  },

  getInitials: function (name) {
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
  },

  getAvatarColor: function (name) {
    const s = String(name || '');
    let hash = 0;
    for (let i = 0; i < s.length; i++) hash = (hash * 31 + s.charCodeAt(i)) >>> 0;
    const palette = ['#4f8cff', '#8b5cf6', '#ec4899', '#f59e0b', '#10b981', '#06b6d4', '#ef4444', '#14b8a6', '#6366f1', '#f97316'];
    return palette[hash % palette.length];
  },

  avatarHtml: function (name, email) {
    const display = name || email || '?';
    return `<span class="avatar" style="background:${this.getAvatarColor(display)}">${this.escapeHtml(this.getInitials(display))}</span>`;
  },

  getAccount: function (id) {
    return this.accounts.find(a => a.id == (id ?? this.currentAccountId));
  },

  unreadCounts: function () {
    const acct = this.getAccount();
    if (!acct) return {};
    if (acct.unread_counts) return acct.unread_counts;
    return { inbox: acct.unread_count || 0 };
  },

  toggleTheme: function () {
    const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    try { localStorage.setItem('emmmail-theme', next); } catch (e) {}
    this.settings.theme = next;
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', next === 'light' ? '#ffffff' : '#0b0d12');
    this.updateAvatar();
    if (this.currentView === '#settings') this.renderSettings();
  },

  applyTheme: function () {
    const t = (() => { try { return localStorage.getItem('emmmail-theme') || 'dark'; } catch (e) { return 'dark'; } })();
    document.documentElement.setAttribute('data-theme', t);
    if (this.settings) this.settings.theme = t;
  },

  updateAvatar: function () {
    const acct = this.getAccount();
    const btn = this.el('#btn-avatar');
    if (!btn) return;
    if (acct) {
      const display = acct.display_name || acct.email || '?';
      btn.textContent = this.getInitials(display);
      btn.style.background = this.getAvatarColor(display);
    } else {
      btn.textContent = '?';
      btn.style.background = 'var(--text-secondary)';
    }
  },

  updateBottomTabs: function () {
    const map = { inbox: 'inbox', sent: 'inbox', drafts: 'inbox', starred: 'inbox', all: 'inbox', search: 'search', settings: 'settings', contacts: 'settings' };
    const view = this.currentView.split('?')[0].split('/')[0] || 'inbox';
    const active = map[view] || 'inbox';
    this.els('.tab-item').forEach(t => t.classList.toggle('active', t.dataset.tab === active));
  },

  toggleSidebar: function () {
    this.isSidebarOpen = !this.isSidebarOpen;
    document.body.classList.toggle('sidebar-open', this.isSidebarOpen);
  },

  closeSidebar: function () {
    this.isSidebarOpen = false;
    document.body.classList.remove('sidebar-open');
  },

  toggleMultiSelect: function (force) {
    this.isMultiSelectMode = (force === undefined) ? !this.isMultiSelectMode : force;
    if (!this.isMultiSelectMode) this.selectedThreadIds.clear();
    this.renderMessageList();
  },

  toggleSelect: function (id) {
    if (!id) return;
    if (this.selectedThreadIds.has(id)) this.selectedThreadIds.delete(id);
    else this.selectedThreadIds.add(id);
    this.renderMessageList();
  },

  allSelected: function () {
    return this.threads.length > 0 && this.threads.every(t => this.selectedThreadIds.has(t.id));
  },

  selectAll: function () {
    this.threads.forEach(t => this.selectedThreadIds.add(t.id));
    this.renderMessageList();
  },

  deselectAll: function () {
    this.selectedThreadIds.clear();
    this.renderMessageList();
  },

  batchAction: async function (action) {
    const ids = [...this.selectedThreadIds];
    if (!ids.length) {
      if (this.currentThread && (action === 'archive' || action === 'delete')) ids.push(this.currentThread.id);
    }
    if (!ids.length) { this.showToast('No messages selected', 'error'); return; }
    if (action === 'tag') return this.showTagPicker(ids);
    try {
      await this.api('batch_action', { action, thread_ids: ids, folder: this.currentFolder }, 'POST');
      const labels = { archive: 'Archived', delete: 'Deleted', star: 'Starred', unstar: 'Unstarred', read: 'Marked read', unread: 'Marked unread' };
      this.pushUndo({ action, ids, folder: this.currentFolder });
      this.showToast(`${labels[action] || 'Done'} ${ids.length} message${ids.length > 1 ? 's' : ''}`, 'info', { label: 'Undo', onClick: () => this.undo() });
      if (this.currentThread) {
        this.currentThread = null;
        this.threadMessages = [];
      }
      this.selectedThreadIds.clear();
      this.toggleMultiSelect(false);
      if (this.currentView.startsWith('#thread')) {
        const prev = this._prevListHash || '#inbox';
        this._prevListHash = null;
        this.navigate(prev);
      } else {
        this.loadThreads(this.currentFolder);
      }
    } catch (err) {
      this.showToast(err.message, 'error');
    }
  },

  showTagPicker: function (ids) {
    this.showModal('Apply tags',
      `<div class="tag-picker">${this.tags.length
        ? this.tags.map(t => `<label class="tag-option"><input type="checkbox" value="${t.id}" style="accent-color:var(--primary)"> ${this.escapeHtml(t.name)}</label>`).join('')
        : '<p>No tags yet. Create one from the sidebar.</p>'}</div>`,
      '<button class="btn btn-primary" data-apply-tags>Apply</button>');
    const ov = this.el('.modal-overlay');
    ov.querySelector('[data-apply-tags]').addEventListener('click', async () => {
      const tagIds = [...ov.querySelectorAll('.tag-option input:checked')].map(i => +i.value);
      this.closeModal();
      try {
        await this.api('batch_tag', { thread_ids: ids, tag_ids: tagIds }, 'POST');
        this.pushUndo({ action: 'tag', ids, tagIds, folder: this.currentFolder });
        this.showToast('Tags applied');
        this.selectedThreadIds.clear();
        this.toggleMultiSelect(false);
        this.loadThreads(this.currentFolder);
      } catch (err) { this.showToast(err.message, 'error'); }
    });
  },

  pushUndo: function (op) {
    this.undoStack.push(op);
    if (this.undoStack.length > 20) this.undoStack.shift();
  },

  undo: async function () {
    const op = this.undoStack.pop();
    if (!op) { this.showToast('Nothing to undo'); return; }
    let payload;
    if (op.action === 'tag') {
      payload = { thread_ids: op.ids, tag_ids: op.tagIds, remove: true };
      try {
        await this.api('batch_tag', payload, 'POST');
        this.showToast('Tag removed');
        this.loadThreads(this.currentFolder);
        return;
      } catch (err) { this.showToast('Undo failed: ' + err.message, 'error'); return; }
    }
    const inverse = { archive: 'unarchive', delete: 'undelete', star: 'unstar', unstar: 'star', read: 'unread', unread: 'read' };
    try {
      await this.api('batch_action', { action: inverse[op.action] || op.action, thread_ids: op.ids, folder: op.folder }, 'POST');
      this.showToast('Undone');
      this.loadThreads(this.currentFolder);
    } catch (err) { this.showToast('Undo failed: ' + err.message, 'error'); }
  },

  toggleStar: async function (id) {
    const t = this.threads.find(x => x.id == id);
    if (!t) return;
    const starred = !t.starred;
    try {
      await this.api('set_flags', { thread_id: id, starred }, 'POST');
      t.starred = starred;
      this.renderMessageList();
    } catch (err) { this.showToast(err.message, 'error'); }
  },

  toggleStarSelected: async function () {
    let ids;
    if (this.selectedThreadIds.size) ids = [...this.selectedThreadIds];
    else if (this.currentThread) ids = [this.currentThread.id];
    else return;
    const target = this.threads.find(t => ids.includes(t.id));
    const starred = !(target && target.starred);
    try {
      await this.api('batch_flags', { thread_ids: ids, starred }, 'POST');
      ids.forEach(id => { const t = this.threads.find(x => x.id === id); if (t) t.starred = starred; });
      if (this.currentThread && ids.includes(this.currentThread.id)) this.currentThread.starred = starred;
      this.renderMessageList();
      if (this.currentView.startsWith('#thread')) this.renderThread();
    } catch (err) { this.showToast(err.message, 'error'); }
  },

  archiveSelected: function () { this.batchAction('archive'); },
  deleteSelected: function () {
    this.showConfirm('Delete message?', 'Move selected message(s) to Trash?').then(ok => { if (ok) this.batchAction('delete'); });
  },
  toggleReadSelected: function () {
    let ids;
    if (this.selectedThreadIds.size) ids = [...this.selectedThreadIds];
    else if (this.currentThread) ids = [this.currentThread.id];
    else return;
    const target = this.threads.find(t => ids.includes(t.id));
    const read = !(target && target.unread);
    this.api('batch_flags', { thread_ids: ids, read }, 'POST')
      .then(() => { ids.forEach(id => { const t = this.threads.find(x => x.id === id); if (t) t.unread = !read; }); this.renderMessageList(); })
      .catch(err => this.showToast(err.message, 'error'));
  },

  currentMessage: function () {
    const msgs = this.threadMessages || [];
    return msgs[msgs.length - 1];
  },

  replyTo: async function (id, all) {
    if (!id) { this.showToast('No message selected', 'error'); return; }
    await this.openCompose({ mode: 'reply', messageId: id, all });
  },

  forwardMessage: async function (id) {
    if (!id) { this.showToast('No message selected', 'error'); return; }
    await this.openCompose({ mode: 'forward', messageId: id });
  },

  moveSelection: function (delta) {
    if (!this.threads.length) return;
    if (this._focusedIdx === undefined || this._focusedIdx === null) this._focusedIdx = 0;
    this._focusedIdx = Math.min(Math.max(0, this._focusedIdx + delta), this.threads.length - 1);
    this.els('[data-thread]').forEach((r, i) => r.classList.toggle('focused', i === this._focusedIdx));
    const row = this.els('[data-thread]')[this._focusedIdx];
    if (row) row.scrollIntoView({ block: 'nearest' });
  },

  openSelected: function () {
    const t = this.threads[this._focusedIdx === undefined ? 0 : this._focusedIdx];
    if (t) this.openThreadById(t.id);
  },

  toggleSelectFocused: function () {
    const t = this.threads[this._focusedIdx === undefined ? 0 : this._focusedIdx];
    if (!t) return;
    if (this.selectedThreadIds.has(t.id)) this.selectedThreadIds.delete(t.id);
    else this.selectedThreadIds.add(t.id);
    const rows = this.els('[data-thread]');
    rows.forEach((r, i) => {
      if (i === this._focusedIdx) {
        r.classList.toggle('selected', this.selectedThreadIds.has(t.id));
        const cb = r.querySelector('[data-select]');
        if (cb) cb.checked = this.selectedThreadIds.has(t.id);
      }
    });
    const count = this.el('[data-sel-count]');
    if (count) count.textContent = this.selectedThreadIds.size + ' selected';
  },

  toggleAvatarMenu: function () {
    const menu = this.el('#avatar-menu');
    const isOpen = menu.classList.contains('open');
    this.closeMenus();
    if (isOpen) return;
    const acct = this.getAccount();
    menu.innerHTML = `
      ${this.accounts.length ? `<div class="menu-title">Accounts</div>
        ${this.accounts.map(a => `<button class="menu-item" data-switch="${a.id}">${this.avatarHtml(a.display_name, a.email)}
          <span class="mi-main">${this.escapeHtml(a.email)}<span class="mi-sub">${this.escapeHtml(a.display_name || '')}</span></span>
          ${a.id === this.currentAccountId ? '<span class="mi-check"><i class="ti ti-check"></i></span>' : ''}</button>`).join('')}
        <div class="menu-divider"></div>` : ''}
      <button class="menu-item" data-theme-toggle><span style="width:22px;text-align:center"><i class="ti ti-${this.settings.theme === 'light' ? 'moon' : 'sun'}"></i></span>${this.settings.theme === 'light' ? 'Dark mode' : 'Light mode'}</button>
      <button class="menu-item" data-shortcuts><span style="width:22px;text-align:center"><i class="ti ti-keyboard"></i></span>Keyboard shortcuts</button>
      <div class="menu-divider"></div>
      <button class="menu-item" data-settings><span style="width:22px;text-align:center"><i class="ti ti-settings"></i></span>Settings</button>
      <button class="menu-item" data-contacts><span style="width:22px;text-align:center"><i class="ti ti-users"></i></span>Contacts</button>
      <div class="menu-divider"></div>
      <button class="menu-item" data-logout><span style="width:22px;text-align:center"><i class="ti ti-logout"></i></span>Log out</button>`;
    menu.classList.add('open');
    menu.querySelectorAll('[data-switch]').forEach(b => b.addEventListener('click', () => {
      this.switchAccount(+b.dataset.switch);
      this.closeMenus();
    }));
    menu.querySelector('[data-theme-toggle]')?.addEventListener('click', () => { this.toggleTheme(); this.closeMenus(); });
    menu.querySelector('[data-shortcuts]')?.addEventListener('click', () => { this.closeMenus(); this.showShortcutsHelp(); });
    menu.querySelector('[data-settings]')?.addEventListener('click', () => { this.closeMenus(); this.navigate('#settings'); });
    menu.querySelector('[data-contacts]')?.addEventListener('click', () => { this.closeMenus(); this.navigate('#contacts'); });
    menu.querySelector('[data-logout]')?.addEventListener('click', async () => {
      await this.api('auth_logout', {}, 'POST').catch(() => {});
      this.accounts = [];
      this.navigate('#login');
      this.closeMenus();
    });
    if (acct) this.renderNotifMenu();
  },

  toggleNotifMenu: function () {
    const menu = this.el('#notif-menu');
    const isOpen = menu.classList.contains('open');
    this.closeMenus();
    if (isOpen) return;
    menu.classList.add('open');
    this.renderNotifMenu();
  },

  renderNotifMenu: function () {
    const menu = this.el('#notif-menu');
    if (!menu.classList.contains('open')) return;
    const items = this.accounts.map(a => {
      const state = a.sync_error
        ? '<span class="mi-sub" style="color:var(--red)">Sync error: ' + this.escapeHtml(a.sync_error) + '</span>'
        : '<span class="mi-sub">Last synced ' + (this.formatDate(a.last_sync_at) || 'never') + '</span>';
      return `<button class="menu-item" data-notif-settings><span class="mi-main">${this.escapeHtml(a.email)}${state}</span></button>`;
    }).join('');
    menu.innerHTML = `<div class="menu-title">Sync status</div>${items || '<div class="menu-item">No accounts</div>'}
      <div class="menu-divider"></div>
      <button class="menu-item" data-notif-settings><span class="mi-main">Manage in Settings</span></button>`;
    menu.querySelectorAll('[data-notif-settings]').forEach(b => b.addEventListener('click', () => { this.closeMenus(); this.navigate('#settings'); }));
  },

  closeMenus: function () {
    this.el('#avatar-menu')?.classList.remove('open');
    this.el('#notif-menu')?.classList.remove('open');
  },

  focusSearch: function () {
    if (this.composeOpen()) return;
    const bar = this.el('#search-bar');
    bar.classList.add('active');
    document.body.classList.add('search-open');
    const input = bar.querySelector('input');
    input.focus();
    input.select();
  },

  closeSearch: function () {
    this.el('#search-bar')?.classList.remove('active');
    document.body.classList.remove('search-open');
  },

  composeOpen: function () {
    const ov = this.el('#compose-overlay');
    return ov && ov.classList.contains('open') && !!this.composeState;
  },

  inInput: function (e) {
    if (this.modalOpen) return true;
    const t = e.target;
    if (!t) return false;
    const tag = t.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return true;
    if (t.isContentEditable) return true;
    return false;
  },

  handleKeydown: function (e) {
    const k = e.key;
    const mod = e.metaKey || e.ctrlKey;

    if (k === 'Escape') {
      if (this.composeOpen()) { this.closeCompose(false); e.preventDefault(); return; }
      if (this.modalOpen) { this.closeModal(); return; }
      const fs = this.el('#fullscreen');
      if (fs && !fs.hidden) { this.navigate(this._prevListHash || '#inbox'); return; }
      if (this.isSidebarOpen) { this.closeSidebar(); return; }
      if (this.isMultiSelectMode) { this.toggleMultiSelect(false); return; }
      if (this.selectedThreadIds.size) { this.selectedThreadIds.clear(); this.renderMessageList(); return; }
      if (document.body.classList.contains('search-open')) { this.closeSearch(); return; }
      return;
    }

    if (mod && k === 'Enter') {
      if (this.composeOpen()) { e.preventDefault(); this.sendMessage(); }
      return;
    }

    if (mod && k.toLowerCase() === 'k') { e.preventDefault(); this.focusSearch(); return; }
    if (k === '/' && !this.inInput(e)) { e.preventDefault(); this.focusSearch(); return; }
    if (k === '?' && !this.inInput(e)) { e.preventDefault(); this.showShortcutsHelp(); return; }

    if (this.inInput(e)) return;
    if (this.composeOpen()) return;

    if (k === 'g') { this._gPending = true; setTimeout(() => { this._gPending = false; }, 1500); return; }
    if (this._gPending) {
      this._gPending = false;
      const m = { i: '#inbox', a: '#all', s: '#sent', d: '#drafts' };
      if (m[k.toLowerCase()]) { this.navigate(m[k.toLowerCase()]); return; }
    }

    if (e.shiftKey && k === 'U') { e.preventDefault(); this.toggleReadSelected(); return; }
    if (k === 'u' && !e.shiftKey) return;

    switch (k) {
      case 'c': this.openCompose(); break;
      case 'r': this.replyTo(this.currentMessage() && this.currentMessage().id, false); break;
      case 'a': this.replyTo(this.currentMessage() && this.currentMessage().id, true); break;
      case 'f': this.forwardMessage(this.currentMessage() && this.currentMessage().id); break;
      case 'e': this.archiveSelected(); break;
      case '#': e.preventDefault(); this.deleteSelected(); break;
      case 's': this.toggleStarSelected(); break;
      case 'j': this.moveSelection(1); break;
      case 'k': this.moveSelection(-1); break;
      case 'o': case 'Enter': this.openSelected(); break;
      case 'x': this.toggleSelectFocused(); break;
      case 'z': this.undo(); break;
      default: break;
    }
  },

  bindGlobal: function () {
    document.addEventListener('keydown', (e) => this.handleKeydown(e));
    window.addEventListener('hashchange', () => this.navigate(location.hash));

    document.addEventListener('click', (e) => {
      const star = e.target.closest('[data-star]');
      if (star) {
        e.preventDefault(); e.stopPropagation();
        this.toggleStar(star.closest('[data-thread]')?.dataset.id);
        return;
      }
      const sel = e.target.closest('[data-select]');
      if (sel) {
        e.preventDefault(); e.stopPropagation();
        this.toggleSelect(sel.closest('[data-thread]')?.dataset.id);
        return;
      }
      const selectAll = e.target.closest('[data-select-all]');
      if (selectAll) {
        e.preventDefault();
        selectAll.checked ? this.selectAll() : this.deselectAll();
        return;
      }
      const deselect = e.target.closest('[data-deselect]');
      if (deselect) { this.deselectAll(); return; }
      const batch = e.target.closest('[data-batch]');
      if (batch) {
        e.preventDefault();
        const map = { archive: 'archive', delete: 'delete', star: 'star', read: 'read', unread: 'unread', tag: 'tag' };
        const action = map[batch.dataset.batch];
        if (action) this.batchAction(action);
        return;
      }
      const batchClose = e.target.closest('[data-batch-close]');
      if (batchClose) { this.toggleMultiSelect(false); return; }
      const multi = e.target.closest('[data-multi]');
      if (multi) { this.toggleMultiSelect(); return; }
      const refresh = e.target.closest('[data-refresh]');
      if (refresh) {
        const btn = refresh;
        btn.innerHTML = '<i class="ti ti-loader"></i>';
        this.api('sync_account', { account_id: this.currentAccountId }, 'POST')
          .then(() => { this.loadThreads(this.currentFolder); this.showToast('Synced'); })
          .catch(err => { btn.innerHTML = '<i class="ti ti-refresh"></i>'; this.showToast(err.message, 'error'); });
        return;
      }
      const syncNow = e.target.closest('[data-sync-now]');
      if (syncNow) { e.preventDefault(); syncNow.innerHTML = '<i class="ti ti-loader"></i> Syncing\u2026'; this.api('sync_account', { account_id: this.currentAccountId }, 'POST').then(() => this.loadThreads(this.currentFolder)).catch(err => this.showToast(err.message, 'error')); return; }
      const row = e.target.closest('[data-thread]');
      if (row && e.target.closest('[data-open]')) { this.openThreadById(row.dataset.id); return; }
      const emptyAction = e.target.closest('[data-empty-action]');
      if (emptyAction) { e.preventDefault(); const h = emptyAction.dataset.emptyAction; if (h && h.startsWith('#')) this.navigate(h); else this.navigate('#inbox'); return; }
      const back = e.target.closest('[data-back]');
      if (back) { return; }
      const tab = e.target.closest('[data-tab]');
      if (tab) {
        if (tab.dataset.tab === 'compose') { e.preventDefault(); this.openCompose(); }
        else if (tab.dataset.tab === 'search') { e.preventDefault(); this.focusSearch(); }
      }
    });

    this.el('#btn-hamburger')?.addEventListener('click', () => this.toggleSidebar());
    this.el('#sidebar-backdrop')?.addEventListener('click', () => this.closeSidebar());
    this.el('#btn-avatar')?.addEventListener('click', (e) => { e.stopPropagation(); this.toggleAvatarMenu(); });
    this.el('#btn-notifications')?.addEventListener('click', (e) => { e.stopPropagation(); this.toggleNotifMenu(); });
    document.addEventListener('click', () => this.closeMenus());

    const bar = this.el('#search-bar');
    if (bar) {
      bar.querySelector('input').addEventListener('focus', () => {
        bar.classList.add('active');
        document.body.classList.add('search-open');
      });
      bar.querySelector('input').addEventListener('input', (e) => {
        this.el('#search-close').hidden = !e.target.value;
      });
      bar.querySelector('input').addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          const q = e.target.value.trim();
          if (q) this.performSearch(q);
        }
      });
      this.el('#search-submit').addEventListener('click', () => {
        const q = bar.querySelector('input').value.trim();
        if (q) this.performSearch(q);
      });
      this.el('#search-close').addEventListener('click', () => {
        bar.querySelector('input').value = '';
        this.el('#search-close').hidden = true;
        this.closeSearch();
      });
      this.el('#btn-search').addEventListener('click', () => this.focusSearch());
    }

    window.addEventListener('resize', () => this.updateIsMobile());
  },

  updateIsMobile: function () {
    this.isMobile = window.matchMedia('(max-width: 640px)').matches;
    document.body.classList.toggle('is-mobile', this.isMobile);
  }
};

window.App = App;

document.addEventListener('DOMContentLoaded', () => { App.init(); });
</script>
</body>
</html>
EMMMAIL_HTML;
    return str_replace('{{VERSION}}', EMMMAIL_VERSION, $html);
}
/* ---------------------------------------------------------------------------
 * 11. Front controller
 * ------------------------------------------------------------------------ */

// CLI sync command
if (PHP_SAPI === 'cli' && isset($argv[1]) && $argv[1] === 'sync') {
    cliSync();
    exit;
}

// Routing
ensureSession();
$action = $_GET['action'] ?? '';

// Public routes (no auth, no CSRF)
$publicRoutes = ['', 'auth_login', 'auth_setup', 'auth_status', 'setup', 'ping', 'test_connection'];
if (!in_array($action, $publicRoutes, true)) {
    requireAuth();
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        verifyCsrf();
    }
}

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

try {
    match ($action) {
        // Public
        'ping'              => apiPing(),
        'auth_login'        => apiAuthLogin(),
        'auth_setup'        => apiAuthSetup(),
        'auth_status'       => apiAuthStatus(),
        'setup'             => apiAuthSetupStatus(),
        // Auth
        'auth_logout'       => apiAuthLogout(),
        // Accounts
        'list_accounts'     => apiListAccounts(),
        'add_account'       => apiAddAccount(),
        'delete_account'    => apiDeleteAccount(),
        'test_connection'   => apiTestConnection(),
        // Messages
        'list_messages'     => apiListMessages(),
        'get_message'       => apiGetMessage(),
        'get_thread'        => apiGetThread(),
        'sync_account'      => apiSyncAccount(),
        // Move/Delete/Expunge
        'move_message'      => apiMoveMessage(),
        'delete_message'    => apiDeleteMessage(),
        'expunge_folder'    => apiExpungeFolder(),
        'batch_action'      => apiBatchAction(),
        // Compose
        'send_message'      => apiSendMessage(),
        'save_draft'        => apiSaveDraft(),
        'list_drafts'       => apiListDrafts(),
        'delete_draft'      => apiDeleteDraft(),
        // Outbox
        'schedule_send'     => apiScheduleSend(),
        'cancel_send'       => apiCancelSend(),
        'process_outbox'    => apiProcessOutbox(),
        // Flags
        'set_flags'         => apiSetFlags(),
        'batch_flags'       => apiBatchFlags(),
        // Tags
        'list_tags'         => apiListTags(),
        'create_tag'        => apiCreateTag(),
        'rename_tag'        => apiRenameTag(),
        'delete_tag'        => apiDeleteTag(),
        'tag_message'       => apiTagMessage(),
        'untag_message'     => apiUntagMessage(),
        'batch_tag'         => apiBatchTag(),
        // Contacts
        'list_contacts'     => apiListContacts(),
        'search_contacts'   => apiSearchContacts(),
        // Search
        'search'            => apiSearch(),
        // Attachments
        'get_attachment'    => apiGetAttachment(),
        'upload_attachment' => apiUploadAttachment(),
        // Settings
        'get_settings'      => apiGetSettings(),
        'update_settings'   => apiUpdateSettings(),
        // Signature
        'get_signature'     => apiGetSignature(),
        'update_signature'  => apiUpdateSignature(),
        // Update
        'check_update'      => apiCheckUpdate(),
        'apply_update'      => apiApplyUpdate(),
        default             => serveFrontendRoute(),
    };
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 500);
}
