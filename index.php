<?php

/**
 * Single-file PHP webmail client.
 * Zero external dependencies. Requires PHP 8+ with ext-imap, mbstring,
 * sodium, pdo_sqlite, dom, openssl.
 *
 * SQLite DB location: $EMMMAIL_DB_PATH (env) or ./emmmail.sqlite (dev).
 */

// [1] Config + session -------------------------------------------------------

define('APP_NAME', 'Emmmail');
define('APP_VERSION', '0.1.0');
define('APP_DEBUG', (getenv('EMMMAIL_DEBUG') ?: '0') === '1');

// Tabler icons (current-color outline set), embedded inline so the UI needs
// no external requests and works fully offline.
define('TABLER_SPRITE', <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" style="display:none" aria-hidden="true">
<symbol id="tabler-mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" /><path d="M3 7l9 6l9 -6" /></symbol>
<symbol id="tabler-mail-opened" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 9l9 6l9 -6l-9 -6l-9 6" /><path d="M21 9v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-10" /><path d="M3 19l6 -6" /><path d="M15 13l6 6" /></symbol>
<symbol id="tabler-inbox" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M4 6a2 2 0 0 1 2 -2h12a2 2 0 0 1 2 2v12a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2l0 -12" /><path d="M4 13h3l3 3h4l3 -3h3" /></symbol>
<symbol id="tabler-send" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 14l11 -11" /><path d="M21 3l-6.5 18a.55 .55 0 0 1 -1 0l-3.5 -7l-7 -3.5a.55 .55 0 0 1 0 -1l18 -6.5" /></symbol>
<symbol id="tabler-pencil" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M4 20h4l10.5 -10.5a2.828 2.828 0 1 0 -4 -4l-10.5 10.5v4" /><path d="M13.5 6.5l4 4" /></symbol>
<symbol id="tabler-trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M4 7l16 0" /><path d="M10 11l0 6" /><path d="M14 11l0 6" /><path d="M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2 -2l1 -12" /><path d="M9 7v-3a1 1 0 0 1 1 -1h4a1 1 0 0 1 1 1v3" /></symbol>
<symbol id="tabler-alert-triangle" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 9v4" /><path d="M10.363 3.591l-8.106 13.534a1.914 1.914 0 0 0 1.636 2.871h16.214a1.914 1.914 0 0 0 1.636 -2.87l-8.106 -13.536a1.914 1.914 0 0 0 -3.274 0" /><path d="M12 16h.01" /></symbol>
<symbol id="tabler-folder" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2" /></symbol>
<symbol id="tabler-search" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 10a7 7 0 1 0 14 0a7 7 0 1 0 -14 0" /><path d="M21 21l-6 -6" /></symbol>
<symbol id="tabler-paperclip" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M15 7l-6.5 6.5a1.5 1.5 0 0 0 3 3l6.5 -6.5a3 3 0 0 0 -6 -6l-6.5 6.5a4.5 4.5 0 0 0 9 9l6.5 -6.5" /></symbol>
<symbol id="tabler-photo" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M15 8h.01" /><path d="M3 6a3 3 0 0 1 3 -3h12a3 3 0 0 1 3 3v12a3 3 0 0 1 -3 3h-12a3 3 0 0 1 -3 -3v-12z" /><path d="M3 16l5 -5c.928 -.893 2.072 -.893 3 0l5 5" /><path d="M14 14l1 -1c.928 -.893 2.072 -.893 3 0l3 3" /></symbol>
<symbol id="tabler-rotate" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M19.95 11a8 8 0 1 0 -.5 4m.5 5v-5h-5" /></symbol>
<symbol id="tabler-arrow-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 12l14 0" /><path d="M5 12l6 6" /><path d="M5 12l6 -6" /></symbol>
<symbol id="tabler-arrow-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M5 12l14 0" /><path d="M13 18l6 -6" /><path d="M13 6l6 6" /></symbol>
<symbol id="tabler-arrow-back" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M9 11l-4 4l4 4m-4 -4h11a4 4 0 0 0 0 -8h-1" /></symbol>
<symbol id="tabler-corner-up-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M18 18v-6a3 3 0 0 0 -3 -3h-10l4 -4m0 8l-4 -4" /></symbol>
<symbol id="tabler-corner-up-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M6 18v-6a3 3 0 0 1 3 -3h10l-4 -4m0 8l4 -4" /></symbol>
<symbol id="tabler-file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M14 3v4a1 1 0 0 0 1 1h4" /><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2" /></symbol>
<symbol id="tabler-progress-check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 20.777a8.942 8.942 0 0 1 -2.48 -.969" /><path d="M14 3.223a9.003 9.003 0 0 1 0 17.554" /><path d="M4.579 17.093a8.961 8.961 0 0 1 -1.227 -2.592" /><path d="M3.124 10.5c.16 -.95 .468 -1.85 .9 -2.675l.169 -.305" /><path d="M6.907 4.579a8.954 8.954 0 0 1 3.093 -1.356" /><path d="M9 12l2 2l4 -4" /></symbol>
<symbol id="tabler-progress" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10 20.777a8.942 8.942 0 0 1 -2.48 -.969" /><path d="M14 3.223a9.003 9.003 0 0 1 0 17.554" /><path d="M4.579 17.093a8.961 8.961 0 0 1 -1.227 -2.592" /><path d="M3.124 10.5c.16 -.95 .468 -1.85 .9 -2.675l.169 -.305" /><path d="M6.907 4.579a8.954 8.954 0 0 1 3.093 -1.356" /></symbol>
<symbol id="tabler-device-floppy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M6 4h10l4 4v10a2 2 0 0 1 -2 2h-12a2 2 0 0 1 -2 -2v-12a2 2 0 0 1 2 -2" /><path d="M12 14m-2 0a2 2 0 1 0 4 0a2 2 0 1 0 -4 0" /><path d="M14 4l0 4l-6 0l0 -4" /></symbol>
<symbol id="tabler-folder-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 19h-7a2 2 0 0 1 -2 -2v-11a2 2 0 0 1 2 -2h4l3 3h7a2 2 0 0 1 2 2v3.5" /><path d="M16 19h6" /><path d="M19 16v6" /></symbol>
<symbol id="tabler-corner-up-left-double" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M19 18v-6a3 3 0 0 0 -3 -3h-7" /><path d="M13 13l-4 -4l4 -4m-5 8l-4 -4l4 -4" /></symbol>
<symbol id="tabler-menu-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M4 6l16 0" /><path d="M4 12l16 0" /><path d="M4 18l16 0" /></symbol>
<symbol id="tabler-sun" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 12m-4 0a4 4 0 1 0 8 0a4 4 0 1 0 -8 0" /><path d="M3 12h1m8 -9v1m8 8h1m-9 8v1m-6.4 -15.4l.7 .7m12.1 -.7l-.7 .7m0 11.4l.7 .7m-12.1 -.7l-.7 .7" /></symbol>
<symbol id="tabler-moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 3c.132 0 .263 0 .393 0a7.5 7.5 0 0 0 7.92 12.446a9 9 0 1 1 -8.313 -12.454z" /></symbol>
<symbol id="tabler-logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2" /><path d="M9 12h12l-3 -3" /><path d="M18 15l3 -3" /></symbol>
<symbol id="tabler-plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 5l0 14" /><path d="M5 12l14 0" /></symbol>
<symbol id="tabler-settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M10.325 4.317c.426 -1.756 2.924 -1.756 3.35 0a1.724 1.724 0 0 0 2.573 1.066c1.543 -.94 3.31 .826 2.37 2.37a1.724 1.724 0 0 0 1.065 2.572c1.756 .426 1.756 2.924 0 3.35a1.724 1.724 0 0 0 -1.066 2.573c.94 1.543 -.826 3.31 -2.37 2.37a1.724 1.724 0 0 0 -2.572 1.065c-.426 1.756 -2.924 1.756 -3.35 0a1.724 1.724 0 0 0 -2.573 -1.066c-1.543 .94 -3.31 -.826 -2.37 -2.37a1.724 1.724 0 0 0 -1.065 -2.572c-1.756 -.426 -1.756 -2.924 0 -3.35a1.724 1.724 0 0 0 1.066 -2.573c-.94 -1.543 .826 -3.31 2.37 -2.37c1 .608 2.296 .07 2.572 -1.065z" /><path d="M9 12a3 3 0 1 0 6 0a3 3 0 0 0 -6 0" /></symbol>
<symbol id="tabler-archive" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 3h18v3a1 1 0 0 1 -1 1h-1a1 1 0 0 0 -1 1v10a2 2 0 0 1 -2 2h-10a2 2 0 0 1 -2 -2v-10a1 1 0 0 0 -1 -1h-1a1 1 0 0 1 -1 -1z" /><path d="M8 13h8" /><path d="M8 17h8" /><path d="M10 21h4" /></symbol>
<symbol id="tabler-markdown" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M3 7a2 2 0 0 1 2 -2h14a2 2 0 0 1 2 2v10a2 2 0 0 1 -2 2h-14a2 2 0 0 1 -2 -2l0 -10" /><path d="M7 15v-6l2 2l2 -2v6" /><path d="M14 13l2 2l2 -2m-2 2v-6" /></symbol>
<symbol id="tabler-help" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path stroke="none" d="M0 0h24v24H0z" fill="none" /><path d="M12 12m-9 0a9 9 0 1 0 18 0a9 9 0 1 0 -18 0" /><path d="M12 17l0 .01" /><path d="M12 13.5a1.5 1.5 0 0 1 1 -1.5a2.6 2.6 0 1 0 -3 -4" /></symbol>
</svg>
SVG);

// IMAP server settings (defaults; override via config section or env)
define('MAIL_SERVER', getenv('EMMMAIL_MAIL_SERVER') ?: ''); // optional; blank = derive from domain
define('MAIL_IMAP_PORT', (int)(getenv('EMMMAIL_IMAP_PORT') ?: 993));
define('MAIL_SMTP_PORT', (int)(getenv('EMMMAIL_SMTP_PORT') ?: 587));
define('IMAP_VERIFY_CERT', (getenv('EMMMAIL_VERIFY_CERT') ?? '1') !== '0');
define('SMTP_VERIFY_CERT', (getenv('EMMMAIL_VERIFY_CERT') ?? '1') !== '0');
define('IMAP_ENCRYPTION', getenv('EMMMAIL_IMAP_ENCRYPTION') ?: 'ssl');
define('SMTP_ENCRYPTION', getenv('EMMMAIL_SMTP_ENCRYPTION') ?: 'tls');
define('TLS_MIN_CRYPTO_METHOD', STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
define('MAX_ATTACHMENT_SIZE', 10 * 1024 * 1024);
define('DEFAULT_EMAILS_PER_PAGE', 20);
// Off: fetching a remote image tells the sender when the message was opened and
// from which IP. Stored as a string, like every other row in `settings`.
define('DEFAULT_SHOW_REMOTE_IMAGES', '0');
define('LOGIN_MAX_FAILURES', 5);
define('LOGIN_WINDOW_SECONDS', 900);       // 15 min
define('SEARCH_CACHE_TTL_SECONDS', 604800); // 7 days
define('SCHEMA_VERSION', 4);               // see db_migration_step()
define('FOLDER_LIST_TTL_SECONDS', 900);    // 15 min; LIST rarely changes
define('SYNC_FULL_INTERVAL_SECONDS', 60);  // flag reconcile cadence
define('SYNC_WINDOW', 500);                // newest N messages cached per folder
define('SYNC_BACKFILL_BATCH', 20);         // attachment flags resolved per sync
define('SYNC_MIN_INTERVAL_SECONDS', 15);   // throttle for the sync endpoint
define('SYNC_CLIENT_MIN_PERIOD', 60);      // how often an open tab checks for new mail
define('THREAD_MAX_MESSAGES', 30);         // conversations render this many newest messages
define('COUNTS_SWEEP_INTERVAL_SECONDS', 60); // sidebar counters: STATUS per closed folder
define('ENCRYPTION_KEY', getenv('EMMMAIL_ENCRYPTION_KEY') ?: 'dev-only-change-me-32-char-key!!');

$dbPath = getenv('EMMMAIL_DB_PATH') ?: __DIR__ . '/emmmail.sqlite';

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data: https:; style-src \'self\' \'unsafe-inline\'; script-src \'self\' \'unsafe-inline\'');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
  header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Error handling: never leak internals in production.
error_reporting(E_ALL);
ini_set('display_errors', defined('APP_DEBUG') && APP_DEBUG ? '1' : '0');
if (function_exists('xdebug_start_error_collection')) { /* noop */
}

// Session hardening
ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Strict',
]);
if (session_status() === PHP_SESSION_NONE) session_start();

// Cap TCP/connection timeouts so a slow or unreachable mail server fails fast
// instead of hanging the request (and crashing PHP at shutdown).
ini_set('default_socket_timeout', '30');

// [2] Core helpers -----------------------------------------------------------

function h($s)
{
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

// Encrypt a value for storage in the session (used for the mail password).
// Returns [ciphertext, nonce] base64 pairs joined by ':'; '' on soft failure.
function session_encrypt(string $plain): string
{
  if (!function_exists('sodium_crypto_secretbox')) {
    return base64_encode($plain);
  }
  $key = sodium_crypto_shorthash_keygen();
  // Derive a deterministic key so we can decrypt across requests using only
  // the server secret + a per-session salt. Simpler: derive from secret.
  $secret = ENCRYPTION_KEY;
  $key = hash('sha256', $secret, true);
  $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
  $cipher = sodium_crypto_secretbox($plain, $nonce, $key);
  return base64_encode($cipher) . ':' . base64_encode($nonce);
}

function session_decrypt(string $stored): string
{
  if (strpos($stored, ':') === false) {
    return base64_decode($stored) ?: '';
  }
  list($cipher, $nonce) = explode(':', $stored, 2);
  $cipher = base64_decode($cipher);
  $nonce  = base64_decode($nonce);
  if ($cipher === false || $nonce === false) {
    return '';
  }
  $key = hash('sha256', ENCRYPTION_KEY, true);
  $plain = @sodium_crypto_secretbox_open($cipher, $nonce, $key);
  return is_string($plain) ? $plain : '';
}

function redirect($url)
{
  header('Location: /' . ltrim($url, '/'));
  exit;
}

function flash_set($type, $msg)
{
  $_SESSION['flash'][$type] = $msg;
}

function flash_get()
{
  $flash = $_SESSION['flash'] ?? [];
  unset($_SESSION['flash']);
  return $flash;
}

// JSON helper
function json_out($data)
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data);
  exit;
}

/**
 * Does this request want JSON back, or a redirect? Pure.
 *
 * The optimistic client sets ajax=1. Anything without it keeps the flash +
 * redirect behaviour, so every mutating form still works with JS off. A plain
 * navigation often lists application/json in Accept alongside text/html, so
 * that alone is not enough to call it an API request.
 */
function request_wants_json(array $post, array $server): bool
{
  if (!empty($post['ajax'])) {
    return true;
  }
  $accept = (string)($server['HTTP_ACCEPT'] ?? '');
  return stripos($accept, 'application/json') !== false && stripos($accept, 'text/html') === false;
}

/**
 * The target of a message-level ?page= (read/reply/forward/download): the uid
 * and the folder from the query string. Pure -- the per-handler !$uid guard and
 * redirect() stay inline where they belong.
 */
function message_target_from_request(): array
{
  return [(int)($_GET['uid'] ?? 0), urldecode($_GET['folder'] ?? 'INBOX')];
}

/**
 * Answer a mutating action: JSON for the optimistic client, flash + redirect for
 * a plain form post. Exits either way, like json_out() and redirect() do.
 */
function respond_mutation(bool $ok, string $message, string $folder, int $count = 0): void
{
  if (request_wants_json($_POST, $_SERVER)) {
    json_out(['ok' => $ok, 'message' => $message, 'count' => $count]);
  }
  flash_set($ok ? 'success' : 'error', $message);
  redirect('?page=folder&folder=' . urlencode($folder));
}

// [3] Security & text helpers ------------------------------------------------

// Allowlist-based HTML sanitizer. Drops disallowed tags/attributes entirely.
// Returns safe, well-formed HTML suitable for <iframe srcdoc> rendering.
/**
 * May this sender's remote images load? Pure.
 *
 * Off by default: fetching a remote image tells the sender the message was opened,
 * from which IP, and when. The whitelist holds one **exact address** per line --
 * not a domain, so trusting one newsletter does not trust everything behind the
 * same mail host. Addresses compare case-insensitively, as they do everywhere
 * else in this app.
 */
function images_allowed(string $sender, bool $onByDefault, string $whitelist): bool
{
  if ($onByDefault) {
    return true;
  }
  // The sender may arrive as a full From header ("Ann <a@b.c>").
  $addr = strtolower(trim(header_address_list($sender) ?: $sender));
  if ($addr === '') {
    return false;
  }
  foreach (preg_split('/[\r\n,]+/', $whitelist) ?: [] as $line) {
    $line = strtolower(trim($line));
    if ($line !== '' && $line === $addr) {
      return true;
    }
  }
  return false;
}

/**
 * Is this a third-party URL, i.e. one that leaks a request when rendered?
 * Pure. Protocol-relative counts: '//host/x.png' is as remote as https.
 */
function url_is_remote(string $url): bool
{
  return (bool)preg_match('#^\s*(?:https?:)?//#i', $url);
}

/**
 * Strip remote url(...) references from CSS, counting what went. Pure.
 * The second image channel, live only because sanitize_html() keeps CSS now.
 */
function css_strip_remote_urls(string $css, int &$blocked): string
{
  return (string)preg_replace_callback(
    '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
    function ($m) use (&$blocked) {
      if (!url_is_remote($m[2])) {
        return $m[0];
      }
      $blocked++;
      return 'none';
    },
    $css
  );
}

/**
 * Scrub a CSS fragment, whether from a style attribute or a <style> block. Pure.
 *
 * CSS is allowed at all because the body renders in an iframe with
 * sandbox="allow-same-origin" and NO allow-scripts, so it cannot reach the app
 * DOM. That is not a reason to keep the parts of CSS that were script:
 *
 *  - '<' is removed first. CSS never needs it, and it is the only way to close
 *    the <style> element and inject markup after it.
 *  - comments are removed before token matching, or `expr/*x*\/ession()` hides.
 *  - @import / @charset / @namespace fetch or reinterpret from a third party.
 *  - expression(), behavior:, -moz-binding: all executed script in old engines.
 *  - javascript:/vbscript: inside url().
 *
 * '>' survives: it is the child combinator.
 */
function sanitize_css(string $css): string
{
  $css = str_replace('<', '', $css);
  $css = (string)preg_replace('#/\*.*?(?:\*/|$)#s', '', $css);
  $css = (string)preg_replace('/@(?:import|charset|namespace)[^;{}]*;?/i', '', $css);
  // Whole declaration, property through value: a bare property name left behind
  // would still carry its url().
  $css = (string)preg_replace('/[a-z\-]*(?:expression|behaviou?r|binding)[^;}]*;?/i', '', $css);
  $css = (string)preg_replace('/(?:javascript|vbscript|livescript|data)\s*:/i', '', $css);
  return trim($css);
}

/**
 * @param bool     $blockRemote Move third-party image references aside.
 * @param int|null $blocked     Out: how many were blocked, for the reader's CTA.
 */
function sanitize_html($html, bool $blockRemote = false, ?int &$blocked = null)
{
  $blocked = 0;
  if ($html === '' || $html === null) {
    return '';
  }
  $allowedTags = [
    'p',
    'br',
    'b',
    'i',
    'u',
    'em',
    'strong',
    'span',
    'div',
    'table',
    'tr',
    'td',
    'th',
    'thead',
    'tbody',
    'tfoot',
    'caption',
    'col',
    'colgroup',
    'ul',
    'ol',
    'li',
    'dl',
    'dt',
    'dd',
    'a',
    'img',
    'blockquote',
    'pre',
    'code',
    'hr',
    'h1',
    'h2',
    'h3',
    'h4',
    'h5',
    'h6',
    // Presentational, and still everywhere in HTML email. Unwrapping these is
    // what flattened messages into unstyled text.
    'style',
    'center',
    'font',
    'small',
    'big',
    'sub',
    'sup',
    's',
    'strike',
    'tt',
    'figure',
    'figcaption',
  ];
  // Allowed on any permitted element. 'style' is scrubbed by sanitize_css();
  // class and id are here because a <style> block's selectors need them.
  $globalAttrs = ['style', 'class', 'id', 'dir', 'lang', 'align', 'valign', 'width', 'height', 'bgcolor'];
  $allowedAttrs = [
    'a'   => ['href'],
    'img' => ['src', 'alt', 'border', 'hspace', 'vspace'],
    'table' => ['cellpadding', 'cellspacing', 'border', 'background'],
    'td'  => ['colspan', 'rowspan', 'background'],
    'th'  => ['colspan', 'rowspan', 'background'],
    'tr'  => ['background'],
    'font' => ['color', 'face', 'size'],
    'col' => ['span'],
    'colgroup' => ['span'],
    'code' => ['class'],
    'pre' => ['class'],
  ];
  // Elements whose content is code or metadata, never body text. These are
  // removed with their whole subtree, so the source does not leak into the
  // rendered output. Everything else disallowed is unwrapped instead, which
  // keeps legitimate fallback text (e.g. inside <object> or <embed>).
  $dropEntirely = ['script', 'head', 'title', 'template'];

  $doc = new DOMDocument();
  // Suppress libxml warnings for malformed input; load as UTF-8.
  $prev = libxml_use_internal_errors(true);
  $doc->loadHTML('<?xml encoding="UTF-8"><div id="root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);

  $root = $doc->getElementById('root');
  if (!$root) {
    return '';
  }

  $walk = function ($node) use (&$walk, $allowedTags, $allowedAttrs, $globalAttrs, $dropEntirely, $blockRemote, &$blocked) {
    // Convert to an array of child nodes to iterate while removing.
    $children = [];
    foreach ($node->childNodes as $child) {
      $children[] = $child;
    }
    foreach ($children as $child) {
      if ($child instanceof DOMElement) {
        $tag = strtolower($child->nodeName);
        if (in_array($tag, $dropEntirely, true)) {
          $child->parentNode->removeChild($child);
          continue;
        }
        // A <style> block's content is CSS, not markup: scrub it as text and do
        // NOT recurse. Setting textContent also collapses anything the parser
        // may have nested inside it.
        if ($tag === 'style') {
          $css = sanitize_css($child->textContent);
          if ($blockRemote) {
            $css = css_strip_remote_urls($css, $blocked);
          }
          while ($child->attributes->length > 0) {
            $child->removeAttribute($child->attributes->item(0)->nodeName);
          }
          if ($css === '') {
            $child->parentNode->removeChild($child);
            continue;
          }
          $child->textContent = $css;
          continue;
        }
        if (!in_array($tag, $allowedTags, true)) {
          // Unwrap: keep the children, drop the element. The subtree must be
          // sanitized FIRST, because promoted nodes are not in this loop's
          // snapshot and would otherwise never be visited.
          $walk($child);
          $parent = $child->parentNode;
          while ($child->firstChild) {
            $parent->insertBefore($child->firstChild, $child);
          }
          $parent->removeChild($child);
          continue;
        }
        // Sanitize attributes.
        $attrs = [];
        foreach ($child->attributes as $attr) {
          $name = strtolower($attr->nodeName);
          $val  = $attr->nodeValue;
          if (strpos($name, 'on') === 0) {
            continue; // strip event handlers
          }
          if ($name === 'style') {
            // Kept, but scrubbed: see sanitize_css() for what goes and why.
            $val = sanitize_css((string)$val);
            if ($blockRemote) {
              $val = css_strip_remote_urls($val, $blocked);
            }
            if ($val === '') {
              continue;
            }
          }
          if ($name === 'href' || $name === 'src' || $name === 'background') {
            if (preg_match('#^\s*(javascript|vbscript|data)\s*:#i', (string)$val)) {
              continue;
            }
          }
          // Third-party image references are held aside, not dropped: the reader
          // offers to load them, and width/height stay so the layout holds.
          if ($blockRemote && ($name === 'src' || $name === 'background') && url_is_remote((string)$val)) {
            $blocked++;
            if ($name === 'src') {
              $attrs['data-blocked-src'] = $val;
            }
            continue;
          }
          $permitted = array_merge($globalAttrs, $allowedAttrs[$tag] ?? []);
          if (in_array($name, $permitted, true)) {
            $attrs[$name] = $val;
          }
        }
        // Rebuild only permitted attributes.
        while ($child->attributes->length > 0) {
          $child->removeAttribute($child->attributes->item(0)->nodeName);
        }
        foreach ($attrs as $name => $val) {
          $child->setAttribute($name, $val);
        }
        // Recurse.
        $walk($child);
      } elseif ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
        $child->parentNode->removeChild($child);
      }
    }
  };

  $walk($root);

  $out = '';
  foreach ($root->childNodes as $child) {
    $out .= $doc->saveHTML($child);
  }
  return $out;
}

// --- Markdown (composer) ---
//
// The composer posts Markdown. md_to_html() builds the text/html part and the
// editor's initial content; the source itself is the text/plain part.
//
// The output lands in the composer's contenteditable, in the MAIN document, where
// the CSP does not stop inline script. It is therefore safe by construction, not
// by a later clean-up: text is escaped as it is emitted, every attribute is built
// here and escaped exactly once, and no rule ever re-reads markup another rule
// produced (that is how a regex cascade lets an autolink break out of an href).
//
// v1 limitations are decisions, pinned by the `markdown v1 limitations` tests and
// listed in docs/plans/2026-09-28-markdown-compose.md and the README.

// Characters a backslash makes literal. The bullet `•` is handled on its own:
// it is multibyte.
define('MD_ESCAPABLE', "\\`*_[]()#->!~.");
// A line longer than this is emitted as escaped text with no inline rules, so
// hostile input cannot make rendering slow. No real message line is this long.
define('MD_INLINE_MAX_LINE', 65536);
// `> > > ...` nests only this deep; deeper markers are shown as text.
define('MD_MAX_QUOTE_DEPTH', 16);

/**
 * May this URL become an href (or an img src)? Pure.
 *
 * Checked on the RAW text, with a positive match, never on escaped text: a
 * control character, whitespace or backslash anywhere is refused outright, which
 * is what defeats `java\tscript:` and `\x01javascript:`. Images are https only:
 * the CSP blocks http images in the editor, and the recipient would still get it.
 */
function md_url_ok(string $url, bool $image): bool
{
  if ($url === '' || preg_match('/[\x00-\x20\x7f\\\\]/', $url)) {
    return false;
  }
  return (bool)preg_match($image ? '#^https://[^/?\#]#i' : '#^(?:https?://[^/?\#]|mailto:.)#i', $url);
}

/**
 * The bare URL starting at byte $i of $s, or '' when there is none. Pure.
 *
 * Trailing punctuation is left out, as GFM does, so "see https://x.io." and
 * "**https://x.io**" link the URL alone; a closing parenthesis is left out only
 * when it is unbalanced, so Wikipedia's `Foo_(bar)` survives. md_escape() calls
 * this too: the two must agree on where a URL ends.
 */
function md_autolink(string $s, int $i): string
{
  if (strncasecmp(substr($s, $i, 8), 'https://', 8) !== 0 && strncasecmp(substr($s, $i, 7), 'http://', 7) !== 0) {
    return '';
  }
  if ($i > 0 && (ctype_alnum($s[$i - 1]) || ord($s[$i - 1]) >= 0x80)) {
    return '';
  }
  $n = strcspn($s, " \t<>\"`\\", $i, 2049);
  if ($n > 2048) {
    return '';
  }
  $url = substr($s, $i, $n);
  while ($url !== '') {
    $last = substr($url, -1);
    if (
      strpos('.,:;!?*_~\'"', $last) !== false
      || ($last === ')' && substr_count($url, '(') < substr_count($url, ')'))
    ) {
      $url = substr($url, 0, -1);
      continue;
    }
    break;
  }
  return md_url_ok($url, false) ? $url : '';
}

/**
 * One line's inline Markdown to HTML. Pure.
 *
 * Single left-to-right pass. Code spans, links, images and bare URLs are atomic
 * pieces, emitted complete. Emphasis runs go on a delimiter stack and are only
 * turned into tags at the end, so a closer always pairs with the nearest
 * compatible opener and the tags nest by construction.
 *
 * @param bool $links false inside a link label: no <a> inside an <a>.
 */
function md_inline(string $s, bool $links = true): string
{
  $len = strlen($s);
  if ($len > MD_INLINE_MAX_LINE) {
    return h($s);
  }
  $pieces = [];
  $delims = [];     // piece index => [char, literal count left]
  $openTags = [];   // piece index => tags emitted after its literal run
  $closeTags = [];  // piece index => tags emitted before its literal run
  $stack = [];      // piece indices of openers still waiting, innermost last
  $bottom = [];     // char => stack height below which no opener of it exists
  $closeBracket = -1; // next unescaped ']' ($len when none); memo, see below
  $noTicks = [];    // backtick run lengths with no closing run further on
  $isWs = fn(string $ch) => $ch === ' ' || $ch === "\t";
  $isWord = fn(string $ch) => ctype_alnum($ch) || ord($ch) >= 0x80;
  $i = 0;
  while ($i < $len) {
    $c = $s[$i];

    if ($c === '\\' && $i + 1 < $len) {
      if (strpos(MD_ESCAPABLE, $s[$i + 1]) !== false) {
        $pieces[] = h($s[$i + 1]);
        $i += 2;
        continue;
      }
      if (substr($s, $i + 1, 3) === '•') {
        $pieces[] = '•';
        $i += 4;
        continue;
      }
    }

    if ($c === '`') {
      $run = strspn($s, '`', $i);
      $end = -1;
      if (empty($noTicks[$run])) {
        $needle = str_repeat('`', $run);
        for ($j = $i + $run; ($j = strpos($s, $needle, $j)) !== false; $j += $r) {
          $r = strspn($s, '`', $j);
          if ($r === $run) {
            $end = $j;
            break;
          }
        }
        // None from here means none from any later opener either.
        $noTicks[$run] = $end < 0;
      }
      if ($end < 0) {
        $pieces[] = str_repeat('`', $run);
        $i += $run;
        continue;
      }
      $code = substr($s, $i + $run, $end - $i - $run);
      if (strlen($code) > 2 && $code[0] === ' ' && substr($code, -1) === ' ' && trim($code) !== '') {
        $code = substr($code, 1, -1);
      }
      $pieces[] = '<code>' . h($code) . '</code>';
      $i = $end + $run;
      continue;
    }

    if ($links && ($c === '[' || ($c === '!' && ($s[$i + 1] ?? '') === '['))) {
      $img = $c === '!';
      $lb = $i + ($img ? 1 : 0);
      // A label ends at the first unescaped ']' (no nesting), so the next one
      // can be remembered: this keeps `[[[[...` linear.
      if ($closeBracket < $lb) {
        $closeBracket = $len;
        for ($j = $lb + 1; ($j = strpos($s, ']', $j)) !== false; $j++) {
          if ($s[$j - 1] !== '\\') {
            $closeBracket = $j;
            break;
          }
        }
      }
      $rb = $closeBracket;
      // The URL may hold one level of balanced parentheses and no whitespace.
      if (
        $rb < $len && ($s[$rb + 1] ?? '') === '('
        && preg_match('/\G((?:[^\s()]++|\([^\s()]*+\))*+)\)/', $s, $m, 0, $rb + 2)
        && strlen($m[1]) <= 2048
      ) {
        $url = $m[1];
        $label = substr($s, $lb + 1, $rb - $lb - 1);
        if (md_url_ok($url, $img) && ($img || $label !== '')) {
          $pieces[] = $img
            ? '<img src="' . h($url) . '" alt="' . h(preg_replace('/\\\\([\\\\`*_\[\]()#\->!~.])/', '$1', $label)) . '">'
            : '<a href="' . h($url) . '">' . md_inline($label, false) . '</a>';
          $i = $rb + 2 + strlen($m[0]);
          continue;
        }
      }
      // Not a link: the brackets are text. A failed image stays `![`, so it
      // never turns into a link to the same URL.
      $pieces[] = $img ? '![' : '[';
      $i = $lb + 1;
      continue;
    }

    if ($links && ($c === 'h' || $c === 'H') && ($url = md_autolink($s, $i)) !== '') {
      $pieces[] = '<a href="' . h($url) . '">' . h($url) . '</a>';
      $i += strlen($url);
      continue;
    }

    if ($c === '*' || $c === '_' || $c === '~') {
      $run = strspn($s, $c, $i);
      $before = $i > 0 ? $s[$i - 1] : ' ';
      $after = $s[$i + $run] ?? ' ';
      $canOpen = !$isWs($after);
      $canClose = !$isWs($before);
      if ($c === '_') {
        // Intra-word `_` is never emphasis: snake_case_name stays as it is.
        $canOpen = $canOpen && !$isWord($before);
        $canClose = $canClose && !$isWord($after);
      }
      if ($c === '~' && $run !== 2) {
        $canOpen = $canClose = false;
      }
      $idx = count($pieces);
      $pieces[] = '';
      $left = $run;
      while ($canClose && $left > 0) {
        $floor = $bottom[$c] ?? 0;
        for ($k = count($stack) - 1; $k >= $floor && $delims[$stack[$k]][0] !== $c; $k--);
        if ($k < $floor) {
          $bottom[$c] = count($stack);
          break;
        }
        $o = $stack[$k];
        $use = $c === '~' ? 2 : min(2, $left, $delims[$o][1]);
        $tag = $c === '~' ? 's' : ($use === 2 ? 'strong' : 'em');
        array_unshift($openTags[$o], "<$tag>");
        $closeTags[$idx][] = "</$tag>";
        $delims[$o][1] -= $use;
        $left -= $use;
        // Openers above the match can no longer pair: that is what keeps the
        // emitted tags properly nested.
        $stack = array_slice($stack, 0, $delims[$o][1] > 0 ? $k + 1 : $k);
        foreach ($bottom as $ch => $b) {
          $bottom[$ch] = min($b, count($stack));
        }
      }
      $delims[$idx] = [$c, $left];
      $openTags[$idx] = [];
      if ($canOpen && $left > 0) {
        $stack[] = $idx;
      }
      $i += $run;
      continue;
    }

    // Plain text up to the next character that could start something. The stop
    // set is ASCII, so a UTF-8 sequence is never split.
    $run = strcspn($s, "\\`![*_~hH", $i);
    $run = $run > 0 ? $run : 1;
    $pieces[] = h(substr($s, $i, $run));
    $i += $run;
  }

  foreach ($delims as $idx => [$c, $left]) {
    $pieces[$idx] = implode('', $closeTags[$idx] ?? []) . str_repeat($c, $left) . implode('', $openTags[$idx]);
  }
  return implode('', $pieces);
}

/**
 * Is this line a list item? [type, first number, text] or null. Pure.
 *
 * Any indentation is accepted and ignored: lists are flat in v1 (L1), so an
 * indented item is one more item of the same list.
 */
function md_list_item(string $line): ?array
{
  if (!preg_match('/^[ \t]*(?:([-*]|•)|(\d{1,9})\.)[ \t]+/u', $line, $m)) {
    return null;
  }
  $text = substr($line, strlen($m[0]));
  return ($m[2] ?? '') !== '' ? ['ol', (int)$m[2], $text] : ['ul', 0, $text];
}

/**
 * Block structure of a list of lines. Pure.
 *
 * One source line is one <div>, and a blank line is <div><br></div>. That is
 * Gmail's own model and exactly what the editor produces, so the text the user
 * sees, the HTML part and the Markdown source stay line-for-line the same.
 */
function md_blocks(array $lines, int $depth): string
{
  while ($lines && trim((string)end($lines)) === '') {
    array_pop($lines);
  }
  $out = [];
  $prev = '';
  $n = count($lines);
  for ($i = 0; $i < $n; $i++) {
    $line = $lines[$i];
    if (trim($line) === '') {
      $out[] = '<div><br></div>';
      $prev = 'blank';
      continue;
    }
    // Fence. An info string may not hold a backtick, so "```x```" on one line
    // stays an inline code span. An unclosed fence runs to the end.
    if (preg_match('/^[ \t]*```[^`]*$/', $line)) {
      $code = [];
      for ($i++; $i < $n && !preg_match('/^[ \t]*```[ \t]*$/', $lines[$i]); $i++) {
        $code[] = $lines[$i];
      }
      $out[] = '<pre><code>' . h(implode("\n", $code)) . '</code></pre>';
      $prev = 'block';
      continue;
    }
    $lead = strspn($line, " \t");
    if (($line[$lead] ?? '') === '>' && $depth < MD_MAX_QUOTE_DEPTH) {
      $inner = [];
      for (; $i < $n; $i++) {
        $l = $lines[$i];
        $p = strspn($l, " \t");
        if (($l[$p] ?? '') !== '>') {
          break;
        }
        $rest = (string)substr($l, $p + 1);
        $inner[] = ($rest !== '' && ($rest[0] === ' ' || $rest[0] === "\t")) ? substr($rest, 1) : $rest;
      }
      $i--;
      $out[] = '<blockquote>' . md_blocks($inner, $depth + 1) . '</blockquote>';
      $prev = 'block';
      continue;
    }
    // iOS Smart Punctuation turns a typed `---` into `—-`.
    if (preg_match('/^[ \t]*(?:([-*_])(?:[ \t]*\1){2,}|—-+)[ \t]*$/u', $line)) {
      $out[] = '<hr>';
      $prev = 'block';
      continue;
    }
    if (preg_match('/^[ \t]*(#{1,6})[ \t]+(?=\S)/', $line, $m)) {
      $level = strlen($m[1]);
      $out[] = "<h$level>" . md_inline(rtrim(substr($line, strlen($m[0])))) . "</h$level>";
      $prev = 'block';
      continue;
    }
    $item = md_list_item($line);
    // Right after a line of text only `1.` may start a numbered list, so
    // "born in\n1986. It was" stays prose.
    if ($item && !($item[0] === 'ol' && $prev === 'text' && $item[1] !== 1)) {
      [$type, $start] = $item;
      $items = [[$item[2]]];
      for ($i++; $i < $n; $i++) {
        $l = $lines[$i];
        if (trim($l) === '') {
          break;
        }
        $next = md_list_item($l);
        if ($next) {
          if ($next[0] !== $type) {
            break;
          }
          $items[] = [$next[2]];
          continue;
        }
        if ($l[0] !== ' ' && $l[0] !== "\t") {
          break;
        }
        $items[count($items) - 1][] = trim($l);
      }
      $i--;
      $html = '<' . $type . ($type === 'ol' && $start !== 1 ? ' start="' . $start . '"' : '') . '>';
      foreach ($items as $parts) {
        $html .= '<li>' . implode('<br>', array_map('md_inline', $parts)) . '</li>';
      }
      $out[] = $html . '</' . $type . '>';
      $prev = 'block';
      continue;
    }
    $out[] = '<div>' . md_inline($line) . '</div>';
    $prev = 'text';
  }
  return implode("\n", $out);
}

/**
 * Markdown to the HTML the composer and the text/html part use. Pure.
 */
function md_to_html(string $md): string
{
  $md = str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $md);
  // h() returns '' for invalid UTF-8, which would blank the body.
  $md = mb_convert_encoding($md, 'UTF-8', 'UTF-8');
  $html = md_blocks(explode("\n", $md), 0);
  // Never an empty HTML part next to a full text part.
  if ($html === '' && trim($md) !== '') {
    return nl2br(h($md), false);
  }
  return $html;
}

/**
 * The text/html part recipients get: md_to_html() in a full document, with
 * inline styles. Pure.
 *
 * Inline because Gmail strips <style>. No text or background colours: under a
 * recipient's dark mode a light code box behind text that turned white is
 * unreadable, so only borders separate things. The editor gets md_to_html()
 * bare, and the app theme styles it instead.
 *
 * Every tag here was emitted by md_to_html() and all text in it is escaped, so
 * a literal `<h1` in the message cannot be matched.
 */
function md_email_html(string $md): string
{
  $styles = [
    'ul' => 'margin:0 0 1em;padding-left:1.5em',
    'ol' => 'margin:0 0 1em;padding-left:1.5em',
    'blockquote' => 'margin:0 0 1em;padding-left:1em;border-left:3px solid #ccc',
    'pre' => 'margin:0 0 1em;white-space:pre-wrap;word-wrap:break-word;font-family:Consolas,Menlo,monospace',
    'code' => 'font-family:Consolas,Menlo,monospace',
    'img' => 'max-width:100%;height:auto',
    'h1' => 'font-size:1.4em;margin:1em 0 .5em',
    'h2' => 'font-size:1.25em;margin:1em 0 .5em',
    'h3' => 'font-size:1.1em;margin:1em 0 .5em',
    'h4' => 'font-size:1em;margin:1em 0 .5em',
    'h5' => 'font-size:1em;margin:1em 0 .5em',
    'h6' => 'font-size:1em;margin:1em 0 .5em',
  ];
  $body = preg_replace_callback(
    '/<(ul|ol|blockquote|pre|code|img|h[1-6])(?=[ >])/',
    fn($m) => '<' . $m[1] . ' style="' . $styles[$m[1]] . '"',
    md_to_html($md)
  );
  return '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>'
    . '<div style="font-family:-apple-system,Segoe UI,Helvetica,Arial,sans-serif;font-size:14px;line-height:1.5">'
    . $body . '</div></body></html>';
}

/**
 * Make text read literally when rendered as Markdown. Pure.
 *
 * For text the user did not write as Markdown: a quoted reply, a forward. The
 * sender's `![](https://tracker/px)` must not load when the user clicks Reply,
 * and their `* note` or `1986.` must not change shape. Only what a rule would
 * actually read is escaped, so ordinary prose (`snake_case`, `2 * 3`, `C:\path`)
 * is left as it was. Bare URLs are copied unchanged and stay links: their text
 * is their target, so they cannot deceive.
 */
function md_escape(string $text): string
{
  $isWs = fn(string $ch) => $ch === ' ' || $ch === "\t";
  $isWord = fn(string $ch) => ctype_alnum($ch) || ord($ch) >= 0x80;
  $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
  foreach ($lines as &$line) {
    $esc = [];
    $lead = strspn($line, " \t");
    $rest = substr($line, $lead);
    if (strncmp($rest, '—-', 4) === 0) {
      $esc[$lead + 3] = true;
    } elseif (preg_match('/^(?:#{1,6}[ \t]+\S|>|[-*][ \t]|•[ \t]|([-*_])(?:[ \t]*\1){2,}[ \t]*$)/u', $rest)) {
      $esc[$lead] = true;
    } elseif (preg_match('/^(\d{1,9})\.[ \t]/', $rest, $m)) {
      $esc[$lead + strlen($m[1])] = true;
    }
    $out = '';
    $len = strlen($line);
    for ($i = 0; $i < $len; $i++) {
      $c = $line[$i];
      if (($c === 'h' || $c === 'H') && ($url = md_autolink($line, $i)) !== '') {
        $out .= $url;
        $i += strlen($url) - 1;
        continue;
      }
      $before = $i > 0 ? $line[$i - 1] : ' ';
      $after = $line[$i + 1] ?? ' ';
      $e = isset($esc[$i]);
      if ($c === '\\') {
        $e = $e || ($i + 1 < $len && strpos(MD_ESCAPABLE, $after) !== false) || substr($line, $i + 1, 3) === '•';
      } elseif ($c === '`' || $c === '[') {
        $e = true;
      } elseif ($c === '*') {
        $e = $e || !($isWs($before) && $isWs($after));
      } elseif ($c === '_') {
        $e = $e || !(($isWs($before) && $isWs($after)) || ($isWord($before) && $isWord($after)));
      } elseif ($c === '~') {
        $e = $e || $before === '~' || $after === '~';
      }
      $out .= ($e ? '\\' : '') . $c;
    }
    $line = $out;
  }
  unset($line);
  return implode("\n", $lines);
}

// Per-form CSRF. Uses an unguessable server secret + rotating nonce stored in
// the session so each form render yields a fresh, verifiable token.
function csrf_generate()
{
  if (empty($_SESSION['csrf_secret'])) {
    $_SESSION['csrf_secret'] = bin2hex(random_bytes(32));
  }
  $nonce = bin2hex(random_bytes(16));
  // Store the current valid token; rotation invalidates the prior one.
  $_SESSION['csrf_nonce'] = $nonce;
  $token = hash_hmac('sha256', $nonce, $_SESSION['csrf_secret']);
  return $token;
}

function csrf_verify($token)
{
  if (!is_string($token) || $token === '') {
    return false;
  }
  if (empty($_SESSION['csrf_secret']) || empty($_SESSION['csrf_nonce'])) {
    return false;
  }
  $expected = hash_hmac('sha256', $_SESSION['csrf_nonce'], $_SESSION['csrf_secret']);
  return hash_equals($expected, $token);
}

// Stable per-request token shared by every form on a page (main + inline).
// Since csrf_generate() rotates the nonce with each call, forms must not each
// call it; they must use this to avoid stamping one form with a stale token.
function csrf_page_token(): string
{
  if (empty($_SESSION['__mail_csrf'])) {
    $_SESSION['__mail_csrf'] = csrf_generate();
  }
  return $_SESSION['__mail_csrf'];
}

// Parse PHP ini shorthand like '8M', '512K', '1G' into bytes. Returns 0 for
// '0', '-1' (unlimited), and empty strings so the caller can treat 0 as "no limit."
function parse_php_size(string $val): int
{
  $val = trim($val);
  if ($val === '' || $val === '0' || (int)$val < 0) {
    return 0;
  }
  $last = strtolower(substr($val, -1));
  $num  = (int)$val;
  return match ($last) {
    'g' => $num * 1024 * 1024 * 1024,
    'm' => $num * 1024 * 1024,
    'k' => $num * 1024,
    default => $num,
  };
}

// True when the incoming request body was larger than the PHP post_max_size.
// When PHP discards an oversized POST, $_POST and $_FILES are empty but
// $_SERVER['CONTENT_LENGTH'] still holds the original size. Call this BEFORE
// csrf_verify() so the user sees "too large" instead of "token invalid."
function detect_post_overflow(int $limitBytes = 0): bool
{
  if (!isset($_SERVER['CONTENT_LENGTH'])) {
    return false;
  }
  $sent = (int)$_SERVER['CONTENT_LENGTH'];
  if ($sent <= 0) {
    return false;
  }
  if ($limitBytes <= 0) {
    return false; // 0 or -1 means unlimited
  }
  return $sent > $limitBytes;
}

// Strip CR/LF/NUL (and other control chars) from header values to prevent
// header injection. Collapses the resulting whitespace runs and trims.
function sanitize_header_value($value)
{
  $clean = str_replace(["\r", "\n", "\0"], ' ', (string)$value);
  $clean = preg_replace('/[^\x20-\x7E\x80-\xFF]/', ' ', $clean);
  $clean = preg_replace('/\s+/', ' ', $clean);
  return trim($clean);
}

// The first RFC 5322 `<...>` token in a header, or '' when there is none.
// References carries a space-separated chain; the FIRST token is the thread
// root. Rejects a token with control characters or one past the length cap, so
// a hostile header cannot become a routable thread key.
function extract_msgid(string $header): string
{
  if ($header === '' || preg_match('/<[^<>]*>/', $header, $m) !== 1) {
    return '';
  }
  $token = $m[0];
  // '<>' is not an id, and the 400-byte cap and the control-character check
  // keep a hostile header from becoming a routable thread key.
  if (strlen($token) <= 2 || strlen($token) > 400) {
    return '';
  }
  if (preg_match('/[\x00-\x1f]/', $token)) {
    return '';
  }
  return $token;
}

// The conversation root for a message: the first References token, else the
// In-Reply-To token, else the message's own Message-ID. '' means the message
// carries no ids, and the caller substitutes a per-message sentinel.
function thread_root(string $message_id, string $in_reply_to, string $references): string
{
  foreach ([$references, $in_reply_to, $message_id] as $header) {
    $id = extract_msgid($header);
    if ($id !== '') {
      return $id;
    }
  }
  return '';
}

// Gate for the reader route: only a well-formed Message-ID may be used as a
// thread key. Sentinels never reach the route (they are always single-member
// threads, which render as ordinary rows), so they are not accepted here.
function thread_key_is_safe(string $key): bool
{
  return preg_match('/^<[^<>\x00-\x1f]{1,398}>$/', $key) === 1;
}

// Decode MIME encoded-word headers (RFC 2047) to UTF-8.
// Pure PHP — no dependency on the imap extension.
function decode_mime_header($string)
{
  if ($string === '' || $string === null) {
    return '';
  }
  // Quickly return if there are no encoded words.
  if (strpos($string, '=?') === false) {
    return utf8_fix($string);
  }
  // Per RFC 2047, whitespace between adjacent encoded-words is ignored,
  // so remove whitespace that is flanked by encoded words first.
  $string = preg_replace(
    '/=\?[^?]+\?[bBqQ]\?[^?]*\?=\K\s+(?==\?)/',
    '',
    $string
  );

  // Replace all encoded-words =?charset?B|Q?data?= with their decoded form.
  $result = preg_replace_callback(
    '/=\?([^?]+)\?([bBqQ])\?([^?]*)\?=/',
    function ($m) {
      $charset = strtolower(trim($m[1]));
      $encoding = strtoupper($m[2]);
      $data = $m[3];
      if ($encoding === 'B') {
        $decoded = base64_decode($data, false);
        if ($decoded === false) {
          $decoded = $data;
        }
      } else { // Q encoding
        $decoded = str_replace('_', ' ', $data);
        $decoded = quoted_printable_decode($decoded);
      }
      // Apply charset conversion, then a single utf8_fix at the end
      // (never double-convert).
      if ($charset !== 'utf-8' && $charset !== 'us-ascii' && $charset !== 'default') {
        $alias = charset_alias($charset);
        $valid = @in_array(strtolower($alias), array_map('strtolower', mb_list_encodings()), true);
        if ($valid) {
          $converted = @mb_convert_encoding($decoded, 'UTF-8', $alias);
          if ($converted !== false) {
            $decoded = $converted;
          }
        }
      }
      return $decoded;
    },
    $string
  );
  return utf8_fix((string)$result);
}

// Decode RFC 2231 parameter value (e.g. UTF-8''caf%C3%A9.pdf).
function decode_rfc2231($string)
{
  if (!is_string($string) || $string === '') {
    return '';
  }
  $string = ltrim($string, '* ');
  $parts = explode("'", $string);
  if (count($parts) === 3) {
    $body = $parts[2];
  } elseif (count($parts) === 1) {
    $body = $parts[0];
  } else {
    $body = $string;
  }
  // Percent-decode.
  $decoded = rawurldecode($body);
  return utf8_fix($decoded);
}

// Map common/non-standard charset names to PHP mb_* equivalents.
function charset_alias($charset)
{
  $map = [
    'default'          => 'UTF-8',
    'ks_c_5601-1987'   => 'EUC-KR',
    'ks_c_5601-1989'   => 'EUC-KR',
    'x-mac-roman'      => 'macintosh',
    'mac'              => 'macintosh',
    'gb2312'           => 'GB18030',
    'gb-2312'          => 'GB18030',
    'gbk'              => 'GB18030',
    'latin1'           => 'ISO-8859-1',
    'latin2'           => 'ISO-8859-2',
    'cp-850'           => 'CP850',
    'windows-874'      => 'cp874',
    'tis-620'          => 'cp874',
    'x-user-defined'   => 'ISO-8859-1',
  ];
  $k = strtolower(trim($charset));
  return $map[$k] ?? $k;
}

// Repair broken UTF-8 and strip bytes that cannot be represented.
function utf8_fix($str)
{
  if (!is_string($str) || $str === '') {
    return (string)$str;
  }
  if (function_exists('mb_convert_encoding')) {
    // Detect and convert if it is not already valid UTF-8.
    $enc = mb_detect_encoding($str, ['UTF-8', 'ISO-8859-1', 'Windows-1252', 'ASCII'], true);
    if ($enc && $enc !== 'UTF-8' && $enc !== 'ASCII') {
      $converted = @mb_convert_encoding($str, 'UTF-8', $enc);
      if ($converted !== false) {
        return $converted;
      }
    }
  }
  // Strip invalid UTF-8 sequences.
  return mb_convert_encoding($str, 'UTF-8', 'UTF-8');
}

// Exponential backoff in seconds for login throttling. Caps at 60s.
function rate_limit_sleep_seconds($consecutiveFailures)
{
  $n = max(0, (int)$consecutiveFailures);
  if ($n === 0) {
    return 0;
  }
  $delay = 1 << ($n - 1); // 1, 2, 4, 8, 16, ...
  return min($delay, 60);
}

// Parse an SMTP EHLO response into capability info.
// Returns ['auth' => [mechanisms...], 'size' => int|null]
function parse_ehlo_capabilities($response)
{
  $auth = [];
  $size = null;
  foreach (preg_split('/\r?\n/', (string)$response) as $line) {
    // Strip the "250-" or "250 " prefix.
    $line = preg_replace('/^\d{3}[ -]?/', '', trim($line));
    $upper = strtoupper($line);
    if (preg_match('/^AUTH\s+(.+)$/', $upper, $m)) {
      foreach (preg_split('/\s+/', $m[1]) as $mech) {
        $auth[] = strtoupper(trim($mech, "= \t"));
      }
    } elseif (preg_match('/^AUTH=([A-Z0-9]+)/', $upper, $m)) {
      $auth[] = $m[1];
    } elseif (preg_match('/^SIZE\s+(\d+)/i', $line, $m)) {
      $size = (int)$m[1];
    }
  }
  $auth = array_values(array_filter($auth));
  return ['auth' => $auth, 'size' => $size];
}

// [5] SMTP — raw MIME message construction (pure, testable) ------------------

// Fold a header line so no line exceeds 78 chars (RFC 5322 SHOULD).
function _fold_header($label, $value)
{
  $encoded = $label . ': ' . $value;
  if (strlen($encoded) <= 76) {
    return $encoded;
  }
  $words = explode(' ', $encoded);
  $lines   = [];
  $current = '';
  foreach ($words as $w) {
    if ($current === '') {
      $current = $w;
    } elseif (strlen($current) + 1 + strlen($w) <= 76) {
      $current .= ' ' . $w;
    } else {
      $lines[] = $current;
      $current = $w;
    }
  }
  if ($current !== '') {
    $lines[] = $current;
  }
  // Continuation lines are indented with a tab.
  $out = array_shift($lines);
  foreach ($lines as $l) {
    $out .= "\r\n\t" . $l;
  }
  return $out;
}

function _dot_stuff($raw)
{
  // Escape lines starting with "." per RFC 5321.
  return preg_replace('/^(\.)/m', '..', $raw);
}

function smtp_build_raw_message($from, $to, $subject, $body, $fromName = '', $attachments = [], $cc = '', $bcc = '', $inReplyTo = '', $references = '', ?string $textBody = null)
{
  $from    = sanitize_header_value($from);
  $to      = sanitize_header_value($to);
  $subject = sanitize_header_value($subject);
  $cc      = sanitize_header_value($cc);
  $bcc     = sanitize_header_value($bcc);
  $fromName = sanitize_header_value($fromName);

  $boundaryMixed = '----=_emmmail_' . bin2hex(random_bytes(16));
  $boundaryAlt   = '----=_emmmail_alt_' . bin2hex(random_bytes(16));

  // The composer passes its Markdown source as $textBody: it is the plain-text
  // part as written, and must not go through strip_tags (`a <b> c` is text).
  if ($textBody !== null) {
    $textBody = str_replace("\n", "\r\n", str_replace(["\r\n", "\r"], "\n", $textBody));
  } else {
    $textBody = strip_tags($body);
    $textBody = html_entity_decode($textBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
  }

  $headers = [];
  $headers[] = 'Return-Path: <' . $from . '>';
  $headers[] = 'Delivered-To: ' . $to;
  if ($fromName !== '') {
    if (preg_match('/[^\x20-\x7E]/', $fromName)) {
      $encName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
      $headers[] = 'From: ' . $encName . ' <' . $from . '>';
    } else {
      $headers[] = 'From: ' . $fromName . ' <' . $from . '>';
    }
  } else {
    $headers[] = 'From: <' . $from . '>';
  }
  $headers[] = 'To: ' . $to;
  if ($cc !== '') {
    $headers[] = 'Cc: ' . $cc;
  }
  // Subject: B-encode if it contains non-ASCII.
  if (preg_match('/[^\x20-\x7E]/', $subject)) {
    $headers[] = 'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=';
  } else {
    $headers[] = 'Subject: ' . $subject;
  }
  $headers[] = 'Date: ' . date('r');
  $headers[] = 'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/^.+@/', '', $from) . '>';
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'X-Mailer: Emmmail/' . APP_VERSION;
  if ($inReplyTo !== '') {
    $headers[] = 'In-Reply-To: ' . sanitize_header_value($inReplyTo);
    // References carries the whole ancestry when the caller knows it. Falling back
    // to the parent alone still threads everywhere; sending nothing does not.
    $headers[] = 'References: ' . sanitize_header_value($references !== '' ? $references : $inReplyTo);
  }

  $hasAttachments = !empty($attachments);

  $bodyRaw = "This is a multi-part message in MIME format.\r\n";
  if ($hasAttachments) {
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"';
    $bodyRaw .= "\r\n--$boundaryMixed\r\n";
    $bodyRaw .= 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n";
    $bodyRaw .= "--$boundaryAlt\r\n";
    $bodyRaw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $bodyRaw .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $bodyRaw .= chunk_split(base64_encode($textBody)) . "\r\n";
    $bodyRaw .= "--$boundaryAlt\r\n";
    $bodyRaw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $bodyRaw .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $bodyRaw .= chunk_split(base64_encode($body)) . "\r\n";
    $bodyRaw .= "--$boundaryAlt--\r\n";
    foreach ($attachments as $att) {
      $name = sanitize_header_value($att['name'] ?? 'attachment');
      $data = $att['data'] ?? '';
      $bodyRaw .= "\r\n--$boundaryMixed\r\n";
      $bodyRaw .= 'Content-Type: application/octet-stream; name="=?UTF-8?B?' . base64_encode($name) . '?="' . "\r\n";
      $bodyRaw .= "Content-Transfer-Encoding: base64\r\n";
      $bodyRaw .= 'Content-Disposition: attachment; filename="=?UTF-8?B?' . base64_encode($name) . '?="' . "\r\n\r\n";
      $bodyRaw .= chunk_split(base64_encode($data)) . "\r\n";
    }
    $bodyRaw .= "--$boundaryMixed--";
  } else {
    $headers[] = 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"';
    $bodyRaw .= "\r\n--$boundaryAlt\r\n";
    $bodyRaw .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $bodyRaw .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $bodyRaw .= chunk_split(base64_encode($textBody)) . "\r\n";
    $bodyRaw .= "--$boundaryAlt\r\n";
    $bodyRaw .= "Content-Type: text/html; charset=UTF-8\r\n";
    $bodyRaw .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $bodyRaw .= chunk_split(base64_encode($body)) . "\r\n";
    $bodyRaw .= "--$boundaryAlt--";
  }

  // Build header block with folding.
  $headerLines = [];
  foreach ($headers as $hdr) {
    list($label, $value) = explode(': ', $hdr, 2);
    $headerLines[] = _fold_header($label, $value);
  }

  $message = implode("\r\n", $headerLines) . "\r\n\r\n" . $bodyRaw;
  return _dot_stuff($message);
}

/**
 * Add a Bcc header to an already-built message, for the copy filed in Sent.
 *
 * smtp_build_raw_message() deliberately never emits Bcc: the blind recipients
 * travel in the SMTP envelope only, and a header would defeat the point. The
 * user still needs a record of who received the mail, so the copy appended to
 * Sent gets the header added here -- to the same bytes that were transmitted, so
 * the Message-ID and MIME boundaries still match.
 *
 * The header is appended to the end of the header block; field order carries no
 * meaning to a reader. sanitize_header_value() flattens CR/LF, so a crafted
 * value cannot start a header line of its own.
 */
function smtp_raw_with_bcc(string $raw, string $bcc): string
{
  $bcc = sanitize_header_value($bcc);
  if ($bcc === '') {
    return $raw;
  }
  $split = strpos($raw, "\r\n\r\n");
  if ($split === false) {
    return $raw;
  }
  return substr($raw, 0, $split) . "\r\n" . _fold_header('Bcc', $bcc) . substr($raw, $split);
}

// [4] SQLite -----------------------------------------------------------------

// Open (and lazily create) the SQLite database with WAL mode and the schema.
// Returns a PDO instance. Pass $path to use an alternate DB (used by tests).
function db_open($path = null)
{
  static $pdo = null;
  if ($path !== null) {
    // Always honor an explicit path (tests use :memory:).
    $pdo = null;
  }
  $path = $path ?: getenv('EMMMAIL_DB_PATH') ?: __DIR__ . '/emmmail.sqlite';
  if ($pdo instanceof PDO) {
    return $pdo;
  }
  if (!class_exists('PDO')) {
    return null;
  }
  try {
    $pdo = new PDO('sqlite:' . $path, null, null, [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    @chmod($path, 0600);
    // busy_timeout must come first: the migrations below take write locks, and
    // without a timeout a concurrent request fails instantly.
    $pdo->exec('PRAGMA busy_timeout=5000');
    // Switching journal mode needs a brief exclusive lock, and SQLite does not
    // honor busy_timeout for it. The mode is persistent, so only attempt the
    // switch when it is not already WAL, and treat a lock conflict as
    // non-fatal: another request is doing it, and the default mode still works.
    try {
      if (strcasecmp((string)$pdo->query('PRAGMA journal_mode')->fetchColumn(), 'wal') !== 0) {
        $pdo->exec('PRAGMA journal_mode=WAL');
      }
    } catch (Throwable $e) {
      // Keep whatever journal mode the database already has.
    }
    $pdo->exec('PRAGMA foreign_keys=ON');
    db_migrate($pdo);
    return $pdo;
  } catch (Throwable $e) {
    // Never leave a half-migrated handle in the static, or the next db_open()
    // would return it and skip migrations entirely.
    $pdo = null;
    return null;
  }
}

// Test-only: reset the cached connection so a fresh DB can be opened.
// Honors EMMMAIL_DB_PATH (e.g. a test file) and falls back to :memory:.
function db_reset_for_test()
{
  db_open(getenv('EMMMAIL_DB_PATH') ?: ':memory:');
}

// The schema version lives in SQLite's own header (PRAGMA user_version), so it
// needs no table and it is transactional with the DDL beside it.
function db_schema_version(PDO $pdo): int
{
  return (int)$pdo->query('PRAGMA user_version')->fetchColumn();
}

// SQLite has no `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`, so an additive
// migration step must check for the column itself.
function db_has_column(PDO $pdo, string $table, string $column): bool
{
  // PRAGMA does not accept bound parameters; quote the identifier instead.
  $quoted = '"' . str_replace('"', '""', $table) . '"';
  foreach ($pdo->query('PRAGMA table_info(' . $quoted . ')')->fetchAll() as $col) {
    if (($col['name'] ?? '') === $column) {
      return true;
    }
  }
  return false;
}

// Apply any outstanding migration steps. Forward-only: there is no `down`.
// A database written by a newer version of the app is left untouched.
function db_migrate(PDO $pdo)
{
  if (db_schema_version($pdo) >= SCHEMA_VERSION) {
    return; // steady state: one integer read, no DDL
  }
  while (true) {
    // BEGIN IMMEDIATE takes the write lock up front, so two concurrent
    // requests cannot apply the same step twice. The loser waits on
    // busy_timeout, re-reads the version below, and finds nothing to do.
    $pdo->exec('BEGIN IMMEDIATE');
    $version = db_schema_version($pdo);
    if ($version >= SCHEMA_VERSION) {
      $pdo->exec('COMMIT');
      return;
    }
    try {
      db_migration_step($pdo, $version + 1);
      // One transaction per step, so a failure part-way through a chain
      // leaves the database at the last step that fully succeeded.
      $pdo->exec('PRAGMA user_version = ' . (int)($version + 1));
      $pdo->exec('COMMIT');
    } catch (Throwable $e) {
      $pdo->exec('ROLLBACK');
      throw $e;
    }
  }
}

// One case per schema version, applied in order. Once a case has shipped it is
// never edited again: add a new case and bump SCHEMA_VERSION instead.
function db_migration_step(PDO $pdo, int $version): void
{
  switch ($version) {
    case 1:
      // Baseline: the schema as it stood before versioning existed. Every
      // statement is guarded, so this is a no-op on an existing database and
      // only advances it from version 0 to 1.
      $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        user_email TEXT NOT NULL,
        key        TEXT NOT NULL,
        value      TEXT,
        PRIMARY KEY (user_email, key)
    )");
      $pdo->exec("CREATE TABLE IF NOT EXISTS contacts (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_email TEXT NOT NULL,
        name       TEXT DEFAULT '',
        email      TEXT NOT NULL,
        frequency  INTEGER DEFAULT 1,
        sent_to    INTEGER DEFAULT 0,
        last_used  TEXT DEFAULT (datetime('now')),
        UNIQUE(user_email, email)
    )");
      $pdo->exec("CREATE TABLE IF NOT EXISTS search_cache (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        user_email   TEXT NOT NULL,
        folder       TEXT NOT NULL,
        uid          INTEGER NOT NULL,
        subject      TEXT,
        sender       TEXT,
        recipient    TEXT,
        body_snippet TEXT,
        date_ts      INTEGER,
        cached_at    INTEGER NOT NULL,
        UNIQUE(user_email, folder, uid)
    )");
      $pdo->exec("CREATE TABLE IF NOT EXISTS login_attempts (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        ip           TEXT NOT NULL,
        attempted_at INTEGER NOT NULL,
        success      INTEGER DEFAULT 0
    )");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_login_ip ON login_attempts(ip, attempted_at)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_contacts_user ON contacts(user_email)");
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_cache_user ON search_cache(user_email, folder)");
      break;

    case 2:
      // Message header cache. Bodies are never stored here; `snippet` holds a
      // short plain-text excerpt captured when a message is opened.
      $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
        user_email       TEXT    NOT NULL,
        folder           TEXT    NOT NULL,
        uid              INTEGER NOT NULL,
        subject          TEXT    DEFAULT '',
        from_display     TEXT    DEFAULT '',
        from_address     TEXT    DEFAULT '',
        to_display       TEXT    DEFAULT '',
        to_address       TEXT    DEFAULT '',
        date_ts          INTEGER NOT NULL,
        size             INTEGER DEFAULT 0,
        unread           INTEGER DEFAULT 0,
        answered         INTEGER DEFAULT 0,
        flagged          INTEGER DEFAULT 0,
        deleted          INTEGER DEFAULT 0,
        has_attachment   INTEGER DEFAULT 0,
        structure_synced INTEGER DEFAULT 0,
        snippet          TEXT    DEFAULT '',
        cached_at        INTEGER NOT NULL,
        UNIQUE(user_email, folder, uid)
    )");
      // uid is part of the key because INTERNALDATE has one-second resolution:
      // bulk delivery produces ties, and without it SQLite adds a temp B-tree
      // for the ORDER BY and pagination can repeat rows across pages.
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_msg_list
        ON messages(user_email, folder, date_ts DESC, uid DESC)");
      // Per-folder sync bookkeeping. The counters are nullable on purpose:
      // NULL means "the server did not tell us", which must never be confused
      // with 0, or an unchanged-looking comparison freezes the cache.
      $pdo->exec("CREATE TABLE IF NOT EXISTS folder_state (
        user_email    TEXT    NOT NULL,
        folder        TEXT    NOT NULL,
        uidvalidity   INTEGER,
        uidnext       INTEGER,
        total         INTEGER,
        unseen        INTEGER,
        cached_rows   INTEGER DEFAULT 0,
        last_sync_ts  INTEGER DEFAULT 0,
        last_full_ts  INTEGER DEFAULT 0,
        PRIMARY KEY (user_email, folder)
    )");
      $pdo->exec("CREATE TABLE IF NOT EXISTS folder_list_cache (
        user_email TEXT PRIMARY KEY,
        payload    TEXT NOT NULL,
        cached_at  INTEGER NOT NULL
    )");
      break;

    case 3:
      // Sidebar counters. Separate from folder_state on purpose: folder_state's
      // counters are sync bookkeeping that sync_decide() compares to STATUS, so
      // a sweep or an optimistic +/-1 written there would hide a real change.
      $pdo->exec("CREATE TABLE IF NOT EXISTS folder_counts (
        user_email TEXT    NOT NULL,
        folder     TEXT    NOT NULL,
        unseen     INTEGER,
        total      INTEGER,
        checked_at INTEGER NOT NULL DEFAULT 0,
        PRIMARY KEY (user_email, folder)
    )");
      break;

    case 4:
      // Threaded conversations. thread_key is the conversation root: the first
      // References token, else In-Reply-To, else the message's own Message-ID
      // (see thread_root()). Only the key is stored; the raw ids are inputs.
      if (!db_has_column($pdo, 'messages', 'thread_key')) {
        $pdo->exec("ALTER TABLE messages ADD COLUMN thread_key TEXT DEFAULT ''");
      }
      // The per-folder listing ranks conversations by their newest member.
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_msg_thread
        ON messages(user_email, folder, thread_key, date_ts DESC, uid DESC)");
      // The cross-folder lookup: every cached member of a conversation.
      $pdo->exec("CREATE INDEX IF NOT EXISTS idx_msg_thread_user
        ON messages(user_email, thread_key)");
      // Rows cached before v4 carry no References, so their thread_key would be
      // '' and GROUP BY would collapse the whole folder into one conversation.
      // This cache is disposable (bodies are never stored here), so drop it and
      // let the next visit to each folder cold-sync with real keys.
      $pdo->exec("DELETE FROM messages");
      $pdo->exec("UPDATE folder_state SET cached_rows = 0, last_full_ts = 0");
      break;

    default:
      throw new RuntimeException('Unknown schema migration step: ' . $version);
  }
}

// --- Settings CRUD ---
function db_get_setting($email, $key, $default = null)
{
  $pdo = db_open();
  if (!$pdo) return $default;
  $stmt = $pdo->prepare('SELECT value FROM settings WHERE user_email = ? AND key = ?');
  $stmt->execute([$email, $key]);
  $row = $stmt->fetch();
  return $row ? $row['value'] : $default;
}

function db_set_setting($email, $key, $value)
{
  $pdo = db_open();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('INSERT INTO settings (user_email, key, value) VALUES (?, ?, ?)
                           ON CONFLICT(user_email, key) DO UPDATE SET value = excluded.value');
  $stmt->execute([$email, $key, $value]);
  return true;
}

function db_get_all_settings($email)
{
  $pdo = db_open();
  if (!$pdo) return [];
  $stmt = $pdo->prepare('SELECT key, value FROM settings WHERE user_email = ?');
  $stmt->execute([$email]);
  $out = [];
  foreach ($stmt->fetchAll() as $row) {
    $out[$row['key']] = $row['value'];
  }
  return $out;
}

// --- Contacts CRUD ---
function db_upsert_contact($email, $name, $addr, $sentTo)
{
  $pdo = db_open();
  if (!$pdo) return false;
  $addr = strtolower(trim($addr));
  if ($addr === '') return false;
  $name = trim($name);
  $stmt = $pdo->prepare('INSERT INTO contacts (user_email, name, email, frequency, sent_to, last_used)
                           VALUES (?, ?, ?, 1, ?, datetime(\'now\'))
                           ON CONFLICT(user_email, email) DO UPDATE SET
                             name = excluded.name,
                             frequency = contacts.frequency + 1,
                             sent_to = MAX(contacts.sent_to, excluded.sent_to),
                             last_used = datetime(\'now\')');
  $stmt->execute([$email, $name, $addr, $sentTo ? 1 : 0]);
  return true;
}

function db_search_contacts($email, $query)
{
  $pdo = db_open();
  if (!$pdo) return [];
  $q = '%' . $query . '%';
  $stmt = $pdo->prepare('SELECT name, email, frequency, sent_to FROM contacts
                           WHERE user_email = ? AND (email LIKE ? OR name LIKE ?)
                           ORDER BY sent_to DESC, frequency DESC, last_used DESC LIMIT 20');
  $stmt->execute([$email, $q, $q]);
  return $stmt->fetchAll();
}

// --- Search cache CRUD ---
function db_cache_email($email, $folder, $uid, $subject, $sender, $recipient, $bodySnippet, $dateTs)
{
  $pdo = db_open();
  if (!$pdo) return false;
  $stmt = $pdo->prepare('INSERT INTO search_cache (user_email, folder, uid, subject, sender, recipient, body_snippet, date_ts, cached_at)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                           ON CONFLICT(user_email, folder, uid) DO UPDATE SET
                             subject = excluded.subject,
                             sender = excluded.sender,
                             recipient = excluded.recipient,
                             body_snippet = excluded.body_snippet,
                             date_ts = excluded.date_ts,
                             cached_at = excluded.cached_at');
  $stmt->execute([$email, $folder, $uid, $subject, $sender, $recipient, $bodySnippet, $dateTs, time()]);
  return true;
}

function db_search_cache($email, $folder, $query, $limit = 200)
{
  $pdo = db_open();
  if (!$pdo) return [];
  $q = '%' . $query . '%';
  $stmt = $pdo->prepare('SELECT folder, uid, subject, sender, recipient, body_snippet, date_ts
                           FROM search_cache
                           WHERE user_email = ? AND folder = ?
                             AND (subject LIKE ? OR sender LIKE ? OR recipient LIKE ? OR body_snippet LIKE ?)
                           ORDER BY date_ts DESC LIMIT ' . (int)$limit);
  $stmt->execute([$email, $folder, $q, $q, $q, $q]);
  return $stmt->fetchAll();
}

function db_prune_cache($email, $maxAgeSeconds = SEARCH_CACHE_TTL_SECONDS)
{
  $pdo = db_open();
  if (!$pdo) return;
  $cutoff = time() - $maxAgeSeconds;
  $stmt = $pdo->prepare('DELETE FROM search_cache WHERE user_email = ? AND cached_at < ?');
  $stmt->execute([$email, $cutoff]);
}

function db_purge_cache_for_email($email)
{
  $pdo = db_open();
  if (!$pdo) return;
  $stmt = $pdo->prepare('DELETE FROM search_cache WHERE user_email = ?');
  $stmt->execute([$email]);
}

function db_remove_cache_uid($email, $folder, $uid)
{
  $pdo = db_open();
  if (!$pdo) return;
  $stmt = $pdo->prepare('DELETE FROM search_cache WHERE user_email = ? AND folder = ? AND uid = ?');
  $stmt->execute([$email, $folder, $uid]);
}

// --- Message header cache ---
// The cache is disposable: on any storage error these helpers degrade to "no
// cache" instead of taking the page down. The callers fall back to live IMAP.

// Cast the integer-as-boolean columns back to real bools for the view layer.
function _cache_hydrate(array $row): array
{
  foreach (['unread', 'answered', 'flagged', 'deleted', 'has_attachment', 'structure_synced'] as $k) {
    $row[$k] = (bool)$row[$k];
  }
  foreach (['uid', 'date_ts', 'size'] as $k) {
    $row[$k] = (int)$row[$k];
  }
  return $row;
}

// Insert or refresh a batch of message headers in a single transaction.
// Returns the number of rows written, or 0 if the cache is unavailable.
//
// has_attachment, structure_synced and snippet are deliberately NOT touched on
// conflict: they are filled in by separate, slower passes and re-syncing an
// envelope must not discard that work. thread_key is repaired only when the
// incoming row carries a real key, so a re-fetch can fix a sentinel but never
// blank a good one.
function db_upsert_messages(string $email, string $folder, array $rows): int
{
  $pdo = db_open();
  if (!$pdo || !$rows) return 0;
  try {
    $stmt = $pdo->prepare('INSERT INTO messages
        (user_email, folder, uid, subject, from_display, from_address,
         to_display, to_address, date_ts, size, unread, answered, flagged,
         deleted, has_attachment, structure_synced, thread_key, cached_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON CONFLICT(user_email, folder, uid) DO UPDATE SET
          subject      = excluded.subject,
          from_display = excluded.from_display,
          from_address = excluded.from_address,
          to_display   = excluded.to_display,
          to_address   = excluded.to_address,
          date_ts      = excluded.date_ts,
          size         = excluded.size,
          unread       = excluded.unread,
          answered     = excluded.answered,
          flagged      = excluded.flagged,
          deleted      = excluded.deleted,
          thread_key   = CASE WHEN length(excluded.thread_key) > 0 THEN excluded.thread_key ELSE thread_key END,
          cached_at    = excluded.cached_at');
    $now = time();
    $n = 0;
    $pdo->beginTransaction();
    foreach ($rows as $r) {
      $stmt->execute([
        $email,
        $folder,
        (int)$r['uid'],
        (string)($r['subject'] ?? ''),
        (string)($r['from_display'] ?? ''),
        (string)($r['from_address'] ?? ''),
        (string)($r['to_display'] ?? ''),
        (string)($r['to_address'] ?? ''),
        (int)($r['date_ts'] ?? 0),
        (int)($r['size'] ?? 0),
        !empty($r['unread']) ? 1 : 0,
        !empty($r['answered']) ? 1 : 0,
        !empty($r['flagged']) ? 1 : 0,
        !empty($r['deleted']) ? 1 : 0,
        // Only meaningful on INSERT; the DO UPDATE above never touches these.
        !empty($r['has_attachment']) ? 1 : 0,
        !empty($r['structure_synced']) ? 1 : 0,
        (string)($r['thread_key'] ?? ''),
        $now,
      ]);
      $n++;
    }
    $pdo->commit();
    return $n;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return 0;
  }
}

function db_list_messages(string $email, string $folder, int $page = 1, int $perPage = 20): array
{
  $pdo = db_open();
  if (!$pdo) return [];
  $perPage = max(1, $perPage);
  $offset = max(0, ($page - 1) * $perPage);
  try {
    $stmt = $pdo->prepare('SELECT * FROM messages
                             WHERE user_email = ? AND folder = ?
                             ORDER BY date_ts DESC, uid DESC
                             LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$offset);
    $stmt->execute([$email, $folder]);
    return array_map('_cache_hydrate', $stmt->fetchAll());
  } catch (Throwable $e) {
    return [];
  }
}

function db_count_messages(string $email, string $folder): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// A folder that may never join a conversation. A reply-draft carries References
// and would otherwise inflate a thread's count and be marked read by the
// reader; Trash and Junk are excluded for the same reason.
function thread_folder_is_member(string $folder): bool
{
  return !MailImap::isDraftsFolder($folder)
    && !MailImap::isTrashFolder($folder)
    && !MailImap::isJunkFolder($folder);
}

// The excluded folders this user actually has cached rows in. Role detection is
// PHP (see thread_folder_is_member), so the filter happens here, not in SQL.
function thread_excluded_folders(string $email): array
{
  $pdo = db_open();
  if (!$pdo) return [];
  try {
    $stmt = $pdo->prepare('SELECT DISTINCT folder FROM messages WHERE user_email = ?');
    $stmt->execute([$email]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
      $name = (string)$name;
      if (!thread_folder_is_member($name)) $out[] = $name;
    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

function db_count_threads(string $email, string $folder): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->prepare('SELECT COUNT(DISTINCT thread_key) FROM messages WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// Rows with no conversation key. A non-zero count means the cache predates the
// conversation view (or a code path skipped the key), and grouping them by ''
// would collapse the whole folder into one thread. The sync rebuilds such a
// folder once. Steady state is 0: sync_folder() always writes a key or sentinel.
function db_count_unthreaded(string $email, string $folder): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_email = ? AND folder = ? AND thread_key = ''");
    $stmt->execute([$email, $folder]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// One listing row per conversation in $folder. The anchor supplied for display
// is the conversation's global newest member (so a reply filed in Sent
// surfaces); the row's uid is the folder's newest member, for the cursor.
function db_list_threads(string $email, string $folder, int $page, int $perPage): array
{
  $pdo = db_open();
  if (!$pdo) return [];
  $perPage = max(1, $perPage);
  $offset = max(0, ($page - 1) * $perPage);
  try {
    $ex = thread_excluded_folders($email);
    $notIn = $ex ? (' AND folder NOT IN (' . implode(',', array_fill(0, count($ex), '?')) . ')') : '';
    // The page of conversations, ranked by the whole conversation's newest
    // member (across the allowed folders), not just this folder's.
    $stmt = $pdo->prepare("SELECT thread_key, MAX(date_ts) AS newest
        FROM messages
        WHERE user_email = ?$notIn
          AND thread_key IN (SELECT DISTINCT thread_key FROM messages WHERE user_email = ? AND folder = ?)
        GROUP BY thread_key
        ORDER BY newest DESC, MAX(uid) DESC
        LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset);
    $stmt->execute(array_merge([$email], $ex, [$email, $folder]));
    $keys = array_column($stmt->fetchAll(), 'thread_key');
    if (!$keys) return [];

    $keyPh = implode(',', array_fill(0, count($keys), '?'));
    $stmt = $pdo->prepare("SELECT * FROM messages
        WHERE user_email = ?$notIn AND thread_key IN ($keyPh)
        ORDER BY date_ts ASC, uid ASC");
    $stmt->execute(array_merge([$email], $ex, $keys));
    $rows = array_map('_cache_hydrate', $stmt->fetchAll());

    $groups = [];
    foreach ($rows as $r) {
      $groups[(string)$r['thread_key']][] = $r;
    }
    $out = [];
    foreach ($keys as $k) {
      $members = $groups[$k] ?? [];
      if (!$members) continue;
      $inFolder = [];
      foreach ($members as $m) {
        if ((string)$m['folder'] === $folder) $inFolder[] = $m;
      }
      if (!$inFolder) continue;
      // Members are ascending, so the last is the newest. The global newest
      // supplies the display; the folder newest supplies the cursor uid.
      $anchor = $members[count($members) - 1];
      $view = cache_rows_to_view([$anchor])[0];
      $view['uid'] = (int)$inFolder[count($inFolder) - 1]['uid'];
      $view['unread'] = false;
      $view['has_attachment'] = false;
      $unread = 0;
      foreach ($members as $m) {
        if (!empty($m['unread'])) {
          $view['unread'] = true;
          $unread++;
        }
        if (!empty($m['has_attachment'])) $view['has_attachment'] = true;
      }
      $view['thread_key']    = $k;
      $view['thread_count']  = count($members);
      $view['thread_unread'] = $unread;
      $view['thread_uids']   = array_map(fn($m) => (int)$m['uid'], $inFolder);
      $out[] = $view;
    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

// Every cached member of one conversation, across folders, oldest first.
function db_thread_members(string $email, string $threadKey): array
{
  $pdo = db_open();
  if (!$pdo || $threadKey === '') return [];
  try {
    $ex = thread_excluded_folders($email);
    $notIn = $ex ? (' AND folder NOT IN (' . implode(',', array_fill(0, count($ex), '?')) . ')') : '';
    $stmt = $pdo->prepare('SELECT * FROM messages
        WHERE user_email = ? AND thread_key = ?' . $notIn . '
        ORDER BY date_ts ASC, uid ASC');
    $stmt->execute(array_merge([$email, $threadKey], $ex));
    return array_map('_cache_hydrate', $stmt->fetchAll());
  } catch (Throwable $e) {
    return [];
  }
}

// The neighbours of a message in the folder's list order — "prev" is the row
// above (newer, what k opens), "next" the row below (older, what j opens).
// Ranked over the WHOLE cached folder, not a page window: a neighbour can sit
// on another page, and db_list_messages() is page-limited to 20.
function read_prev_next(string $email, string $folder, int $uid): array
{
  $pdo = db_open();
  if (!$pdo) return ['prev' => 0, 'next' => 0];
  try {
    $stmt = $pdo->prepare('SELECT date_ts, uid FROM messages WHERE user_email = ? AND folder = ? AND uid = ?');
    $stmt->execute([$email, $folder, $uid]);
    $cur = $stmt->fetch();
    if (!$cur) return ['prev' => 0, 'next' => 0];
    $ts = (int)$cur['date_ts'];
    // The row above sorts immediately before this one in list order, so it is
    // the smallest (date_ts, uid) pair strictly greater than ours.
    $stmt = $pdo->prepare('SELECT uid FROM messages WHERE user_email = ? AND folder = ?
                           AND (date_ts > ? OR (date_ts = ? AND uid > ?))
                           ORDER BY date_ts ASC, uid ASC LIMIT 1');
    $stmt->execute([$email, $folder, $ts, $ts, $uid]);
    $prev = (int)$stmt->fetchColumn();
    // The row below is the largest pair strictly smaller than ours.
    $stmt = $pdo->prepare('SELECT uid FROM messages WHERE user_email = ? AND folder = ?
                           AND (date_ts < ? OR (date_ts = ? AND uid < ?))
                           ORDER BY date_ts DESC, uid DESC LIMIT 1');
    $stmt->execute([$email, $folder, $ts, $ts, $uid]);
    $next = (int)$stmt->fetchColumn();
    return ['prev' => $prev, 'next' => $next];
  } catch (Throwable $e) {
    return ['prev' => 0, 'next' => 0];
  }
}

// Highest UID we hold for a folder. Drives the "UID <max+1>:*" range that finds
// new messages, so it must never be higher than a contiguous cached window.
function db_max_cached_uid(string $email, string $folder): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->prepare('SELECT MAX(uid) FROM messages WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// Delete specific UIDs, or the whole folder when $uids is null.
function db_delete_messages(string $email, string $folder, ?array $uids = null): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  if ($uids !== null && !$uids) return 0;
  try {
    if ($uids === null) {
      $stmt = $pdo->prepare('DELETE FROM messages WHERE user_email = ? AND folder = ?');
      $stmt->execute([$email, $folder]);
      return $stmt->rowCount();
    }
    $ints = array_map('intval', $uids);
    $in = implode(',', array_fill(0, count($ints), '?'));
    $stmt = $pdo->prepare("DELETE FROM messages WHERE user_email = ? AND folder = ? AND uid IN ($in)");
    $stmt->execute(array_merge([$email, $folder], $ints));
    return $stmt->rowCount();
  } catch (Throwable $e) {
    return 0;
  }
}

function db_set_message_flags(string $email, string $folder, int $uid, array $flags): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  $allowed = ['unread', 'answered', 'flagged', 'deleted'];
  $set = [];
  $args = [];
  foreach ($allowed as $f) {
    if (array_key_exists($f, $flags)) {
      $set[] = "$f = ?";
      $args[] = $flags[$f] ? 1 : 0;
    }
  }
  if (!$set) return false;
  try {
    $args[] = $email;
    $args[] = $folder;
    $args[] = $uid;
    $stmt = $pdo->prepare('UPDATE messages SET ' . implode(', ', $set)
      . ' WHERE user_email = ? AND folder = ? AND uid = ?');
    $stmt->execute($args);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function db_set_message_snippet(string $email, string $folder, int $uid, string $snippet): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  try {
    $stmt = $pdo->prepare('UPDATE messages SET snippet = ? WHERE user_email = ? AND folder = ? AND uid = ?');
    $stmt->execute([$snippet, $email, $folder, $uid]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// UIDs whose attachment flag has not been resolved yet. Newest first, because
// those are the rows the user is most likely to be looking at.
function db_pending_structure_uids(string $email, string $folder, int $limit): array
{
  $pdo = db_open();
  if (!$pdo || $limit < 1) return [];
  try {
    $stmt = $pdo->prepare('SELECT uid FROM messages
                             WHERE user_email = ? AND folder = ? AND structure_synced = 0
                             ORDER BY date_ts DESC, uid DESC
                             LIMIT ' . (int)$limit);
    $stmt->execute([$email, $folder]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
  } catch (Throwable $e) {
    return [];
  }
}

// $map is [uid => bool]. Marks structure_synced so the UID is not re-examined:
// attachment presence cannot change for a given UID.
function db_set_has_attachment(string $email, string $folder, array $map): int
{
  $pdo = db_open();
  if (!$pdo || !$map) return 0;
  try {
    $stmt = $pdo->prepare('UPDATE messages SET has_attachment = ?, structure_synced = 1
                             WHERE user_email = ? AND folder = ? AND uid = ?');
    $n = 0;
    $pdo->beginTransaction();
    foreach ($map as $uid => $has) {
      $stmt->execute([$has ? 1 : 0, $email, $folder, (int)$uid]);
      $n++;
    }
    $pdo->commit();
    return $n;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return 0;
  }
}

// [uid => flags] for every cached row in a folder. This is the reconcile input,
// so it must cover the whole cached window, not just one page.
function db_message_flags_map(string $email, string $folder): array
{
  $pdo = db_open();
  if (!$pdo) return [];
  try {
    $stmt = $pdo->prepare('SELECT uid, unread, answered, flagged, deleted
                             FROM messages WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
      $out[(int)$r['uid']] = [
        'uid'      => (int)$r['uid'],
        'unread'   => (bool)$r['unread'],
        'answered' => (bool)$r['answered'],
        'flagged'  => (bool)$r['flagged'],
        'deleted'  => (bool)$r['deleted'],
      ];
    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

// Force a folder to resync on the next opportunity, without discarding what we
// know about it. uidvalidity is deliberately preserved: clearing it would make
// the next sync_decide() see a changed generation and purge the whole folder.
function cache_invalidate_folder(string $email, string $folder): void
{
  $pdo = db_open();
  if (!$pdo) return;
  try {
    // Only touch a folder we have actually synced; do not invent a state row.
    $stmt = $pdo->prepare('UPDATE folder_state SET last_sync_ts = 0, last_full_ts = 0
                             WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
  } catch (Throwable $e) {
    // A cache that cannot be invalidated is corrected by the next full sync.
  }
}

// A message left $from: dropped, moved to trash, or restored. Remove the cached
// row and make the destination resync so it appears there straight away.
function cache_apply_move(string $email, string $from, int $uid, ?string $to = null): void
{
  db_delete_messages($email, $from, [$uid]);
  cache_invalidate_folder($email, $from);
  if ($to !== null && $to !== '') {
    cache_invalidate_folder($email, $to);
  }
}

// Escape LIKE wildcards so a query containing % or _ matches literally.
function _like_escape(string $s): string
{
  return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
}

// Local full-ish search over cached headers plus any snippet captured when a
// message was opened. Replaces the old search_cache path, and unlike it this
// returns real flags and supports real pagination.
function db_search_messages(string $email, string $folder, string $query, int $page = 1, int $perPage = 20): array
{
  $pdo = db_open();
  if (!$pdo || trim($query) === '') return [];
  $perPage = max(1, $perPage);
  $offset = max(0, ($page - 1) * $perPage);
  $q = '%' . _like_escape($query) . '%';
  try {
    $stmt = $pdo->prepare("SELECT * FROM messages
                             WHERE user_email = ? AND folder = ?
                               AND (subject LIKE ? ESCAPE '\\'
                                 OR from_display LIKE ? ESCAPE '\\'
                                 OR from_address LIKE ? ESCAPE '\\'
                                 OR to_display LIKE ? ESCAPE '\\'
                                 OR to_address LIKE ? ESCAPE '\\'
                                 OR snippet LIKE ? ESCAPE '\\')
                             ORDER BY date_ts DESC, uid DESC
                             LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset);
    $stmt->execute([$email, $folder, $q, $q, $q, $q, $q, $q]);
    return array_map('_cache_hydrate', $stmt->fetchAll());
  } catch (Throwable $e) {
    return [];
  }
}

function db_count_search_messages(string $email, string $folder, string $query): int
{
  $pdo = db_open();
  if (!$pdo || trim($query) === '') return 0;
  $q = '%' . _like_escape($query) . '%';
  try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages
                             WHERE user_email = ? AND folder = ?
                               AND (subject LIKE ? ESCAPE '\\'
                                 OR from_display LIKE ? ESCAPE '\\'
                                 OR from_address LIKE ? ESCAPE '\\'
                                 OR to_display LIKE ? ESCAPE '\\'
                                 OR to_address LIKE ? ESCAPE '\\'
                                 OR snippet LIKE ? ESCAPE '\\')");
    $stmt->execute([$email, $folder, $q, $q, $q, $q, $q, $q]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// How many message headers we are holding for a user, across all folders.
function db_count_cached_messages(string $email): int
{
  $pdo = db_open();
  if (!$pdo) return 0;
  try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE user_email = ?');
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn();
  } catch (Throwable $e) {
    return 0;
  }
}

// Drop everything cached for a user. Reconstructible from the server, so the
// only cost is one cold sync per folder the user opens next.
function db_clear_cache_for_user(string $email): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  try {
    $pdo->beginTransaction();
    foreach (['messages', 'folder_state', 'folder_list_cache', 'folder_counts'] as $table) {
      $stmt = $pdo->prepare("DELETE FROM $table WHERE user_email = ?");
      $stmt->execute([$email]);
    }
    $pdo->commit();
    return true;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    return false;
  }
}

// --- Folder sync state ---

function db_get_folder_state(string $email, string $folder): ?array
{
  $pdo = db_open();
  if (!$pdo) return null;
  try {
    $stmt = $pdo->prepare('SELECT * FROM folder_state WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    $row = $stmt->fetch();
    if (!$row) return null;
    // Keep the unknown/known distinction: NULL must not collapse to 0.
    foreach (['uidvalidity', 'uidnext', 'total', 'unseen'] as $k) {
      $row[$k] = $row[$k] === null ? null : (int)$row[$k];
    }
    foreach (['cached_rows', 'last_sync_ts', 'last_full_ts'] as $k) {
      $row[$k] = (int)$row[$k];
    }
    return $row;
  } catch (Throwable $e) {
    return null;
  }
}

function db_set_folder_state(string $email, string $folder, array $state): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  $nullable = function ($v) {
    return $v === null ? null : (int)$v;
  };
  try {
    $stmt = $pdo->prepare('INSERT INTO folder_state
        (user_email, folder, uidvalidity, uidnext, total, unseen,
         cached_rows, last_sync_ts, last_full_ts)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON CONFLICT(user_email, folder) DO UPDATE SET
          uidvalidity  = excluded.uidvalidity,
          uidnext      = excluded.uidnext,
          total        = excluded.total,
          unseen       = excluded.unseen,
          cached_rows  = excluded.cached_rows,
          last_sync_ts = excluded.last_sync_ts,
          last_full_ts = excluded.last_full_ts');
    $stmt->execute([
      $email,
      $folder,
      $nullable($state['uidvalidity'] ?? null),
      $nullable($state['uidnext'] ?? null),
      $nullable($state['total'] ?? null),
      $nullable($state['unseen'] ?? null),
      (int)($state['cached_rows'] ?? 0),
      (int)($state['last_sync_ts'] ?? 0),
      (int)($state['last_full_ts'] ?? 0),
    ]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// Drop the sync state for a folder that no longer exists on the server. Unlike
// cache_invalidate_folder() this removes the row outright: there is nothing left
// to resync, and a surviving row would keep the mailbox alive in the cache.
// Its sidebar counters go with it.
function db_delete_folder_state(string $email, string $folder): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  try {
    $stmt = $pdo->prepare('DELETE FROM folder_state WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    $stmt = $pdo->prepare('DELETE FROM folder_counts WHERE user_email = ? AND folder = ?');
    $stmt->execute([$email, $folder]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// --- Sidebar folder counters ---
//
// Display only. Never read by the sync: see migration step 3 for why these do
// not live in folder_state.

function db_set_folder_counts(string $email, string $folder, ?int $unseen, ?int $total, int $now): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  try {
    $stmt = $pdo->prepare('INSERT INTO folder_counts (user_email, folder, unseen, total, checked_at)
        VALUES (?,?,?,?,?)
        ON CONFLICT(user_email, folder) DO UPDATE SET
          unseen = excluded.unseen, total = excluded.total, checked_at = excluded.checked_at');
    $stmt->execute([$email, $folder, $unseen, $total, $now]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// [folder => ['unseen'=>?int, 'total'=>?int, 'checked_at'=>int]]
function db_get_folder_counts(string $email): array
{
  $pdo = db_open();
  if (!$pdo) return [];
  try {
    $stmt = $pdo->prepare('SELECT folder, unseen, total, checked_at FROM folder_counts WHERE user_email = ?');
    $stmt->execute([$email]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
      $out[$r['folder']] = [
        'unseen'     => $r['unseen'] === null ? null : (int)$r['unseen'],
        'total'      => $r['total'] === null ? null : (int)$r['total'],
        'checked_at' => (int)$r['checked_at'],
      ];
    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

// Optimistic +/-1 after a local flag change. The next STATUS overwrites it, so
// drift is bounded by one sync or sweep. Clamped at 0; unknown stays unknown.
function db_bump_folder_unseen(string $email, string $folder, int $delta): void
{
  $pdo = db_open();
  if (!$pdo) return;
  try {
    $stmt = $pdo->prepare('UPDATE folder_counts SET unseen = MAX(0, unseen + ?)
                             WHERE user_email = ? AND folder = ? AND unseen IS NOT NULL');
    $stmt->execute([$delta, $email, $folder]);
  } catch (Throwable $e) {
    // A missed bump is corrected by the next STATUS.
  }
}

// Parse POST UIDs (single 'uid' or comma string 'uids'), returning deduped positive ints in order.
function post_uids(): array
{
  $raw = null;
  if (isset($_POST['uid']) && !isset($_POST['uids'])) {
    $raw = $_POST['uid'];
  } elseif (isset($_POST['uids'])) {
    $raw = $_POST['uids'];
  }
  if ($raw === null || $raw === '') {
    return [];
  }
  if (is_array($raw)) {
    $list = $raw;
  } else {
    $s = (string)$raw;
    if (strlen($s) > 12 * 200) {
      $s = substr($s, 0, 12 * 200);
    }
    $list = explode(',', $s);
  }
  $seen = [];
  $out = [];
  foreach ($list as $v) {
    if (is_array($v)) {
      continue;
    }
    $t = trim((string)$v);
    if ($t === '' || !ctype_digit($t)) {
      continue;
    }
    $n = intval($t);
    if ($n <= 0) {
      continue;
    }
    $k = (string)$n;
    if (isset($seen[$k])) {
      continue;
    }
    $seen[$k] = true;
    $out[] = $n;
    if (count($out) >= 200) {
      break;
    }
  }
  return $out;
}

// Record a read/unread change in the cache, and move the sidebar counter only
// when the cached flag actually flips: opening a message that is already read
// must not decrement anything. An uncached message leaves the counter alone.
function cache_mark_read(string $email, string $folder, int $uid, bool $read): void
{
  $pdo = db_open();
  if (!$pdo) return;
  try {
    // Conditional on the old value, so rowCount() says whether it flipped.
    $stmt = $pdo->prepare('UPDATE messages SET unread = ?
                             WHERE user_email = ? AND folder = ? AND uid = ? AND unread = ?');
    $stmt->execute([$read ? 0 : 1, $email, $folder, $uid, $read ? 1 : 0]);
    if ($stmt->rowCount() === 1) {
      db_bump_folder_unseen($email, $folder, $read ? -1 : 1);
    }
  } catch (Throwable $e) {
    // The next sync reconciles both the flag and the counter.
  }
}

// --- Folder list cache (avoids a LIST on every render) ---

function db_get_folder_list(string $email, int $ttlSeconds): ?array
{
  $pdo = db_open();
  if (!$pdo) return null;
  try {
    $stmt = $pdo->prepare('SELECT payload, cached_at FROM folder_list_cache WHERE user_email = ?');
    $stmt->execute([$email]);
    $row = $stmt->fetch();
    if (!$row) return null;
    if (time() - (int)$row['cached_at'] > $ttlSeconds) return null;
    $decoded = json_decode($row['payload'], true);
    return is_array($decoded) ? $decoded : null;
  } catch (Throwable $e) {
    return null;
  }
}

function db_set_folder_list(string $email, array $folders): bool
{
  $pdo = db_open();
  if (!$pdo) return false;
  // Encode before binding. A folder name can carry bytes json_encode() refuses --
  // the server sent them -- and false would be stored as '', which decodes back to
  // null. That is a silent miss no refresh can clear: every listing page re-LISTs
  // and every chrome page stays empty for ever. Keep the last good list instead.
  // JSON_INVALID_UTF8_SUBSTITUTE is not the fix: a substituted name is a name that
  // cannot be SELECTed, so it trades a loud miss for a quiet wrong folder.
  $payload = json_encode($folders);
  if ($payload === false) return false;
  try {
    $stmt = $pdo->prepare('INSERT INTO folder_list_cache (user_email, payload, cached_at)
                             VALUES (?, ?, ?)
                             ON CONFLICT(user_email) DO UPDATE SET
                               payload = excluded.payload,
                               cached_at = excluded.cached_at');
    $stmt->execute([$email, $payload, time()]);
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

// --- Login attempts (brute-force protection) ---
function db_record_login_attempt($ip, $success)
{
  $pdo = db_open();
  if (!$pdo) return;
  $stmt = $pdo->prepare('INSERT INTO login_attempts (ip, attempted_at, success) VALUES (?, ?, ?)');
  $stmt->execute([$ip, time(), $success ? 1 : 0]);
}

function db_count_recent_failures($ip, $windowSeconds = LOGIN_WINDOW_SECONDS)
{
  $pdo = db_open();
  if (!$pdo) return 0;
  $cutoff = time() - $windowSeconds;
  $stmt = $pdo->prepare('SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?');
  $stmt->execute([$ip, $cutoff]);
  return (int)$stmt->fetch()['c'];
}

function db_cleanup_login_attempts($olderThanSeconds = 86400)
{
  $pdo = db_open();
  if (!$pdo) return;
  $cutoff = time() - $olderThanSeconds;
  $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < ?');
  $stmt->execute([$cutoff]);
}

// Map cached message rows onto the shape the list view expects. Pure.
//
// The cache stores a decoded display name and an address separately; the view
// renders a single 'from'/'to' string, so fall back to the address when there is
// no display form.
function cache_rows_to_view(array $rows): array
{
  $out = [];
  foreach ($rows as $r) {
    $out[] = [
      'uid'            => (int)$r['uid'],
      'subject'        => ($r['subject'] ?? '') !== '' ? $r['subject'] : '(No subject)',
      'from'           => ($r['from_display'] ?? '') !== '' ? $r['from_display'] : ($r['from_address'] ?? ''),
      'from_address'   => $r['from_address'] ?? '',
      'to'             => ($r['to_display'] ?? '') !== '' ? $r['to_display'] : ($r['to_address'] ?? ''),
      'to_address'     => $r['to_address'] ?? '',
      'timestamp'      => (int)($r['date_ts'] ?? 0),
      'date'           => (int)($r['date_ts'] ?? 0) ? date('r', (int)$r['date_ts']) : '',
      'size'           => (int)($r['size'] ?? 0),
      'unread'         => !empty($r['unread']),
      'answered'       => !empty($r['answered']),
      'flagged'        => !empty($r['flagged']),
      'has_attachment' => !empty($r['has_attachment']),
    ];
  }
  return $out;
}

// Assemble everything a folder listing needs, preferring the local cache.
//
// $imapFactory is a callable returning a MailImap. It is called lazily and only
// when the cache cannot answer, so a warm render costs zero IMAP round trips.
//
// Returns the render payload plus 'source' (cache|live) and 'error'.
function folder_view_data(callable $imapFactory, string $email, string $folder, int $page, int $perPage, array $opts = []): array
{
  $error = null;

  $imap = null;
  $getImap = function () use ($imapFactory, &$imap) {
    if ($imap === null) {
      $imap = $imapFactory();
    }
    return $imap;
  };

  $state  = db_get_folder_state($email, $folder);
  $cached = db_count_messages($email, $folder);

  // "No cached rows" is not the same as "nothing cached": a folder the server
  // says is empty is fully cached with zero rows. Drafts is usually empty, so
  // conflating the two made every visit to it pay a connect and a sync while the
  // inbox rendered from cache. Only folder_state can tell them apart — an absent
  // row is the real cold cache.
  //
  // Cold cache: sync now, or the user's first visit to a folder is blank. A
  // cache with unthreaded rows is also synced, so the conversation rebuild
  // happens on this render instead of waiting for the background poll.
  if (
    $state === null || ($cached === 0 && (int)($state['total'] ?? 0) > 0)
    || db_count_unthreaded($email, $folder) > 0
  ) {
    $conn = $getImap();
    $result = $conn ? sync_folder($conn, $email, $folder, $opts) : ['ok' => false, 'reason' => 'no-connection'];
    if (!$result['ok']) {
      $error = 'Could not sync this folder (' . $result['reason'] . ').';
    }
    $state  = db_get_folder_state($email, $folder);
    $cached = db_count_messages($email, $folder);
  }

  $folders = folder_list_for($getImap, $email);

  // The server total, when known, is authoritative for pagination: the cache is
  // only a newest-N window and would otherwise silently truncate the folder.
  $total = $state['total'] ?? null;
  if ($total === null) {
    $total = $cached;
  }

  $offset = max(0, ($page - 1) * $perPage);
  // Anything reaching past the cached window has to be fetched live.
  $beyondWindow = ($offset + $perPage) > $cached && $total > $cached;

  // Still no rows after the sync attempt: serve live, unless the folder is known
  // to be empty. A missing state row means the sync failed and the total is
  // unknown, which must fall back to live rather than show an empty mailbox.
  $knownEmpty = $state !== null && (int)($state['total'] ?? 0) === 0;

  if (($cached === 0 && !$knownEmpty) || $beyondWindow) {
    $conn = $getImap();
    if ($conn) {
      $live = $conn->getEmails($folder, $page, $perPage);
      return [
        'emails'         => $live['emails'],
        'total'          => $live['total'],
        'pages'          => $live['pages'],
        'current_page'   => $page,
        'folders'        => $folders,
        'unread'         => $state['unseen'] ?? null,
        'last_sync_ts'   => (int)($state['last_sync_ts'] ?? 0),
        'source'         => 'live',
        'threads'        => false,
        'error'          => $error,
      ];
    }
    $error = $error ?? 'Mail server unavailable and nothing is cached for this folder.';
  }

  // Thread only a folder whose whole message set is cached. A partial window
  // would let a conversation straddle the page boundary, and paginating
  // conversations over it would double up against the flat live tail. Drafts is
  // unfinished work, never a conversation.
  $threaded = !MailImap::isDraftsFolder($folder) && (int)$total <= $cached;
  if ($threaded) {
    $threadCount = db_count_threads($email, $folder);
    return [
      'emails'       => db_list_threads($email, $folder, $page, $perPage),
      'total'        => $threadCount,
      'pages'        => (int)ceil($threadCount / max(1, $perPage)),
      'current_page' => $page,
      'folders'      => $folders,
      'unread'       => $state['unseen'] ?? null,
      'last_sync_ts' => (int)($state['last_sync_ts'] ?? 0),
      'source'       => 'cache',
      'threads'      => true,
      'error'        => $error,
    ];
  }

  return [
    'emails'       => cache_rows_to_view(db_list_messages($email, $folder, $page, $perPage)),
    'total'        => $total,
    'pages'        => (int)ceil($total / max(1, $perPage)),
    'current_page' => $page,
    'folders'      => $folders,
    'unread'       => $state['unseen'] ?? null,
    'last_sync_ts' => (int)($state['last_sync_ts'] ?? 0),
    'source'       => 'cache',
    'threads'      => false,
    'error'        => $error,
  ];
}

// Folder list, from the local cache when it is fresh. view_inbox needs the
// 'group' key from orderFolders(), so this cannot be skipped entirely -- only
// served without a LIST.
function folder_list_for(callable $getImap, string $email): array
{
  $folders = db_get_folder_list($email, FOLDER_LIST_TTL_SECONDS);
  if ($folders === null) {
    $conn = $getImap();
    if (!$conn) {
      return [];
    }
    $folders = $conn->getFolders();
    if ($folders) {
      db_set_folder_list($email, $folders);
    }
  }
  // A \Noselect mailbox is a node in the hierarchy, not a mailbox: STATUS and
  // SELECT both fail on it, so linking one in the sidebar can only ever land the
  // user on "Could not sync this folder (status-failed)". Filtered here, on the
  // read path, so an already-cached list is cleaned up without waiting for TTL.
  return array_values(array_filter($folders, function (array $f) {
    return !((int)($f['attributes'] ?? 0) & LATT_NOSELECT);
  }));
}

// Folders for the shared sidebar. Unlike folder_list_for() this never opens a
// connection: the sidebar is chrome, and requiring IMAP just to render a
// compose or settings page would add a login handshake to every secondary page.
//
// The TTL is deliberately not applied. It answers "may the folder list have
// changed?", and only the listing pages can act on that -- they already hold a
// connection and re-LIST through folder_list_for(). Here a miss has no second
// source, so it rendered as a bare heading over an empty list and dropped
// data-kb-map, which is what made `g i` fail on a page that was merely old.
// Stale beats empty: a folder created elsewhere shows up on the next inbox visit.
function cached_folder_list(string $email): array
{
  $folders = db_get_folder_list($email, PHP_INT_MAX);
  return array_values(array_filter($folders ?: [], function (array $f) {
    return !((int)($f['attributes'] ?? 0) & LATT_NOSELECT);
  }));
}

// Short human-readable age, for the sync indicator. Pure.
function relative_time(int $ts, int $now): string
{
  if ($ts <= 0) {
    return 'never';
  }
  // Clamp a future stamp: clock skew between the server and a stored value
  // should not render as "in -3 minutes".
  $diff = max(0, $now - $ts);
  if ($diff < 45)    return 'Just now';
  if ($diff < 90)    return '1 min ago';
  if ($diff < 3600)  return (int)round($diff / 60) . ' min ago';
  if ($diff < 7200)  return '1 hour ago';
  if ($diff < 86400) return (int)floor($diff / 3600) . ' hours ago';
  if ($diff < 172800) return 'yesterday';
  return (int)floor($diff / 86400) . ' days ago';
}

// Whether a sync is worth spending a connection on. Pure.
function sync_should_run(?array $state, int $now, int $minInterval, bool $force): bool
{
  if ($force) {
    return true;
  }
  if ($state === null) {
    return true;
  }
  $last = (int)($state['last_sync_ts'] ?? 0);
  // A future stamp means a clock moved; treat it as recent rather than syncing
  // on every request until the clock catches up.
  if ($last > $now) {
    return false;
  }
  return ($now - $last) >= $minInterval;
}

// Whether a listing's cached headers are old enough that opening the page
// should sync at once, not after a whole poll period. A zero stamp (never
// synced, or invalidated) counts as stale. Pure.
function sync_is_stale(int $lastSyncTs, int $now, int $period): bool
{
  if ($lastSyncTs <= 0) {
    return true;
  }
  if ($lastSyncTs > $now) {
    return false;
  }
  return ($now - $lastSyncTs) >= $period;
}

// A folder name arriving from a request is concatenated into the c-client
// mailbox spec "{host:port/imap/ssl}Folder". Braces would let a caller redirect
// the connection, and CR/LF would let them inject a second IMAP command. Pure.
function imap_folder_name_is_safe(string $folder): bool
{
  if ($folder === '' || strlen($folder) > 255) {
    return false;
  }
  if (strpbrk($folder, "{}\r\n") !== false) {
    return false;
  }
  // Reject any other control character, including NUL.
  return !preg_match('/[\x00-\x1F\x7F]/', $folder);
}

// Drive one sync for the sync endpoint. Separate from handle_sync() so it can be
// tested without a session or HTTP.
//
// $imapFactory is called lazily and ONLY when a sync is actually due, so a
// throttled request costs no TCP, no TLS and no LOGIN.
function sync_request(callable $imapFactory, string $email, string $folder, array $opts = []): array
{
  $now         = (int)($opts['now'] ?? time());
  $minInterval = (int)($opts['min_interval'] ?? SYNC_MIN_INTERVAL_SECONDS);
  $force       = !empty($opts['force']);

  $out = [
    'ok' => false,
    'skipped' => false,
    'changed' => 0,
    'arrivals' => 0,
    'unread' => null,
    'total' => null,
    'last_sync_ts' => 0,
    'reason' => '',
    'error' => null,
  ];

  if (!imap_folder_name_is_safe($folder)) {
    $out['reason'] = 'bad-folder';
    $out['error']  = 'Invalid folder name.';
    return $out;
  }

  $state = db_get_folder_state($email, $folder);
  $out['last_sync_ts'] = (int)($state['last_sync_ts'] ?? 0);
  $out['unread'] = $state['unseen'] ?? null;
  $out['total']  = $state['total'] ?? null;

  if (!sync_should_run($state, $now, $minInterval, $force)) {
    $out['ok'] = true;
    $out['skipped'] = true;
    $out['reason'] = 'throttled';
    return $out;
  }

  $conn = $imapFactory();
  if (!$conn) {
    $out['reason'] = 'no-connection';
    $out['error']  = 'Not connected.';
    return $out;
  }

  $result = sync_folder($conn, $email, $folder, $opts + ['now' => $now]);
  $out['ok']      = $result['ok'];
  $out['reason']  = $result['reason'];
  $out['changed'] = $result['changed'];
  $out['arrivals'] = $result['arrivals'];
  $out['unread']  = $result['unread'];
  $out['total']   = $result['total'];
  $out['last_sync_ts'] = $result['ok'] ? $now : $out['last_sync_ts'];
  if (!$result['ok']) {
    $out['error'] = 'Sync failed (' . $result['reason'] . ').';
  } elseif (!empty($result['refused'])) {
    // The reconcile declined to apply something suspicious. Surface it rather
    // than silently drifting.
    $out['error'] = 'Partial sync (' . $result['refused'] . ').';
  }
  return $out;
}

// The sidebar badge for one folder, or null for none. Pure.
//
// Sent, Trash and Archive get none: an unread count there is noise. Drafts
// shows its total, because drafts are stored \Seen and an unseen count would
// always read 0. Like folder_is_protected(), a custom map replaces detection.
function folder_badge(string $name, ?array $row, ?array $folderMap = null): ?array
{
  $is = function (string $role) use ($name, $folderMap) {
    if ($folderMap !== null) {
      return isset($folderMap[$role]) && strcasecmp((string)$folderMap[$role], $name) === 0;
    }
    switch ($role) {
      case 'sent':
        return MailImap::isSentFolder($name);
      case 'trash':
        return MailImap::isTrashFolder($name);
      case 'archive':
        return MailImap::isArchiveFolder($name);
      default:
        return MailImap::isDraftsFolder($name);
    }
  };
  if ($row === null || $is('sent') || $is('trash') || $is('archive')) {
    return null;
  }
  $kind = $is('drafts') ? 'total' : 'unseen';
  return $row[$kind] === null ? null : ['n' => (int)$row[$kind], 'kind' => $kind];
}

// Refresh the counters of the folders that are not open: one STATUS each, and
// only for a folder not checked in the last $interval seconds. $getImap is
// called at most once, and only when some folder is due. The open folder is
// skipped because its own sync already wrote its counts. Returns how many
// folders were swept.
function counts_sweep(callable $getImap, string $email, array $folders, ?array $folderMap, string $open, int $now, int $interval): int
{
  $known = db_get_folder_counts($email);
  $conn = null;
  $swept = 0;
  foreach ($folders as $f) {
    $name = (string)($f['name'] ?? '');
    if ($name === '' || $name === $open) {
      continue;
    }
    // A dummy row, so folder_badge() answers only the "which role" question.
    if (folder_badge($name, ['unseen' => 0, 'total' => 0], $folderMap) === null) {
      continue;
    }
    if (isset($known[$name]) && $now - $known[$name]['checked_at'] < $interval) {
      continue;
    }
    if ($conn === null) {
      $conn = $getImap() ?: false;
    }
    if (!$conn) {
      break;
    }
    $status = $conn->statusFor($name);
    if ($status === null) {
      continue; // keep the last known counts rather than blanking them
    }
    db_set_folder_counts($email, $name, $status['unseen'], $status['messages'], $now);
    $swept++;
  }
  return $swept;
}

// [4b] Sync planning — pure functions ----------------------------------------
//
// These decide what the sync must do. They touch neither IMAP nor SQLite, so
// every rule below is unit-testable without a network or a test double. The
// driver feeds them STATUS values and UID sets and applies the result.

// Decide how much work a folder needs, from STATUS alone.
//
// $status fields are tri-state: an int when the server told us, null when it
// did not. c-client silently degrades to EXAMINE on pre-IMAP4rev1 servers and
// strips uidnext/uidvalidity when it does, so "unknown" is a normal case and
// must never be read as 0 -- two zeroes compare equal and would freeze the
// cache permanently.
function sync_decide(?array $state, array $status, int $now, int $fullInterval, int $unthreaded = 0): array
{
  $plan = ['purge_all' => false, 'scan' => true, 'full' => true, 'reason' => 'cold'];

  // A cache whose rows carry no conversation key predates the conversation view
  // (or was written by a path that skipped it). Grouping them by '' collapses the
  // whole folder into one thread, and a normal sync never re-fetches existing
  // UIDs, so rebuild this folder from the server once. Steady state is 0.
  if ($unthreaded > 0) {
    return ['purge_all' => true, 'scan' => true, 'full' => true, 'reason' => 'unthreaded'];
  }

  if ($state === null) {
    return $plan; // nothing cached yet
  }

  $sv = $status['uidvalidity'] ?? null;
  $cv = $state['uidvalidity'] ?? null;

  // A changed UIDVALIDITY invalidates every UID we hold. Only act on it when
  // both sides are actually known -- never purge on missing information.
  if ($sv !== null && $cv !== null && $sv !== $cv) {
    return ['purge_all' => true, 'scan' => true, 'full' => true, 'reason' => 'uidvalidity'];
  }

  foreach (['uidvalidity', 'uidnext', 'messages', 'unseen'] as $k) {
    if (($status[$k] ?? null) === null) {
      return ['purge_all' => false, 'scan' => true, 'full' => true, 'reason' => 'status-incomplete'];
    }
  }

  // RFC 3501 2.3.1.1: UIDNEXT must advance on any arrival, even one that is
  // later expunged. So unchanged UIDNEXT proves no additions, and given no
  // additions, an unchanged MESSAGES proves no expunges either.
  $same = $sv === $cv
    && $status['uidnext']  === ($state['uidnext'] ?? null)
    && $status['messages'] === ($state['total'] ?? null)
    && $status['unseen']   === ($state['unseen'] ?? null);

  if ($same) {
    // Flags move neither counter, so a reconcile is still due on a timer.
    $due = ($now - (int)($state['last_full_ts'] ?? 0)) >= $fullInterval;
    return [
      'purge_all' => false,
      'scan'      => false,
      'full'      => $due,
      'reason'    => $due ? 'unchanged-reconcile-due' : 'unchanged',
    ];
  }

  return ['purge_all' => false, 'scan' => true, 'full' => true, 'reason' => 'changed'];
}

// How many rows this sync brought in that the user has not seen: the number
// behind a browser notification. Pure, so every exclusion is testable without a
// server.
//
// $floor    the uidnext from BEFORE this sync, or null when there is no
//           previous state to compare against. A UID at or above it is one the
//           server allocated after our last look, so it is genuinely new.
// $purged   the sync discarded the cache first (UIDVALIDITY changed), so every
//           row is a new uid while none of them is a new message.
//
// `changed` cannot serve here: it also counts flag flips and expunges, which
// are not arrivals, and a notification for those would be noise.
function sync_count_arrivals(array $upsert, ?int $floor, bool $purged): int
{
  if ($floor === null || $purged) {
    return 0;
  }
  $n = 0;
  foreach ($upsert as $row) {
    if (empty($row['unread'])) {
      continue;
    }
    if ((int)($row['uid'] ?? 0) >= $floor) {
      $n++;
    }
  }
  return $n;
}

// Diff the cache against the server view and return the writes to apply.
//
// $cached  [uid => ['unread','answered','flagged','deleted']]
// $server  ['new'=>rows, 'undeleted'=>?int[], 'deleted'=>?int[],
//           'unseen'=>?int[], 'flagged'=>?int[], 'answered'=>?int[],
//           'messages'=>?int]
// $opts    ['min_new_uid'=>int, 'max_delete'=>?int]
//
// Returns ['upsert'=>rows, 'flag_updates'=>[uid=>flags], 'delete_uids'=>int[],
//          'refused'=>?string].
function sync_reconcile(array $cached, array $server, array $opts = []): array
{
  $minNewUid = (int)($opts['min_new_uid'] ?? 1);
  $out = ['upsert' => [], 'flag_updates' => [], 'delete_uids' => [], 'refused' => null];

  // --- new messages ---------------------------------------------------------
  // A UID range always includes the last message in the mailbox (RFC 3501
  // 6.4.8), so "<max+1>:*" returns one stray newest row when there is nothing
  // new. Dropping it here keeps the plan empty, which keeps the client from
  // reloading on every sync.
  foreach (($server['new'] ?? []) as $row) {
    if ((int)($row['uid'] ?? 0) >= $minNewUid) {
      $out['upsert'][] = $row;
    }
  }

  $undeleted = $server['undeleted'] ?? null;
  $deleted   = $server['deleted'] ?? null;
  $messages  = $server['messages'] ?? null;

  // --- deletions ------------------------------------------------------------
  // imap_search() returns false for "no matches", which is indistinguishable
  // from a failed search, a failed SELECT or a dropped connection. Treating
  // that as an empty result would delete the entire folder cache, so an
  // unknown set means "delete nothing".
  if ($undeleted === null || $deleted === null) {
    $out['refused'] = 'unknown-live-set';
  } else {
    $exists = array_flip(array_map('intval', array_merge($undeleted, $deleted)));

    // MESSAGES counts \Deleted-but-unexpunged messages, so the live set alone
    // will not match it. The sum must.
    if ($messages !== null && count($exists) !== (int)$messages) {
      $out['refused'] = 'count-mismatch';
    } else {
      $candidates = [];
      foreach (array_keys($cached) as $uid) {
        if (!isset($exists[(int)$uid])) {
          $candidates[] = (int)$uid;
        }
      }
      // Even with the counts agreeing, refuse an implausible mass deletion and
      // let a cold resync sort it out. Silent bulk loss must be impossible.
      $cap = $opts['max_delete'] ?? max(50, (int)floor(count($cached) * 0.2));
      if (count($candidates) > $cap) {
        $out['refused'] = 'blast-radius';
      } else {
        $out['delete_uids'] = $candidates;
      }
    }
  }

  // --- flags ----------------------------------------------------------------
  // A null set means "not fetched this round": leave the cached value alone.
  $sets = [
    'unread'   => $server['unseen'] ?? null,
    'flagged'  => $server['flagged'] ?? null,
    'answered' => $server['answered'] ?? null,
    'deleted'  => $deleted,
  ];
  $lookup = [];
  foreach ($sets as $flag => $uids) {
    $lookup[$flag] = $uids === null ? null : array_flip(array_map('intval', $uids));
  }

  $doomed = array_flip($out['delete_uids']);
  foreach ($cached as $uid => $row) {
    $uid = (int)$uid;
    if (isset($doomed[$uid])) {
      continue; // about to be removed; no point updating it
    }
    $changes = [];
    foreach ($lookup as $flag => $set) {
      if ($set === null) {
        continue;
      }
      $want = isset($set[$uid]);
      if ($want !== (bool)($row[$flag] ?? false)) {
        $changes[$flag] = $want;
      }
    }
    // Only emit a write when something really differs, so an already-correct
    // cache yields an empty plan and the sync reports "no changes".
    if ($changes) {
      $out['flag_updates'][$uid] = $changes;
    }
  }

  return $out;
}

// Sync one folder: read STATUS, decide what is needed, fetch the minimum, and
// apply the reconciliation plan. Returns a summary for the sync endpoint.
//
// Cost: one STATUS when nothing changed. About 7 commands when something did,
// plus one per attachment backfilled. Never fetches the whole folder.
function sync_folder(MailImap $imap, string $email, string $folder, array $opts = []): array
{
  $now          = (int)($opts['now'] ?? time());
  $window       = (int)($opts['window'] ?? 500);
  $fullInterval = (int)($opts['full_interval'] ?? 60);
  $backfill     = (int)($opts['backfill'] ?? 20);

  $summary = [
    'ok' => false,
    'reason' => '',
    'changed' => 0,
    'arrivals' => 0,
    'unread' => null,
    'total' => null,
    'refused' => null,
    'last_sync_ts' => $now,
  ];

  $status = $imap->statusFor($folder);
  if ($status === null) {
    // Leave the cache exactly as it is; a transient STATUS failure must not
    // look like an empty folder.
    $summary['reason'] = 'status-failed';
    return $summary;
  }
  $summary['unread'] = $status['unseen'];
  $summary['total']  = $status['messages'];
  // The open folder's sidebar counter rides on this STATUS for free.
  db_set_folder_counts($email, $folder, $status['unseen'], $status['messages'], $now);

  $state = db_get_folder_state($email, $folder);
  $plan  = sync_decide($state, $status, $now, $fullInterval, db_count_unthreaded($email, $folder));
  $summary['reason'] = $plan['reason'];
  // The uidnext from BEFORE this sync, for counting arrivals. Kept here because
  // the state row is overwritten at the bottom with the opening status.
  $prevUidnext = $state === null ? null : ($state['uidnext'] ?? null);

  // Nothing to do: one command spent, and no SELECT. This is the hot path.
  if (!$plan['purge_all'] && !$plan['scan'] && !$plan['full']) {
    db_set_folder_state($email, $folder, [
      'uidvalidity' => $status['uidvalidity'],
      'uidnext'     => $status['uidnext'],
      'total'       => $status['messages'],
      'unseen'      => $status['unseen'],
      'cached_rows' => db_count_messages($email, $folder),
      'last_sync_ts' => $now,
      'last_full_ts' => (int)($state['last_full_ts'] ?? 0),
    ]);
    $summary['ok'] = true;
    return $summary;
  }

  // Everything below needs the mailbox selected. Never continue without it:
  // the searches would run against whatever mailbox was selected before, and
  // the reconcile would then compare one folder's UIDs to another's cache.
  //
  // Forced, so a reused connection refreshes its EXISTS / last-UID view. Free
  // in the normal case, where each request builds a fresh connection anyway.
  if (!$imap->selectFolder($folder, true)) {
    $summary['reason'] = 'select-failed';
    return $summary;
  }

  if ($plan['purge_all']) {
    db_delete_messages($email, $folder);
  }

  $cachedCount = db_count_messages($email, $folder);
  $new = [];
  $minNewUid = 1;

  if ($cachedCount === 0) {
    // Cold start. Take the newest window by message-number tail, not by UID
    // arithmetic: UIDs are sparse after expunges, so uidnext-N can return
    // almost nothing on a churned folder.
    $total = $status['messages'];
    if ($total === null) {
      $total = $imap->getEmailCount($folder); // costs one CHECK
    }
    if ($total > 0) {
      $lo = max(1, $total - ($window - 1));
      // Attachment flags ride along in the same batched FETCH.
      $new = $imap->overviewRange($lo . ':' . $total, false, true);
    }
    if ($plan['reason'] === 'cold' || $plan['purge_all']) {
      $summary['reason'] = $plan['purge_all'] ? $plan['reason'] : 'cold';
    }
  } elseif ($plan['scan']) {
    $maxUid = db_max_cached_uid($email, $folder);
    $minNewUid = $maxUid + 1;
    // Skip the fetch entirely when no UID can possibly be new. Otherwise the
    // "<max+1>:*" range returns the newest message again (RFC 3501 6.4.8) and
    // we would re-upsert it on every single sync.
    if ($status['uidnext'] === null || $minNewUid < $status['uidnext']) {
      $new = $imap->overviewRange($minNewUid . ':*', true);
      // A UID range cannot be walked ascending by message number without first
      // resolving UIDs, so new arrivals get their flags from the same bounded
      // backfill pass below. There are only ever a handful of them.
    }
  }

  // Flag and deletion reconciliation. UNDELETED and DELETED are both needed:
  // STATUS MESSAGES counts \Deleted-but-unexpunged messages, so the invariant
  // is count(UNDELETED) + count(DELETED) == MESSAGES.
  $server = [
    'new'       => $new,
    'undeleted' => null,
    'deleted'   => null,
    'unseen'    => null,
    'flagged'   => null,
    'answered'  => null,
    'messages'  => $status['messages'],
  ];
  if ($plan['full']) {
    $server['undeleted'] = $imap->uidSet('UNDELETED') ?? [];
    $server['deleted']   = $imap->uidSet('DELETED') ?? [];
    $server['unseen']    = $imap->uidSet('UNSEEN') ?? [];
    $server['flagged']   = $imap->uidSet('FLAGGED') ?? [];
    $server['answered']  = $imap->uidSet('ANSWERED') ?? [];
  }

  $cached = db_message_flags_map($email, $folder);
  $result = sync_reconcile($cached, $server, ['min_new_uid' => $minNewUid]);
  $summary['refused'] = $result['refused'];

  if ($result['upsert']) {
    // Compute the conversation key here, where the folder is known: a message
    // with no ids at all cannot share an empty key, so it gets a sentinel that
    // is unique to its (folder, uid) and therefore never groups.
    foreach ($result['upsert'] as &$uRow) {
      $root = thread_root(
        (string)($uRow['message_id'] ?? ''),
        (string)($uRow['in_reply_to'] ?? ''),
        (string)($uRow['references'] ?? '')
      );
      $uRow['thread_key'] = $root !== '' ? $root : ('uid:' . $folder . ':' . (int)$uRow['uid']);
    }
    unset($uRow);
    db_upsert_messages($email, $folder, $result['upsert']);
    // Harvest contacts here rather than on every render: this runs once per
    // message, when it first enters the cache.
    foreach ($result['upsert'] as $row) {
      if (!empty($row['from_address'])) {
        db_upsert_contact($email, (string)($row['from_display'] ?? ''), $row['from_address'], false);
      }
    }
  }
  foreach ($result['flag_updates'] as $uid => $flags) {
    db_set_message_flags($email, $folder, (int)$uid, $flags);
  }
  if ($result['delete_uids']) {
    db_delete_messages($email, $folder, $result['delete_uids']);
  }

  $summary['changed'] = count($result['upsert'])
    + count($result['flag_updates'])
    + count($result['delete_uids']);
  // Only new UNREAD rows count. The empty-folder refill re-upserts rows it
  // already knew, but the old uidnext still tells them apart from the one
  // message that actually arrived.
  $summary['arrivals'] = sync_count_arrivals(
    $result['upsert'],
    $prevUidnext === null ? null : (int)$prevUidnext,
    $plan['purge_all']
  );

  // Bounded attachment backfill. Deliberately excluded from 'changed': it would
  // otherwise report work on every sync and the client would reload in a loop.
  if ($backfill > 0) {
    $pending = db_pending_structure_uids($email, $folder, $backfill);
    if ($pending) {
      db_set_has_attachment($email, $folder, $imap->attachmentFlags($pending));
    }
  }

  db_set_folder_state($email, $folder, [
    // Store the OPENING status. Deriving uidnext from what we fetched would
    // record an arrival that landed mid-sync as already seen, losing it.
    'uidvalidity'  => $status['uidvalidity'],
    'uidnext'      => $status['uidnext'],
    'total'        => $status['messages'],
    'unseen'       => $status['unseen'],
    'cached_rows'  => db_count_messages($email, $folder),
    'last_sync_ts' => $now,
    'last_full_ts' => $plan['full'] ? $now : (int)($state['last_full_ts'] ?? 0),
  ]);

  $summary['ok'] = true;
  return $summary;
}

// [5] MailImap — IMAP operations ---------------------------------------------
class MailImap
{
  /** @var resource|null */
  private $conn = null;
  private $serverPrefix = '';
  private $currentFolder = '';
  /** @var array|null Per-request memo for getFolders(); LIST is not free. */
  private $folderCache = null;
  /** @var array|null User-defined folder role mapping. */
  private $folderMap = null;
  public  $lastError = '';

  public function __construct(string $server, int $port, string $username, string $password, string $encryption = 'ssl')
  {
    if (!function_exists('imap_open')) {
      $this->lastError = 'PHP IMAP extension is not installed.';
      return;
    }
    // c-client's imap_open may block indefinitely (and crash PHP at shutdown)
    // on an unreachable host. Verify TCP reachability first with a bounded
    // timeout so we fail fast instead of hanging/segfaulting.
    $errno = 0;
    $errstr = '';
    $probe = @stream_socket_client("tcp://$server:$port", $errno, $errstr, 5);
    if ($probe === false) {
      $this->lastError = "Unable to connect to $server:$port ($errstr).";
      return;
    }
    fclose($probe);

    $verify = IMAP_VERIFY_CERT ? '' : '/novalidate-cert';
    $this->serverPrefix = '{' . $server . ':' . $port . '/imap/' . $encryption . $verify . '}';
    $this->conn = @imap_open(
      $this->serverPrefix . 'INBOX',
      $username,
      $password,
      OP_HALFOPEN | OP_SILENT,
      1
    );
    if (!$this->conn) {
      $this->lastError = @imap_last_error() ?: 'IMAP connection failed.';
    }
  }

  public function isConnected(): bool
  {
    return (bool)$this->conn;
  }

  /**
   * Set the user-defined folder role mapping. When set, find*Folder() methods
   * check this map first before falling back to keyword matching.
   */
  public function setFolderMap(array $map): void
  {
    $this->folderMap = $map;
  }

  public function disconnect(): void
  {
    if ($this->conn) {
      // Plain close. CL_EXPUNGE would expunge whatever mailbox happened to be
      // selected on every request teardown, including \Deleted flags set by
      // other clients. Every delete path here expunges explicitly already.
      @imap_close($this->conn);
      $this->conn = null;
    }
  }

  public function __destruct()
  {
    $this->disconnect();
  }

  private function fullPath(string $folder): string
  {
    return $this->serverPrefix . $folder;
  }

  /**
   * Select a mailbox. Pass $force to re-issue SELECT even if this connection
   * already has that folder selected, which refreshes the server's EXISTS /
   * last-UID view. A sync needs that: on a reused connection the memo would
   * otherwise hide messages that arrived since the first SELECT.
   */
  public function selectFolder(string $folder, bool $force = false): bool
  {
    // Already selected: skip the round trip. The memo is only ever set after a
    // verified select, so a failed select can never be short-circuited into
    // looking like a success -- callers would then operate on the wrong mailbox.
    if (!$force && $this->currentFolder === $folder && $this->conn) {
      return true;
    }
    if (!$this->conn) {
      return false;
    }
    // imap_reopen() returns TRUE even when the server answers NO to SELECT,
    // leaving the stream with no mailbox selected at all (imap_check() then
    // reports "<no_mailbox>"). c-client records the refusal on the error stack
    // instead, and reading that stack costs no round trip. Verified: a
    // successful reopen leaves the stack empty.
    @imap_errors();
    $ok = @imap_reopen($this->conn, $this->fullPath($folder));
    $errs = @imap_errors();
    if (!$ok || $errs) {
      $this->lastError = $errs ? (string)end($errs) : 'Could not select folder.';
      $this->currentFolder = '';
      return false;
    }
    $this->currentFolder = $folder;
    return true;
  }

  public function getFolders(): array
  {
    if ($this->folderCache !== null) {
      return $this->folderCache;
    }
    $folders = [];
    $list = @imap_getmailboxes($this->conn, $this->serverPrefix, '*');
    if (!is_array($list)) {
      return $folders; // do not memoize a failure
    }
    foreach ($list as $mailbox) {
      $name = str_replace($this->serverPrefix, '', $mailbox->name);
      $folders[] = [
        'name'        => $name,
        'displayName' => folder_display_name($name),
        'delimiter'   => $mailbox->delimiter,
        'attributes'  => $mailbox->attributes,
      ];
    }
    $this->folderCache = $this->orderFolders($folders);
    return $this->folderCache;
  }

  // Assign each folder to a canonical ordered group so the sidebar can show:
  // Inbox, Drafts, Sent, Spam, Archive  |  Trash  |  <others alphabetical>
  private function classifyFolder(string $name, string $displayName): array
  {
    $n = strtoupper($name);
    if ($n === 'INBOX' || $displayName === 'Inbox') return [0, 'primary'];
    if (strpos($n, 'DRAFT') !== false) return [1, 'primary'];
    if (strpos($n, 'SENT') !== false || $displayName === 'Sent') return [2, 'primary'];
    if (strpos($n, 'JUNK') !== false || strpos($n, 'SPAM') !== false || $displayName === 'Spam') return [3, 'primary'];
    if (strpos($n, 'ARCHIV') !== false || $displayName === 'Archive') return [4, 'primary'];
    if (
      strpos($n, 'TRASH') !== false || strpos($n, 'KOSZ') !== false
      || strpos($n, 'DELETED') !== false || $displayName === 'Trash'
    ) return [5, 'trash'];
    return [6, 'other'];
  }

  private function orderFolders(array $folders): array
  {
    foreach ($folders as &$f) {
      [$rank, $group] = $this->classifyFolder($f['name'], $f['displayName']);
      $f['group'] = $group;
      $f['_rank'] = $rank;
    }
    unset($f);
    usort($folders, function ($a, $b) {
      if ($a['_rank'] !== $b['_rank']) {
        return $a['_rank'] <=> $b['_rank'];
      }
      // Within each group keep server order except 'other' is alphabetical.
      if ($a['_rank'] === 6) {
        return strcasecmp($a['displayName'], $b['displayName']);
      }
      return 0;
    });
    foreach ($folders as &$f) {
      unset($f['_rank']);
    }
    unset($f);
    return $folders;
  }

  public function findTrashFolder(): ?string
  {
    return self::findTrashFolderIn($this->getFolders(), $this->folderMap);
  }

  // findTrashFolder() over a folder list the caller already holds. Public and
  // static so the delete confirm copy asks the SAME question the handler will
  // answer one POST later: two resolvers is how the copy came to promise a move
  // to Trash that moveManyToTrash() could not perform.
  public static function findTrashFolderIn(array $folders, ?array $folderMap = null): ?string
  {
    return self::matchSpecialFolder($folders, $folderMap, ['Trash', 'Kosz', 'Deleted', 'Deleted Items', 'Gmail'], 'trash');
  }

  // findSentFolder() over a folder list the caller already holds. Public and
  // static so the sync can keep Sent warm without opening a connection to LIST.
  public static function findSentFolderIn(array $folders, ?array $folderMap = null): ?string
  {
    return self::matchSpecialFolder($folders, $folderMap, ['Sent', 'Wysłane', 'Wyslane', 'Sent Messages', 'Sent Items', 'Gmail'], 'sent');
  }

  public function findSentFolder(): ?string
  {
    return self::findSentFolderIn($this->getFolders(), $this->folderMap);
  }

  public function findDraftsFolder(): ?string
  {
    return $this->findSpecialFolder(['Drafts', 'Draft', 'Kopie robocze'], 'draft');
  }

  public function findJunkFolder(): ?string
  {
    return $this->findSpecialFolder(['Junk', 'Spam', 'Junk E-mail', 'Bulk Mail'], 'junk');
  }

  public function findArchiveFolder(): ?string
  {
    return $this->findSpecialFolder(['Archive', 'Archived', 'Archiwum'], 'archiv');
  }

  private function findSpecialFolder(array $names, string $keyword): ?string
  {
    return self::matchSpecialFolder($this->getFolders(), $this->folderMap, $names, $keyword);
  }

  // The one resolver behind every role lookup, static so the render path can
  // ask it about a folder list it already holds, with no connection. The map
  // wins, then an exact name, then the substring fallback.
  private static function matchSpecialFolder(array $folders, ?array $folderMap, array $names, string $keyword): ?string
  {
    // User-defined folder map takes priority when set.
    if ($folderMap !== null) {
      $roleMap = [
        'sent' => 'sent',
        'draft' => 'drafts',
        'drafts' => 'drafts',
        'junk' => 'spam',
        'spam' => 'spam',
        'trash' => 'trash',
        'archive' => 'archive',
        'archiv' => 'archive'
      ];
      $role = $roleMap[strtolower($keyword)] ?? $keyword;
      if (isset($folderMap[$role])) {
        $mapped = $folderMap[$role];
        foreach ($folders as $f) {
          if (strcasecmp($f['name'], $mapped) === 0) {
            return $f['name'];
          }
        }
      }
    }
    // Name-based matching first.
    foreach ($folders as $f) {
      $name = $f['name'];
      foreach ($names as $n) {
        if (strcasecmp($name, $n) === 0) {
          return $name;
        }
      }
    }
    // Substring fallback.
    foreach ($folders as $f) {
      $name = $f['name'];
      if (strpos(strtolower($name), $keyword) !== false && $name !== 'INBOX') {
        return $name;
      }
    }
    return null;
  }

  // Pure name matching, so the render path can classify a folder without
  // holding an IMAP connection. Static on purpose.
  public static function isTrashFolder(string $folder): bool
  {
    $n = strtolower($folder);
    return in_array($n, ['trash', 'kosz', 'deleted', 'deleted items'], true)
      || strpos($n, 'trash') !== false || strpos($n, 'kosz') !== false;
  }

  public static function isSentFolder(string $folder): bool
  {
    $n = strtolower($folder);
    return in_array($n, ['sent', 'wysłane', 'wyslane', 'sent messages', 'sent items'], true)
      || strpos($n, 'sent') !== false;
  }

  public static function isDraftsFolder(string $folder): bool
  {
    $n = strtolower($folder);
    return in_array($n, ['drafts', 'draft', 'kopie robocze'], true)
      || strpos($n, 'draft') !== false;
  }

  public static function isJunkFolder(string $folder): bool
  {
    $n = strtolower($folder);
    return in_array($n, ['junk', 'spam', 'junk e-mail', 'bulk mail'], true)
      || strpos($n, 'junk') !== false || strpos($n, 'spam') !== false;
  }

  public static function isArchiveFolder(string $folder): bool
  {
    $n = strtolower($folder);
    return in_array($n, ['archive', 'archived', 'archiwum'], true)
      || strpos($n, 'archiv') !== false;
  }

  public function getEmailCount(string $folder = 'INBOX'): int
  {
    $this->selectFolder($folder);
    $info = @imap_check($this->conn);
    return $info ? (int)$info->Nmsgs : 0;
  }

  /**
   * Fetch a page of emails, newest first, using server-side SORT when
   * available. Avoids fetching the whole folder into memory.
   *
   * Works in message numbers, not UIDs, on purpose: passing FT_UID to
   * imap_fetch_overview makes c-client resolve every UID with its own
   * `UID FETCH <uid> UID` round trip, which costs one command per message.
   * The overview response carries the UID anyway.
   */
  public function getEmails(string $folder, int $page = 1, int $perPage = 20): array
  {
    if (!$this->selectFolder($folder)) {
      return ['emails' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page];
    }

    // Server-side sort by arrival (received) time, descending, as message
    // numbers. Message numbers are stable for the life of this connection.
    $msgnos = @imap_sort($this->conn, SORTARRIVAL, 1 /*descending*/);
    if (!is_array($msgnos) || empty($msgnos)) {
      // Fallback: sort by Date header, then ALL search reversed.
      $msgnos = @imap_sort($this->conn, SORTDATE, 1 /*descending*/);
    }
    if (!is_array($msgnos) || empty($msgnos)) {
      // Last-resort fallback: ALL search, reverse. Message number order
      // follows arrival order, so a reverse sort is newest-first.
      $msgnos = @imap_search($this->conn, 'ALL');
      if (!is_array($msgnos)) {
        $msgnos = [];
      }
      rsort($msgnos);
    }

    $total = count($msgnos);
    if ($total === 0) {
      return ['emails' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page];
    }
    $pages = (int)ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;
    $pageMsgnos = array_slice($msgnos, $offset, $perPage);

    $emails = $this->fetchOverviews($pageMsgnos);
    // Guarantee newest-received-first ordering regardless of server sort
    // behavior or which fallback path produced the message set.
    usort($emails, function ($a, $b) {
      return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
    });
    return [
      'emails' => $emails,
      'total'  => $total,
      'pages'  => $pages,
      'current_page' => $page,
    ];
  }

  private function fetchOverviews(array $msgnos): array
  {
    $emails = [];
    if (empty($msgnos)) {
      return $emails;
    }
    // Resolve attachment flags FIRST, walking message numbers in ASCENDING
    // order. c-client's lookahead (IMAPLOOKAHEAD, 20 ahead) then batches
    // ENVELOPE + BODYSTRUCTURE + INTERNALDATE + SIZE + FLAGS for the whole
    // window into a single FETCH.
    //
    // Order is load-bearing, and measured: for a 20-message page this costs
    // 1 command. Calling imap_fetch_overview() first caches the envelope, after
    // which each structure fetch goes out on its own -- 21 commands. Walking
    // descending (newest first, as the list does) also defeats it, because the
    // lookahead only ever prefetches forwards.
    $ascending = array_map('intval', $msgnos);
    sort($ascending, SORT_NUMERIC);
    $attach = [];
    foreach ($ascending as $n) {
      $attach[$n] = $this->checkAttachment($n);
    }

    $overview = @imap_fetch_overview($this->conn, implode(',', $msgnos));
    if (!is_array($overview)) {
      return $emails;
    }
    foreach ($overview as $msg) {
      $uid = (int)($msg->uid ?? 0);
      if ($uid === 0) {
        continue;
      }
      $emails[] = [
        'uid'           => $uid,
        'subject'       => decode_mime_header($msg->subject ?? '(No subject)'),
        'from'          => decode_mime_header($msg->from ?? 'Unknown'),
        'from_address'  => $this->extractEmail($msg->from ?? ''),
        'to'            => decode_mime_header($msg->to ?? ''),
        'to_address'    => $this->extractEmail($msg->to ?? ''),
        'date'          => $msg->date ?? '',
        'timestamp'     => $msg->udate ?? strtotime($msg->date ?? 'now'),
        'size'          => (int)($msg->size ?? 0),
        'unread'        => !isset($msg->seen) || !$msg->seen,
        'answered'      => isset($msg->answered) && $msg->answered,
        'flagged'       => isset($msg->flagged) && $msg->flagged,
        // The threading ids, from the same FETCH (see overviewRange).
        'message_id'    => trim((string)($msg->message_id ?? '')),
        'in_reply_to'   => trim((string)($msg->in_reply_to ?? '')),
        'references'    => trim((string)($msg->references ?? '')),
        'has_attachment' => $attach[(int)($msg->msgno ?? 0)] ?? false,
      ];
    }
    return $emails;
  }

  public function getEmailByUid(int $uid): ?array
  {
    $msgno = @imap_msgno($this->conn, $uid);
    if (!$msgno) {
      return null;
    }
    $header = @imap_headerinfo($this->conn, $msgno);
    $structure = @imap_fetchstructure($this->conn, $msgno);
    if (!$header || !$structure) {
      return null;
    }
    $body = $this->getBody($msgno, $structure);

    $fromAddress = $this->extractEmail($header->fromaddress ?? '');
    if (empty($fromAddress) && !empty($header->from) && is_array($header->from)) {
      $from = $header->from[0];
      $fromAddress = ($from->mailbox ?? '') . '@' . ($from->host ?? '');
    }

    return [
      'uid'          => $uid,
      'subject'      => decode_mime_header($header->subject ?? '(No subject)'),
      'from'         => decode_mime_header($header->fromaddress ?? 'Unknown'),
      'from_address' => $fromAddress,
      'to'           => decode_mime_header($header->toaddress ?? ''),
      'cc'           => isset($header->ccaddress) ? decode_mime_header($header->ccaddress) : '',
      // Present only on the copy filed in Sent; a received message never has one.
      'bcc'          => isset($header->bccaddress) ? decode_mime_header($header->bccaddress) : '',
      // A list, or a no-reply sender, points replies elsewhere with Reply-To.
      'reply_to_address' => isset($header->reply_toaddress) ? decode_mime_header($header->reply_toaddress) : '',
      // Threading. c-client fills message_id and in_reply_to from the ENVELOPE;
      // references is not part of it and is often absent, hence the ?? ''.
      'message_id'   => trim((string)($header->message_id ?? '')),
      'in_reply_to'  => trim((string)($header->in_reply_to ?? '')),
      'references'   => trim((string)($header->references ?? '')),
      'date'         => $header->date ?? '',
      'timestamp'    => $header->udate ?? strtotime($header->date ?? 'now'),
      'body_html'    => $body['html'] ?? '',
      'body_text'    => $body['text'] ?? '',
      'attachments'  => $this->getAttachments($msgno, $structure),
      'unread'       => !isset($header->Unseen) || $header->Unseen !== 'U',
    ];
  }

  private function getBody(int $msgno, $structure): array
  {
    $body = ['html' => '', 'text' => ''];
    if (empty($structure->parts)) {
      // FT_PEEK: fetching a body must not set \Seen. Marking a message read is
      // an explicit action (handle_read), not a side effect of reply, forward
      // or attachment download.
      $raw = @imap_body($this->conn, $msgno, FT_PEEK);
      if ($raw === false) {
        return $body;
      }
      $decoded = $this->decodeBody($raw, $structure);
      $subtype = strtolower($structure->subtype ?? 'plain');
      if ($subtype === 'html') {
        $body['html'] = $decoded;
      } else {
        $body['text'] = $decoded;
      }
    } else {
      $this->parseParts($msgno, $structure->parts, '', $body);
    }
    if (empty($body['html']) && !empty($body['text'])) {
      $body['html'] = nl2br(h($body['text']));
    }
    return $body;
  }

  private function parseParts(int $msgno, array $parts, string $prefix, array &$body): void
  {
    foreach ($parts as $index => $part) {
      $partNumber = $prefix ? ($prefix . '.' . ($index + 1)) : (string)($index + 1);
      if (($part->type ?? 0) === 1 && !empty($part->parts)) {
        $this->parseParts($msgno, $part->parts, $partNumber, $body);
        continue;
      }
      if (($part->type ?? 0) === 0) {
        $isAttachment = $this->isAttachmentPart($part);
        if (!$isAttachment) {
          $data = @imap_fetchbody($this->conn, $msgno, $partNumber, FT_PEEK);
          if ($data === false) {
            continue;
          }
          $decoded = $this->decodeBody($data, $part);
          $subtype = strtolower($part->subtype ?? 'plain');
          if ($subtype === 'html') {
            if (empty($body['html'])) $body['html'] = $decoded;
          } else {
            if (empty($body['text'])) $body['text'] = $decoded;
          }
        }
      }
    }
  }

  private function isAttachmentPart($part): bool
  {
    if (!empty($part->ifdisposition) && strtolower($part->disposition) === 'attachment') {
      return true;
    }
    if (!empty($part->ifdisposition) && strtolower($part->disposition) === 'inline') {
      // Inline images are decorative; treat as body content.
      return false;
    }
    if (!empty($part->parameters)) {
      foreach ($part->parameters as $p) {
        if (strtolower($p->attribute) === 'name') {
          return true;
        }
      }
    }
    if (!empty($part->dparameters)) {
      foreach ($part->dparameters as $p) {
        if (strtolower($p->attribute) === 'filename') {
          return true;
        }
      }
    }
    return false;
  }

  private function checkAttachment(int $msgno): bool
  {
    if ($msgno < 1) {
      return false;
    }
    $structure = @imap_fetchstructure($this->conn, $msgno);
    if (!$structure || empty($structure->parts)) {
      return false;
    }
    return $this->hasAttachmentInParts($structure->parts);
  }

  private function hasAttachmentInParts(array $parts): bool
  {
    foreach ($parts as $part) {
      if ($this->isAttachmentPart($part) && strtolower($part->disposition ?? '') === 'attachment') {
        return true;
      }
      if (!empty($part->parts) && $this->hasAttachmentInParts($part->parts)) {
        return true;
      }
    }
    return false;
  }

  public function getAttachments(int $msgno, $structure = null): array
  {
    if (!$structure) {
      $structure = @imap_fetchstructure($this->conn, $msgno);
    }
    $attachments = [];
    if (!$structure || empty($structure->parts)) {
      return $attachments;
    }
    $this->findAttachments($msgno, $structure->parts, '', $attachments);
    return $attachments;
  }

  private function findAttachments(int $msgno, array $parts, string $prefix, array &$attachments): void
  {
    foreach ($parts as $index => $part) {
      $partNumber = $prefix ? ($prefix . '.' . ($index + 1)) : (string)($index + 1);
      if (($part->type ?? 0) === 1 && !empty($part->parts)) {
        $this->findAttachments($msgno, $part->parts, $partNumber, $attachments);
        continue;
      }
      if (!$this->isAttachmentPart($part)) {
        continue;
      }
      $attachments[] = [
        'part_number' => $partNumber,
        'filename'    => $this->partFilename($part, $partNumber),
        // Size comes from the structure. Downloading the part just to call
        // strlen() on it transferred every attachment on every message view.
        'size'        => $this->partSize($part),
        'subtype'     => strtolower($part->subtype ?? 'octet-stream'),
      ];
    }
  }

  // BODYSTRUCTURE reports the ENCODED octet count. Convert base64 to its
  // decoded length so the size shown matches the file the user downloads.
  private function partSize($part): int
  {
    $bytes = (int)($part->bytes ?? 0);
    if ($bytes <= 0) {
      return 0;
    }
    if ((int)($part->encoding ?? 0) === ENCBASE64) {
      return (int)floor($bytes * 3 / 4);
    }
    return $bytes;
  }

  private function partFilename($part, string $fallback): string
  {
    $filename = 'attachment_' . $fallback;
    // RFC 2231 (filename*) takes priority.
    if (!empty($part->parameters)) {
      foreach ($part->parameters as $p) {
        $attr = strtolower($p->attribute);
        if ($attr === 'filename*') {
          $filename = decode_rfc2231($p->value);
        }
      }
    }
    if (!empty($part->dparameters)) {
      foreach ($part->dparameters as $p) {
        $attr = strtolower($p->attribute);
        if ($attr === 'filename*') {
          $filename = decode_rfc2231($p->value);
        }
      }
    }
    // Plain filename as fallback.
    if (strpos($filename, 'attachment_') === 0) {
      if (!empty($part->parameters)) {
        foreach ($part->parameters as $p) {
          if (strtolower($p->attribute) === 'name') {
            $filename = decode_mime_header($p->value);
          }
        }
      }
      if (!empty($part->dparameters)) {
        foreach ($part->dparameters as $p) {
          if (strtolower($p->attribute) === 'filename') {
            $filename = decode_mime_header($p->value);
          }
        }
      }
    }
    if (trim($filename) === '' || strpos($filename, 'attachment_') === 0) {
      $filename = 'attachment_' . $fallback;
    }
    return $filename;
  }

  public function getAttachmentByUid(int $uid, string $partNumber): ?array
  {
    $msgno = @imap_msgno($this->conn, $uid);
    if (!$msgno) {
      return null;
    }
    $structure = @imap_fetchstructure($this->conn, $msgno);
    if (!$structure || empty($structure->parts)) {
      return null;
    }
    $part = $this->findPartByNumber($structure->parts, $partNumber);
    if (!$part) {
      return null;
    }
    $data = @imap_fetchbody($this->conn, $msgno, $partNumber, FT_PEEK);
    if ($data === false || $data === '') {
      return null;
    }
    $data = $this->decodeBody($data, $part);
    return [
      'filename' => $this->partFilename($part, $partNumber),
      'data'     => $data,
      'subtype'  => strtolower($part->subtype ?? 'octet-stream'),
    ];
  }

  private function findPartByNumber(array $parts, string $partNumber)
  {
    $part = null;
    $current = $parts;
    foreach (explode('.', $partNumber) as $i => $idx) {
      $idx = (int)$idx - 1;
      if (!isset($current[$idx])) {
        return null;
      }
      $part = $current[$idx];
      if ($i < count(explode('.', $partNumber)) - 1) {
        if (empty($part->parts)) {
          return null;
        }
        $current = $part->parts;
      }
    }
    return $part;
  }

  public function searchEmails(string $folder, string $query, int $page = 1, int $perPage = 20): array
  {
    if (!$this->selectFolder($folder)) {
      return ['emails' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page, 'query' => $query];
    }
    $escaped = str_replace(['"', '\\'], '', $query);
    // Single TEXT search (covers subject, from, body) with UTF-8 charset.
    // Message numbers, not SE_UID: see getEmails() on the FT_UID cost.
    $msgnos = @imap_search($this->conn, 'TEXT "' . $escaped . '"', SE_FREE, 'UTF-8');
    if (!is_array($msgnos) || empty($msgnos)) {
      return ['emails' => [], 'total' => 0, 'pages' => 0, 'current_page' => $page, 'query' => $query];
    }
    rsort($msgnos);
    $total = count($msgnos);
    $pages = (int)ceil($total / $perPage);
    $offset = ($page - 1) * $perPage;
    $pageMsgnos = array_slice($msgnos, $offset, $perPage);
    return [
      'emails' => $this->fetchOverviews($pageMsgnos),
      'total'  => $total,
      'pages'  => $pages,
      'current_page' => $page,
      'query'  => $query,
    ];
  }

  // --- Mutations (POST + CSRF guarded at the handler level) ---

  // The one mover. Every filing action funnels through here so the
  // uid -> msgno -> move -> expunge dance exists once.
  // (replaced by moveManyToFolder; kept as wrapper below)

  public function moveToTrash(int $uid): bool
  {
    $trash = $this->findTrashFolder();
    if (!$trash) {
      return $this->deleteEmailByUid($uid); // no trash folder: hard delete
    }
    return $this->moveToFolder($uid, $trash);
  }

  // Unlike moveToTrash, a missing Junk mailbox must NOT fall back to a delete:
  // "this is spam" is a filing decision, never a destructive one.
  public function moveToJunk(int $uid): bool
  {
    $junk = $this->findJunkFolder();
    if (!$junk) {
      return false;
    }
    return $this->moveToFolder($uid, $junk);
  }

  public function restoreFromTrash(int $uid): bool
  {
    $trash = $this->findTrashFolder();
    if (!$trash) {
      return false;
    }
    $this->selectFolder($trash);
    return $this->moveToFolder($uid, 'INBOX');
  }

  public function deleteEmailByUid(int $uid): bool
  {
    $res = @imap_delete($this->conn, $uid, ST_UID);
    if ($res) {
      @imap_expunge($this->conn);
    }
    return (bool)$res;
  }

  // Wipe a whole mailbox. The CALLER must have run folder_empty_check() first:
  // the delete below is by SEQUENCE NUMBER across everything selected.
  public function emptyFolder(string $folder): bool
  {
    // Bail if the mailbox cannot be selected, or the delete would destroy the
    // first N messages of whatever happened to be selected instead.
    if (!$this->selectFolder($folder)) {
      return false;
    }
    $info = @imap_check($this->conn);
    if (!$info || (int)$info->Nmsgs === 0) {
      return true;
    }
    $res = @imap_delete($this->conn, '1:' . $info->Nmsgs);
    if ($res) {
      @imap_expunge($this->conn);
    }
    return (bool)$res;
  }

  // Both return bool: handle_mark_read() guards the local cache write on it.
  // As `: void` they returned null, so that write never ran and the list kept
  // showing the stale flag until the background sync caught up.
  public function markAsReadByUid(int $uid): bool
  {
    return (bool)@imap_setflag_full($this->conn, (string)$uid, '\\Seen', ST_UID);
  }

  public function markAsUnreadByUid(int $uid): bool
  {
    return (bool)@imap_clearflag_full($this->conn, (string)$uid, '\\Seen', ST_UID);
  }

  public function moveManyToFolder(array $uids, string $dest): int
  {
    $uids = array_values(array_filter($uids, function ($n) {
      return is_int($n) && $n > 0;
    }));
    if (empty($uids)) {
      return 0;
    }
    $ok = @imap_mail_move($this->conn, implode(',', $uids), $dest, CP_UID);
    if ($ok) {
      @imap_expunge($this->conn);
    }
    return $ok ? count($uids) : 0;
  }

  public function moveToFolder(int $uid, string $dest): bool
  {
    $msgno = @imap_msgno($this->conn, $uid);
    if (!$msgno) {
      return false;
    }
    return $this->moveManyToFolder([$uid], $dest) > 0;
  }

  public function markManyAsRead(array $uids, bool $read = true): int
  {
    $uids = array_values(array_filter($uids, function ($n) {
      return is_int($n) && $n > 0;
    }));
    if (empty($uids)) {
      return 0;
    }
    @imap_errors();
    if ($read) {
      @imap_setflag_full($this->conn, implode(',', $uids), '\\Seen', ST_UID);
    } else {
      @imap_clearflag_full($this->conn, implode(',', $uids), '\\Seen', ST_UID);
    }
    @imap_errors();
    return count($uids);
  }

  public function deleteManyByUid(array $uids): int
  {
    $uids = array_values(array_filter($uids, function ($n) {
      return is_int($n) && $n > 0;
    }));
    if (empty($uids)) {
      return 0;
    }
    $res = @imap_delete($this->conn, implode(',', $uids), ST_UID);
    if ($res) {
      @imap_expunge($this->conn);
    }
    return $res ? count($uids) : 0;
  }

  public function moveManyToTrash(array $uids): int
  {
    $trash = $this->findTrashFolder();
    if (!$trash) {
      return $this->deleteManyByUid($uids);
    }
    return $this->moveManyToFolder($uids, $trash);
  }

  public function appendToFolder(string $folder, string $rawMessage, string $flags = '\\Seen'): bool
  {
    return (bool)@imap_append($this->conn, $this->fullPath($folder), $rawMessage, $flags);
  }

  // --- Mailbox management ---
  //
  // Both drop the per-request LIST memo: the sidebar is rendered after these run
  // and would otherwise still show the old set. Both also drain c-client's error
  // stack on failure -- @ hides the immediate warning, but the queued error is
  // dumped at shutdown and would otherwise be attributed to a later call.

  public function createFolder(string $folder): bool
  {
    @imap_errors();
    $ok = (bool)@imap_createmailbox($this->conn, $this->fullPath($folder));
    $this->folderCache = null;
    if (!$ok) {
      $errs = @imap_errors();
      $this->lastError = $errs ? (string)end($errs) : 'Could not create the folder.';
    }
    return $ok;
  }

  public function deleteFolder(string $folder): bool
  {
    @imap_errors();
    $ok = (bool)@imap_deletemailbox($this->conn, $this->fullPath($folder));
    $this->folderCache = null;
    if (!$ok) {
      $errs = @imap_errors();
      $this->lastError = $errs ? (string)end($errs) : 'Could not delete the folder.';
    }
    return $ok;
  }

  // --- Sync primitives (used by sync_folder) ---

  /**
   * STATUS for a folder: one command, and no SELECT on an IMAP4rev1 server.
   *
   * Returns null if STATUS itself failed. Otherwise every value is int|null,
   * where null means "the server did not report it". That distinction is
   * load-bearing: c-client silently falls back to EXAMINE on pre-rev1 servers
   * and strips UIDNEXT/UIDVALIDITY when it does, and two zeroes would compare
   * equal forever, freezing the cache.
   */
  public function statusFor(string $folder): ?array
  {
    if (!$this->conn) {
      return null;
    }
    $s = @imap_status($this->conn, $this->fullPath($folder), SA_ALL);
    if (!$s) {
      return null;
    }
    $flags = (int)($s->flags ?? 0);
    $read = function ($bit, $prop) use ($s, $flags) {
      // Both tests must pass: the flag says the server answered, and the
      // property exists. Never fall back to 0.
      return ($flags & $bit) && property_exists($s, $prop) ? (int)$s->$prop : null;
    };
    return [
      'messages'    => $read(SA_MESSAGES, 'messages'),
      'unseen'      => $read(SA_UNSEEN, 'unseen'),
      'uidnext'     => $read(SA_UIDNEXT, 'uidnext'),
      'uidvalidity' => $read(SA_UIDVALIDITY, 'uidvalidity'),
    ];
  }

  /**
   * UIDs matching a search, or null when nothing matched.
   *
   * imap_search() returns false both for "no matches" and for any failure
   * (no mailbox selected, dropped connection, unparseable criteria, server NO).
   * They are indistinguishable, so both surface as null and callers must treat
   * that as "unknown" rather than "empty".
   *
   * Note: pass server-side criteria only. A bare 'ALL' is resolved locally by
   * c-client at one FETCH per 1000 messages; use 'UNDELETED' instead.
   */
  public function uidSet(string $criteria): ?array
  {
    if (!$this->conn) {
      return null;
    }
    $res = @imap_search($this->conn, $criteria, SE_UID);
    if (!is_array($res)) {
      return null;
    }
    return array_values(array_map('intval', $res));
  }

  /**
   * Fetch a contiguous range as cache rows, in one command.
   * $range is a message-number range by default, or a UID range when $byUid.
   *
   * With $withAttachments, attachment flags are resolved in the same batched
   * FETCH: structures are requested in ascending message order BEFORE the
   * envelopes, which is the only ordering c-client's lookahead will batch (see
   * fetchOverviews). Resolving them afterwards costs one command per message.
   */
  public function overviewRange(string $range, bool $byUid = false, bool $withAttachments = false): array
  {
    if (!$this->conn || $range === '') {
      return [];
    }
    $attach = null;
    if ($withAttachments && !$byUid) {
      // Only message-number ranges can be walked ascending without first
      // resolving UIDs, which would defeat the batching we are after.
      $attach = [];
      foreach ($this->expandMsgnoRange($range) as $n) {
        $attach[$n] = $this->checkAttachment($n);
      }
    }
    $overview = @imap_fetch_overview($this->conn, $range, $byUid ? FT_UID : 0);
    if (!is_array($overview)) {
      return [];
    }
    $rows = [];
    foreach ($overview as $msg) {
      $uid = (int)($msg->uid ?? 0);
      if ($uid === 0) {
        continue;
      }
      $row = [
        'uid'          => $uid,
        'subject'      => decode_mime_header($msg->subject ?? ''),
        'from_display' => decode_mime_header($msg->from ?? ''),
        'from_address' => $this->extractEmail($msg->from ?? ''),
        'to_display'   => decode_mime_header($msg->to ?? ''),
        'to_address'   => $this->extractEmail($msg->to ?? ''),
        // udate is INTERNALDATE, which is what SORTARRIVAL orders by.
        'date_ts'      => (int)($msg->udate ?? strtotime($msg->date ?? 'now') ?: 0),
        'size'         => (int)($msg->size ?? 0),
        'unread'       => !isset($msg->seen) || !$msg->seen,
        'answered'     => isset($msg->answered) && $msg->answered,
        'flagged'      => isset($msg->flagged) && $msg->flagged,
        'deleted'      => isset($msg->deleted) && $msg->deleted,
        // The threading ids. c-client returns References because it already
        // requests BODY.PEEK[HEADER.FIELDS (… References)] in this one FETCH.
        'message_id'   => trim((string)($msg->message_id ?? '')),
        'in_reply_to'  => trim((string)($msg->in_reply_to ?? '')),
        'references'   => trim((string)($msg->references ?? '')),
      ];
      if ($attach !== null) {
        $row['has_attachment']   = $attach[(int)($msg->msgno ?? 0)] ?? false;
        $row['structure_synced'] = true;
      }
      $rows[] = $row;
    }
    return $rows;
  }

  // Expand a simple "lo:hi" or "n" message-number range. Only used to walk a
  // range ascending; "*" resolves to the current message count.
  private function expandMsgnoRange(string $range): array
  {
    $total = (int)@imap_num_msg($this->conn); // local, no round trip
    $out = [];
    foreach (explode(',', $range) as $part) {
      if (strpos($part, ':') !== false) {
        [$a, $b] = explode(':', $part, 2);
        $a = ($a === '*') ? $total : (int)$a;
        $b = ($b === '*') ? $total : (int)$b;
        if ($a > $b) {
          [$a, $b] = [$b, $a];
        }
        for ($i = max(1, $a); $i <= min($total, $b); $i++) {
          $out[] = $i;
        }
      } elseif ($part !== '') {
        $n = ($part === '*') ? $total : (int)$part;
        if ($n >= 1 && $n <= $total) {
          $out[] = $n;
        }
      }
    }
    sort($out, SORT_NUMERIC);
    return $out;
  }

  /**
   * [uid => bool] attachment presence.
   *
   * UIDs are walked in ascending order so c-client's lookahead can batch the
   * BODYSTRUCTURE fetches (see fetchOverviews). Cost is therefore roughly one
   * command per 21 consecutive messages rather than one per message, but it is
   * still unbounded for a scattered set, so callers must cap the batch.
   */
  public function attachmentFlags(array $uids): array
  {
    $out = [];
    if (!$this->conn) {
      return $out;
    }
    $sorted = array_map('intval', $uids);
    sort($sorted, SORT_NUMERIC);
    foreach ($sorted as $uid) {
      $msgno = @imap_msgno($this->conn, $uid);
      $out[$uid] = $msgno ? $this->checkAttachment((int)$msgno) : false;
    }
    return $out;
  }

  // --- Decoding helpers ---

  private function decodeBody($data, $part): string
  {
    $encoding = (int)($part->encoding ?? 0);
    if ($encoding === 3) { // BASE64
      $data = base64_decode($data, false);
    } elseif ($encoding === 4) { // QUOTED-PRINTABLE
      $data = quoted_printable_decode($data);
    }
    if ($data === false) {
      $data = '';
    }
    $charset = $this->partCharset($part);
    $decoded = @mb_convert_encoding($data, 'UTF-8', charset_alias($charset));
    return $decoded !== false ? $decoded : utf8_fix((string)$data);
  }

  private function partCharset($part): string
  {
    if (!empty($part->parameters)) {
      foreach ($part->parameters as $p) {
        if (strtolower($p->attribute) === 'charset') {
          return $p->value;
        }
      }
    }
    if (!empty($part->charset)) {
      return $part->charset;
    }
    return 'UTF-8';
  }

  private function extractEmail(string $from): string
  {
    if (preg_match('/<([^>]+)>/', $from, $m)) {
      return $m[1];
    }
    return trim($from);
  }
}

// [6] SMTP — connection and sending ------------------------------------------

// Connect to SMTP, negotiate STARTTLS, authenticate. Returns a stream resource
// or null on failure (populates $err).
// SSL stream-context options shared by SMTP (and available to any TLS socket).
//
// 'cafile' must be OMITTED when it is not configured. Setting it to null or ''
// leaves the key in the context, and stream_socket_enable_crypto() then throws
// "ValueError: Path must not be empty" — a fatal on every send.
function tls_ssl_options(bool $verify): array
{
  $opts = [
    'verify_peer'       => $verify,
    'verify_peer_name'  => $verify,
    'allow_self_signed' => !$verify,
    'crypto_method'     => TLS_MIN_CRYPTO_METHOD,
  ];
  $cafile = trim((string)getenv('EMMMAIL_CAFILE'));
  if ($cafile !== '') {
    $opts['cafile'] = $cafile;
  }
  return $opts;
}

function smtp_connect(string $server, int $port, string $username, string $password, ?string &$err = null, string $encryption = SMTP_ENCRYPTION)
{
  $ctx = stream_context_create(['ssl' => tls_ssl_options(SMTP_VERIFY_CERT)]);

  $conn = @stream_socket_client(
    'tcp://' . $server . ':' . $port,
    $errno,
    $errstr,
    30,
    STREAM_CLIENT_CONNECT,
    $ctx
  );
  if (!$conn) {
    $err = "Connection failed: $errstr ($errno)";
    return null;
  }
  stream_set_timeout($conn, 30);

  $resp = smtp_read_response($conn);
  if (!smtp_ok($resp)) {
    $err = "SMTP greeting failed: " . trim($resp);
    fclose($conn);
    return null;
  }

  // EHLO #1
  $ehlo = smtp_send_command($conn, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? gethostname()));
  if (!smtp_ok($ehlo)) {
    $err = "EHLO failed: " . trim($ehlo);
    fclose($conn);
    return null;
  }

  $caps = parse_ehlo_capabilities($ehlo);

  // STARTTLS if advertised and using tls encryption.
  if ($encryption === 'tls' && strpos(strtoupper($ehlo), 'STARTTLS') !== false) {
    $resp = smtp_send_command($conn, 'STARTTLS');
    if (!smtp_ok($resp)) {
      $err = "STARTTLS failed: " . trim($resp);
      fclose($conn);
      return null;
    }
    $crypto = stream_socket_enable_crypto($conn, true, TLS_MIN_CRYPTO_METHOD);
    if (!$crypto) {
      $err = 'TLS negotiation failed.';
      fclose($conn);
      return null;
    }
    // Re-EHLO after TLS.
    $ehlo = smtp_send_command($conn, 'EHLO ' . ($_SERVER['SERVER_NAME'] ?? gethostname()));
    $caps = parse_ehlo_capabilities($ehlo);
  }

  // Authenticate. Prefer PLAIN then LOGIN.
  $authMechs = array_map('strtoupper', $caps['auth'] ?? []);
  $creds = base64_encode("\0" . $username . "\0" . $password);
  if (in_array('PLAIN', $authMechs, true)) {
    $resp = smtp_send_command($conn, 'AUTH PLAIN ' . $creds);
  } elseif (in_array('LOGIN', $authMechs, true)) {
    smtp_send_command($conn, 'AUTH LOGIN');
    smtp_send_command($conn, base64_encode($username));
    $resp = smtp_send_command($conn, base64_encode($password));
  } else {
    $err = 'Server advertises no supported AUTH mechanism.';
    fclose($conn);
    return null;
  }
  if (!smtp_ok($resp)) {
    $err = 'SMTP authentication failed: ' . trim($resp);
    fclose($conn);
    return null;
  }

  return $conn;
}

function smtp_read_response($conn): string
{
  $response = '';
  while ($line = fgets($conn, 515)) {
    $response .= $line;
    if (isset($line[3]) && $line[3] === ' ') {
      break;
    }
  }
  return $response;
}

function smtp_send_command($conn, string $cmd): string
{
  fwrite($conn, $cmd . "\r\n");
  return smtp_read_response($conn);
}

function smtp_ok(string $response): bool
{
  return (bool)preg_match('/^[23]\d\d/', $response);
}

// Send a message. Returns true on success. $smtpErr populated on failure.
function smtp_send($conn, string $from, array $recipients, string $rawMessage, ?string &$smtpErr = null): bool
{
  $resp = smtp_send_command($conn, 'MAIL FROM:<' . $from . '>');
  if (!smtp_ok($resp)) {
    $smtpErr = trim($resp);
    return false;
  }
  foreach ($recipients as $rcpt) {
    $rcpt = trim($rcpt);
    if ($rcpt === '') {
      continue;
    }
    if (!filter_var($rcpt, FILTER_VALIDATE_EMAIL)) {
      $smtpErr = "Invalid recipient: $rcpt";
      return false;
    }
    $resp = smtp_send_command($conn, 'RCPT TO:<' . $rcpt . '>');
    if (!smtp_ok($resp)) {
      $smtpErr = "RCPT TO $rcpt rejected: " . trim($resp);
      return false;
    }
  }
  $resp = smtp_send_command($conn, 'DATA');
  if (!smtp_ok($resp)) {
    $smtpErr = trim($resp);
    return false;
  }
  fwrite($conn, $rawMessage . "\r\n.\r\n");
  $resp = smtp_read_response($conn);
  if (!smtp_ok($resp)) {
    $smtpErr = trim($resp);
    return false;
  }
  return true;
}

function smtp_disconnect($conn): void
{
  if (is_resource($conn)) {
    @smtp_send_command($conn, 'QUIT');
    fclose($conn);
  }
}

// [7] Action handlers --------------------------------------------------------

// Resolve the IMAP/SMTP host for an email address. Uses MAIL_SERVER when set,
// otherwise derives from the address domain (imap./smtp. prefix).
// Known IMAP/SMTP server presets for common providers.
function server_presets(): array
{
  return [
    'gmail'   => [
      'label' => 'Gmail',
      'imap' => ['server' => 'imap.gmail.com',       'port' => 993, 'enc' => 'ssl'],
      'smtp' => ['server' => 'smtp.gmail.com',       'port' => 587, 'enc' => 'tls']
    ],
    'yahoo'   => [
      'label' => 'Yahoo Mail',
      'imap' => ['server' => 'imap.mail.yahoo.com',  'port' => 993, 'enc' => 'ssl'],
      'smtp' => ['server' => 'smtp.mail.yahoo.com',  'port' => 587, 'enc' => 'tls']
    ],
    'outlook' => [
      'label' => 'Outlook / Office 365',
      'imap' => ['server' => 'outlook.office365.com', 'port' => 993, 'enc' => 'ssl'],
      'smtp' => ['server' => 'smtp-mail.outlook.com', 'port' => 587, 'enc' => 'tls']
    ],
    'icloud'  => [
      'label' => 'iCloud Mail',
      'imap' => ['server' => 'imap.mail.me.com',     'port' => 993, 'enc' => 'ssl'],
      'smtp' => ['server' => 'smtp.mail.me.com',     'port' => 587, 'enc' => 'tls']
    ],
    'migadu'  => [
      'label' => 'Migadu',
      'imap' => ['server' => 'imap.migadu.com',      'port' => 993, 'enc' => 'ssl'],
      'smtp' => ['server' => 'smtp.migadu.com',      'port' => 587, 'enc' => 'tls']
    ],
  ];
}

// Resolve a concrete IMAP/SMTP config from login form input. Accepts either a
// preset key (imap/smtp values looked up) or explicit custom fields. Blank
// server falls back to the address domain with default ports/encryption.
function resolve_mail_config(array $post, string $email): array
{
  $presets = server_presets();
  $cfg = [
    'imap' => ['server' => '', 'port' => MAIL_IMAP_PORT, 'enc' => IMAP_ENCRYPTION],
    'smtp' => ['server' => '', 'port' => MAIL_SMTP_PORT, 'enc' => SMTP_ENCRYPTION],
  ];

  $preset = strtolower(trim($post['preset'] ?? ''));
  if ($preset !== '' && isset($presets[$preset])) {
    $cfg['imap'] = $presets[$preset]['imap'];
    $cfg['smtp'] = $presets[$preset]['smtp'];
  } else {
    // Custom fields.
    $imapServer = trim($post['imap_server'] ?? MAIL_SERVER);
    $smtpServer = trim($post['smtp_server'] ?? MAIL_SERVER);
    if ($imapServer !== '') {
      $cfg['imap']['server'] = $imapServer;
      $cfg['imap']['port']   = (int)($post['imap_port'] ?? MAIL_IMAP_PORT);
      $cfg['imap']['enc']    = ($post['imap_enc'] ?? IMAP_ENCRYPTION) ?: IMAP_ENCRYPTION;
    }
    if ($smtpServer !== '') {
      $cfg['smtp']['server'] = $smtpServer;
      $cfg['smtp']['port']   = (int)($post['smtp_port'] ?? MAIL_SMTP_PORT);
      $cfg['smtp']['enc']    = ($post['smtp_enc'] ?? SMTP_ENCRYPTION) ?: SMTP_ENCRYPTION;
    }
  }

  foreach (['imap', 'smtp'] as $proto) {
    if ($cfg[$proto]['server'] === '') {
      $cfg[$proto]['server'] = mail_host_for($email);
    }
  }
  return $cfg;
}

// The operator's server lock. EMMMAIL_PROVIDER pins a preset; EMMMAIL_IMAP_HOST
// and EMMMAIL_SMTP_HOST pin a host pair (EMMMAIL_MAIL_SERVER fills whichever is
// blank) on the env ports and encryption. A lock that cannot be resolved fails
// closed: still locked, no config, and an error that refuses every sign-in.
// Read at call time, like tls_ssl_options(), so the tests can putenv().
function server_lock(): array
{
  $provider = strtolower(trim((string)getenv('EMMMAIL_PROVIDER')));
  $base     = trim((string)getenv('EMMMAIL_MAIL_SERVER'));
  $imapEnv  = trim((string)getenv('EMMMAIL_IMAP_HOST'));
  $smtpEnv  = trim((string)getenv('EMMMAIL_SMTP_HOST'));
  $imapHost = $imapEnv !== '' ? $imapEnv : $base;
  $smtpHost = $smtpEnv !== '' ? $smtpEnv : $base;

  if ($provider === '' && $imapHost === '' && $smtpHost === '') {
    return ['locked' => false, 'error' => '', 'config' => null];
  }
  $broken = ['locked' => true, 'config' => null,
    'error' => 'Sign-in is unavailable: the mail server is not configured correctly. Please contact your administrator.'];

  if ($provider !== '') {
    $presets = server_presets();
    if (!isset($presets[$provider]) || $imapHost !== '' || $smtpHost !== '') {
      return $broken;
    }
    return ['locked' => true, 'error' => '',
      'config' => ['imap' => $presets[$provider]['imap'], 'smtp' => $presets[$provider]['smtp']]];
  }
  if ($imapHost === '' || $smtpHost === '') {
    return $broken;
  }
  return ['locked' => true, 'error' => '', 'config' => [
    'imap' => ['server' => $imapHost, 'port' => MAIL_IMAP_PORT, 'enc' => IMAP_ENCRYPTION],
    'smtp' => ['server' => $smtpHost, 'port' => MAIL_SMTP_PORT, 'enc' => SMTP_ENCRYPTION],
  ]];
}

// EMMMAIL_ALLOWED_DOMAINS, split on commas or whitespace, lowercased, a leading
// "@" tolerated. Empty means every domain may sign in.
function allowed_domains(): array
{
  $out = [];
  foreach (preg_split('/[\s,]+/', strtolower((string)getenv('EMMMAIL_ALLOWED_DOMAINS'))) as $d) {
    $d = ltrim($d, '@');
    if ($d !== '') $out[$d] = true;
  }
  return array_keys($out);
}

// Exact domain match on the part after the last "@"; a subdomain is not the domain.
function email_domain_allowed(string $email): bool
{
  $domains = allowed_domains();
  if (!$domains) return true;
  $at = strrpos($email, '@');
  if ($at === false) return false;
  return in_array(strtolower(trim(substr($email, $at + 1))), $domains, true);
}

// The refusals handle_login() makes before the rate limiter and before any IMAP
// connection: a lock that cannot be resolved, or a domain off the list. Returns
// '' when the attempt may go ahead.
function login_gate(string $email): string
{
  $lock = server_lock();
  if ($lock['error'] !== '') {
    return $lock['error'];
  }
  if (!email_domain_allowed($email)) {
    $names = array_map(fn($d) => '@' . $d, allowed_domains());
    $last = array_pop($names);
    return 'Only ' . ($names ? implode(', ', $names) . ' or ' : '') . $last . ' accounts can sign in here.';
  }
  return '';
}

// Config stored for the current session (set on successful login).
function session_mail_config(): array
{
  return $_SESSION['mail_config'] ?? [];
}

function imap_config(): array
{
  $c = session_mail_config();
  return $c['imap'] ?? ['server' => '', 'port' => MAIL_IMAP_PORT, 'enc' => IMAP_ENCRYPTION];
}

function smtp_config(): array
{
  $c = session_mail_config();
  return $c['smtp'] ?? ['server' => '', 'port' => MAIL_SMTP_PORT, 'enc' => SMTP_ENCRYPTION];
}

function mail_host_for(string $email): string
{
  if (MAIL_SERVER !== '') {
    return MAIL_SERVER;
  }
  $domain = substr(strrchr($email, '@'), 1) ?: '';
  return $domain !== '' ? $domain : 'localhost';
}

function require_login(): void
{
  if (empty($_SESSION['logged_in'])) {
    redirect('?page=login');
  }
}

function current_user(): ?string
{
  return $_SESSION['email'] ?? null;
}

// Build an IMAP connection for the logged-in user (or fail to login).
function imap_for_session(): ?MailImap
{
  if (empty($_SESSION['email']) || empty($_SESSION['pass'])) {
    return null;
  }
  $email = $_SESSION['email'];
  $pass  = session_decrypt($_SESSION['pass']);
  $ic    = imap_config();
  $imap  = new MailImap($ic['server'], $ic['port'], $email, $pass, $ic['enc']);
  if (!$imap->isConnected()) {
    flash_set('error', 'Unable to connect to the mail server: ' . $imap->lastError);
    redirect('?page=logout');
  }
  // Apply user-defined folder role mapping when available.
  $folderMap = json_decode((string)db_get_setting($email, 'protected_folders', '{}'), true);
  if (is_array($folderMap) && $folderMap !== []) {
    $imap->setFolderMap($folderMap);
  }
  return $imap;
}

function user_setting(string $key, $default = null)
{
  $email = current_user();
  if (!$email) return $default;
  return db_get_setting($email, $key, $default);
}

// Auto-detect the folder role map from the IMAP server's actual names. This is
// the single source of truth for pre-filling the folder-map modal and the
// onboarding dialog. Reuses the find*Folder() keyword lists -- no duplicates.
// The result is cached per session so the render path costs one connection.
function detect_folder_map(?MailImap $imap = null): array
{
  if (isset($_SESSION['detected_folder_map']) && is_array($_SESSION['detected_folder_map'])) {
    return $_SESSION['detected_folder_map'];
  }
  $imap = $imap ?? imap_for_session();
  if (!$imap) {
    return [];
  }
  $map = [];
  $detected = [
    'sent'    => $imap->findSentFolder(),
    'drafts'  => $imap->findDraftsFolder(),
    'spam'    => $imap->findJunkFolder(),
    'trash'   => $imap->findTrashFolder(),
    'archive' => $imap->findArchiveFolder(),
  ];
  foreach ($detected as $role => $folder) {
    if ($folder !== null) {
      $map[$role] = $folder;
    }
  }
  $_SESSION['detected_folder_map'] = $map;
  return $map;
}

// Whether the folder-map onboarding is still owed for this account. Pure
// besides the DB read; render_page() uses it to decide on every page load.
function onboarding_pending(string $email, string $page = ''): bool
{
  return $page !== 'saveOnboarding'
    && db_get_setting($email, 'folder_setup_complete', '0') !== '1';
}

// Validate a decoded protected-folder map: only the five role keys, string
// values, non-empty, case-insensitive. A JSON array (numeric keys) is not a
// map and collapses to nothing.
function folder_map_clean(array $decoded): array
{
  $allowed = ['sent', 'drafts', 'spam', 'trash', 'archive'];
  $clean = [];
  foreach ($decoded as $k => $v) {
    if (
      is_string($k) && in_array(strtolower($k), $allowed, true)
      && is_string($v) && $v !== ''
    ) {
      $clean[strtolower($k)] = $v;
    }
  }
  return $clean;
}

// The signature as a body prefix: it goes ABOVE whatever the composer already
// filled in (quote, forward), or nothing at all when the user set none. The
// quote/forward builder supplies the single blank line under it, so this must
// not add one too — that is what left a gap between the signature and the quote.
function signature_prefix(): string
{
  return trim((string)user_setting('signature', ''));
}

// Get client IP for rate limiting.
function client_ip(): string
{
  $ip = $_SERVER['REMOTE_ADDR'] ?? '';
  foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP'] as $h) {
    if (!empty($_SERVER[$h])) {
      $first = trim(explode(',', $_SERVER[$h])[0]);
      if (filter_var($first, FILTER_VALIDATE_IP)) {
        $ip = $first;
        break;
      }
    }
  }
  return $ip ?: '0.0.0.0';
}

// --- Login / Logout ---

function handle_login(): void
{
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
      flash_set('error', 'Security token invalid. Please try again.');
      redirect('?page=login');
    }
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($email === '' || $password === '') {
      flash_set('error', 'Enter your email and password.');
      render_login();
      exit;
    }

    $gate = login_gate($email);
    if ($gate !== '') {
      flash_set('error', $gate);
      render_login();
      exit;
    }

    $cfg = server_lock()['config'] ?? resolve_mail_config($_POST, $email);
    $imapCfg = $cfg['imap'];

    // Brute-force protection.
    $ip = client_ip();
    $failures = db_count_recent_failures($ip);
    if ($failures >= LOGIN_MAX_FAILURES) {
      sleep(rate_limit_sleep_seconds(min($failures, 15)));
      flash_set('error', 'Too many failed attempts. Please wait and try again.');
      redirect('?page=login');
    }

    $imap = new MailImap($imapCfg['server'], $imapCfg['port'], $email, $password, $imapCfg['enc']);
    if ($imap->isConnected()) {
      $imap->disconnect();
      // Session fixation protection: new session id after auth.
      session_regenerate_id(true);
      $_SESSION['logged_in'] = true;
      $_SESSION['email']     = $email;
      $_SESSION['pass']      = session_encrypt($password);
      $_SESSION['mail_config'] = $cfg;
      // Persist the config so the next login can prefill it.
      foreach (
        [
          'imap_server' => $cfg['imap']['server'],
          'imap_port' => $cfg['imap']['port'],
          'imap_enc' => $cfg['imap']['enc'],
          'smtp_server' => $cfg['smtp']['server'],
          'smtp_port' => $cfg['smtp']['port'],
          'smtp_enc' => $cfg['smtp']['enc']
        ] as $k => $v
      ) {
        db_set_setting($email, $k, $v);
      }
      db_record_login_attempt($ip, true);
      db_prune_cache($email);
      db_cleanup_login_attempts();
      flash_set('success', 'Logged in successfully.');
      redirect('?page=inbox');
    }
    db_record_login_attempt($ip, false);
    $errDetail = trim($imap->lastError);
    if ($errDetail !== '' && $errDetail !== 'Invalid login or unable to connect.') {
      $msg = 'Unable to connect to ' . h($imapCfg['server']) . ': ' . h($errDetail);
    } else {
      $msg = 'Invalid login or unable to connect.';
    }
    flash_set('error', $msg);
    render_login();
    exit;
  }

  // GET: show login form.
  render_login();
}

function handle_logout(): void
{
  db_purge_cache_for_email(current_user() ?? '');
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();
  redirect('?page=login');
}

// --- Inbox / Folder ---

function handle_inbox(): void
{
  render_folder_listing('INBOX', 'Inbox');
}

function handle_folder(): void
{
  require_login();
  $folder = urldecode($_GET['folder'] ?? 'INBOX');
  // Same name the sidebar shows, so the heading and the active entry agree.
  $title = folder_display_name($folder);
  // The in-place folder switch fetches this page with ajax=1 and swaps only the
  // mailbox section into the already-open listing.
  if (!empty($_GET['ajax'])) {
    json_out(folder_listing_json($folder, $title));
  }
  render_folder_listing($folder, $title);
}

// Read the listing parameters and assemble the folder-view payload. Shared by
// the full render and the ajax path, so both show exactly the same mailbox.
function folder_listing_data(string $folder): array
{
  $page    = max(1, (int)($_GET['p'] ?? 1));
  $perPage = (int)(user_setting('emails_per_page', DEFAULT_EMAILS_PER_PAGE));
  $me      = current_user();
  return folder_view_data(
    'imap_for_session',
    $me,
    $folder,
    $page,
    $perPage,
    [
      'window'        => SYNC_WINDOW,
      'full_interval' => SYNC_FULL_INTERVAL_SECONDS,
      // No attachment backfill on the render path: it is the slowest part of a
      // cold sync and ?page=sync picks it up immediately after the page paints.
      'backfill'      => 0,
    ]
  );
}

// The render payload render_inbox()/view_mailbox() expect, for one folder.
function folder_listing_view_data(array $data, string $folder, string $title): array
{
  return [
    'emails'         => $data['emails'],
    'total'          => $data['total'],
    'pages'          => $data['pages'],
    'current_page'   => $data['current_page'],
    'current_folder' => $folder,
    'title'          => $title,
    'is_sent'        => MailImap::isSentFolder($folder),
    'is_trash'       => MailImap::isTrashFolder($folder),
    'is_junk'        => MailImap::isJunkFolder($folder),
    'is_drafts'      => MailImap::isDraftsFolder($folder),
    'threads'        => !empty($data['threads']),
    'folders'        => $data['folders'],
    'folder_map'     => json_decode((string)user_setting('protected_folders', '{}'), true) ?: null,
    'search_query'   => '',
    'last_sync_ts'   => $data['last_sync_ts'],
  ];
}

// The ajax=1 answer: the mailbox section alone, plus the page title (so JS can
// keep <title> in step) and any sync error to float as a toast.
function folder_listing_json(string $folder, string $title): array
{
  $data = folder_listing_data($folder);
  return [
    'ok'    => true,
    'html'  => view_mailbox(folder_listing_view_data($data, $folder, $title)),
    'title' => $title . ' — ' . APP_NAME,
    'error' => $data['error'],
  ];
}

// Shared listing path for the inbox and every other folder. Reads the header
// cache, so a warm render performs no IMAP work at all: the connection is only
// built if the cache cannot answer.
function render_folder_listing(string $folder, string $title): void
{
  require_login();
  $data = folder_listing_data($folder);
  if ($data['error']) {
    flash_set('error', $data['error']);
  }

  render_inbox(folder_listing_view_data($data, $folder, $title));
}

// --- Read ---

function handle_read(): void
{
  require_login();
  [$uid, $folder] = message_target_from_request();
  if (!$uid) {
    redirect('?page=inbox');
  }
  $imap = imap_for_session();
  $me = current_user();
  $imap->selectFolder($folder);
  $email = $imap->getEmailByUid($uid);
  if (!$email) {
    flash_set('error', 'Message not found.');
    redirect('?page=folder&folder=' . urlencode($folder));
  }
  $imap->markAsReadByUid($uid);
  // Keep the cache in step, so the row is not still bold when the user goes
  // back to the list. Without this it stays unread until the next sync.
  cache_mark_read($me, $folder, $uid, true);
  db_set_message_snippet($me, $folder, $uid, mb_substr(trim(strip_tags($email['body_text'])), 0, 500));
  // Remote images: the global setting, auto-show setting, this sender's
  // standing trust, or a one-off "&images=1" for this view alone.
  $sender = ($email['from_address'] ?? '') ?: ($email['from'] ?? '');
  $imagesOn = user_setting('show_remote_images', DEFAULT_SHOW_REMOTE_IMAGES) === '1';
  $whitelist = (string)user_setting('image_whitelist', '');
  $showOnce = !empty($_GET['images']);
  render_read($email, $uid, $folder, (string)$me, [
    'block_images' => !$showOnce && !images_allowed($sender, $imagesOn, $whitelist),
  ]);
}

// Open a conversation. Its cached members are gathered across their own folders,
// the newest THREAD_MAX_MESSAGES are loaded, every unread member is marked read
// in one batch per folder, and the thread renders oldest-first.
function handle_thread(): void
{
  require_login();
  $key    = (string)($_GET['key'] ?? '');
  $folder = urldecode($_GET['folder'] ?? 'INBOX');
  $me     = current_user();
  $stored = thread_key_is_safe($key) ? db_thread_members($me, $key) : [];
  if (!$stored) {
    flash_set('error', 'Conversation not found.');
    redirect('?page=folder&folder=' . urlencode($folder));
  }

  $imap     = imap_for_session();
  $imagesOn = user_setting('show_remote_images', DEFAULT_SHOW_REMOTE_IMAGES) === '1';
  $whitelist = (string)user_setting('image_whitelist', '');
  $showOnce = !empty($_GET['images']);

  // Only the newest cap is fetched, so a 300-message conversation cannot cost
  // 300 bodies. The rest are still marked read below, one command per folder.
  $omitted = max(0, count($stored) - THREAD_MAX_MESSAGES);
  $fetch   = $omitted > 0 ? array_slice($stored, -THREAD_MAX_MESSAGES) : $stored;

  $messages = [];
  $unreadByFolder = [];
  $current = null;
  foreach ($fetch as $row) {
    $mf = (string)$row['folder'];
    if (!imap_folder_name_is_safe($mf)) continue;
    if ($mf !== $current) {
      if (!$imap->selectFolder($mf)) {
        $current = null;
        continue;
      }
      $current = $mf;
    }
    $uid = (int)$row['uid'];
    $email = $imap->getEmailByUid($uid);
    if (!$email) continue;
    $sender = ($email['from_address'] ?? '') ?: ($email['from'] ?? '');
    $messages[] = [
      'email'        => $email,
      'uid'          => $uid,
      'folder'       => $mf,
      'block_images' => !$showOnce && !images_allowed($sender, $imagesOn, $whitelist),
    ];
    db_set_message_snippet($me, $mf, $uid, mb_substr(trim(strip_tags((string)($email['body_text'] ?? ''))), 0, 500));
  }

  // Mark every unread member read (not only the fetched ones), and keep the
  // cache in step so the conversation's listing row is not still bold on return.
  foreach ($stored as $row) {
    if (!empty($row['unread'])) $unreadByFolder[(string)$row['folder']][] = (int)$row['uid'];
  }
  foreach ($unreadByFolder as $mf => $uids) {
    if (!imap_folder_name_is_safe((string)$mf)) continue;
    if (!$imap->selectFolder((string)$mf)) continue;
    $imap->markManyAsRead($uids, true);
    foreach ($uids as $uid) cache_mark_read($me, (string)$mf, $uid, true);
  }

  if (!$messages) {
    flash_set('error', 'Conversation not found.');
    redirect('?page=folder&folder=' . urlencode($folder));
  }
  render_thread($messages, $folder, (string)$me, $omitted);
}

// --- Compose / Reply / Forward / Send ---

function handle_compose(): void
{
  require_login();
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    handle_send();
    return;
  }
  $draftUid = (int)($_GET['draft'] ?? 0);
  if ($draftUid > 0) {
    handle_edit_draft($draftUid);
    return;
  }
  render_compose([
    'to'          => '',
    'subject'     => '',
    'body'        => (string)user_setting('signature', ''),
    'cc'          => '',
    'bcc'         => '',
    'forward_attachments' => [],
    'undo_period_seconds' => (int)user_setting('undo_period_seconds', 0),
    'csrf'        => csrf_page_token(),
  ]);
}

// Continue a saved draft. The stored copy stays where it is until the draft is
// saved again or sent, which is also what lets its attachments be re-fetched.
function handle_edit_draft(int $uid): void
{
  $imap = imap_for_session();
  $drafts = $imap->findDraftsFolder();
  if (!$drafts || !$imap->selectFolder($drafts)) {
    flash_set('error', 'No Drafts folder on the server.');
    redirect('?page=inbox');
  }
  $email = $imap->getEmailByUid($uid);
  if (!$email) {
    flash_set('error', 'Draft not found.');
    redirect('?page=folder&folder=' . urlencode($drafts));
  }
  // Attachments ride along through the existing forward mechanism, so they are
  // re-read from this copy when the draft is saved again or sent.
  if (!empty($email['attachments'])) {
    $_SESSION['forward_src'] = [
      'uid' => $uid,
      'folder' => $drafts,
      'parts' => array_column($email['attachments'], 'part_number')
    ];
  } else {
    unset($_SESSION['forward_src']);
  }
  render_compose([
    // Headers come back as "Name <a@b.c>", which the send path would reject.
    'to'          => header_address_list($email['to']),
    'subject'     => $email['subject'] === '(No subject)' ? '' : $email['subject'],
    // A text part is taken as Markdown: Emmmail writes its drafts that way.
    // An HTML-only draft came from another client, so it is text and escaped.
    'body'        => $email['body_text'] !== '' ? $email['body_text'] : md_escape(quote_source_text($email)),
    'cc'          => header_address_list($email['cc']),
    'bcc'         => header_address_list($email['bcc'] ?? ''),
    'draft_uid'   => $uid,
    'in_reply_to' => $email['in_reply_to'] ?? '',
    'references'  => $email['references'] ?? '',
    'forward_attachments' => $email['attachments'],
    'undo_period_seconds' => (int)user_setting('undo_period_seconds', 0),
    'csrf'        => csrf_page_token(),
  ]);
}

function handle_reply(): void
{
  render_reply_form(false);
}

function handle_reply_all(): void
{
  render_reply_form(true);
}

// Shared by reply and reply-all: identical but for who ends up in To and Cc.
function render_reply_form(bool $all): void
{
  require_login();
  [$uid, $folder] = message_target_from_request();
  if (!$uid) {
    redirect('?page=inbox');
  }
  $imap = imap_for_session();
  $imap->selectFolder($folder);
  $email = $imap->getEmailByUid($uid);
  if (!$email) {
    redirect('?page=inbox');
  }
  render_compose(reply_form_data($email, (string)current_user(), $all) + [
    'undo_period_seconds' => (int)user_setting('undo_period_seconds', 0),
    'csrf' => csrf_page_token(),
  ]);
}

function handle_forward(): void
{
  require_login();
  [$uid, $folder] = message_target_from_request();
  if (!$uid) {
    redirect('?page=inbox');
  }
  $imap = imap_for_session();
  $imap->selectFolder($folder);
  $email = $imap->getEmailByUid($uid);
  if (!$email) {
    redirect('?page=inbox');
  }
  $sigPrefix = signature_prefix();
  $quote = forward_quote($email);
  $subject = (stripos($email['subject'], 'Fwd:') === 0) ? $email['subject'] : 'Fwd: ' . $email['subject'];
  // Store forward source metadata server-side for the send step.
  $_SESSION['forward_src'] = ['uid' => $uid, 'folder' => $folder, 'parts' => array_column($email['attachments'], 'part_number')];
  render_compose([
    'to'          => '',
    'subject'     => $subject,
    'body'        => $sigPrefix . $quote,
    'cc'          => '',
    'bcc'         => '',
    'forward_attachments' => $email['attachments'],
    'undo_period_seconds' => (int)user_setting('undo_period_seconds', 0),
    'csrf'        => csrf_page_token(),
  ]);
}

/**
 * Collect the attachments for a compose POST: uploaded files plus any carried
 * over from another message (a forward, or the previous copy of a draft).
 *
 * The carry-over source is read from the session, never from the POST, so the
 * request cannot name an arbitrary message to read. On any problem this flashes
 * and redirects back to the composer, which is why both callers can treat the
 * return value as final.
 */
function compose_gather_attachments(MailImap $imap): array
{
  $attachments = [];
  if (!empty($_FILES['attachments']['name'][0])) {
    foreach ($_FILES['attachments']['name'] as $i => $name) {
      if (trim((string)$name) === '') {
        continue;
      }
      $error = $_FILES['attachments']['error'][$i];
      $tmp   = $_FILES['attachments']['tmp_name'][$i];
      $size  = (int)$_FILES['attachments']['size'][$i];
      if ($error !== UPLOAD_ERR_OK) {
        flash_set('error', "Upload failed for '$name'.");
        redirect('?page=compose');
      }
      if ($size > MAX_ATTACHMENT_SIZE) {
        flash_set('error', "Attachment '$name' exceeds the size limit.");
        redirect('?page=compose');
      }
      $safeTmp = tempnam(sys_get_temp_dir(), 'emmmail_');
      if (!move_uploaded_file($tmp, $safeTmp)) {
        flash_set('error', "Could not process '$name'.");
        redirect('?page=compose');
      }
      $data = file_get_contents($safeTmp);
      @unlink($safeTmp);
      if ($data === false || $data === '') {
        flash_set('error', "Attachment '$name' is empty.");
        redirect('?page=compose');
      }
      $attachments[] = ['name' => $name, 'data' => $data];
    }
  }

  $total = array_sum(array_map('strlen', array_column($attachments, 'data')));
  if (!empty($_SESSION['forward_src']) && !empty($_POST['forward_parts'])) {
    $fs = $_SESSION['forward_src'];
    $fs['parts'] = array_intersect($fs['parts'], (array)$_POST['forward_parts']);
    // Read before the caller deletes anything: for a draft this IS the copy
    // about to be replaced, and its attachments would be gone otherwise.
    $imap->selectFolder($fs['folder']);
    foreach ($fs['parts'] as $part) {
      $att = $imap->getAttachmentByUid((int)$fs['uid'], $part);
      if ($att && strlen($att['data']) > 0) {
        $attachments[] = ['name' => $att['filename'], 'data' => $att['data']];
        $total += strlen($att['data']);
      }
    }
  }
  unset($_SESSION['forward_src']);

  if ($total > MAX_ATTACHMENT_SIZE * 2) {
    flash_set('error', 'Total attachment size exceeds the limit.');
    redirect('?page=compose');
  }
  return $attachments;
}

// Save the composed message to the Drafts folder. IMAP has no update, so an
// existing draft is replaced: append the new copy, then delete the old one.
function handle_save_draft(): void
{
  require_login();
  if (detect_post_overflow(parse_php_size((string)ini_get('post_max_size')))) {
    flash_set('error', 'Message too large (exceeds server upload limit).');
    redirect('?page=compose');
  }
  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Security token invalid.');
    redirect('?page=compose');
  }
  $me       = current_user();
  $to       = trim($_POST['to'] ?? '');
  $subject  = trim($_POST['subject'] ?? '');
  $body     = (string)($_POST['body'] ?? '');
  $cc       = trim($_POST['cc'] ?? '');
  $bcc      = trim($_POST['bcc'] ?? '');
  $draftUid = (int)($_POST['draft_uid'] ?? 0);
  // A draft of a reply must stay a reply: without these the thread is lost the
  // moment the draft is saved.
  $inReplyTo  = trim($_POST['in_reply_to_id'] ?? '');
  $references = trim($_POST['references'] ?? '');

  // Deliberately laxer than sending: an unfinished draft is the normal case.
  // Only a message with nothing in it at all is refused.
  if (
    $to === '' && $subject === '' && trim($body) === '' && $cc === '' && $bcc === ''
    && empty($_FILES['attachments']['name'][0])
  ) {
    flash_set('error', 'Nothing to save.');
    redirect('?page=compose');
  }

  $imap = imap_for_session();
  $drafts = $imap->findDraftsFolder();
  if (!$drafts) {
    flash_set('error', 'No Drafts folder on the server.');
    redirect('?page=compose');
  }

  $attachments = compose_gather_attachments($imap);

  $fromName = user_setting('display_name', strstr($me, '@', true) ?: $me);
  $raw = smtp_build_raw_message($me, $to, $subject, md_email_html($body), $fromName, $attachments, $cc, $bcc, $inReplyTo, $references, textBody: $body);
  // A draft keeps its Bcc: it is not in transit, and the addresses must still be
  // there when the draft is finally sent.
  if (!$imap->appendToFolder($drafts, smtp_raw_with_bcc($raw, $bcc), '\\Draft \\Seen')) {
    flash_set('error', 'Could not save the draft.');
    redirect('?page=compose');
  }

  // Only now that the new copy exists is the old one expendable.
  if ($draftUid > 0 && $imap->selectFolder($drafts)) {
    $imap->deleteEmailByUid($draftUid);
    db_delete_messages($me, $drafts, [$draftUid]);
  }
  cache_invalidate_folder($me, $drafts);

  flash_set('success', 'Draft saved.');
  redirect('?page=folder&folder=' . urlencode($drafts));
}

function handle_send(): void
{
  if (detect_post_overflow(parse_php_size((string)ini_get('post_max_size')))) {
    flash_set('error', 'Message too large (exceeds server upload limit).');
    redirect('?page=compose');
  }
  if (!csrf_verify($_POST['csrf_token'] ?? '')) {
    flash_set('error', 'Security token invalid.');
    redirect('?page=compose');
  }
  $me = current_user();

  $to = trim($_POST['to'] ?? '');
  $subject = trim($_POST['subject'] ?? '');
  $body = (string)($_POST['body'] ?? '');
  $cc = trim($_POST['cc'] ?? '');
  $bcc = trim($_POST['bcc'] ?? '');
  $inReplyTo = trim($_POST['in_reply_to_id'] ?? '');
  $references = trim($_POST['references'] ?? '');

  if ($to === '' || $subject === '') {
    flash_set('error', 'Recipient and subject are required.');
    redirect('?page=compose');
  }
  $extra = array_merge(
    $cc !== '' ? explode(',', $cc) : [],
    $bcc !== '' ? explode(',', $bcc) : []
  );
  foreach (array_merge([$to], $extra) as $addr) {
    if (trim($addr) !== '' && !filter_var(trim($addr), FILTER_VALIDATE_EMAIL)) {
      flash_set('error', 'Invalid email address: ' . h(trim($addr)));
      redirect('?page=compose');
    }
  }

  // Connect only once the request is known to be valid: a rejected compose
  // should not pay a full IMAP login. Kept above smtp_send() on purpose --
  // imap_for_session() redirects to logout on failure, and below the send that
  // would log the user out after the mail had already gone.
  $imap = imap_for_session();

  $attachments = compose_gather_attachments($imap);

  // Build + send via SMTP.
  $sc = smtp_config();
  $smtp = smtp_connect($sc['server'], $sc['port'], $me, session_decrypt($_SESSION['pass']), $smtpErr, $sc['enc']);
  if (!$smtp) {
    flash_set('error', 'SMTP connection failed: ' . $smtpErr);
    redirect('?page=compose');
  }

  $fromName = user_setting('display_name', strstr($me, '@', true) ?: $me);
  // $bcc is passed for completeness but never becomes a header: the blind
  // recipients are in the envelope below, and in the Sent copy further down.
  $raw = smtp_build_raw_message($me, $to, $subject, md_email_html($body), $fromName, $attachments, $cc, $bcc, $inReplyTo, $references, textBody: $body);
  $recipients = array_merge([$to], $extra);

  // Undo send: when enabled, hold the message in the session for a configurable
  // period so the user can cancel and edit before it actually goes out.
  $undoPeriod = (int)user_setting('undo_period_seconds', 0);
  if ($undoPeriod > 0) {
    $token = bin2hex(random_bytes(16));
    $_SESSION['undo_queue'][$token] = [
      'raw'         => $raw,
      'recipients'  => $recipients,
      'bcc'         => $bcc,
      'me'          => $me,
      'sent_folder' => null, // resolved on flush
      'expires'     => time() + $undoPeriod,
      'draft_uid'   => (int)($_POST['draft_uid'] ?? 0),
    ];
    if (request_wants_json($_POST, $_SERVER)) {
      json_out(['ok' => true, 'undo' => true, 'token' => $token, 'seconds' => $undoPeriod]);
    }
    flash_set('success', 'Message queued. You have ' . $undoPeriod . ' seconds to undo.');
    redirect('?page=inbox');
  }

  $ok = smtp_send($smtp, $me, $recipients, $raw, $smtpErr);
  smtp_disconnect($smtp);

  if (!$ok) {
    flash_set('error', 'Failed to send: ' . $smtpErr);
    redirect('?page=compose');
  }

  // Record recipients as contacts + append to Sent.
  $imap->selectFolder('INBOX');
  foreach ($recipients as $addr) {
    $addr = trim($addr);
    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
      db_upsert_contact($me, $addr, $addr, true);
    }
  }
  $sent = $imap->findSentFolder();
  if ($sent) {
    // Only the filed copy records the blind recipients.
    $imap->appendToFolder($sent, smtp_raw_with_bcc($raw, $bcc), '\\Seen');
    // The new message is on the server but not in our cache; make Sent resync.
    cache_invalidate_folder($me, $sent);
  }

  // The message is away, so the draft it came from is finished. Done after the
  // send, never before: a failed send must leave the draft intact.
  $draftUid = (int)($_POST['draft_uid'] ?? 0);
  if ($draftUid > 0) {
    $drafts = $imap->findDraftsFolder();
    if ($drafts && $imap->selectFolder($drafts)) {
      $imap->deleteEmailByUid($draftUid);
      db_delete_messages($me, $drafts, [$draftUid]);
      cache_invalidate_folder($me, $drafts);
    }
  }

  flash_set('success', 'Message sent successfully.');
  redirect('?page=folder&folder=' . urlencode($sent ?: 'INBOX'));
}

// Cancel a queued undo-send message. The token identifies the entry in the
// session's undo_queue.
function handle_undo_send(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Invalid request.']);
  }
  $token = trim($_POST['token'] ?? '');
  if ($token === '' || !isset($_SESSION['undo_queue'][$token])) {
    json_out(['ok' => false, 'error' => 'Message not found or already sent.']);
  }
  unset($_SESSION['undo_queue'][$token]);
  json_out(['ok' => true]);
}

// Flush an undo-send message: actually send it after the countdown expires.
function handle_flush_undo(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Invalid request.']);
  }
  $token = trim($_POST['token'] ?? '');
  if ($token === '' || !isset($_SESSION['undo_queue'][$token])) {
    json_out(['ok' => false, 'error' => 'Message not found or already sent.']);
  }
  $entry = $_SESSION['undo_queue'][$token];
  unset($_SESSION['undo_queue'][$token]);

  // Expired? Ignore silently — the window already passed.
  if (time() > (int)$entry['expires']) {
    json_out(['ok' => true, 'expired' => true]);
  }

  $me = $entry['me'];
  $sc = smtp_config();
  $smtp = smtp_connect($sc['server'], $sc['port'], $me, session_decrypt($_SESSION['pass']), $smtpErr, $sc['enc']);
  if (!$smtp) {
    json_out(['ok' => false, 'error' => 'SMTP connection failed: ' . $smtpErr]);
  }
  $ok = smtp_send($smtp, $me, $entry['recipients'], $entry['raw'], $smtpErr);
  smtp_disconnect($smtp);
  if (!$ok) {
    json_out(['ok' => false, 'error' => 'Failed to send: ' . $smtpErr]);
  }

  // Record recipients + append to Sent.
  $imap = imap_for_session();
  $imap->selectFolder('INBOX');
  foreach ($entry['recipients'] as $addr) {
    $addr = trim($addr);
    if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
      db_upsert_contact($me, $addr, $addr, true);
    }
  }
  $sent = $imap->findSentFolder();
  if ($sent) {
    $imap->appendToFolder($sent, smtp_raw_with_bcc($entry['raw'], $entry['bcc']), '\\Seen');
    cache_invalidate_folder($me, $sent);
  }

  // Clean up the draft if one existed.
  $draftUid = (int)($entry['draft_uid'] ?? 0);
  if ($draftUid > 0) {
    $drafts = $imap->findDraftsFolder();
    if ($drafts && $imap->selectFolder($drafts)) {
      $imap->deleteEmailByUid($draftUid);
      db_delete_messages($me, $drafts, [$draftUid]);
      cache_invalidate_folder($me, $drafts);
    }
  }

  json_out(['ok' => true]);
}

// --- Delete / Restore / Empty Trash ---

function handle_delete(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $uids = post_uids();
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  if (!imap_folder_name_is_safe($folder)) {
    respond_mutation(false, 'Invalid folder name.', $folder);
  }
  if (empty($uids)) {
    respond_mutation(true, 'No messages given.', $folder, 0);
  }
  $imap = imap_for_session();
  // A UID delete against the wrong mailbox would destroy someone else's mail.
  if (!$imap->selectFolder($folder)) {
    respond_mutation(false, 'Could not open the folder.', $folder);
  }
  $me = current_user();
  // Already in Trash means the user asked for the permanent one.
  if (MailImap::isTrashFolder($folder)) {
    $count = $imap->deleteManyByUid($uids);
    if ($count > 0) {
      foreach ($uids as $uid) {
        cache_apply_move($me, $folder, $uid, null);
      }
      cache_invalidate_folder($me, $folder);
    }
    respond_mutation($count > 0 || count($uids) === 0, $count > 0 ? 'Messages permanently deleted.' : 'Could not delete messages.', $folder, $count);
  }
  $count = $imap->moveManyToTrash($uids);
  if ($count > 0) {
    $trash = $imap->findTrashFolder();
    foreach ($uids as $uid) {
      cache_apply_move($me, $folder, $uid, $trash ?: null);
    }
    cache_invalidate_folder($me, $folder);
    if ($trash) {
      cache_invalidate_folder($me, $trash);
    }
  }
  respond_mutation($count > 0 || count($uids) === 0, $count > 0 ? 'Messages moved to Trash.' : 'Could not move to Trash.', $folder, $count);
}

// Filing a message as spam. Deliberately not a delete: with no Junk mailbox the
// move fails loudly rather than destroying mail, and the message stays put.
// Filing a message into an ordinary folder. The target is validated against the
// live folder list by a pure gate, so a typo cannot scatter mail into mailboxes
// that do not exist.
function handle_move(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $uids = post_uids();
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  $dest = urldecode($_POST['dest'] ?? '');
  if (!imap_folder_name_is_safe($folder)) {
    respond_mutation(false, 'Invalid folder name.', $folder);
  }
  if (empty($uids)) {
    respond_mutation(true, 'No messages given.', $folder, 0);
  }
  $imap = imap_for_session();
  // A UID move against the wrong mailbox would file someone else's mail.
  if (!$imap->selectFolder($folder)) {
    respond_mutation(false, 'Could not open the folder.', $folder);
  }
  $folders = $imap->getFolders();
  $gate = folder_move_check($folder, $dest, $folders);
  if ($gate !== 'ok') {
    respond_mutation(false, [
      'same'     => 'The message is already in that folder.',
      'unknown'  => 'No such folder.',
      'noselect' => 'That folder cannot hold messages.',
    ][$gate] ?? 'Invalid folder name.', $folder);
  }
  $count = $imap->moveManyToFolder($uids, $dest);
  if ($count > 0) {
    $me = current_user();
    foreach ($uids as $uid) {
      cache_apply_move($me, $folder, $uid, $dest);
    }
    cache_invalidate_folder($me, $folder);
    cache_invalidate_folder($me, $dest);
  }
  respond_mutation($count > 0, $count > 0 ? 'Messages moved to ' . $dest . '.' : 'Could not move the messages.', $folder, $count);
}

function handle_spam(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $uid = (int)($_POST['uid'] ?? 0);
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  if (!$uid) {
    respond_mutation(false, 'No message given.', $folder);
  }
  $imap = imap_for_session();
  // A UID move against the wrong mailbox would file someone else's mail.
  if (!$imap->selectFolder($folder)) {
    respond_mutation(false, 'Could not open the folder.', $folder);
  }
  $junk = $imap->findJunkFolder();
  if (!$junk) {
    respond_mutation(false, 'No Spam folder on this account.', $folder);
  }
  $ok = $imap->moveToJunk($uid);
  if ($ok) {
    cache_apply_move(current_user(), $folder, $uid, $junk);
  }
  respond_mutation($ok, $ok ? 'Message marked as spam.' : 'Could not mark as spam.', $folder);
}

function handle_restore(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $uid = (int)($_POST['uid'] ?? 0);
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  if (!$uid) {
    respond_mutation(false, 'No message given.', $folder ?: 'INBOX');
  }
  $imap = imap_for_session();
  $ok = $imap->restoreFromTrash($uid);
  if ($ok) {
    // Leaves Trash, lands in INBOX: drop it here and let INBOX resync.
    cache_apply_move(current_user(), $imap->findTrashFolder() ?: $folder, $uid, 'INBOX');
  }
  respond_mutation($ok, $ok ? 'Message restored to Inbox.' : 'Could not restore message.', $folder ?: 'INBOX');
}

// Wipe Trash or Spam. One route for both, because the only difference was the
// label; the gate is what keeps it off every other mailbox.
function handle_empty_folder(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $folder = urldecode($_POST['folder'] ?? '');
  if (folder_empty_check($folder) !== 'ok') {
    flash_set('error', 'That folder cannot be emptied.');
    redirect('?page=folder&folder=' . urlencode($folder ?: 'INBOX'));
  }
  $imap = imap_for_session();
  $ok = $imap->emptyFolder($folder);
  $label = MailImap::isJunkFolder($folder) ? 'Spam' : 'Trash';
  flash_set($ok ? 'success' : 'error', $ok ? $label . ' emptied.' : 'Could not empty ' . $label . '.');
  if ($ok) {
    db_delete_messages(current_user(), $folder);
    cache_invalidate_folder(current_user(), $folder);
  }
  redirect('?page=folder&folder=' . urlencode($folder));
}

// --- Folder management ---

// Re-read the folder list after a mailbox was created or deleted, so the sidebar
// is right on the very next render instead of after the cache TTL. An empty LIST
// result means the round trip failed: caching that would blank the sidebar for
// the whole TTL, so it is left alone.
function cache_refresh_folder_list(string $email, MailImap $imap): void
{
  $folders = $imap->getFolders();
  if ($folders) {
    db_set_folder_list($email, $folders);
  }
}

function handle_create_folder(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $name = trim($_POST['name'] ?? '');
  if (folder_create_check($name) !== 'ok') {
    flash_set('error', 'Invalid folder name.');
    redirect('?page=inbox');
  }
  $me = current_user();
  $imap = imap_for_session();
  if ($imap->createFolder($name)) {
    flash_set('success', 'Folder "' . $name . '" created.');
    cache_refresh_folder_list($me, $imap);
  } else {
    flash_set('error', 'Could not create "' . $name . '". It may already exist.');
  }
  redirect('?page=inbox');
}

function handle_delete_folder(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $folder = urldecode($_POST['folder'] ?? '');
  $folderMap = json_decode((string)user_setting('protected_folders', '{}'), true) ?: null;
  // Refused before a connection is built: hiding the button in the sidebar is
  // presentation, this is the actual control.
  switch (folder_delete_check($folder, $folderMap)) {
    case 'protected':
      flash_set('error', folder_display_name($folder) . ' cannot be deleted.');
      redirect('?page=inbox');
      break;
    case 'invalid':
      flash_set('error', 'Invalid folder name.');
      redirect('?page=inbox');
      break;
  }
  $me = current_user();
  $imap = imap_for_session();
  if ($imap->deleteFolder($folder)) {
    flash_set('success', 'Folder "' . $folder . '" deleted.');
    // The mailbox is gone, so its cached rows and sync state are garbage.
    db_delete_messages($me, $folder);
    db_delete_folder_state($me, $folder);
    cache_refresh_folder_list($me, $imap);
  } else {
    flash_set('error', 'Could not delete "' . $folder . '".');
  }
  redirect('?page=inbox');
}

// --- Download ---

function handle_download(): void
{
  require_login();
  [$uid, $folder] = message_target_from_request();
  $part = $_GET['part'] ?? '';
  if (!$uid || !preg_match('/^\d+(\.\d+)*$/', $part)) {
    redirect('?page=inbox');
  }
  $imap = imap_for_session();
  $imap->selectFolder($folder);
  $att = $imap->getAttachmentByUid($uid, $part);
  if (!$att || strlen($att['data']) === 0) {
    redirect('?page=inbox');
  }
  $filename = sanitize_header_value($att['filename'] ?: 'attachment');
  header('Content-Type: application/octet-stream');
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: attachment; filename="' . addcslashes($filename, '"\\') . '"');
  header("Content-Length: " . strlen($att['data']));
  echo $att['data'];
  exit;
}

// --- Mark read / unread (POST + JSON) ---

function handle_mark_read(bool $read): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['success' => false]);
  }
  $uids = post_uids();
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  if (!imap_folder_name_is_safe($folder)) {
    http_response_code(400);
    json_out(['success' => false, 'error' => 'Invalid folder name.']);
  }
  $me = current_user();
  // A conversation row toggles every cached member, across its folders. The
  // returned count is only this folder's flipped members, which is what its
  // sidebar badge moves by; the other folders correct on the next poll.
  $thread = trim((string)($_POST['thread'] ?? ''));
  if ($thread !== '') {
    if (!thread_key_is_safe($thread)) {
      http_response_code(400);
      json_out(['success' => false, 'error' => 'Invalid conversation.']);
    }
    $members = db_thread_members($me, $thread);
    if (!$members) {
      json_out(['success' => true, 'count' => 0]);
    }
    $imap = imap_for_session();
    $byFolder = [];
    $flipped = 0;
    foreach ($members as $m) {
      $mf = (string)$m['folder'];
      $byFolder[$mf][] = (int)$m['uid'];
      $wasUnread = !empty($m['unread']);
      if ((($read && $wasUnread) || (!$read && !$wasUnread)) && $mf === $folder) {
        $flipped++;
      }
    }
    foreach ($byFolder as $mf => $fus) {
      if (!imap_folder_name_is_safe((string)$mf)) continue;
      if (!$imap->selectFolder((string)$mf)) continue;
      $imap->markManyAsRead($fus, $read);
      foreach ($fus as $fuid) cache_mark_read($me, (string)$mf, $fuid, $read);
      cache_invalidate_folder($me, (string)$mf);
    }
    json_out(['success' => true, 'count' => $flipped]);
  }
  if (empty($uids)) {
    json_out(['success' => true, 'count' => 0]);
  }
  $imap = imap_for_session();
  if (!$imap->selectFolder($folder)) {
    http_response_code(409);
    json_out(['success' => false, 'error' => 'Could not open the folder.']);
  }
  $count = $imap->markManyAsRead($uids, $read);
  if ($count > 0) {
    foreach ($uids as $uid) {
      cache_mark_read($me, $folder, $uid, $read);
    }
    cache_invalidate_folder($me, $folder);
  }
  json_out(['success' => true, 'count' => $count]);
}

// --- Background sync (called by the client after a page load) ---

function handle_sync(): void
{
  // A poll is not a navigation. require_login() would answer 302 with the login
  // page, r.json() would throw on the HTML, and the chip would say "Offline" for
  // ever, so the client gets a 401 it can read and act on.
  if (empty($_SESSION['logged_in'])) {
    http_response_code(401);
    json_out(['ok' => false, 'error' => 'Signed out.']);
  }
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Invalid request.']);
  }
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  $force  = !empty($_POST['force']);
  $me     = current_user();

  // Read the credentials out of the session BEFORE releasing it. session_start()
  // holds an exclusive lock for the whole request, so a sync that takes a second
  // or two would otherwise block every other tab -- including the navigation the
  // user is about to make. Nothing below writes to the session.
  $folderMap = json_decode((string)user_setting('protected_folders', '{}'), true) ?: null;
  $creds = [
    'email' => $_SESSION['email'] ?? '',
    'pass'  => $_SESSION['pass'] ?? '',
    'cfg'   => imap_config(),
  ];
  session_write_close();

  $factory = function () use ($creds) {
    if ($creds['email'] === '' || $creds['pass'] === '') {
      return null;
    }
    $imap = new MailImap(
      $creds['cfg']['server'],
      $creds['cfg']['port'],
      $creds['email'],
      session_decrypt($creds['pass']),
      $creds['cfg']['enc']
    );
    return $imap->isConnected() ? $imap : null;
  };
  // One connection for the sync and the counter sweep, opened only if either
  // needs it.
  $conn = null;
  $shared = function () use (&$conn, $factory) {
    if ($conn === null) {
      $conn = $factory() ?: false;
    }
    return $conn ?: null;
  };

  $opts = [
    'force'         => $force,
    'window'        => SYNC_WINDOW,
    'full_interval' => SYNC_FULL_INTERVAL_SECONDS,
    'backfill'      => SYNC_BACKFILL_BATCH,
    'min_interval'  => SYNC_MIN_INTERVAL_SECONDS,
  ];
  // A non-listing page (the reader, compose, settings) has no .sync chip, so the
  // client names INBOX itself and this syncs it once. A listing names its own
  // folder, and INBOX is synced alongside it on the same connection, because
  // the title counter, the notification and the sidebar badge all read INBOX
  // while the user is looking at something else.
  $inbox = sync_request($shared, $me, 'INBOX', $opts);
  $r = $folder === 'INBOX' ? $inbox : sync_request($shared, $me, $folder, $opts);
  // Keep Sent warm on the same connection so a reply you sent can join its
  // Inbox conversation. Resolved from the cached list (no connection to LIST);
  // a missing Sent is skipped, not fatal, and its own throttle applies.
  $folders = cached_folder_list($me);
  $sent = MailImap::findSentFolderIn($folders, $folderMap);
  if ($sent !== null && $sent !== 'INBOX' && $sent !== $folder) {
    sync_request($shared, $me, $sent, $opts);
  }

  counts_sweep($shared, $me, $folders, $folderMap, $folder, time(), COUNTS_SWEEP_INTERVAL_SECONDS);
  // urlencoded name (the sidebar's data-folder) => count, badged folders only.
  $counts = db_get_folder_counts($me);
  $badges = [];
  foreach ($folders as $f) {
    $b = folder_badge($f['name'], $counts[$f['name']] ?? null, $folderMap);
    if ($b !== null) {
      $badges[urlencode($f['name'])] = $b['n'];
    }
  }

  json_out([
    'ok'       => $r['ok'],
    'skipped'  => $r['skipped'],
    // `changed` is the open folder's: it is what decides the list refresh.
    'changed'  => $r['changed'],
    // `arrivals` is INBOX's: it is what raises a notification, and only the
    // inbox is a notification source.
    'arrivals' => $inbox['arrivals'],
    'unread'   => $r['unread'],
    'total'    => $r['total'],
    'counts'   => (object)$badges,
    'synced'   => relative_time((int)$r['last_sync_ts'], time()),
    'error'    => $r['error'],
  ]);
}

// --- Search ---

function handle_search(): void
{
  require_login();
  $query = trim($_GET['q'] ?? '');
  $folder = urldecode($_GET['folder'] ?? 'INBOX');
  $page = max(1, (int)($_GET['p'] ?? 1));
  $perPage = (int)(user_setting('emails_per_page', DEFAULT_EMAILS_PER_PAGE));
  $onServer = !empty($_GET['server']);
  $me = current_user();

  if ($query === '') {
    redirect('?page=folder&folder=' . urlencode($folder));
  }

  // Lazily built: a local search never needs a connection.
  $imapHolder = null;
  $getImap = function () use (&$imapHolder) {
    if ($imapHolder === null) {
      $imapHolder = imap_for_session();
    }
    return $imapHolder;
  };

  $base = [
    'current_folder' => $folder,
    'title'          => "Search: \"$query\"",
    'is_sent'        => MailImap::isSentFolder($folder),
    'is_trash'       => MailImap::isTrashFolder($folder),
    'is_junk'        => MailImap::isJunkFolder($folder),
    'is_drafts'      => MailImap::isDraftsFolder($folder),
    'search_query'   => $query,
    'last_sync_ts'   => (int)(db_get_folder_state($me, $folder)['last_sync_ts'] ?? 0),
  ];

  if (!$onServer) {
    // Local search over cached headers and snippets: no round trip at all.
    $total = db_count_search_messages($me, $folder, $query);
    if ($total > 0) {
      render_inbox(array_merge($base, [
        'emails'       => cache_rows_to_view(db_search_messages($me, $folder, $query, $page, $perPage)),
        'total'        => $total,
        'pages'        => (int)ceil($total / max(1, $perPage)),
        'current_page' => $page,
        'folders'      => folder_list_for($getImap, $me),
        // Cached headers cannot match on body text that was never opened, so
        // always offer the server-side full-text search as well.
        'server_search' => true,
      ]));
      return;
    }
  }

  // Nothing locally, or the user explicitly asked the server: full-text search.
  $imap = $getImap();
  if (!$imap) {
    render_inbox(array_merge($base, [
      'emails' => [],
      'total' => 0,
      'pages' => 0,
      'current_page' => 1,
      'folders' => [],
      'server_search' => false,
    ]));
    return;
  }
  $result = $imap->searchEmails($folder, $query, $page, $perPage);
  render_inbox(array_merge($base, $result, [
    'folders'       => folder_list_for($getImap, $me),
    'server_search' => false,
  ]));
}

// --- Settings ---

function handle_settings(): void
{
  require_login();
  $me = current_user();
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? '')) {
      flash_set('error', 'Security token invalid.');
      redirect('?page=settings');
    }
    // Clear the local header cache. Everything in it is reconstructible from
    // the server, so this is safe -- it only costs one cold sync per folder.
    if (!empty($_POST['clear_cache'])) {
      db_clear_cache_for_user($me);
      flash_set('success', 'Local cache cleared.');
      redirect('?page=settings');
    }
    $perPage = (int)($_POST['emails_per_page'] ?? DEFAULT_EMAILS_PER_PAGE);
    $perPage = max(5, min(200, $perPage));
    $displayName = trim($_POST['display_name'] ?? '');
    db_set_setting($me, 'emails_per_page', (string)$perPage);
    db_set_setting($me, 'display_name', $displayName);
    db_set_setting($me, 'show_remote_images', empty($_POST['show_remote_images']) ? '0' : '1');
    db_set_setting($me, 'signature', trim($_POST['signature'] ?? ''));
    db_set_setting($me, 'auto_poll', empty($_POST['auto_poll']) ? '0' : '1');
    $undoPeriod = (int)($_POST['undo_period_seconds'] ?? 0);
    $undoPeriod = in_array($undoPeriod, [0, 5, 10, 15, 30], true) ? $undoPeriod : 0;
    db_set_setting($me, 'undo_period_seconds', (string)$undoPeriod);
    $protectedFolders = trim($_POST['protected_folders'] ?? '{}');
    // Validate JSON: must decode to an object (not array/null).
    $decoded = json_decode($protectedFolders, true);
    if (!is_array($decoded) || json_last_error() !== JSON_ERROR_NONE) {
      $protectedFolders = '{}';
    }
    db_set_setting($me, 'protected_folders', $protectedFolders);
    // A non-empty user map means the folder setup is done, so onboarding must
    // not nag again (the onboarding dialog's Save goes through its own route).
    if (json_decode($protectedFolders, true) !== []) {
      db_set_setting($me, 'folder_setup_complete', '1');
    }
    flash_set('success', 'Settings saved.');
    redirect('?page=settings');
  }
  $folderMapJson = (string)user_setting('protected_folders', '{}');
  render_settings([
    'emails_per_page' => (int)user_setting('emails_per_page', DEFAULT_EMAILS_PER_PAGE),
    'display_name'    => (string)user_setting('display_name', strstr($me, '@', true) ?: $me),
    'show_remote_images' => user_setting('show_remote_images', DEFAULT_SHOW_REMOTE_IMAGES) === '1',
    'image_whitelist' => (string)user_setting('image_whitelist', ''),
    'signature'       => (string)user_setting('signature', ''),
    'auto_poll'  => user_setting('auto_poll', '1') === '1',
    'undo_period_seconds' => (int)user_setting('undo_period_seconds', 0),
    'protected_folders' => $folderMapJson,
    // Only pre-fill detection when the user has no map yet: their saved choices
    // are the single source of truth once set. detect_folder_map() caches, so
    // the settings page never pays for a second connection.
    'detected_folder_map' => (json_decode($folderMapJson, true) ?: []) === []
      ? detect_folder_map()
      : [],
    'folder_list' => cached_folder_list($me),
    'cached_messages' => db_count_cached_messages($me),
    'csrf'            => csrf_page_token(),
  ]);
}

// Save the folder map from the first-access onboarding dialog. AJAX-only: the
// JS posts it, then reloads the page so the sidebar picks up the new map.
function handle_save_onboarding(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Forbidden']);
  }
  $me = current_user();
  $raw = trim((string)($_POST['protected_folders'] ?? '{}'));
  $decoded = json_decode($raw, true);
  $clean = is_array($decoded) ? folder_map_clean($decoded) : [];
  $json = json_encode($clean);
  db_set_setting($me, 'protected_folders', $json);
  // Only mark setup complete when at least one role is mapped. An empty save
  // leaves the flag off so the dialog returns on the next session.
  if ($clean !== []) {
    db_set_setting($me, 'folder_setup_complete', '1');
  }
  json_out(['ok' => true]);
}

// Trust one sender's remote images from now on. The list holds exact addresses,
// so this never widens to a whole mail host.
function handle_trust_sender(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    exit('Forbidden');
  }
  $me = current_user();
  $sender = strtolower(trim(header_address_list((string)($_POST['sender'] ?? ''))));
  $uid = (int)($_POST['uid'] ?? 0);
  $folder = urldecode($_POST['folder'] ?? 'INBOX');
  if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
    flash_set('error', 'That is not an address I can trust.');
    redirect('?page=folder&folder=' . urlencode($folder));
  }
  $list = (string)user_setting('image_whitelist', '');
  if (!images_allowed($sender, false, $list)) {
    db_set_setting($me, 'image_whitelist', trim($list . "\n" . $sender));
  }
  flash_set('success', 'Images from ' . $sender . ' will load from now on.');
  redirect($uid
    ? '?page=read&uid=' . $uid . '&folder=' . urlencode($folder)
    : '?page=folder&folder=' . urlencode($folder));
}

// Revoke a trusted sender: remove from the whitelist.
function handle_revoke_sender(): void
{
  require_login();
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST' || !csrf_verify($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    json_out(['ok' => false, 'error' => 'Invalid request.']);
  }
  $me = current_user();
  $sender = strtolower(trim((string)($_POST['sender'] ?? '')));
  if ($sender === '' || !filter_var($sender, FILTER_VALIDATE_EMAIL)) {
    json_out(['ok' => false, 'error' => 'Invalid address.']);
  }
  $list = (string)user_setting('image_whitelist', '');
  $lines = array_filter(array_map('trim', preg_split('/[\r\n]+/', $list) ?: []));
  $lines = array_filter($lines, function ($line) use ($sender) {
    return strtolower($line) !== $sender;
  });
  db_set_setting($me, 'image_whitelist', implode("\n", $lines));
  json_out(['ok' => true]);
}

// --- Contacts JSON API (autocomplete) ---

function handle_contacts_api(): void
{
  require_login();
  $q = trim($_GET['q'] ?? '');
  $results = $q === '' ? [] : array_map(function ($c) {
    return ['name' => $c['name'], 'email' => $c['email']];
  }, db_search_contacts(current_user(), $q));
  json_out(['results' => $results]);
}

// [8] Views ------------------------------------------------------------------

function render_page(string $title, string $content, bool $authenticated): void
{
  // Compress HTML responses. Enabled here, not globally, because
  // handle_download() sends an explicit Content-Length that gzip would
  // invalidate, and it never routes through this function.
  if (!headers_sent() && extension_loaded('zlib')) {
    @ini_set('zlib.output_compression', '1');
  }
  $flash = flash_get();
  $user = current_user();
  $authed = $authenticated && $user;
  // Folder-map onboarding: once per account, until the user confirms a map.
  // The saveOnboarding route itself is AJAX and never renders a page.
  $onboardingPending = $authed ? onboarding_pending($user, $_GET['page'] ?? '') : false;
  $displayName = $authed ? (string)user_setting('display_name', strstr($user, '@', true) ?: $user) : '';
  // The keyboard layer's folder map. Lives on <body>, not the sidebar, because the
  // reader page renders no sidebar at all and g X still has to work there. The
  // listing pages duplicate it into data-kb-map too; the reader has nowhere else.
  $kbMap = $authed ? kb_folder_map(
    cached_folder_list($user),
    json_decode((string)user_setting('protected_folders', '{}'), true) ?: null,
  ) : [];
  echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">';
  echo '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
  echo '<meta name="csrf" content="' . h(csrf_page_token()) . '">';
  echo '<meta name="color-scheme" content="light dark">';
  echo '<title>' . h($title) . ' — ' . APP_NAME . '</title>';
  // Apply the stored theme before first paint, so the page never flashes the
  // wrong palette. Falls through to the OS preference when nothing is stored.
  echo '<script>(function(){try{var t=localStorage.getItem("emmmail-theme");'
    . 'if(t==="dark"||t==="light"){document.documentElement.setAttribute("data-theme",t);}}catch(e){}})();</script>';
  echo css_inline();
  echo '</head><body' . ($authed ? '' : ' class="auth"')
    . ($kbMap === [] ? '' : ' data-kb-map="' . h(json_encode($kbMap)) . '"') . '>';
  echo tabler_sprite();
  if ($authed) {
    // The browser's poll period, on the topbar because that is the one element
    // every signed-in page carries. It is not a setting: an open tab asks once a
    // minute, because faster buys nothing and holds the session open for longer.
    // 0 means the user turned the automatic check off, so the client runs no
    // timer at all.
    $pollPeriod = user_setting('auto_poll', '1') === '1' ? SYNC_CLIENT_MIN_PERIOD : 0;
    echo '<header class="topbar" data-period="' . $pollPeriod . '">'
      . '<button type="button" class="icon-btn nav-toggle" data-nav-toggle aria-label="Open folders">'
      . icon('menu-2') . '</button>'
      . '<a class="brand" href="?page=inbox">' . icon('mail', 22) . '<span>' . APP_NAME . '</span></a>'
      . '<div class="topbar-spacer"></div>'
      . '<div class="account">'
      . '<button type="button" class="account-toggle" data-account-menu aria-haspopup="true" '
      . 'aria-expanded="false" aria-label="Account menu">'
      . '<span class="avatar">' . h(avatar_initial($user)) . '</span></button>'
      . '<div class="account-menu" role="menu">'
      . '<div class="account-header"><strong>' . h($displayName) . '</strong><br>' . h($user) . '</div>'
      . '<a role="menuitem" href="?page=settings">' . icon('settings') . ' Settings</a>'
      . '<button type="button" role="menuitem" data-theme-toggle><span class="icon-moon">' . icon('moon') . '</span>'
      . '<span class="icon-sun">' . icon('sun') . '</span> <span class="label">Theme</span></button>'
      . '<hr class="menu-sep">'
      . '<button type="button" role="menuitem" data-kb-help>' . icon('help')
      . '<span class="label">Help</span><kbd class="menu-kbd">?</kbd></button>'
      . '<hr class="menu-sep">'
      . '<a role="menuitem" class="menu-danger" href="?page=logout">' . icon('logout') . ' Logout</a>'
      . '</div></div>'
      . '</header>';
    // Acknowledges a click that is about to cost a round trip. Lives in the
    // chrome because it outlives the page that started it.
    echo '<div class="nav-progress" aria-hidden="true"></div>';
    echo '<div class="nav-backdrop" data-nav-close></div>';
  }
  // Floating notifications. Rendered as fixed toasts, never as in-flow blocks,
  // so a flash arriving or leaving cannot shift the page. role=status/alert
  // keeps screen readers announcing them. JS auto-dismisses these on the same
  // schedule it uses for its own toasts (see js_inline()).
  echo '<div class="toast-container">';
  if (!empty($flash['success'])) {
    echo '<div class="toast toast-success" role="status">' . h($flash['success']) . '</div>';
  }
  if (!empty($flash['error'])) {
    echo '<div class="toast toast-error" role="alert">' . h($flash['error']) . '</div>';
  }
  echo '</div>';
  echo '<main class="app">' . $content . '</main>';
  if ($authed) {
    echo '<dialog class="modal" id="delete-dialog">'
      . '<h3 class="modal-title">Delete?</h3>'
      . '<p class="modal-desc"></p>'
      . '<div class="modal-actions">'
      . '<button class="btn-cancel" type="button">Cancel</button>'
      . '<button class="btn-confirm" autofocus type="button">' . icon('trash') . '<span class="btn-label">Delete</span></button>'
      . '</div></dialog>';
    echo '<dialog class="modal" id="folder-dialog">'
      . '<h3 class="modal-title">New folder</h3>'
      . '<input type="text" class="folder-name" placeholder="Folder name" maxlength="255">'
      . '<div class="modal-actions">'
      . '<button class="btn-cancel" type="button">Cancel</button>'
      . '<button class="btn-confirm" type="button">' . icon('progress-check') . '<span class="btn-label">Create</span></button>'
      . '</div></dialog>';
    echo '<dialog class="modal" id="folder-map-dialog">'
      . '<h3 class="modal-title">Select system folders</h3>'
      . '<div class="folder-map-rows" style="margin:16px 0"></div>'
      . '<div class="modal-actions">'
      . '<button class="btn-cancel" type="button">Cancel</button>'
      . '<button class="btn-confirm-primary" autofocus type="button">' . icon('device-floppy') . '<span class="btn-label">Save</span></button>'
      . '</div></dialog>';
    if ($onboardingPending) {
      // First-access onboarding. Server detects the real folder names when the
      // user has no map yet, so the dialog opens pre-filled. A failing IMAP
      // (cold start) leaves the list empty and only "Later" works.
      echo '<dialog class="modal onboarding-dialog" id="onboarding-dialog"'
        . ' data-folders="' . h(json_encode(cached_folder_list($user))) . '"'
        . ' data-default-map="' . h(json_encode(detect_folder_map())) . '">'
        . '<h3 class="modal-title">Set up your folders</h3>'
        . '<p class="modal-desc">Map your email folders to the correct system roles. '
        . 'This is required before you can use Emmmail.</p>'
        . '<div class="folder-map-rows onboarding-rows" style="margin:16px 0"></div>'
        . '<div class="modal-actions">'
        . '<button class="btn-cancel" type="button">Later</button>'
        . '<button class="btn-confirm-primary" autofocus type="button">' . icon('device-floppy') . '<span class="btn-label">Save &amp; Continue</span></button>'
        . '</div></dialog>';
    }
    echo '<dialog class="modal" id="trusted-senders-dialog">'
      . '<h3 class="modal-title">Trusted senders</h3>'
      . '<p class="modal-desc">These senders can load remote images in your messages.</p>'
      . '<div class="trusted-senders-list" style="margin:16px 0"></div>'
      . '<div class="modal-actions">'
      . '<button class="btn-cancel" type="button">Close</button>'
      . '</div></dialog>';
    echo view_kb_help_dialog();
  }
  echo '<script>' . js_inline() . '</script>';
  if ($onboardingPending) {
    // Must run after js_inline(): it depends on the $ toolkit and $.ready. The
    // flag is set on window, not `win`: `win` is an IIFE-local inside
    // js_inline(), so a top-level script referencing it would throw.
    echo '<script>$.ready(function(){ window.__openOnboarding = true; });</script>';
  }
  echo '</body></html>';
}

function render_login(): void
{
  // Flash is consumed and rendered as a toast by render_page().
  render_page('Login', view_login(csrf_page_token()), false);
}

function render_inbox(array $d): void
{
  $d['folder_counts'] = db_get_folder_counts(current_user());
  render_page($d['title'], view_inbox($d), true);
}

function render_read(array $email, int $uid, string $folder, string $me = '', array $opts = []): void
{
  // Neighbours for the reader's j/k, computed from the whole cached folder so
  // they work across page boundaries. view_read() turns them into data-prev/
  // data-next on the card.
  if ($me !== '') {
    $pn = read_prev_next($me, $folder, $uid);
    $opts['prev_uid'] = $pn['prev'];
    $opts['next_uid'] = $pn['next'];
  }
  // Straight from the folder-list cache, so the Move picker costs no round trip.
  render_page($email['subject'], view_read(
    $email,
    $uid,
    $folder,
    $me,
    cached_folder_list($me ?: current_user()),
    $opts
  ), true);
}

function render_compose(array $d): void
{
  $folderMap = json_decode((string)user_setting('protected_folders', '{}'), true) ?: null;
  render_page('Compose', view_sidebar(cached_folder_list(current_user()), '', $folderMap, db_get_folder_counts(current_user())) . '<section class="mailbox">' . view_compose($d) . '</section></div>', true);
}

function render_settings(array $d): void
{
  $folderMap = json_decode((string)user_setting('protected_folders', '{}'), true) ?: null;
  render_page('Settings', view_sidebar(cached_folder_list(current_user()), '', $folderMap, db_get_folder_counts(current_user())) . '<section class="mailbox">' . view_settings($d) . '</section></div>', true);
}

// [9] CSS + JS -----------------------------------------------------------------

function css_inline(): string
{
  return <<<'CSS'
<style>
/* --- Nord tokens ------------------------------------------------------- */
:root {
  --nord0:#2e3440; --nord1:#3b4252; --nord2:#434c5e; --nord3:#4c566a;
  --nord4:#d8dee9; --nord5:#e5e9f0; --nord6:#eceff4;
  --nord7:#8fbcbb; --nord8:#88c0d0; --nord9:#81a1c1; --nord10:#5e81ac;
  --nord11:#bf616a; --nord12:#d08770; --nord13:#ebcb8b; --nord14:#a3be8c; --nord15:#b48ead;
  --bg:var(--nord6);
  --surface:#ffffff;
  --surface-2:#f6f8fb;
  --surface-3:var(--nord5);
  --border:var(--nord4);
  --border-soft:#e4e8ef;
  --text:var(--nord0);
  --text-muted:var(--nord3);
  --text-faint:#7b8794;
  --accent:var(--nord10);
  --accent-hover:#4f7099;
  --accent-soft:rgba(94,129,172,.12);
  --accent-soft-hover:rgba(94,129,172,.24);
  --on-accent:#ffffff;
  --danger:var(--nord11);
  --danger-hover:#a94f57;
  --danger-soft:rgba(191,97,106,.12);
  --warning:#a75f37;
  --warning-soft:rgba(208,135,112,.14);
  --success:#5f8f68;
  --success-soft:rgba(163,190,140,.20);
  --ring:rgba(94,129,172,.35);
  --shadow-sm:0 1px 2px rgba(46,52,64,.05),0 1px 3px rgba(46,52,64,.07);
  --shadow-md:0 6px 18px rgba(46,52,64,.10);
  --shadow-lg:0 18px 50px rgba(46,52,64,.22);
  --radius-sm:8px; --radius:12px; --radius-lg:16px;
  --topbar-h:64px;
  color-scheme:light;
}
[data-theme="dark"] {
  --bg:var(--nord0);
  --surface:var(--nord1);
  --surface-2:#434c5e;
  --surface-3:#434c5e;
  --border:#4c566a;
  --border-soft:#434c5e;
  --text:var(--nord6);
  --text-muted:#aab6c6;
  --text-faint:#8695a8;
  --accent:var(--nord8);
  --accent-hover:var(--nord7);
  --accent-soft:rgba(136,192,208,.16);
  --accent-soft-hover:rgba(136,192,208,.30);
  --on-accent:var(--nord0);
  --danger:var(--nord11);
  --danger-hover:#cf727a;
  --danger-soft:rgba(191,97,106,.20);
  --warning:var(--nord12);
  --warning-soft:rgba(208,135,112,.22);
  --success:var(--nord14);
  --success-soft:rgba(163,190,140,.16);
  --ring:rgba(136,192,208,.40);
  --shadow-sm:0 1px 2px rgba(0,0,0,.25);
  --shadow-md:0 6px 18px rgba(0,0,0,.35);
  --shadow-lg:0 18px 50px rgba(0,0,0,.50);
  color-scheme:dark;
}
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) {
    --bg:var(--nord0);
    --surface:var(--nord1);
    --surface-2:#434c5e;
    --surface-3:#434c5e;
    --border:#4c566a;
    --border-soft:#434c5e;
    --text:var(--nord6);
    --text-muted:#aab6c6;
    --text-faint:#8695a8;
    --accent:var(--nord8);
    --accent-hover:var(--nord7);
    --accent-soft:rgba(136,192,208,.16);
  --accent-soft-hover:rgba(136,192,208,.30);
    --on-accent:var(--nord0);
    --danger:var(--nord11);
    --danger-hover:#cf727a;
    --danger-soft:rgba(191,97,106,.20);
    --warning:var(--nord12);
    --warning-soft:rgba(208,135,112,.22);
    --success:var(--nord14);
    --success-soft:rgba(163,190,140,.16);
    --ring:rgba(136,192,208,.40);
    --shadow-sm:0 1px 2px rgba(0,0,0,.25);
    --shadow-md:0 6px 18px rgba(0,0,0,.35);
    --shadow-lg:0 18px 50px rgba(0,0,0,.50);
    color-scheme:dark;
  }
}

/* --- Base -------------------------------------------------------------- */
* { margin:0; padding:0; box-sizing:border-box; }
html { -webkit-text-size-adjust:100%; }
body {
  font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;
  background:var(--bg); color:var(--text); line-height:1.5; min-height:100vh;
  -webkit-font-smoothing:antialiased; text-rendering:optimizeLegibility;
}
body.auth { background:radial-gradient(1100px 520px at 50% -12%, var(--accent-soft), transparent 62%), var(--bg); }
a { color:var(--accent); }
img { max-width:100%; }
svg.ti { display:inline-block; vertical-align:middle; flex-shrink:0; }

/* --- Top bar ----------------------------------------------------------- */
.topbar {
  position:sticky; top:0; z-index:40; display:flex; align-items:center; gap:12px;
  height:var(--topbar-h); padding:0 18px; background:var(--surface);
  border-bottom:1px solid var(--border-soft);
}
.brand { display:flex; align-items:center; gap:9px; font-size:1.15rem; font-weight:800;
  letter-spacing:-.02em; color:var(--text); text-decoration:none; }
.brand svg.ti { color:var(--accent); }
.topbar-spacer { flex:1; }
/* Navigation in flight. Sticks under the topbar and animates on its own, because
   the request it reports on has not sent a single byte back yet: an indeterminate
   bar is the honest shape for a wait of unknown length. */
.nav-progress { position:sticky; top:var(--topbar-h); z-index:39; height:2px;
  overflow:hidden; opacity:0; transition:opacity .1s; }
.nav-progress.loading { opacity:1; }
.nav-progress::after { content:""; display:block; height:100%; width:40%;
  background:var(--accent); transform:translateX(-100%); }
.nav-progress.loading::after { animation:nav-progress-slide 1.1s ease-in-out infinite; }
@keyframes nav-progress-slide {
  0% { transform:translateX(-100%); } 100% { transform:translateX(350%); }
}
@media (prefers-reduced-motion:reduce) {
  .nav-progress.loading::after { animation:none; width:100%; transform:none; opacity:.6; }
}
.icon-btn { display:inline-flex; align-items:center; justify-content:center; gap:6px;
  min-width:38px; height:38px; padding:0 8px; border:none; border-radius:50%;
  background:transparent; color:var(--text-muted); cursor:pointer; text-decoration:none;
  font-size:.85rem; font-family:inherit; transition:background .15s,color .15s; }
.icon-btn:hover { background:var(--accent-soft); color:var(--text); }
.icon-btn:focus-visible { outline:none; box-shadow:0 0 0 3px var(--ring); }
.theme-toggle .icon-sun { display:none; }
[data-theme="dark"] .theme-toggle .icon-sun { display:inline-flex; }
[data-theme="dark"] .theme-toggle .icon-moon { display:none; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) .theme-toggle .icon-sun { display:inline-flex; }
  :root:not([data-theme="light"]) .theme-toggle .icon-moon { display:none; }
}
/* --- Account (avatar) menu ---------------------------------------------- */
.account { position:relative; }
.account-toggle { display:flex; align-items:center; gap:9px; padding:4px 10px 4px 4px;
  border:none; border-radius:999px; background:transparent; color:var(--text);
  cursor:pointer; font-family:inherit; font-size:1rem; transition:background .15s; }
.account-toggle:hover, .account.open .account-toggle { background:transparent; }
.account-toggle:hover .avatar { border-color:var(--accent); }
.account-toggle .avatar { width:32px; height:32px; font-size:.82rem; border: 2px solid var(--border); transition: border-color .15s; }
.account-menu { position:absolute; top:calc(100% + 6px); right:0; min-width:190px;
  background:var(--surface); border:1px solid var(--border-soft); border-radius:var(--radius-lg);
  box-shadow:var(--shadow-lg); padding:6px; z-index:60; display:none;
  flex-direction:column; gap:2px; }
.account.open .account-menu { display:flex; }
.account-menu a, .account-menu button { display:flex; align-items:center; gap:10px;
  padding:9px 12px; border:none; border-radius:10px; background:transparent; color:var(--text);
  font-family:inherit; font-size:.9rem; cursor:pointer; text-decoration:none; text-align:left; }
.account-menu a:hover, .account-menu button:hover { background:var(--surface-3); }
.account-menu button .label { flex:1; }
.account-menu button[data-theme-toggle] .icon-sun { display:none; }
[data-theme="dark"] .account-menu button[data-theme-toggle] .icon-sun { display:inline-flex; }
[data-theme="dark"] .account-menu button[data-theme-toggle] .icon-moon { display:none; }
@media (prefers-color-scheme: dark) {
  :root:not([data-theme="light"]) .account-menu button[data-theme-toggle] .icon-sun { display:inline-flex; }
  :root:not([data-theme="light"]) .account-menu button[data-theme-toggle] .icon-moon { display:none; }
}
.account-header { padding:8px 12px 6px; font-size:.82rem; color:var(--text-muted);
  white-space:nowrap; overflow:hidden; text-overflow:ellipsis; cursor:default; }
.account-header + a, .account-header + button { margin-top:4px; }
.menu-sep { border:none; border-top:1px solid var(--border-soft); margin:6px 4px; }
.menu-sep + a, .menu-sep + button { margin-top:4px; }
.menu-danger:hover { background:rgba(191,97,106,.15); color:var(--danger); }

/* --- Avatars ----------------------------------------------------------- */
.avatar, .mail-avatar { display:inline-flex; align-items:center; justify-content:center;
  border-radius:50%; font-weight:700; text-transform:uppercase; flex:0 0 auto; user-select:none; }
.avatar { width:32px; height:32px; font-size:.82rem; }
.mail-avatar { width:40px; height:40px; font-size:.95rem; }
.av-0 { background:#5e81ac; color:#eceff4; }
.av-1 { background:#8fbcbb; color:#2e3440; }
.av-2 { background:#81a1c1; color:#2e3440; }
.av-3 { background:#b48ead; color:#2e3440; }
.av-4 { background:#a3be8c; color:#2e3440; }
.av-5 { background:#d08770; color:#2e3440; }
.av-6 { background:#88c0d0; color:#2e3440; }
.av-7 { background:#ebcb8b; color:#2e3440; }

/* --- App shell --------------------------------------------------------- */
.app { min-height:calc(100vh - var(--topbar-h)); }
.layout { display:grid; grid-template-columns:264px minmax(0,1fr);
  min-height:calc(100vh - var(--topbar-h)); }
.nav-backdrop { position:fixed; inset:var(--topbar-h) 0 0 0; background:rgba(46,52,64,.45);
  opacity:0; pointer-events:none; transition:opacity .2s; z-index:50; }
body.nav-open .nav-backdrop { opacity:1; pointer-events:auto; }

/* --- Sidebar ----------------------------------------------------------- */
.sidebar { background:var(--surface); border-right:1px solid var(--border-soft);
  padding:16px 12px; display:flex; flex-direction:column; gap:8px;
  position:sticky; top:var(--topbar-h); height:calc(100vh - var(--topbar-h)); overflow-y:auto; }
.compose-btn { gap:8px; }
.sidebar h3 { font-size:.68rem; text-transform:uppercase; letter-spacing:.09em;
  color:var(--text-faint); font-weight:700; margin:14px 8px 6px; }
.sidebar ul { list-style:none; display:flex; flex-direction:column; gap:2px; }
.sidebar li { display:flex; align-items:center; border-radius:10px; }
.sidebar li > a { flex:1; min-width:0; display:flex; align-items:center; gap:11px;
  padding:9px 12px; border-radius:10px; color:var(--text); text-decoration:none;
  font-size:.9rem; font-weight:500; transition:background .12s,color .12s; }
.sidebar li > a:hover { background:var(--surface-3); }
.sidebar li.active > a { background:var(--accent-soft); color:var(--accent); font-weight:700; }
/* Unread counter, pushed to the right edge of the link. */
.sidebar .folder-count { margin-left:auto; font-size:.75rem; font-weight:700;
  color:var(--text-muted); font-variant-numeric:tabular-nums; }
.sidebar li.active .folder-count { color:var(--accent); }
.sidebar li .folder-del { opacity:0; color:var(--text-faint); flex:0 0 auto; width:30px;
  height:30px; min-width:30px; border-radius:8px; transition:opacity .12s,color .12s; }
.sidebar li:hover .folder-del, .sidebar li .folder-del:focus { opacity:1; }
.sidebar li .folder-del:hover { color:var(--danger); background:var(--danger-soft); }
.sidebar li.group-sep { height:1px; background:var(--border-soft); margin:6px 8px; }
.sidebar li.group-sep:first-child, .sidebar li.group-sep:last-child { display:none; }
/* Stands in for the folder list when the cache cannot answer, so the panel reads
   as a condition rather than as a broken page. */
.sidebar .sidebar-empty { margin:0 8px; font-size:.82rem; line-height:1.45;
  color:var(--text-muted); }
.new-folder { margin-top:8px; }

/* --- Mailbox ----------------------------------------------------------- */
.mailbox { background:var(--surface); min-width:0; display:flex; flex-direction:column; }
.mailbox-head { display:flex; align-items:center; gap:12px; flex-wrap:wrap;
  padding:15px 20px; border-bottom:1px solid var(--border-soft); container-type:inline-size; }
.mailbox-head h2 { font-size:1.15rem; font-weight:700; letter-spacing:-.01em; }
.mailbox-head h2 small { color:var(--text-faint); font-weight:600; font-size:.85rem; }
.toolbar { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin-left:auto; }
.search { display:flex; align-items:center; gap:6px; background:var(--surface-2);
  border:1px solid var(--border-soft); border-radius:999px; padding:3px 4px 3px 14px;
  transition:border-color .15s,box-shadow .15s; }
.search:focus-within { border-color:var(--accent); box-shadow:0 0 0 3px var(--ring); }
.search input[type=text] { border:none; background:transparent; outline:none; color:var(--text);
  font-size:.9rem; font-family:inherit; width:210px; padding:6px 0; }
/* Bare icon inside the rounded field: a filled square fought the pill shape.
   Only the glyph colour reacts, so it reads as part of the input. */
.search .search-btn { width:34px; height:34px; min-width:34px; border-radius:50%; padding:0;
  background:transparent; border-color:transparent; color:var(--text-faint); }
.search .search-btn:hover { background:transparent; color:var(--accent); }
.search .search-btn:active { color:var(--accent-hover); }
/* Outside the search pill now, so it needs the pill's own corner to match it.
   .btn.sync-btn, not .sync-btn: the Buttons section sits after this one, so a
   single class would lose the border-radius tie to .btn-sm. */
/* Pill shape, shared by the toolbar chips that sit beside the 999px search
   field. .btn.btn-pill, not .btn-pill: .btn-sm's 9px corner would otherwise win
   on source order, since the Buttons section is emitted after this one. */
.btn.btn-pill { border-radius:999px; padding:6px 13px; }
.btn.sync-btn { border-radius:999px; padding:6px 13px; }
/* Scoped to .sync.syncing, not .sync-btn.syncing: js_inline() puts the class on
   the WRAPPER, so the old selector matched nothing and the icon never spun. */
.sync.syncing .sync-btn svg.ti { animation:sync-spin .9s linear infinite; }
.sync.sync-error .sync-btn { color:var(--danger); }
@keyframes sync-spin { to { transform:rotate(360deg); } }
@media (prefers-reduced-motion:reduce) { .sync.syncing .sync-btn svg.ti { animation:none; } }

/* --- Undo send bar ------------------------------------------------------ */
.undo-bar { position:fixed; bottom:20px; left:50%; transform:translateX(-50%);
  display:flex; align-items:center; gap:12px; padding:10px 20px;
  background:var(--card-bg); border:1px solid var(--border-soft); border-radius:8px;
  box-shadow:0 4px 12px rgba(0,0,0,0.15); z-index:1000; }
.undo-bar-text { font-size:0.9rem; }
.undo-bar-countdown { font-weight:600; color:var(--accent); min-width:3ch; }
.undo-bar-btn { margin-left:8px; }

/* --- Message list ------------------------------------------------------ */
.mail-list { display:flex; flex-direction:column; }
.mail { position:relative; display:grid; grid-template-columns:40px minmax(0,1fr) auto;
  align-items:center; gap:14px; padding:12px 20px; border-bottom:1px solid var(--border-soft);
  cursor:pointer; transition:background .12s; }
.mail:hover { background:var(--surface-2); }
.mail.unread { background:var(--accent-soft); }
.mail.unread:hover { background:var(--accent-soft-hover); }
/* The keyboard cursor: a bar on the leading edge plus a subtle wash, using only
   shared tokens so the three theme blocks all cover it. The row itself has no
   native focus, so this stays separate from :focus-visible. */
.mail.cursor { box-shadow:inset 3px 0 0 var(--accent); background:var(--surface-3); }
.mail-avatar { position:relative; z-index:1; }
.mail-main { position:relative; min-width:0; display:flex; flex-direction:column; gap:2px;
  text-decoration:none; color:inherit; }
.mail-main::after { content:""; position:absolute; inset:0; }
.mail-line { display:flex; align-items:baseline; justify-content:space-between; gap:10px; }
.mail-from { font-size:.92rem; font-weight:600; white-space:nowrap; overflow:hidden;
  text-overflow:ellipsis; }
.mail-subject { font-size:.85rem; color:var(--text-muted); white-space:nowrap;
  overflow:hidden; text-overflow:ellipsis; }
.mail.unread .mail-from, .mail.unread .mail-subject { font-weight:700; color:var(--text); }
.mail-date { font-size:.76rem; color:var(--text-faint); white-space:nowrap; flex:0 0 auto; }
/* The conversation message count. It is its own flex item, so .mail-from keeps
   the free space and ellipsises rather than being squeezed by the chip. It
   borrows --surface-3/--text-faint, so no new token is needed in the three
   theme blocks. */
.mail-line-right { display:flex; align-items:baseline; gap:12px; flex:0 0 auto; }
.mail-thread-count { flex:0 0 auto; font-size:.72rem; font-weight:700; line-height:1;
  color:var(--text-faint); background:var(--surface-3); border:1px solid var(--border-soft);
  border-radius:10px; padding:2px 7px; }
.mail.unread .mail-thread-count { color:var(--text); }
.mail-actions { position:relative; z-index:1; display:flex; align-items:center; gap:2px;
  opacity:0; transition:opacity .12s; }
.mail:focus-within .mail-actions { opacity:1; }
/* Hover reveals only where hover is real. A tap leaves :hover stuck on the row it
   touched, and the old touch rule showed every action on every row. */
@media (hover:hover) { .mail:hover .mail-actions { opacity:1; } }
/* On touch, an idle row shows no actions, and a hidden one takes no tap: opacity:0
   still receives the pointer, so a hidden Delete would fire from an empty-looking
   spot. A picked row, or keyboard focus, brings them back. */
@media (hover:none) {
  .mail-actions { pointer-events:none; }
  .mail.picked .mail-actions, .mail:focus-within .mail-actions { opacity:1; pointer-events:auto; }
}
/* The row picker, centred over the avatar rather than in a column of its own: the
   row is a 3-column grid, and a fourth would shift every row the moment one
   rendered. Hidden with opacity so it stays in the tab order -- display:none
   would take it out of the keyboard path entirely. 24px is the WCAG 2.5.8
   minimum and still fits inside the 36px phone avatar. */
.mail-pick { position:absolute; inset:0; margin:auto; width:24px; height:24px;
  z-index:1; opacity:0; cursor:pointer;
  box-shadow:0 0 0 3px var(--surface); }
/* Both boxes are round, to match the round avatar. The native control ignores
   border-radius, so it gives up its native look and draws its own. The tick is a
   clipped ::before in --on-accent, not a data-URI SVG: that token is white in
   light and nord0 in dark, and an SVG cannot read a token. */
.mail-pick, .mail-pick-all { appearance:none; -webkit-appearance:none; display:grid;
  place-content:center; border-radius:50%; border:2px solid var(--text-faint);
  background:var(--surface); cursor:pointer; }
.mail-pick-all { width:18px; height:18px; margin:0; flex:0 0 auto; }
/* Fixed sizes, not percentages: the grid track is sized by this very box, so a
   percentage resolves against nothing and the tick collapses to zero. */
.mail-pick::before, .mail-pick-all::before { content:""; width:12px; height:12px;
  clip-path:polygon(14% 44%, 0 65%, 50% 100%, 100% 16%, 80% 0%, 43% 62%);
  background:var(--on-accent); transform:scale(0); }
.mail-pick-all::before { width:10px; height:10px; }
.mail-pick:checked, .mail-pick-all:checked, .mail-pick-all:indeterminate { background:var(--accent);
  border-color:var(--accent); }
.mail-pick:checked::before, .mail-pick-all:checked::before, .mail-pick-all:indeterminate::before { transform:scale(1); }
.mail-pick-all:indeterminate::before { clip-path:inset(40% 10% 40% 10%); }
.mail-pick-all:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
.mail:focus-within .mail-pick, .mail.picked .mail-pick { opacity:1; }
@media (hover:hover) { .mail:hover .mail-pick { opacity:1; } }
/* No touch rule: a touch screen shows no pickers on an idle list. The picker sits
   over the avatar at opacity:0, which still takes a tap, so tapping the avatar
   starts a selection; from then on the bar is up and :has() shows every picker. */
/* opacity:0 hides the native focus ring too, so keyboard focus has to draw one. */
.mail-pick:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
/* An optimistically removed row collapses rather than blinking out, and is only
   detached once the server confirms -- so putting it back is one class away. */
.mail.row-gone { opacity:0; max-height:0; padding-top:0; padding-bottom:0;
  border-bottom-width:0; overflow:hidden; transition:opacity .12s, max-height .18s; }
@media (prefers-reduced-motion:reduce) { .mail.row-gone { transition:none; } }
/* A clicked row, while the navigation it started is still in flight. The avatar
   becomes the spinner rather than growing one beside it, so the row does not
   change size the moment it is touched. */
.mail.opening { background:var(--surface-2); }
/* An opaque cap in the row's own colour first. Without it the arc is drawn onto
   whichever of the eight avatar tones this sender got, and two of those tones ARE
   the accent (av-0 is nord10, av-6 is nord8) -- so the spinner was invisible on
   them and low-contrast on the rest. */
.mail.opening .mail-avatar::before { content:""; position:absolute; inset:0;
  border-radius:50%; background:var(--surface-2); }
.mail.opening .mail-avatar::after { content:""; position:absolute; inset:4px;
  border-radius:50%; border:2px solid var(--border); border-top-color:var(--accent);
  animation:sync-spin .7s linear infinite; }
/* Answered on pointer-down, before any request exists. Costs nothing and is the
   only feedback that cannot arrive late. Both selectors are needed: `.mail.unread`
   already paints accent-soft, and `.mail.unread:hover` (0,3,0) would outrank a
   lone `.mail:active` (0,2,0) on every row the pointer is actually over. */
.mail:active, .mail.unread:active { background:var(--surface-3); }
/* Selected. Every variant is spelled out because `.mail.unread:hover` and
   `.mail.unread:active` are (0,3,0) and outrank a lone `.mail.picked` (0,2,0)
   whatever the source order -- so a selected unread row under the pointer would
   lose its highlight, which is the state a user looks at longest. */
.mail.picked, .mail.unread.picked, .mail.unread.picked:hover { background:var(--accent-soft); }
@media (prefers-reduced-motion:reduce) {
  .mail.opening .mail-avatar::after { animation:none; opacity:.5; }
}
/* The picker is not the only thing that can sit on the avatar: `.mail.opening
   .mail-avatar::before` paints an opaque cap in the row colour precisely so the
   spinner arc is visible on all eight avatar tones. A checkbox over that cap
   hides the arc it exists to clear, so an opening row drops its picker. */
.mail.opening .mail-pick { opacity:0; }
/* The selection controls. They live in the mailbox as a sibling of the head, and
   :has() answers "is the bar up?" in CSS alone -- the client never asks, which is
   what keeps one rule from drifting between the two layers. */
.mailbox:has(.bulk-bar:not(.hidden)) .mail-pick { opacity:1; }
/* The bar is a flex item of the head, in the free space between select-all and
   the toolbar, so showing it moves nothing below. It must never wrap -- a wrap is
   a second line, which is the shift this placement removes -- so it may shrink
   and the buttons sit at its end. The count is the select-all label, not part of
   the bar. 42px is the toolbar's height: where the bar replaces the toolbar, the
   head keeps its height. */
.bulk-bar { display:flex; align-items:center; justify-content:flex-end; gap:8px;
  flex:1 1 0; min-width:0; flex-wrap:nowrap; min-height:42px; }
.bulk-bar .btn { flex:0 0 auto; }
/* `.hidden` is the last top-level rule in this sheet, so `.bulk-bar` (0,1,0)
   alone would lose the tie on source order and the bar would never hide. Naming
   both classes raises this to (0,2,0) and settles it. */
.bulk-bar.hidden { display:none; }
.pick-all { display:inline-flex; align-items:center; gap:7px; padding-left:12px;
  border-left:1px solid var(--border); font-size:.85rem; font-weight:600;
  color:var(--text-muted); cursor:pointer; user-select:none; white-space:nowrap; }
/* The tiers read the HEAD's width, not the viewport's: the sidebar comes and goes
   at 900px, so a viewport query guesses wrong on either side of it. Each tier
   keeps the head on the same number of lines with the bar up and down. */
@container (max-width:1020px) {
  .bulk-bar .btn-label { display:none; }
  .bulk-bar .btn-sm { width:32px; min-width:32px; padding:0; }
}
@container (max-width:800px) {
  .mailbox-head:has(.bulk-bar:not(.hidden)) .toolbar { display:none; }
}
/* A phone: the toolbar always takes its own second line, and the bar takes that
   same line when it is up. Both states are two lines tall. */
@container (max-width:600px) {
  .toolbar { flex-basis:100%; justify-content:flex-end; min-height:42px; }
  .bulk-bar { flex-basis:100%; }
  /* The search fills what the sync chip leaves, so the toolbar stays ONE line
     down to a 320px phone instead of breaking into two there. */
  .toolbar .search { flex:1 1 0; min-width:140px; }
  .toolbar .search input[type=text] { width:100%; min-width:0; }
}
.pagination { display:flex; align-items:center; justify-content:center; gap:16px; padding:18px; }
.page-info { font-size:.85rem; color:var(--text-faint); }
.empty { padding:72px 20px; text-align:center; color:var(--text-faint); font-size:.95rem; }

/* --- Buttons ----------------------------------------------------------- */
.btn { display:inline-flex; align-items:center; justify-content:center; gap:7px;
  padding:9px 15px; border:1px solid var(--border); border-radius:10px;
  background:var(--surface); color:var(--text); text-decoration:none; cursor:pointer;
  font-size:.88rem; font-weight:600; font-family:inherit; line-height:1;
  transition:background .15s,border-color .15s,color .15s,box-shadow .15s; }
.btn:hover { background:var(--surface-3); }
.btn:focus-visible { outline:none; box-shadow:0 0 0 3px var(--ring); }
/* Pressed. Answered on pointer-down, before any request exists, which is the only
   feedback that cannot arrive late. */
.btn:active, .icon-btn:active { transform:scale(.97); }
/* Working. Every reader action is a round trip -- a reply has to fetch the message
   to quote it -- so the control that was clicked carries the wait: its own icon is
   swapped for a spinner, and it stops accepting clicks so the action cannot be
   fired twice. */
.btn.busy, .icon-btn.busy, .btn-confirm.busy { pointer-events:none; opacity:.7; }
.btn.busy svg.ti, .icon-btn.busy svg.ti, .btn-confirm.busy svg.ti { animation:sync-spin .7s linear infinite; }
/* A sidebar folder link is an <a>, not a .btn, so the reader rule above misses
   it. Navigating to a cold folder is one of the slowest waits in the app, so
   the folder's own icon becomes the spinner the moment it is clicked. */
.sidebar .folder-link.busy svg.ti { animation:sync-spin .7s linear infinite; }
@media (prefers-reduced-motion:reduce) {
  .btn:active, .icon-btn:active { transform:none; }
  .btn.busy svg.ti, .icon-btn.busy svg.ti, .btn-confirm.busy svg.ti { animation:none; }
  .sidebar .folder-link.busy svg.ti { animation:none; }
}
.btn-primary { background:var(--accent); border-color:var(--accent); color:var(--on-accent); }
.btn-primary:hover { background:var(--accent-hover); border-color:var(--accent-hover); }
.btn-danger { background:var(--danger); border-color:var(--danger); color:#fff; }
.btn-danger:hover { background:var(--danger-hover); border-color:var(--danger-hover); }
/* Ordinary buttons that only reveal their intent on hover, like .menu-danger and
   .folder-del. A destructive action next to Reply should not shout by default. */
.btn-danger-soft:hover { background:var(--danger-soft); border-color:var(--danger); color:var(--danger); }
.btn-warn-soft:hover { background:var(--warning-soft); border-color:var(--warning); color:var(--warning); }
.btn-sm { padding:6px 11px; font-size:.82rem; border-radius:9px; }
.btn-icon { width:32px; min-width:32px; height:32px; padding:0; }
.btn-block { display:flex; width:100%; }
.btn-ghost { background:transparent; border-color:transparent; color:var(--text-muted); }
.btn-ghost:hover { background:var(--surface-3); color:var(--text); }

/* --- Tooltips ---------------------------------------------------------- */
/* One styled tooltip for the whole app. It reads data-tip, NOT title: CSS cannot
   suppress the native bubble, so keeping both would show two tooltips at once.
   The accessible name is carried by aria-label on every icon-only control. */
.tip { position:relative; }
.tip::after, .tip::before { opacity:0; pointer-events:none; transition:opacity .12s .3s; }
.tip::after { content:attr(data-tip); position:absolute; bottom:calc(100% + 7px); left:50%;
  transform:translateX(-50%); z-index:60; padding:5px 9px; border-radius:7px;
  background:var(--nord0); color:var(--nord6); font-size:.75rem; font-weight:600;
  line-height:1.2; white-space:nowrap; box-shadow:var(--shadow-md); }
.tip::before { content:""; position:absolute; bottom:calc(100% + 2px); left:50%;
  transform:translateX(-50%); z-index:60; border:5px solid transparent;
  border-top-color:var(--nord0); }
.tip:hover::after, .tip:hover::before,
.tip:focus-visible::after, .tip:focus-visible::before { opacity:1; }
/* The sidebar scrolls, so a centred tooltip above the button would be clipped on
   both axes. Hang it below and to the right instead, inside the container. */
.sidebar .tip::after { left:auto; right:0; transform:none; bottom:auto; top:calc(100% + 7px); }
.sidebar .tip::before { left:auto; right:9px; transform:none; bottom:auto; top:calc(100% + 2px);
  border-top-color:transparent; border-bottom-color:var(--nord0); }
/* A tap leaves :hover stuck on touch, so a tooltip there never goes away. */
@media (hover:none) { .tip::after, .tip::before { content:none; display:none; } }

/* --- Cards / reader ---------------------------------------------------- */
.card { background:var(--surface); border:1px solid var(--border-soft);
  border-radius:var(--radius-lg); box-shadow:var(--shadow-sm); padding:26px;
  max-width:860px; margin:24px auto; width:calc(100% - 32px); }
.card h1 { font-size:1.4rem; font-weight:700; letter-spacing:-.02em; margin-bottom:16px; }
.actions-bar { display:flex; flex-wrap:wrap; justify-content:space-between; gap:8px; margin-bottom:18px; }
.actions-bar-right { display:flex; flex-wrap:wrap; align-items:center; gap:8px; }
.actions-sep { width:1px; align-self:stretch; margin:2px 2px; background:var(--border);
  flex:0 0 auto; }
.meta { background:var(--surface-2); border:1px solid var(--border-soft);
  border-radius:var(--radius); padding:14px 16px; margin-bottom:18px; font-size:.88rem; }
.meta div { padding:2px 0; }
.meta strong { display:inline-block; min-width:56px; color:var(--text-faint); font-weight:600; }
.attachments { margin:16px 0; }
.attachment-list { display:flex; flex-wrap:wrap; gap:8px; margin-top:8px; }
.attachment-item { display:inline-flex; align-items:center; gap:7px; padding:8px 12px;
  background:var(--surface-2); border:1px solid var(--border-soft); border-radius:10px;
  text-decoration:none; color:var(--text); font-size:.85rem; transition:border-color .15s,color .15s; }
.attachment-item:hover { border-color:var(--accent); color:var(--accent); }
.body { margin-top:18px; border-top:1px solid var(--border-soft); padding-top:18px; }
/* --- Conversation reader ------------------------------------------------ */
.thread-top { display:flex; align-items:center; gap:8px; margin-bottom:10px; }
.thread-more { margin:0 0 10px; }
.thread-msg { border-top:1px solid var(--border-soft); padding:8px 0; }
.thread-msg:first-of-type { border-top:0; }
.thread-msg > summary { display:flex; align-items:center; gap:10px; cursor:pointer;
  list-style:none; }
.thread-msg > summary::-webkit-details-marker { display:none; }
.thread-msg > summary .avatar { width:28px; height:28px; font-size:.82rem; }
.thread-msg-from { font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.thread-msg-date { margin-left:auto; font-size:.76rem; color:var(--text-faint);
  white-space:nowrap; flex:0 0 auto; }
.thread-msg-body { padding:10px 0 4px; }
/* Blocked-image bar. Deliberately loud: a message whose content is one tracked
   image otherwise looks simply empty. */
.images-cta { display:flex; align-items:center; flex-wrap:wrap; gap:10px; margin:0 0 4px;
  padding:10px 14px; border:1px solid var(--warning); border-radius:var(--radius);
  background:var(--warning-soft); font-size:.85rem; }
.images-cta-text { display:inline-flex; align-items:center; gap:7px; color:var(--warning);
  font-weight:600; margin-right:auto; }
.body-iframe { width:100%; border:0; border-radius:var(--radius); background:#fff; display:block; }

/* --- Login ------------------------------------------------------------- */
.login-box { max-width:420px; margin:8vh auto 40px; background:var(--surface);
  border:1px solid var(--border-soft); border-radius:var(--radius-lg);
  box-shadow:var(--shadow-md); padding:36px 32px; }
.login-box h1 { display:flex; align-items:center; justify-content:center; gap:10px;
  font-size:1.5rem; font-weight:800; letter-spacing:-.03em; margin-bottom:6px; }
.login-box h1 svg.ti { color:var(--accent); }
.login-box .sub { text-align:center; color:var(--text-muted); margin-bottom:24px; font-size:.9rem; }
.login-alert { background:var(--danger-soft); border:1px solid var(--danger); color:var(--text);
  border-radius:8px; padding:10px 12px; margin-bottom:16px; font-size:.88rem; }
.login-footer { max-width:420px; margin:0 auto 40px; text-align:center; color:var(--text-faint);
  font-size:.78rem; line-height:1.55; }
.login-footer p + p { margin-top:10px; }
.login-footer a { color:var(--text-muted); }
.login-footer a:hover { color:var(--accent); }

/* The credit line sits at the bottom of the left panel. margin-top:auto spends
   the panel's spare height on the gap, so it pins without a fixed footer. */
.sidebar-footer { margin-top:auto; padding:14px 8px 0; border-top:1px solid var(--border-soft);
  color:var(--text-faint); font-size:.72rem; line-height:1.5; }
.sidebar-footer p + p { margin-top:10px; }
.sidebar-footer a { color:var(--text-muted); }
.sidebar-footer a:hover { color:var(--accent); }

/* --- Forms ------------------------------------------------------------- */
.form-group { margin-bottom:16px; }
.form-group label { display:block; margin-bottom:6px; font-size:.82rem; font-weight:600;
  color:var(--text-muted); }
.form-group input, .form-group textarea, .form-group select { width:100%; padding:11px 13px;
  border:1px solid var(--border); border-radius:10px; font-size:.92rem; font-family:inherit;
  background:var(--surface); color:var(--text); transition:border-color .15s,box-shadow .15s; }
.form-group input:focus, .form-group textarea:focus, .form-group select:focus {
  outline:none; border-color:var(--accent); box-shadow:0 0 0 3px var(--ring); }
.form-group textarea { resize:vertical; min-height:180px; line-height:1.6; }
.custom-servers { background:var(--surface-2); border:1px solid var(--border-soft);
  border-radius:var(--radius); padding:14px; margin-bottom:16px; }
.custom-servers .fieldset-title { font-size:.68rem; font-weight:700; text-transform:uppercase;
  letter-spacing:.06em; color:var(--text-faint); margin:12px 0 10px; }
.custom-servers .fieldset-title:first-child { margin-top:0; }
.custom-servers .row2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
.custom-servers .form-group { margin-bottom:10px; }
.form-actions { display:flex; flex-wrap:wrap; justify-content:space-between; gap:10px; margin-top:20px; padding-top:18px;
  border-top:1px solid var(--border-soft); }

/* --- Compose (compact, Gmail-style) -------------------------------------- */
.card form:has(.compose-row) { padding:0; }
.compose-row { display:flex; align-items:center; gap:0; padding:4px 20px;
  border-bottom:1px solid var(--border-soft); }
.compose-row label { font-size:.82rem; font-weight:600; color:var(--text-muted);
  flex:0 0 auto; min-width:36px; padding-right:8px; }
.compose-row input[type="text"] { flex:1; border:none; background:transparent;
  padding:10px 0; font-size:.92rem; font-family:inherit; color:var(--text);
  outline:none; min-width:0; }
.compose-cc-tog button, .compose-bcc-tog button {
  border:none; background:transparent; color:var(--accent); font-size:.82rem;
  font-weight:600; cursor:pointer; padding:4px 8px; border-radius:6px; }
.compose-cc-tog button:hover, .compose-bcc-tog button:hover { background:var(--surface-3); }
.compose-subject { border-bottom:1px solid var(--border-soft); }
.compose-subject input { padding:12px 20px; font-weight:600; }
.compose-body { padding:0; }
.compose-body textarea { width:100%; border:none; background:transparent;
  padding:14px 20px; font-size:.92rem; font-family:inherit; color:var(--text);
  resize:vertical; min-height:200px; line-height:1.6; outline:none; }
/* The Markdown editor. Same box as the textarea it replaces. */
.md-editor { padding:14px 20px; font-size:.92rem; color:var(--text); min-height:200px;
  line-height:1.6; outline:none; overflow-wrap:anywhere; }
.md-editor.is-empty::before { content:attr(data-placeholder); color:var(--text-muted); pointer-events:none; }
.md-editor h1, .md-editor h2, .md-editor h3, .md-editor h4, .md-editor h5, .md-editor h6 {
  margin:.6em 0 .3em; line-height:1.3; }
.md-editor h1 { font-size:1.4em; } .md-editor h2 { font-size:1.25em; } .md-editor h3 { font-size:1.1em; }
.md-editor h4, .md-editor h5, .md-editor h6 { font-size:1em; }
.md-editor ul, .md-editor ol { margin:0; padding-left:1.5em; }
.md-editor blockquote { margin:0; padding-left:1em; border-left:3px solid var(--border); color:var(--text-muted); }
.md-editor pre { margin:0; padding:8px 10px; border:1px solid var(--border-soft); border-radius:6px;
  white-space:pre-wrap; }
.md-editor code, .md-editor pre { font-family:Consolas,Menlo,monospace; font-size:.9em; }
.md-editor code { padding:0 3px; border:1px solid var(--border-soft); border-radius:4px; }
.md-editor pre code { padding:0; border:none; }
.md-editor hr { border:none; border-top:1px solid var(--border); margin:.6em 0; }
.md-editor img { max-width:100%; height:auto; }
.md-editor a { color:var(--accent); }
/* Chrome and Safari leave styled spans behind (a Backspace that merges a heading
   into a line keeps its font size). The Markdown cannot carry them, so they must
   not show either: what the user sees is what is sent. */
.md-editor [style], .md-editor font { font:inherit !important; color:inherit !important; background:none !important; }
/* The Markdown help: its own row above the actions, a quiet link-like button,
   and its modal. The top border matches the one under the To row. */
.md-help-row { border-top:1px solid var(--border-soft); padding:8px 16px 0; }
/* Forwarded attachments already draw that line under themselves. */
.compose-attachments + .md-help-row { border-top:none; }
.md-help { display:inline-flex; align-items:center; gap:6px; padding:2px 4px;
  border:none; background:none; border-radius:6px; font:inherit; font-size:.8rem;
  color:var(--text-muted); cursor:pointer; }
.md-help:hover { color:var(--text); }
dialog.modal.md-help-dialog { max-width:560px; max-height:calc(100vh - 40px); overflow:auto; }
.md-help-dialog h3:focus { outline:none; }
.md-help-table { width:100%; border-collapse:collapse; font-size:.86rem; }
.md-help-table th, .md-help-table td { text-align:left; padding:6px 8px; vertical-align:top;
  border-bottom:1px solid var(--border-soft); }
.md-help-table thead th { font-size:.74rem; text-transform:uppercase; letter-spacing:.04em;
  color:var(--text-muted); }
.md-help-table tbody th { padding-top:14px; font-weight:600; }
.md-help-table td:first-child { white-space:nowrap; }
.md-help-table code { font-family:Consolas,Menlo,monospace; font-size:.92em; padding:0 3px;
  border:1px solid var(--border-soft); border-radius:4px; white-space:pre; }
/* The ?-sheet: same table treatment as the Markdown help, plus key caps. */
dialog.modal.kb-help-dialog { width:min(520px, calc(100vw - 40px)); max-width:none; max-height:calc(100vh - 40px); overflow:auto; }
.kb-help-table { width:100%; border-collapse:collapse; font-size:.86rem; }
.kb-help-table th, .kb-help-table td { text-align:left; padding:6px 8px; vertical-align:top;
  border-bottom:1px solid var(--border-soft); }
.kb-help-table thead th { font-size:.74rem; text-transform:uppercase; letter-spacing:.04em;
  color:var(--text-muted); }
.kb-help-table td:first-child { white-space:nowrap; width:1%; }
.kb-help-table kbd { font-family:Consolas,Menlo,monospace; font-size:.86em; padding:1px 6px;
  border:1px solid var(--border-soft); border-radius:4px; background:var(--surface-2);
  color:var(--text); white-space:pre; }
/* A gap between the table and the note under it. */
dialog.modal.kb-help-dialog .modal-desc { margin-top:16px; }
/* A shortcut badge in the account menu, right-aligned against the label. */
.menu-kbd { margin-left:auto; font-family:Consolas,Menlo,monospace; font-size:.72rem;
  padding:1px 6px; border:1px solid var(--border-soft); border-radius:4px;
  background:var(--surface-2); color:var(--text-muted); }
.compose-attachments { padding:8px 20px; font-size:.82rem; color:var(--text-muted);
  border-bottom:1px solid var(--border-soft); }
.compose-att-label { font-weight:600; margin-right:6px; }
.compose-att-item { display:inline-flex; align-items:center; gap:4px; margin-right:14px;
  font-weight:normal; cursor:pointer; }
.compose-att-item input[type="checkbox"] { margin:0; }
.compose-actions { display:flex; align-items:center; justify-content:flex-end; gap:8px; padding:12px 20px; }
/* Always in view: on a long message the row sticks to the bottom of the window
   and the text scrolls under it. bottom:-1px leaves a stuck row one pixel out of
   view, which is how the JS tells stuck from in place (.stuck shows the line).
   The transparent border keeps that line's pixel, so sticking shifts nothing. */
.compose-actions { position:sticky; bottom:-1px; z-index:2; background:var(--surface);
  border-top:1px solid transparent; padding-bottom:calc(12px + env(safe-area-inset-bottom)); }
.compose-actions.stuck { border-top-color:var(--border-soft); box-shadow:0 -8px 12px -10px rgba(46,52,64,.35); }
/* The page scrolls the caret into view at the window's edge, which is under the
   stuck row; this keeps the line being typed above it instead. */
html:has(.compose-actions) { scroll-padding-bottom:80px; }
.compose-discard { margin-right:auto; }
.compose-actions .btn-sm { height:32px; }
.compose-actions .btn-sm:not(.compose-attach-btn) { min-width:112px; }
.compose-actions .btn-primary { padding:8px 24px; }
.compose-attach-btn { cursor:pointer; overflow:hidden; position:relative; }
.compose-attach-btn input[type="file"] { position:absolute; inset:0; opacity:0; cursor:pointer; }
.compose-attach-list { display:flex; flex-wrap:wrap; gap:6px; padding:8px 20px; }
.compose-attach-list:empty { display:none; }
.compose-attach-chip { display:inline-flex; align-items:center; gap:4px; padding:4px 10px;
  background:var(--surface-3); border-radius:16px; font-size:.8rem; color:var(--text); }
.compose-attach-remove { border:none; background:transparent; color:var(--text-muted);
  cursor:pointer; padding:0; font-size:1rem; line-height:1; }
.compose-attach-remove:hover { color:var(--danger); }

/* --- Toasts ------------------------------------------------------------- */
/* Floating notifications pinned to the top-center. position:fixed takes them
   out of the flow entirely, so a toast appearing or leaving never shifts the
   layout under it. The container passes clicks through; each toast claims its
   own, so only the toast itself is clickable (click to dismiss). Centered so a
   stack of toasts reads as a single message rather than a corner pile. The
   background is opaque: it sits over the list or the reader, and a translucent
   tint put the mail underneath the message text. The type rides on the border,
   because --success on --surface is only 3.7:1 at this size. */
.toast-container { position:fixed; top:calc(var(--topbar-h) + 12px); left:50%;
  transform:translateX(-50%); z-index:200; display:flex; flex-direction:column;
  align-items:center; gap:10px; pointer-events:none;
  width:min(360px, calc(100vw - 32px)); }
.toast { pointer-events:auto; padding:12px 16px; border-radius:var(--radius);
  font-size:.88rem; background:var(--surface); color:var(--text);
  border:1px solid var(--border); box-shadow:var(--shadow-md);
  cursor:pointer; animation:toast-in .18s ease-out; }
.toast-success { border-color:var(--success); }
.toast-error { border-color:var(--danger); }
.toast.hiding { opacity:0; transform:translateY(-6px);
  transition:opacity .2s, transform .2s; }
@keyframes toast-in { from { opacity:0; transform:translateY(-6px); } }
@media (prefers-reduced-motion:reduce) {
  .toast { animation:none; }
  .toast.hiding { transition:none; }
}

/* --- Autocomplete ------------------------------------------------------ */
.autocomplete-wrap { position:relative; }
.ac-dropdown { position:absolute; top:calc(100% + 4px); left:0; right:0; background:var(--surface);
  border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-md);
  max-height:220px; overflow-y:auto; display:none; z-index:50; padding:4px; }
.ac-item { padding:9px 11px; border-radius:8px; cursor:pointer; font-size:.88rem; }
.ac-item:hover { background:var(--accent-soft); }
.ac-item .em { color:var(--text-faint); font-size:.78rem; }

/* --- Misc -------------------------------------------------------------- */
.settings-sub { font-size:1.05rem; font-weight:700; margin:26px 0 8px; }
.cache-card { margin-top:24px; padding:24px; }
.cache-row { display:flex; justify-content:space-between; align-items:center; gap:20px; }
.cache-info { flex:1; }
.cache-action { flex-shrink:0; }
.settings-note { color:var(--text-faint); font-size:.82rem; margin-top:6px; line-height:1.5; }

/* Switch. The checkbox stays in the DOM and drives the track through :checked, so
   there is no JS and the keyboard still works. .form-group input sets width:100%,
   which is why the bare checkbox rendered as a stray stretched square. */
.settings-toggle { display:flex; align-items:center; justify-content:space-between; gap:24px; }
.settings-toggle-text { min-width:0; flex:0 1 32rem; }
/* The right-hand column when a row holds more than one control. */
.settings-control { display:flex; align-items:center; gap:12px; flex:0 0 auto; }
/* One line between consecutive option rows, never above the first. */
.settings-toggle + .settings-toggle { border-top:1px solid var(--border); padding-top:16px; }
/* `.form-group select` (0,1,1) sets width:100%; qualified to win outright, not
   on source order, so the select sits on the right at its own width. */
.form-group.settings-toggle select { width:auto; flex:0 0 auto; }
/* Both of these are labels inside a .form-group, so `.form-group label` (0,1,1)
   outranks a lone class. Its margin-bottom:6px is what pushed the switch 3px off
   centre, since flex centring counts margins. Qualified to win outright rather
   than on source order. */
.form-group .settings-toggle-text label { margin-bottom:0; }
.settings-label { font-weight:600; margin-bottom:0; }
.form-group label.switch { display:inline-flex; flex:0 0 auto; align-items:center;
  position:relative; cursor:pointer; margin:0; }
.switch input { position:absolute; inset:0; width:100%; height:100%; margin:0; opacity:0;
  cursor:pointer; }
.switch-track { display:block; width:42px; height:24px; border-radius:999px;
  background:var(--surface-3); border:1px solid var(--border); position:relative;
  transition:background .15s, border-color .15s; }
.switch-track::after { content:""; position:absolute; top:2px; left:2px; width:18px; height:18px;
  border-radius:50%; background:var(--surface); box-shadow:var(--shadow-sm);
  transition:transform .15s; }
.switch input:checked + .switch-track { background:var(--accent); border-color:var(--accent); }
.switch input:checked + .switch-track::after { transform:translateX(18px); background:var(--on-accent); }
.switch input:focus-visible + .switch-track { box-shadow:0 0 0 3px var(--ring); }
@media (prefers-reduced-motion:reduce) {
  .switch-track, .switch-track::after { transition:none; }
}
.hint { padding:12px 20px; font-size:.82rem; color:var(--text-muted);
  background:var(--surface-2); border-bottom:1px solid var(--border-soft); }

/* --- Modals ------------------------------------------------------------ */
dialog.modal { border:none; border-radius:var(--radius-lg); padding:26px 28px; max-width:420px;
  width:calc(100% - 40px); box-shadow:var(--shadow-lg); text-align:left; position:fixed;
  top:50%; left:50%; transform:translate(-50%,-50%); margin:0; background:var(--surface);
  color:var(--text); }
dialog.modal::backdrop { background:rgba(46,52,64,.50); }
body:has(dialog.modal[open]) { overflow:hidden; }
dialog.modal h3 { margin:0 0 32px; font-size:1.1rem; }
dialog.modal .modal-desc { margin:0 0 10px; font-size:.92rem; color:var(--text-muted); }
dialog.modal .modal-actions { display:flex; justify-content:space-between; gap:10px; margin-top:32px; }
dialog.modal .modal-actions button { padding:9px 18px; border-radius:10px; border:none;
  cursor:pointer; font-size:.88rem; font-weight:600; font-family:inherit;
  display:inline-flex; align-items:center; gap:6px; }
dialog.modal .btn-cancel { background:var(--surface-3); color:var(--text); }
dialog.modal .btn-confirm { background:var(--danger); color:#fff; }
dialog.modal .btn-confirm:hover { background:var(--danger-hover); }
dialog.modal .btn-confirm-primary { background:var(--accent); color:#fff; }
dialog.modal .btn-confirm-primary:hover { background:var(--accent-hover); }
dialog.modal .folder-name { width:100%; padding:11px 13px; border:1px solid var(--border);
  border-radius:10px; font-size:.92rem; background:var(--surface); color:var(--text); }
dialog.modal .move-dest { width:100%; padding:11px 13px; border:1px solid var(--border);
  border-radius:10px; font-size:.92rem; background:var(--surface); color:var(--text);
  font-family:inherit; }
#folder-dialog .btn-confirm { background:var(--accent); }
#folder-dialog .btn-confirm:hover { background:var(--accent-hover); }
/* Filing is not destructive, so the confirm button must not read as a delete. */
#move-dialog .btn-confirm { background:var(--accent); }
#move-dialog .btn-confirm:hover { background:var(--accent-hover); }

/* --- Responsive -------------------------------------------------------- */
@media (min-width:901px) { .nav-toggle { display:none; } .nav-backdrop { display:none; } }
@media (max-width:900px) {
  .layout { grid-template-columns:1fr; }
  .sidebar { position:fixed; top:var(--topbar-h); left:0; bottom:0; width:280px; height:auto;
    z-index:60; transform:translateX(-105%); transition:transform .22s ease;
    box-shadow:var(--shadow-lg); }
  body.nav-open .sidebar { transform:translateX(0); }
}
@media (max-width:620px) {
  .topbar { gap:6px; padding:0 10px; flex-wrap:nowrap; }
  .topbar .brand { flex:0 0 auto; }
  .topbar .brand span { display:none; }
  .topbar .nav-toggle { min-width:34px; height:34px; }
  .account-toggle { padding:2px; gap:0; }
  .account-toggle .avatar { width:30px; height:30px; font-size:.8rem; border: 2px solid var(--border); transition: border-color .15s; }
  .account-menu { position:fixed; top:calc(var(--topbar-h) + 4px); right:10px; left:auto;
    width:200px; }
  .mail { grid-template-columns:36px minmax(0,1fr) auto; gap:10px; padding:11px 14px; }
  .mail-avatar { width:36px; height:36px; font-size:.85rem; }
  .card { margin:16px auto; padding:20px 18px; }
  .compose-row { padding:4px 14px; }
  .compose-subject input { padding:10px 14px; }
  .compose-body textarea, .md-editor { padding:10px 14px; min-height:160px; }
  .compose-actions { padding:10px 14px calc(10px + env(safe-area-inset-bottom)); }
  .md-help-row { padding:8px 10px 0; }
  dialog.modal.md-help-dialog { padding:20px 16px; }
  .md-help-table td:first-child { white-space:normal; }
  /* Back plus six actions with text needs ~500px, so the labels go and the whole
     bar becomes icons. aria-label keeps each action named. */
  .btn-label { display:none; }
  .actions-bar .btn-sm { width:32px; min-width:32px; padding:0; }
  /* The selector repeats the desktop min-width rule so it outranks it. */
  .compose-actions .btn-sm, .compose-actions .btn-sm:not(.compose-attach-btn) { width:32px; min-width:32px; padding:0; }
  .search input[type=text] { width:120px; }
  /* A shade tighter, so the count never pushes the sender out of the row. */
  .mail-thread-count { font-size:.68rem; padding:1px 5px; }
  .thread-msg > summary { gap:8px; }
}

/* --- Utilities --------------------------------------------------------- */
/* .compose-row.hidden is spelled out because .compose-row sets display:flex
   at the same specificity, so a bare .hidden would depend on source order. */
.hidden, .compose-row.hidden { display:none; }
</style>
CSS;
}

function js_inline(): string
{
  return <<<'JS'
(function(){
  'use strict';
  var doc = document;
  var win = window;
  var slice = Array.prototype.slice;

  // --- $ : a small jQuery-like toolkit shared by the whole UI -------------
  // A chainable wrapper over a set of nodes. Start with a selector, a node,
  // a NodeList, the window or the document, then chain:
  //   $('.foo').on('click', fn).addClass('bar')...
  // Also exposes statics used across pages: $.ready, $.get, $.post,
  // $.param (CSRF-aware), $.escapeHtml and $.token.
  // ------------------------------------------------------------------------

  function $(sel, ctx){
    var nodes;
    if (sel instanceof $) {
      return sel;
    } else if (typeof sel === 'string') {
      nodes = slice.call((ctx || doc).querySelectorAll(sel));
    } else if (sel === win || sel === doc) {
      nodes = [sel];
    } else if (sel && sel.nodeType) {
      nodes = [sel];
    } else if (sel && typeof sel.length === 'number') {
      nodes = slice.call(sel);
    } else {
      nodes = [];
    }
    return new Em(nodes);
  }

  function Em(nodes){
    for (var i = 0; i < nodes.length; i++) this[i] = nodes[i];
    this.length = nodes.length;
  }

  $.escapeHtml = function(s){
    if (s === null || s === undefined) return '';
    var d = doc.createElement('div');
    d.textContent = String(s);
    return d.innerHTML;
  };
  win.$ = $; // expose the toolkit to other inline scripts
  $.token = (function(){
    var m = doc.querySelector('meta[name="csrf"]');
    return m ? m.getAttribute('content') : (win.__csrf || '');
  })();
  $.ready = function(fn){
    if (doc.readyState === 'loading') doc.addEventListener('DOMContentLoaded', fn);
    else fn();
  };
  $.param = function(data){
    var body = new URLSearchParams();
    body.set('csrf_token', $.token);
    for (var k in data) {
      if (data.hasOwnProperty(k) && data[k] !== undefined) body.set(k, data[k]);
    }
    return body.toString();
  };
  $.post = function(url, data){
    return win.fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: $.param(data)
    });
  };
  $.get = function(url){
    return win.fetch(url).then(function(r){ return r.json(); });
  };

  Em.prototype.each = function(fn){
    for (var i = 0; i < this.length; i++) {
      if (fn.call(this[i], i, this[i]) === false) break;
    }
    return this;
  };
  Em.prototype.on = function(type, sel, fn){
    if (typeof sel === 'function') {
      fn  = sel;
      sel = null;
    }
    if (!sel) {
      return this.each(function(){ this.addEventListener(type, fn); });
    }
    // Delegated binding: fn runs with `this` set to the matched descendant.
    return this.each(function(){
      this.addEventListener(type, function(e){
        var t = e.target;
        while (t && t !== this) {
          if (t.matches && t.matches(sel)) { fn.call(t, e); return; }
          t = t.parentNode;
        }
      });
    });
  };
  Em.prototype.addClass = function(cls){
    var names = String(cls).split(/\s+/);
    return this.each(function(){
      for (var i = 0; i < names.length; i++) if (names[i]) this.classList.add(names[i]);
    });
  };
  Em.prototype.removeClass = function(cls){
    var names = String(cls).split(/\s+/);
    return this.each(function(){
      for (var i = 0; i < names.length; i++) if (names[i]) this.classList.remove(names[i]);
    });
  };
  Em.prototype.toggleClass = function(cls, on){
    return this.each(function(){ this.classList.toggle(cls, on); });
  };
  Em.prototype.hasClass = function(cls){
    for (var i = 0; i < this.length; i++) {
      if (this[i].classList.contains(cls)) return true;
    }
    return false;
  };
  Em.prototype.text = function(v){
    if (v === undefined) return this[0] ? this[0].textContent : '';
    return this.each(function(){ this.textContent = v; });
  };
  Em.prototype.html = function(v){
    if (v === undefined) return this[0] ? this[0].innerHTML : '';
    return this.each(function(){ this.innerHTML = v; });
  };
  Em.prototype.val = function(v){
    if (v === undefined) return this[0] ? this[0].value : '';
    return this.each(function(){ this.value = v; });
  };
  Em.prototype.css = function(name, v){
    if (v === undefined) return this[0] ? win.getComputedStyle(this[0])[name] : '';
    return this.each(function(){ this.style[name] = v; });
  };
  Em.prototype.attr = function(name, v){
    if (v === undefined) return this[0] ? this[0].getAttribute(name) : '';
    return this.each(function(){ this.setAttribute(name, v); });
  };
  Em.prototype.data = function(name){
    return this[0] ? this[0].getAttribute('data-' + name) : '';
  };
  Em.prototype.show = function(){ return this.css('display', ''); };
  Em.prototype.hide = function(){ return this.css('display', 'none'); };
  Em.prototype.toggle = function(){
    return this.each(function(){
      this.style.display = (this.style.display === 'none') ? '' : 'none';
    });
  };
  Em.prototype.parent = function(){ return $(this[0] ? this[0].parentNode : null); };
  Em.prototype.find = function(sel){
    var out = [];
    this.each(function(){
      var found = this.querySelectorAll(sel);
      for (var i = 0; i < found.length; i++) out.push(found[i]);
    });
    return $(out);
  };
  Em.prototype.append = function(htmlOrNode){
    return this.each(function(){
      if (typeof htmlOrNode === 'string') this.insertAdjacentHTML('beforeend', htmlOrNode);
      else this.appendChild(htmlOrNode);
    });
  };
  Em.prototype.get = function(i){
    return i === undefined ? slice.call(this) : this[i];
  };
  Em.prototype.remove = function(){
    return this.each(function(){
      if (this.parentNode) this.parentNode.removeChild(this);
    });
  };

  // --- Optimistic message actions -----------------------------------------
  //
  // Every list action patches the row first and tells the server after, so
  // nothing waits on a round trip. A refusal or a dead network puts the row
  // back: a UI that lies about what happened is worse than a reload.
  //
  // Removal hides the row and only detaches it once the server confirms, which
  // means rolling back never has to re-insert a node at the right index.

  function rowFor(uid){
    return doc.querySelector('.mail[data-uid="' + uid + '"]');
  }

  function rowError(msg){
    win.toast(msg || 'That did not work. Nothing was changed.', 'error');
  }

  // Floating toast. Appends into the fixed .toast-container render_page() ships,
  // so it can never move the layout. Auto-dismisses after a short while (delays
  // are overridable via win.__toastMs for tests) and on click. The container
  // passes clicks through; the toast itself claims its own.
  win.toast = function(msg, type){
    type = type || 'error';
    var container = doc.querySelector('.toast-container');
    if (!container) return null;
    var el = doc.createElement('div');
    el.className = 'toast toast-' + type;
    el.setAttribute('role', type === 'success' ? 'status' : 'alert');
    el.textContent = msg || '';
    container.appendChild(el);
    var leaving = false;
    var dismiss = function(){
      if (leaving) return;
      leaving = true;
      el.classList.add('hiding');
      setTimeout(function(){
        if (el.parentNode) el.parentNode.removeChild(el);
      }, 220);
    };
    var ms = (win.__toastMs && win.__toastMs[type]) || (type === 'success' ? 4000 : 6000);
    setTimeout(dismiss, ms);
    el.addEventListener('click', dismiss);
    return el;
  };

  function bumpCount(by){
    var el = doc.querySelector('.mail-count');
    if (!el) return;
    var n = parseInt(String(el.textContent).replace(/[^0-9]/g, ''), 10);
    if (isNaN(n)) return;
    el.textContent = '(' + Math.max(0, n + by) + ')';
  }

  // Sidebar counters. `folder` is urlencoded, like data-folder. A folder with no
  // badge (Sent, Trash, Archive) has no span, so both are no-ops there. `kind`
  // limits a bump to 'unseen' or 'total' badges; omitted, any kind moves.
  // Returns the change actually applied (clamped at 0), so an undo can reverse
  // exactly that.
  function badgeFor(folder){
    var links = doc.querySelectorAll('.folder-link');
    for (var i = 0; i < links.length; i++) {
      if (links[i].getAttribute('data-folder') === String(folder)) {
        return links[i].querySelector('.folder-count');
      }
    }
    return null;
  }
  function setBadge(el, n){
    el.textContent = String(n);
    if (n > 0) el.removeAttribute('hidden'); else el.setAttribute('hidden', '');
    syncTitle();
  }
  function bumpBadge(folder, by, kind){
    var el = badgeFor(folder);
    if (!el || (kind && el.getAttribute('data-kind') !== kind)) return 0;
    var n = parseInt(el.textContent, 10);
    if (isNaN(n)) return 0;
    var m = Math.max(0, n + by);
    setBadge(el, m);
    return m - n;
  }

  function setReadIcon(row, unread){
    // The icon is the button's own. The row's first <use> is the subject's
    // paperclip on a message with an attachment, which this used to repaint.
    var btn = row.querySelectorAll('[data-read-toggle]')[0];
    if (!btn) return;
    var use = btn.querySelectorAll('use')[0];
    if (use) use.setAttribute('href', unread ? '#tabler-mail' : '#tabler-mail-opened');
    var label = unread ? 'Mark read' : 'Mark unread';
    btn.setAttribute('data-tip', label);
    btn.setAttribute('aria-label', label);
  }

  win.rowAction = function(uid, folder, url, mode, extra){
    var row = rowFor(uid);
    var fields = { uid: uid, folder: folder, ajax: '1' };
    if (extra) {
      for (var k in extra) { if (Object.prototype.hasOwnProperty.call(extra, k)) fields[k] = extra[k]; }
    }
    var undo;
    if (!row) {
      // The reader has no row to patch, so there is nothing to be optimistic
      // about: follow the message out of the folder once it is filed. The wait is
      // real and has nowhere else to show, so the bar reports it.
      $navBar.addClass('loading');
      undo = clearBusy;
    } else if (mode === 'flag') {
      var nowUnread = !row.classList.contains('unread');
      row.classList.toggle('unread', nowUnread);
      setReadIcon(row, nowUnread);
      var flagged = bumpBadge(folder, nowUnread ? 1 : -1, 'unseen');
      undo = function(){ row.classList.toggle('unread', !nowUnread); setReadIcon(row, !nowUnread); bumpBadge(folder, -flagged); };
    } else {
      row.classList.add('row-gone');
      bumpCount(-1);
      // A Drafts-style total drops for any row; an unseen count only for an unread one.
      var removed = bumpBadge(folder, -1, row.classList.contains('unread') ? null : 'total');
      undo = function(){ row.classList.remove('row-gone'); bumpCount(1); bumpBadge(folder, -removed); };
    }
    return $.post(url, fields).then(function(r){ return r.json(); }).then(function(d){
      if (!d || d.ok === false || d.success === false) {
        undo();
        rowError(d && d.message);
        return;
      }
      if (row && mode !== 'flag' && row.parentNode) { row.parentNode.removeChild(row); }
      if (!row) { win.location.href = '?page=folder&folder=' + folder; }
    }).catch(function(){
      undo();
      rowError('Offline. Nothing was changed.');
    });
  };

  // --- Bulk actions ---------------------------------------------------------
  // One request for the whole selection. The rows are patched here rather than
  // by N calls to rowAction, because N calls is N round trips for what the
  // server already does in one, and because a batch is all-or-nothing at the
  // IMAP layer: a comma sequence naming one stale UID is refused as a unit, so
  // the client must be able to undo all of it when the count comes back short.
  //
  // `unread` is an explicit direction. win.rowAction's flag mode derives it per
  // row from .unread, which is unambiguous for one row and wrong for N: on a
  // mixed set "Mark read" would toggle the already-read rows the other way,
  // painted instantly.
  win.bulkAction = function(action, unread){
    var uids = pickUids();
    if (!uids.length) return;
    var folder = bulkFolder();
    // The patched state is captured BEFORE the request and the undo is built
    // from it. A closure that re-queries the DOM finds nothing in a browser
    // once a row is removed, so the undo would silently no-op.
    var patched = [];
    var badgeTotal = 0;
    for (var i = 0; i < uids.length; i++) {
      var row = rowFor(uids[i]);
      if (!row) continue;
      var wasUnread = row.classList.contains('unread');
      row.classList.toggle('unread', unread);
      setReadIcon(row, unread);
      // bumpBadge returns what it actually applied, so the deltas sum to one
      // exact undo. A single call cannot: with an unseen badge of 12 and five
      // rows deleted of which three were unread, the server says 9 and one
      // bumpBadge(-5) would paint 7. It also corrupts doc.title via setBadge.
      // The kind is always the unseen count: a flag change moves it either way,
      // and a total badge (Drafts) is left alone by the kind mismatch, exactly as
      // a single row's toggle leaves it alone.
      if (unread !== wasUnread) {
        badgeTotal += bumpBadge(folder, unread ? 1 : -1, 'unseen');
      }
      patched.push({ row: row, wasUnread: wasUnread });
    }
    // The selection goes with the repaint, not with the reply: rows that already
    // show their new state but stay ticked for the round trip look unfinished.
    // undo() does not reset it again, because by then it may be a NEW selection
    // the user made while the request was in flight.
    pickReset();
    var url = action === 'read' ? '?page=markRead' : '?page=markUnread';
    var fields = { uids: uids.join(','), folder: folder, ajax: '1' };
    function undo(){
      for (var i = 0; i < patched.length; i++) {
        patched[i].row.classList.toggle('unread', patched[i].wasUnread);
        setReadIcon(patched[i].row, patched[i].wasUnread);
      }
      bumpBadge(folder, -badgeTotal);
    }
    return $.post(url, fields).then(function(r){ return r.json(); }).then(function(d){
      // count < picked is a lie from the server, not a display detail: a batch
      // refused as a unit changed nothing, so every row has to go back.
      var applied = d && typeof d.count === 'number' ? d.count : 0;
      if (!d || d.ok === false || d.success === false || applied < uids.length) {
        undo();
        rowError(d && d.message ? d.message : 'Nothing was changed.');
        return;
      }
      // The rows STAY. A flag change does not take a message out of the folder,
      // so nothing is removed here: delete and move remove rows because the
      // message left, and copying that into this path made the list shorter than
      // the server's until a reload. The selection is already gone, see above.
    }).catch(function(){
      undo();
      rowError('Offline. Nothing was changed.');
    });
  };

  // The folder every bulk action posts. The bar is the one element that always
  // carries it, and it is read fresh on each call because a swap replaces it.
  function bulkFolder(){
    var bar = doc.querySelector('.bulk-bar');
    var f = bar ? bar.getAttribute('data-bulk-folder') : '';
    return f || '';
  }

  // The bulk move reuses the existing dialog: there is one picker for the whole
  // app and a second one would be a second copy of the same list to keep in
  // sync. openConfirm-style, the dialog carries _uids instead of _uid.
  win.bulkMoveTo = function(){
    var dlg = doc.querySelector('#move-dialog');
    var uids = pickUids();
    if (!dlg || !uids.length) return;
    dlg._uid = null;
    dlg._uids = uids;
    dlg._folder = bulkFolder();
    if (typeof dlg.showModal === 'function') dlg.showModal();
  };

  // Bulk delete is the only irreversible one, so it takes the same confirm
  // dialog as a single delete. The COPY is the server's, rendered with the
  // count left open, because only the server knows whether this folder is Trash.
  win.bulkDelete = function(){
    var uids = pickUids();
    var bar = doc.querySelector('.bulk-bar');
    if (!uids.length || !bar) return;
    var folder = bulkFolder();
    var copy = bar.getAttribute('data-bulk-delete-copy') || 'Delete %d messages?';
    var title = copy.replace(/%d/g, String(uids.length));
    openConfirm('?page=delete', { uids: uids.join(','), folder: folder, ajax: '1' }, title, '', 'Delete');
    var dlg = doc.querySelector('#delete-dialog');
    if (!dlg) return;
    dlg._run = function(){
      markBusy(readerControl('[data-delete]'));
      bulkRemove(uids, folder, '?page=delete');
    };
  };

  // The removal half of a bulk action, shared by delete and move: mark the rows
  // gone, bump the list count and the folder badge once per row, then post.
  // The nodes are captured up front -- a closure that re-queried the DOM would
  // find nothing in a browser after a confirmed remove, and the undo would
  // silently no-op.
  function bulkRemove(uids, folder, url, extra){
    var fields = { uids: uids.join(','), folder: folder, ajax: '1' };
    if (extra) {
      for (var ek in extra) { if (Object.prototype.hasOwnProperty.call(extra, ek)) fields[ek] = extra[ek]; }
    }
    var patched = [];
    var badgeTotal = 0, countTotal = 0;
    for (var i = 0; i < uids.length; i++) {
      var row = rowFor(uids[i]);
      if (!row) continue;
      row.classList.add('row-gone');
      countTotal += 1;
      // The header total steps down per row here, not once for the batch: the
      // rows are collapsed one at a time, and a header still reading the old
      // total while rows vanish is exactly the drift the undo below reverses.
      bumpCount(-1);
      badgeTotal += bumpBadge(folder, -1, row.classList.contains('unread') ? null : 'total');
      patched.push({ row: row });
    }
    function undo(){
      for (var i = 0; i < patched.length; i++) patched[i].row.classList.remove('row-gone');
      bumpCount(countTotal);
      bumpBadge(folder, -badgeTotal);
      pickReset();
    }
    return $.post(url, fields)
      .then(function(r){ return r.json(); }).then(function(d){
        var applied = d && typeof d.count === 'number' ? d.count : 0;
        if (!d || d.ok === false || d.success === false || applied < uids.length) {
          undo();
          rowError(d && d.message ? d.message : 'Nothing was changed.');
          return;
        }
        for (var i = 0; i < patched.length; i++) {
          if (patched[i].row.parentNode) patched[i].row.parentNode.removeChild(patched[i].row);
        }
        pickReset();
      }).catch(function(){
        undo();
        rowError('Offline. Nothing was changed.');
      });
  }

  // One button, both directions: the row's own class says which way to go, so
  // the markup never has to rewrite its own onclick.
  win.toggleRead = function(uid, folder){
    var row = rowFor(uid);
    var unread = row ? row.classList.contains('unread') : false;
    var thread = row ? (row.getAttribute('data-thread') || '') : '';
    if (!thread) {
      return win.rowAction(uid, folder, unread ? '?page=markRead' : '?page=markUnread', 'flag');
    }
    // A conversation row marks every member, across folders, not just the
    // anchor. The badge is corrected from the server's count of flipped members
    // in this folder, because the row's own class cannot say how many were unread.
    var read = unread;
    if (row) { row.classList.toggle('unread', !read); setReadIcon(row, !read); }
    return $.post('?page=markRead', { thread: thread, folder: folder }).then(function(r){ return r.json(); }).then(function(d){
      if (!d || d.success === false || d.ok === false) {
        if (row) { row.classList.toggle('unread', read); setReadIcon(row, read); }
        rowError(d && d.message);
        return;
      }
      var n = typeof d.count === 'number' ? d.count : 0;
      if (n) bumpBadge(folder, read ? -n : n, 'unseen');
    }).catch(function(){
      if (row) { row.classList.toggle('unread', read); setReadIcon(row, read); }
      rowError('Offline. Nothing was changed.');
    });
  };

  // Read view only. Reloading here would hit handle_read again, which marks the
  // message read, so this one leaves for the listing instead.
  win.markUnreadBack = function(uid, folder){
    return $.post('?page=markUnread', { uid: uid, folder: folder }).then(function(){
      win.location.href = '?page=folder&folder=' + folder;
    }).catch(function(){
      clearBusy();
      rowError('Offline. Nothing was changed.');
    });
  };

  // Login page: reveal custom IMAP/SMTP fields only when that preset is picked.
  $('#login-preset').on('change', function(){
    $('.custom-servers').css('display', (this.value === 'custom') ? 'block' : 'none');
  });

  // Login page: remember the last email in localStorage, put the cursor where
  // the next keystroke belongs (email only when blank, password otherwise), and
  // spin the Sign in button while the POST is in flight. A second submit is
  // refused: the first one is already on its way. The preset rides along, and
  // with it the six custom server fields, so a return visit signs in against the
  // same servers. localStorage is the only memory here: the server has no email
  // to look the settings up by until one is typed.
  var $loginEmail = $('#login-email');
  if ($loginEmail.length) {
    var storedEmail = '';
    try { storedEmail = win.localStorage.getItem('emmmail-last-email') || ''; } catch (e) {}
    if (!$loginEmail.val() && storedEmail) $loginEmail.val(storedEmail);
    var $loginPass = $('#login-password');
    if ($loginEmail.val()) {
      if ($loginPass[0] && $loginPass[0].focus) $loginPass[0].focus();
    } else if ($loginEmail[0] && $loginEmail[0].focus) {
      $loginEmail[0].focus();
    }

    // The six fields view_login() renders inside .custom-servers.
    var serverFields = ['imap_server', 'imap_port', 'imap_enc',
      'smtp_server', 'smtp_port', 'smtp_enc'];
    var $loginPreset = $('#login-preset');
    var storedPreset = '';
    var storedServers = null;
    try {
      storedPreset = win.localStorage.getItem('emmmail-last-preset') || '';
      storedServers = JSON.parse(win.localStorage.getItem('emmmail-last-custom') || 'null');
    } catch (e) {}
    var serverPreset = $loginPreset.val();
    if (storedPreset) {
      $loginPreset.val(storedPreset);
      // A key this release has no option for blanks the select in a real browser
      // (one with no matching option reports ''), so it falls back to the server's.
      if (!$loginPreset.val()) $loginPreset.val(serverPreset);
    }
    // Setting the value fires no change event, so the block is opened here too.
    $('.custom-servers').css('display', ($loginPreset.val() === 'custom') ? 'block' : 'none');
    // Overwrite rather than fill-if-blank: a fresh visit renders the ports and
    // the encryption selects as defaults, not blanks, so a blank test would lose
    // the remembered value. The server only renders custom values after a failed
    // POST, and that submit has already saved exactly those.
    if (storedServers) {
      for (var sf = 0; sf < serverFields.length; sf++) {
        var $sf = $('[name="' + serverFields[sf] + '"]');
        if (!$sf.length || storedServers[serverFields[sf]] === undefined) continue;
        var serverValue = $sf.val();
        $sf.val(storedServers[serverFields[sf]]);
        // A select with no such option reports '' and then renders blank, so an
        // unknown value falls back to what the server sent. An honestly empty
        // input keeps its empty value.
        if (serverValue !== '' && $sf.val() === '') $sf.val(serverValue);
      }
    }

    $loginEmail[0].closest('form').addEventListener('submit', function(ev){
      var btn = this.querySelectorAll('.btn-primary')[0];
      if (btn && btn.classList.contains('busy')) { ev.preventDefault(); return; }
      try { win.localStorage.setItem('emmmail-last-email', $loginEmail.val() || ''); } catch (e) {}
      // A locked page has no preset to remember; saving blanks would wipe the
      // servers a user remembered from an unlocked deployment.
      if ($loginPreset.length) try {
        win.localStorage.setItem('emmmail-last-preset', $loginPreset.val() || '');
        var servers = {};
        for (var sf2 = 0; sf2 < serverFields.length; sf2++) {
          servers[serverFields[sf2]] = $('[name="' + serverFields[sf2] + '"]').val();
        }
        win.localStorage.setItem('emmmail-last-custom', JSON.stringify(servers));
      } catch (e) {}
      markBusy(this.querySelectorAll('.btn-primary')[0]);
    });
  }

  // Color theme: cycle stored value; missing value follows the OS preference.
  var themeRoot = doc.documentElement;
  var $tgl = $('[data-theme-toggle]');
  if ($tgl.length) {
    $tgl.on('click', function(){
      var cur = themeRoot.getAttribute('data-theme') || '';
      var next = (cur === 'dark') ? 'light' : 'dark';
      themeRoot.setAttribute('data-theme', next);
      try { win.localStorage.setItem('emmmail-theme', next); } catch (e) {}
    });
  }

  // Account (avatar) menu: the toggle opens it, an outside click or Escape
  // closes it, and picking an item closes it before navigating.
  var $acc = $('[data-account-menu]');
  if ($acc.length) {
    var $accBox = $('.account');
    var closeMenu = function(){
      if ($accBox.length) $accBox.removeClass('open');
      $acc.attr('aria-expanded', 'false');
    };
    $acc.on('click', function(){
      var open = $accBox.length && $accBox.hasClass('open');
      if (open) { closeMenu(); return; }
      if ($accBox.length) $accBox.addClass('open');
      $acc.attr('aria-expanded', 'true');
    });
    if (doc.addEventListener) {
      doc.addEventListener('click', function(ev){
        var t = ev.target;
        var inMenu = t && t.closest ? (t.closest('.account') || t.closest('[data-account-menu]')) : null;
        if (!inMenu) closeMenu();
      });
      doc.addEventListener('keydown', function(ev){
        if (ev.key === 'Escape' || ev.keyCode === 27) closeMenu();
      });
    }
    $('.account-menu a, .account-menu button').on('click', function(){ closeMenu(); });
  }

  // Folders drawer on narrow screens: the hamburger opens it, the backdrop closes it.
  var $navTgl = $('[data-nav-toggle]');
  if ($navTgl.length) {
    $navTgl.on('click', function(){ doc.body.classList.toggle('nav-open'); });
    $('[data-nav-close]').on('click', function(){ doc.body.classList.remove('nav-open'); });
    // Navigating in the drawer should close it before the next page loads.
    $('.sidebar a, .sidebar button').on('click', function(){ doc.body.classList.remove('nav-open'); });
  }

  // Read page: size the sandboxed email iframe to fit its content, so the body
  // is read in the page and never in a nested scrollbar.
  function fitOne(frame){
    // A cross-origin frame throws here rather than returning null. Never let that
    // take the rest of the handler with it.
    var innerDoc = null;
    try { innerDoc = frame.contentWindow ? frame.contentWindow.document : null; } catch (e) { return null; }
    var body = innerDoc ? innerDoc.body : null;
    if (!body) return null;
    // HTML mail routinely puts its margins on <html>, and then body.scrollHeight
    // alone is short and the tail of the message is clipped.
    var root = innerDoc.documentElement;
    var height = Math.max(body.scrollHeight || 0, root ? (root.scrollHeight || 0) : 0);
    frame.style.height = (height + 30) + 'px';
    // Only once the height is known is it safe to kill the inner scrollbar. Left
    // in CSS it would truncate the message on any browser that blocks the
    // measurement, which is worse than a nested scrollbar.
    frame.style.overflow = 'hidden';
    return body;
  }
  function fitSrcdoc(){
    $('iframe.srcdoc').each(function(){
      var frame = this;
      var body = fitOne(frame);
      // Images and web fonts settle after load and change the height. Observe the
      // body once, so a late reflow resizes the frame instead of clipping it.
      if (body && win.ResizeObserver && !frame._ro) {
        frame._ro = new win.ResizeObserver(function(){ fitOne(frame); });
        frame._ro.observe(body);
      }
    });
  }
  $('iframe.srcdoc').on('load', fitSrcdoc);
  // srcdoc can finish loading before this script runs, so the load listener above
  // may never fire. Window load is the backstop.
  $(win).on('load', fitSrcdoc);
  // Opening a collapsed message in a conversation gives its iframe a real box,
  // so its height has to be measured again.
  $('details.thread-msg').on('toggle', function(){ if (this.hasAttribute('open')) fitSrcdoc(); });
  fitSrcdoc();

  // Navigation in flight. The row that was clicked marks itself, and this reports
  // the wait at the top of the page.
  var $navBar = $('.nav-progress');

  // A control that has started work it cannot cancel. Its own icon becomes the
  // spinner -- remembered in data-icon-was, so it can be put back -- and the
  // control stops taking clicks, which is what keeps a second Delete from being
  // fired into the same message.
  function markBusy(el){
    if (!el || el.classList.contains('busy')) return;
    var use = el.querySelectorAll('use')[0];
    if (use) {
      el.setAttribute('data-icon-was', use.getAttribute('href') || '');
      use.setAttribute('href', '#tabler-progress');
    }
    el.classList.add('busy');
    $navBar.addClass('loading');
  }
  // Called whenever the work ends without this page going away: a refusal, an
  // offline post, or the browser restoring the page after Back. Anything that
  // leaves a control spinning for ever is worse than the wait it was reporting.
  function clearBusy(){
    $('.busy').each(function(){
      var use = this.querySelectorAll('use')[0];
      var was = this.getAttribute('data-icon-was');
      if (use && was) use.setAttribute('href', was);
      this.removeAttribute('data-icon-was');
      this.classList.remove('busy');
    });
    $navBar.removeClass('loading');
  }

  // Reader actions. Back, Reply, Reply all and Forward navigate, and Unread and
  // Spam post immediately, so all of them are working the moment they are clicked.
  // Move and Delete are excluded: they open a dialog that can still be cancelled,
  // and their own code paths mark them once the dialog is confirmed.
  // The message the reader is working on. A single message is the one `.card`;
  // a conversation has one open <details.thread-msg> per message, and the last
  // open one is the message the keyboard and the dialog-first controls target.
  function activeThreadCard(){
    var all = doc.querySelectorAll('.thread-msg');
    var last = null;
    for (var i = 0; i < all.length; i++) {
      if (all[i].hasAttribute('open')) last = all[i];
    }
    return last;
  }

  // The reader's own copy of a control. Scoped to the active message inside a
  // conversation, else to .actions-bar, because a list page has one [data-delete]
  // per row and none of those belong to this.
  function readerControl(sel){
    var active = activeThreadCard();
    if (active) {
      var bar = active.querySelectorAll('.actions-bar')[0];
      return bar ? bar.querySelectorAll(sel)[0] : null;
    }
    return $('.actions-bar').find(sel)[0];
  }

  // Move and Delete are excluded here: they open a dialog that can still be
  // cancelled, so their own confirm paths mark them instead.
  function roundTripClick(){
    if (this.hasAttribute('data-move') || this.hasAttribute('data-delete')) return;
    markBusy(this);
  }
  // The composer. Send is the longest wait in the app -- an SMTP conversation, plus
  // the upload of any attachment -- and Save draft is an IMAP append. Both are
  // submits, so the submit event is the honest hook: `to` and `subject` are
  // required, and a submit the browser refuses never fires, so a validation error
  // cannot leave a button spinning.
  //
  // ev.submitter names the button that was pressed, which matters because Save
  // draft is not a send; Safari before 15.4 does not provide it, so the primary
  // action is the fallback. Attaching (a label over a file input) and the Cc/Bcc
  // toggles are untouched: neither is a round trip.
  $('.compose-form').on('submit', function(ev){
    var form = this;
    var btn = ev.submitter || form.querySelectorAll('.btn-primary')[0];
    // A second send while one is in flight is refused: the primary button is
    // already busy (a mouse user can double-click, and Cmd+Enter fires twice).
    if (btn && btn.classList.contains('busy')) { ev.preventDefault(); return; }
    // Sending is always a round trip, undo or not: the button reports the wait
    // and the chrome bar runs. A normal submit navigates away and `pageshow`
    // clears both, so nothing has to un-spin it here.
    markBusy(btn);
    // Check if undo send is enabled (hidden input added by PHP). Only the AJAX
    // interception is conditional on it: with undo off the browser posts the
    // form itself.
    var undoInput = form.querySelector('input[name="undo_period_seconds"]');
    var undoSeconds = undoInput ? parseInt(undoInput.value, 10) : 0;
    if (undoSeconds <= 0) return;
    ev.preventDefault();
    var fd = new FormData(form);
    fd.append('ajax', '1');
    win.fetch(form.action, { method: 'POST', body: fd })
      .then(function(r){ return r.json(); })
      .then(function(d){
        clearBusy();
        if (!d.ok) {
          alert(d.error || 'Send failed.');
          return;
        }
        if (d.undo) {
          // Show the undo countdown bar.
          var bar = doc.createElement('div');
          bar.className = 'undo-bar';
          bar.innerHTML = '<span class="undo-bar-text">Message sent.</span>'
            + '<span class="undo-bar-countdown">' + d.seconds + 's</span>'
            + '<button type="button" class="btn btn-sm undo-bar-btn">Undo</button>';
          doc.body.appendChild(bar);
          var remaining = d.seconds;
          var timer = win.setInterval(function(){
            remaining--;
            var cd = bar.querySelector('.undo-bar-countdown');
            if (cd) cd.textContent = remaining + 's';
            if (remaining <= 0) {
              win.clearInterval(timer);
              bar.remove();
              // Flush the message.
              var fd2 = new FormData();
              fd2.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
              fd2.append('token', d.token);
              fd2.append('ajax', '1');
              win.fetch('?page=flushUndo', { method: 'POST', body: fd2 });
            }
          }, 1000);
          bar.querySelector('.undo-bar-btn').addEventListener('click', function(){
            win.clearInterval(timer);
            bar.remove();
            var fd3 = new FormData();
            fd3.append('csrf_token', form.querySelector('input[name="csrf_token"]').value);
            fd3.append('token', d.token);
            fd3.append('ajax', '1');
            win.fetch('?page=undoSend', { method: 'POST', body: fd3 })
              .then(function(r){ return r.json(); })
              .then(function(res){
                if (res.ok) {
                  // Re-enable form for editing.
                  form.querySelector('button[type="submit"]').disabled = false;
                }
              });
          });
        } else {
          win.location.href = '?page=inbox';
        }
      })
      .catch(function(){
        clearBusy();
        alert('Network error. Please try again.');
      });
  });
  // The sticky action row draws its separator only while it is stuck. A stuck
  // row sits 1px out of view (bottom:-1px), so it is never fully visible.
  $('.compose-actions').each(function(){
    if (!win.IntersectionObserver) return;
    var row = this;
    new win.IntersectionObserver(function(entries){
      entries.forEach(function(e){ $(row).toggleClass('stuck', e.intersectionRatio < 1); });
    }, { threshold: [1] }).observe(row);
  });

  // --- Markdown: the composer's editor DOM back to Markdown -----------------
  // The editor shows md_to_html() output, then whatever the browser's editing
  // commands make of it (<b>, <i>, &nbsp;, stray spans, nested lists). This
  // writes it back as the Markdown the server renders, and escapes plain text
  // exactly as md_escape() does, so an untouched draft round-trips byte for
  // byte. mdEscape/mdAutolink/mdUrlOk mirror their PHP namesakes; tests/js.php
  // checks them against the PHP output.
  var MD_ESC = "\\`*_[]()#->!~.";
  function mdIsWs(c){ return c === ' ' || c === '\t'; }
  function mdIsWord(c){ return /[A-Za-z0-9]/.test(c) || c > '\x7f'; }
  function mdCount(s, c){ return s.split(c).length - 1; }
  function mdUrlOk(url, image){
    if (!url || /[\x00-\x20\x7f\\]/.test(url)) return false;
    return image ? /^https:\/\/[^\/?#]/i.test(url) : /^(?:https?:\/\/[^\/?#]|mailto:.)/i.test(url);
  }
  function mdAutolink(s, i){
    var head = s.substr(i, 8).toLowerCase();
    if (head !== 'https://' && head.slice(0, 7) !== 'http://') return '';
    if (i > 0 && mdIsWord(s.charAt(i - 1))) return '';
    var url = /^[^ \t<>"`\\]*/.exec(s.slice(i, i + 2049))[0];
    if (url.length > 2048) return '';
    while (url) {
      var last = url.charAt(url.length - 1);
      if (".,:;!?*_~'\"".indexOf(last) >= 0 || (last === ')' && mdCount(url, '(') < mdCount(url, ')'))) {
        url = url.slice(0, -1);
        continue;
      }
      break;
    }
    return mdUrlOk(url, false) ? url : '';
  }
  // A URL as a link target: kept when md_to_html() would read it back whole,
  // otherwise whitespace and parentheses are percent-encoded.
  function mdUrlOut(url){
    if (/^(?:[^\s()]|\([^\s()]*\))*$/.test(url)) return url;
    return url.replace(/[\s()]/g, function(c){ return '%' + ('0' + c.charCodeAt(0).toString(16).toUpperCase()).slice(-2); });
  }
  // Escape one run of plain text. before/after are the characters printed
  // next to it; atStart applies the line-start (block) rules too.
  function mdEscape(text, before, after, atStart, inLabel){
    var esc = {}, out = '', ctx = before + text + after, i, c, b, a, e, url, m;
    if (atStart) {
      var lead = /^[ \t]*/.exec(text)[0].length, rest = text.slice(lead) + after;
      if (rest.slice(0, 2) === '\u2014-') esc[lead + 1] = true;
      else if (/^(?:#{1,6}[ \t]+\S|>|[-*][ \t]|\u2022[ \t]|([-*_])(?:[ \t]*\1){2,}[ \t]*$)/.test(rest)) esc[lead] = true;
      else if ((m = /^(\d{1,9})\.[ \t]/.exec(rest))) esc[lead + m[1].length] = true;
    }
    for (i = 0; i < text.length; i++) {
      c = text.charAt(i);
      if (!inLabel && (c === 'h' || c === 'H') && (url = mdAutolink(ctx, i + 1))) {
        url = url.slice(0, text.length - i);
        out += url;
        i += url.length - 1;
        continue;
      }
      b = i > 0 ? text.charAt(i - 1) : before;
      a = i + 1 < text.length ? text.charAt(i + 1) : after;
      e = !!esc[i];
      if (c === '\\') e = e || MD_ESC.indexOf(a) >= 0 || a === '\u2022';
      else if (c === '`' || c === '[' || (inLabel && c === ']')) e = true;
      else if (c === '*') e = e || !(mdIsWs(b) && mdIsWs(a));
      else if (c === '_') e = e || !((mdIsWs(b) && mdIsWs(a)) || (mdIsWord(b) && mdIsWord(a)));
      else if (c === '~') e = e || b === '~' || a === '~';
      out += (e ? '\\' : '') + c;
    }
    return out;
  }
  var MD_MARK = { B: '**', STRONG: '**', I: '*', EM: '*', S: '~~', STRIKE: '~~', DEL: '~~' };
  var MD_SKIP = { SCRIPT: 1, STYLE: 1, TEMPLATE: 1 };
  function mdKids(n){ return slice.call(n.childNodes || []); }
  function mdTag(n){ return n.nodeType === 1 ? String(n.nodeName).toUpperCase() : ''; }
  // Editor artefacts: typed spaces arrive as &nbsp;, and a ZWSP holds the
  // caret outside inline code.
  function mdText(s){ return String(s || '').replace(/\u00a0/g, ' ').replace(/\u200b/g, ''); }
  function mdAllText(n){
    if (n.nodeType === 3) return mdText(n.nodeValue);
    if (mdTag(n) === 'BR') return '\n';
    return mdKids(n).map(mdAllText).join('');
  }
  // Inline nodes to tokens: text, br, m (a marker), raw (finished), a (link).
  function mdInline(nodes, out, inLabel){
    nodes.forEach(function(n){
      if (n.nodeType === 3) {
        var v = mdText(n.nodeValue);
        if (v) out.push({ t: 'text', v: v });
        return;
      }
      var tag = mdTag(n);
      if (!tag || MD_SKIP[tag]) return;
      if (tag === 'BR') { out.push({ t: 'br' }); return; }
      if (MD_MARK[tag]) { mdWrap(MD_MARK[tag], mdKids(n), out, inLabel); return; }
      if (tag === 'CODE') {
        var code = mdAllText(n).replace(/\n/g, ' ');
        if (!code) return;
        var runs = code.match(/`+/g) || [], fence = '`';
        runs.forEach(function(r){ if (r.length >= fence.length) fence = r + '`'; });
        var pad = /`/.test(code) || (code.charAt(0) === ' ' && code.slice(-1) === ' ' && code.trim() !== '') ? ' ' : '';
        out.push({ t: 'raw', v: fence + pad + code + pad + fence });
        return;
      }
      if (tag === 'A') {
        var href = mdUrlOut(n.getAttribute('href') || '');
        if (inLabel || !mdUrlOk(href, false)) { mdInline(mdKids(n), out, inLabel); return; }
        var label = [];
        mdInline(mdKids(n), label, true);
        label = label.map(function(x){ return x.t === 'br' ? { t: 'text', v: ' ' } : x; });
        if (label.length) out.push({ t: 'a', href: href, label: label });
        return;
      }
      if (tag === 'IMG') {
        var src = mdUrlOut(n.getAttribute('src') || '');
        if (!mdUrlOk(src, true)) return;
        var alt = String(n.getAttribute('alt') || '').replace(/[\\\]]/g, '\\$&');
        out.push({ t: 'raw', v: '![' + alt + '](' + src + ')' });
        return;
      }
      mdInline(mdKids(n), out, inLabel);   // span, font, u, ...: transparent
    });
  }
  // Emphasis never spans a line, and edge spaces sit outside the markers
  // ("a **x** b", not "a** x **b", which is not emphasis at all).
  function mdWrap(mark, nodes, out, inLabel){
    var inner = [], seg = [];
    mdInline(nodes, inner, inLabel);
    function flush(){
      var lead = '', trail = '', m;
      while (seg.length && seg[0].t === 'text' && (m = /^\s+/.exec(seg[0].v))) {
        lead += m[0];
        seg[0] = { t: 'text', v: seg[0].v.slice(m[0].length) };
        if (!seg[0].v) seg.shift();
      }
      while (seg.length && seg[seg.length - 1].t === 'text' && (m = /\s+$/.exec(seg[seg.length - 1].v))) {
        var last = seg[seg.length - 1];
        trail = m[0] + trail;
        seg[seg.length - 1] = { t: 'text', v: last.v.slice(0, -m[0].length) };
        if (!seg[seg.length - 1].v) seg.pop();
      }
      if (lead) out.push({ t: 'text', v: lead });
      if (seg.length) out.push.apply(out, [{ t: 'm', v: mark }].concat(seg, [{ t: 'm', v: mark }]));
      if (trail) out.push({ t: 'text', v: trail });
      seg = [];
    }
    inner.forEach(function(tok){ if (tok.t === 'br') { flush(); out.push(tok); } else seg.push(tok); });
    flush();
  }
  // Tokens of one line to Markdown.
  function mdLine(toks, atStart, inLabel, before, after){
    var merged = [];
    toks.forEach(function(t){
      var prev = merged[merged.length - 1];
      if (t.t === 'text' && prev && prev.t === 'text') merged[merged.length - 1] = { t: 'text', v: prev.v + t.v };
      else merged.push(t);
    });
    // The characters each token prints at its edges, for its neighbours. A
    // link is assumed explicit ('[' ... ')'); that only ever escapes more.
    var first = merged.map(function(t){ return t.t === 'a' ? '[' : t.v.charAt(0); });
    var last = merged.map(function(t){ return t.t === 'a' ? ')' : t.v.slice(-1); });
    var out = merged.map(function(t, k){
      if (t.t !== 'text') return t.t === 'a' ? '' : t.v;
      return mdEscape(t.v, k ? last[k - 1] : before, k < merged.length - 1 ? first[k + 1] : after, atStart && k === 0, inLabel);
    });
    // Links right to left: a link whose text is its URL is written bare when
    // md_to_html() would autolink it back to exactly that URL.
    for (var k = merged.length - 1; k >= 0; k--) {
      var t = merged[k];
      if (t.t !== 'a') continue;
      var prev = k === 0 ? ' ' : (merged[k - 1].t === 'a' ? '' : last[k - 1]);
      var plain = t.label.every(function(x){ return x.t === 'text'; })
        && t.label.map(function(x){ return x.v; }).join('') === t.href;
      out[k] = plain && prev && mdAutolink(prev + t.href + out.slice(k + 1).join(''), 1) === t.href
        ? t.href
        : '[' + mdLine(t.label, false, true, '[', ']') + '](' + t.href + ')';
    }
    return out.join('');
  }
  // Split tokens into lines at <br>. A <br> that ends a block is the browser's
  // placeholder, not a line of its own.
  function mdLines(toks){
    var segs = [[]], lines = [];
    toks.forEach(function(t){ if (t.t === 'br') segs.push([]); else segs[segs.length - 1].push(t); });
    if (segs.length > 1 && !segs[segs.length - 1].length) segs.pop();
    segs.forEach(function(s){ lines.push(mdLine(s, true, false, ' ', ' ')); });
    return lines;
  }
  function mdList(list, lines){
    var ol = mdTag(list) === 'OL';
    var num = ol ? (parseInt(list.getAttribute('start'), 10) || 1) : 0;
    var items = [];
    // Lists are flat (v1, L1): a nested list's items join the outer one.
    (function collect(l){
      mdKids(l).forEach(function(li){
        var tag = mdTag(li);
        if (tag === 'UL' || tag === 'OL') { collect(li); return; }
        if (tag !== 'LI') return;
        var nested = [], rest = [], sub = [];
        mdKids(li).forEach(function(k){ (mdTag(k) === 'UL' || mdTag(k) === 'OL' ? nested : rest).push(k); });
        mdBlocks(rest, sub);
        items.push(sub.length ? sub : ['']);
        nested.forEach(collect);
      });
    })(list);
    items.forEach(function(sub, k){
      var marker = ol ? (num + k) + '. ' : '- ';
      var pad = marker.replace(/./g, ' ');
      lines.push(marker + sub[0]);
      sub.slice(1).forEach(function(l){ lines.push(l === '' ? '' : pad + l); });
    });
  }
  function mdBlocks(nodes, lines){
    var run = [];
    function flush(){
      // The newlines between blocks in server-rendered HTML are not text.
      var layout = run.every(function(n){ return n.nodeType === 3 && /^[ \t\r\n]*$/.test(n.nodeValue) && /\n/.test(n.nodeValue); });
      if (run.length && !layout) {
        var toks = [];
        mdInline(run, toks, false);
        mdLines(toks).forEach(function(l){ lines.push(l); });
      }
      run = [];
    }
    nodes.forEach(function(n){
      var tag = mdTag(n), sub = [];
      if (tag === 'DIV' || tag === 'P' || tag === 'LI') {
        flush();
        mdBlocks(mdKids(n), sub);
        (sub.length ? sub : ['']).forEach(function(l){ lines.push(l); });
      } else if (/^H[1-6]$/.test(tag)) {
        flush();
        var toks = [];
        mdInline(mdKids(n), toks, false);
        toks = toks.map(function(x){ return x.t === 'br' ? { t: 'text', v: ' ' } : x; });
        var text = mdLine(toks, false, false, ' ', ' ').replace(/^[ \t]+|[ \t]+$/g, '');
        lines.push(text ? '######'.slice(0, +tag.charAt(1)) + ' ' + text : '');
      } else if (tag === 'UL' || tag === 'OL') {
        flush();
        mdList(n, lines);
      } else if (tag === 'BLOCKQUOTE') {
        flush();
        mdBlocks(mdKids(n), sub);
        while (sub.length && sub[sub.length - 1] === '') sub.pop();
        sub.forEach(function(l){ lines.push(l === '' ? '>' : '> ' + l); });
      } else if (tag === 'PRE') {
        flush();
        var code = mdAllText(n).replace(/\n$/, '');
        lines.push('```');
        if (code !== '') code.split('\n').forEach(function(l){ lines.push(l); });
        lines.push('```');
      } else if (tag === 'HR') {
        flush();
        lines.push('---');
      } else if (!MD_SKIP[tag]) {
        run.push(n);
      }
    });
    flush();
  }
  function mdSerialize(root){
    var lines = [];
    mdBlocks(mdKids(root), lines);
    while (lines.length && lines[lines.length - 1] === '') lines.pop();
    return lines.join('\n');
  }
  win.mdSerialize = mdSerialize;

  // Which typed text becomes formatting. `text` is the current line up to the
  // caret, `trigger` the key just typed (' ' and inline marks arrive as input,
  // '\n' is Enter). Pure; the result says which editing command to run and how
  // many characters before the caret it replaces. The rules are the renderer's,
  // so what the editor shows is what md_to_html() would show for the same
  // source. Only `1.` starts a numbered list: other numbers are prose here, and
  // the serializer escapes them, so both sides agree.
  function mdMatchRule(text, trigger){
    var m, s = String(text).replace(/\u00a0/g, ' ');
    if (trigger === ' ') {
      if ((m = /^(#{1,6}) $/.exec(s))) return { rule: 'block', cmd: 'formatBlock', arg: '<h' + m[1].length + '>', len: s.length };
      if (/^[-*\u2022] $/.test(s)) return { rule: 'block', cmd: 'insertUnorderedList', len: 2 };
      if (s === '1. ') return { rule: 'block', cmd: 'insertOrderedList', len: 3 };
      if (s === '> ') return { rule: 'block', cmd: 'formatBlock', arg: '<blockquote>', len: 2 };
      if (s === '``` ') return { rule: 'block', cmd: 'formatBlock', arg: '<pre>', len: 4 };
      return null;
    }
    if (trigger === '\n') {
      if (/^(?:---|\*\*\*|___|\u2014-)$/.test(s)) return { rule: 'block', cmd: 'insertHorizontalRule', len: s.length };
      return null;
    }
    if (trigger === ')') {
      if ((m = /(!?)\[([^\[\]]*)\]\(([^\s()]*(?:\([^\s()]*\)[^\s()]*)*)\)$/.exec(s))) {
        var img = m[1] === '!';
        if ((img || m[2] !== '') && mdUrlOk(m[3], img)) {
          return { rule: 'inline', cmd: img ? 'image' : 'link', text: m[2], url: m[3], len: m[0].length };
        }
      }
      return null;
    }
    var rules = {
      '*': [[/(^|[^*\\])\*\*([^\s*\\](?:[^*]*[^\s*\\])?)\*\*$/, 'bold'], [/(^|[^*\\])\*([^\s*\\](?:[^*]*[^\s*\\])?)\*$/, 'italic']],
      '_': [[/(^|[^\w\\])_([^\s_\\](?:[^_]*[^\s_\\])?)_$/, 'italic']],
      '~': [[/(^|[^~\\])~~([^\s~\\](?:[^~]*[^\s~\\])?)~~$/, 'strikeThrough']],
      '`': [[/(^|[^`\\])`([^`]+)`$/, 'code']]
    }[trigger] || [];
    for (var i = 0; i < rules.length; i++) {
      if ((m = rules[i][0].exec(s))) {
        return { rule: 'inline', cmd: rules[i][1], text: m[2], len: m[0].length - m[1].length };
      }
    }
    return null;
  }
  win.mdMatchRule = mdMatchRule;

  // The Markdown help. The button ships hidden: it only means something where
  // the modal can open. A click on the backdrop lands on the <dialog> itself
  // (its content is padded inside it), so that closes it; Esc is native.
  $('[data-md-help]').each(function(){
    var dlg = doc.querySelector('#md-help-dialog');
    if (!dlg || typeof dlg.showModal !== 'function') return;
    $(this.parentNode).removeClass('hidden');
    $(this).on('click', function(){ dlg.showModal(); });
    $(dlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){ dlg.close(); });
    $(dlg).on('click', function(ev){ if (ev.target === dlg) dlg.close(); });
  });

  // The composer's editor. Shown only where the browser has editing commands;
  // elsewhere the textarea stays, and the server renders the same Markdown.
  $('.md-editor').each(function(){
    var ed = this;
    var form = ed.closest('form');
    var ta = form && form.querySelectorAll('textarea[name="body"]')[0];
    if (!ta || typeof doc.execCommand !== 'function') return;
    // Written back only after a real edit: an untouched draft or reply is
    // posted exactly as the server prefilled it.
    var dirty = false;
    function empty(){
      $(ed).toggleClass('is-empty', !String(ed.textContent || '').replace(/\u200b/g, '') && !ed.querySelectorAll('img').length);
    }
    function sync(){ if (dirty) ta.value = mdSerialize(ed); }
    // One Enter is one line, as in Gmail and as md_to_html() renders it.
    try { doc.execCommand('defaultParagraphSeparator', false, 'div'); } catch (e) {}
    $(ed).removeClass('hidden');
    $(ta).addClass('hidden');
    empty();
    // autofocus already fired on the textarea, which is now hidden.
    if (doc.activeElement === ta) ed.focus();
    $(ed).on('input', function(ev){ dirty = true; sync(); empty(); });
    $(form).on('submit', sync);

    // --- Input rules. Every change goes through execCommand, which is what
    // keeps native undo working (a trigger costs two undo steps: the marker's
    // removal, then the formatting). execCommand fires `input` itself, hence
    // the `applying` guard.
    var applying = false;
    var undoable = null;    // the block just made from a typed marker
    function blockOf(node){
      for (var n = node; n && n !== ed; n = n.parentNode) {
        if (n.nodeType === 1 && /^(DIV|P|LI|H[1-6]|PRE|BLOCKQUOTE)$/.test(n.nodeName)) return n;
      }
      return ed;
    }
    function inside(node, re){
      for (var n = node; n && n !== ed; n = n.parentNode) if (n.nodeType === 1 && re.test(n.nodeName)) return true;
      return false;
    }
    // The current line up to the caret, or null without a collapsed caret.
    function caret(){
      var sel = win.getSelection && win.getSelection();
      if (!sel || !sel.rangeCount || !sel.isCollapsed) return null;
      var r = sel.getRangeAt(0), block = blockOf(r.startContainer), pre = doc.createRange();
      pre.selectNodeContents(block);
      pre.setEnd(r.startContainer, r.startOffset);
      // mdAllText, not toString(): a <br> is a line break there, and a line
      // starts after the last one (a <pre>, or Shift+Enter in a line).
      var text = mdAllText(pre.cloneContents());
      var line = text.slice(text.lastIndexOf('\n') + 1);
      return { sel: sel, node: r.startContainer, block: block, text: line, first: line === text };
    }
    function apply(c, rule){
      applying = true;
      try {
        // modify() moves by user-perceived character, so count code points.
        var n = Array.from(c.text.replace(/\u00a0/g, ' ').slice(-rule.len)).length;
        for (var i = 0; i < n; i++) c.sel.modify('extend', 'backward', 'character');
        doc.execCommand('delete');
        var esc = $.escapeHtml;
        if (rule.rule === 'block') {
          doc.execCommand(rule.cmd, false, rule.arg);
          undoable = rule;
        } else if (rule.cmd === 'code') {
          // A ZWSP after the element puts the caret outside it; the
          // serializer drops it.
          doc.execCommand('insertHTML', false, '<code>' + esc(rule.text) + '</code>\u200b');
        } else if (rule.cmd === 'link') {
          doc.execCommand('insertHTML', false, '<a href="' + esc(rule.url) + '">' + esc(rule.text) + '</a>\u200b');
        } else if (rule.cmd === 'image') {
          doc.execCommand('insertHTML', false, '<img src="' + esc(rule.url) + '" alt="' + esc(rule.text) + '">');
        } else {
          // Turn the style on, type, turn it off, so what follows is plain.
          doc.execCommand(rule.cmd);
          doc.execCommand('insertText', false, rule.text);
          doc.execCommand(rule.cmd);
        }
      } finally {
        applying = false;
      }
      dirty = true;
      sync();
      empty();
    }
    function run(trigger){
      var c = caret();
      if (!c || inside(c.node, /^(CODE|PRE)$/) && trigger !== '\n') return false;
      var rule = mdMatchRule(c.text, trigger);
      if (!rule) return false;
      // Block markers only on a plain line, typed at its start: not in a list
      // item, heading or code, and not after a Shift+Enter (formatBlock would
      // turn the whole block, earlier lines included).
      if (rule.rule === 'block' && (!c.first || !/^(DIV|P|BLOCKQUOTE)$/.test(c.block === ed ? 'DIV' : c.block.nodeName))) return false;
      apply(c, rule);
      return true;
    }
    $(ed).on('input', function(ev){
      if (applying) return;
      undoable = null;
      if (ev.isComposing || !/^insert(Text|CompositionText|ReplacementText)?$/.test(ev.inputType || '-')) return;
      var c = caret();
      if (c) run(c.text.slice(-1).replace(/\u00a0/, ' '));
    });
    // Android keyboards report whole words as composition: run the rules when
    // the word is committed.
    $(ed).on('compositionend', function(){
      var c = caret();
      if (c) run(c.text.slice(-1).replace(/\u00a0/, ' '));
    });

    $(ed).on('keydown', function(ev){
      var k = String(ev.key || '');
      // L8: Markdown has no underline, so the browser must not make one.
      if ((ev.metaKey || ev.ctrlKey) && k.toLowerCase() === 'u') { ev.preventDefault(); return; }
      if (k === 'Backspace' && undoable) {
        // Backspace right after a trigger gives the typed marker back.
        var b = caret();
        undoable = null;
        if (b && b.text === '') {
          ev.preventDefault();
          doc.execCommand('undo');
          doc.execCommand('undo');
        }
        return;
      }
      undoable = null;
      if (k !== 'Enter' || ev.shiftKey || ev.isComposing) return;
      var c = caret();
      if (!c) return;
      if (run('\n')) {
        ev.preventDefault();
        return;
      }
      if (c.block.nodeName === 'PRE') {
        // Enter in code adds a line; Enter on an empty last line leaves it.
        ev.preventDefault();
        var after = doc.createRange();
        after.selectNodeContents(c.block);
        after.setStart(c.sel.getRangeAt(0).startContainer, c.sel.getRangeAt(0).startOffset);
        if (c.text === '' && mdAllText(after.cloneContents()).replace(/\n$/, '') === '') {
          // formatBlock would nest a <div> in the <pre>, so the new line is
          // inserted with the caret placed after the block instead.
          applying = true;
          try {
            doc.execCommand('delete');
            var out = doc.createRange();
            out.setStartAfter(c.block);
            out.collapse(true);
            c.sel.removeAllRanges();
            c.sel.addRange(out);
            doc.execCommand('insertHTML', false, '<div><br></div>');
          } finally { applying = false; }
        } else {
          doc.execCommand('insertLineBreak');
        }
        dirty = true;
        sync();
        return;
      }
      // Enter on an empty line in a quote leaves the quote.
      if (c.text === '' && inside(c.node, /^BLOCKQUOTE$/) && !String(c.block.textContent || '').replace(/\u200b/g, '')) {
        ev.preventDefault();
        doc.execCommand('outdent');
        if (inside(caret() ? caret().node : ed, /^BLOCKQUOTE$/)) doc.execCommand('formatBlock', false, '<div>');
        dirty = true;
        sync();
      }
    });

    // L7: paste and drop insert plain text. Foreign markup never enters the
    // editor, and pasted Markdown is not rendered in v1.
    $(ed).on('beforeinput', function(ev){
      var dt = ev.dataTransfer;
      if (!dt || !/^insertFrom(Paste|Drop|PasteAsQuotation)$/.test(ev.inputType || '')) return;
      ev.preventDefault();
      var ranges = ev.getTargetRanges ? ev.getTargetRanges() : [];
      var sel = win.getSelection && win.getSelection();
      if (sel && ranges.length && doc.createRange) {
        var r = doc.createRange();
        r.setStart(ranges[0].startContainer, ranges[0].startOffset);
        r.setEnd(ranges[0].endContainer, ranges[0].endOffset);
        sel.removeAllRanges();
        sel.addRange(r);
      }
      doc.execCommand('insertText', false, dt.getData('text/plain'));
    });
  });

  // Compose attachment list: show picked files as removable chips.
  // The server limit is exposed so files can be rejected before upload.
  var postMaxBytes = 
JS
    . (int)parse_php_size((string)ini_get('post_max_size')) . <<<'JS'
;
  $('.compose-attach-list').each(function(){
    var $list = $(this);
    var form = this.closest('form');
    if (!form) return;
    var input = form.querySelector('input[type="file"][name="attachments[]"]');
    if (!input) return;
    var accumulated = [];

    function totalSize(){
      var s = 0;
      for (var i = 0; i < accumulated.length; i++) s += accumulated[i].size;
      return s;
    }

    function syncInput(){
      var dt = new DataTransfer();
      for (var i = 0; i < accumulated.length; i++) dt.items.add(accumulated[i]);
      input.files = dt.files;
    }

    function render(){
      this_node.innerHTML = '';
      for (var i = 0; i < accumulated.length; i++){
        var chip = doc.createElement('span');
        chip.className = 'compose-attach-chip';
        chip.innerHTML = $.escapeHtml(accumulated[i].name)
          + ' <button type="button" class="compose-attach-remove" data-idx="' + i + '">&times;</button>';
        this_node.appendChild(chip);
      }
    }

    var this_node = this;
    input.addEventListener('change', function(){
      for (var i = 0; i < input.files.length; i++) accumulated.push(input.files[i]);
      // Reject the batch when the total exceeds the server POST limit.
      // Base64 encoding inflates the wire size by ~37 %; use 75 % of the
      // raw limit as a safe ceiling so the form fields still fit.
      if (postMaxBytes > 0 && totalSize() > postMaxBytes * 0.75) {
        accumulated.splice(accumulated.length - input.files.length, input.files.length);
        alert('Attachments too large. The server accepts up to '
          + Math.floor(postMaxBytes / 1024 / 1024) + ' MB per request.');
      }
      syncInput();
      render.call(this_node);
    });

    $list.on('click', '.compose-attach-remove', function(){
      var idx = parseInt(this.getAttribute('data-idx'), 10);
      var name = accumulated[idx] ? accumulated[idx].name : 'this file';
      openConfirm(null, null, 'Remove "' + name + '"?', '', 'Remove');
      var dlg = doc.querySelector('#delete-dialog');
      if (dlg) {
        dlg._run = function(){
          accumulated.splice(idx, 1);
          syncInput();
          render.call(this_node);
        };
      }
    });

    render.call(this_node);
  });

  // Fetch a folder listing (with ajax=1) and swap it into the open mailbox
  // section in place, so switching folders never reloads the page. `href` is the
  // target URL exactly as the clicked link carried it. `opts.push` records a
  // history entry so Back walks the folders visited. Resolves true when the swap
  // happened, false when the caller must fall back to a full navigation.
  function loadMailbox(href, opts){
    opts = opts || {};
    var url = href + (href.indexOf('?') === -1 ? '?' : '&') + 'ajax=1';
    return $.get(url).then(function(d){
      var box = doc.querySelector('.mailbox');
      if (!d || d.ok === false || typeof d.html !== 'string' || !box) return false;
      var wrap = doc.createElement('div');
      wrap.innerHTML = d.html;
      var fresh = wrap.querySelector('.mailbox');
      if (!fresh) return false;
      box.parentNode.replaceChild(fresh, box);
      if (d.title) { doc.title = d.title; syncTitle(); }
      // The sidebar marks the folder you are looking at. A pagination href is
      // not in the sidebar, so the entry stays where it was.
      $('.sidebar li').removeClass('active');
      $('.sidebar a').each(function(){
        if (this.getAttribute('href') === href) this.parentNode.classList.add('active');
      });
      if (d.error) win.toast(d.error, 'error');
      if (opts.push) { try { win.history.pushState({ emmmail: true }, '', href); } catch (e) {} }
      // A real navigation starts at the top; match it rather than dropping the
      // user into the middle of the new folder.
      if (win.scrollTo) win.scrollTo(0, 0);
      wireMailbox();
      // A pagination swap keeps the keyboard cursor where it was; a folder
      // switch starts clean. `opts.cursor` is the index of the row that was
      // selected before the fresh list replaced this one.
      if (opts.cursor !== undefined) kbCursorSet(opts.cursor);
      else kbCursorClear();
      return true;
    });
  }

  // Open a folder listing in place. The caller has already acknowledged the
  // click (markBusy), so on success the acknowledgement is cleared; on any
  // failure the folder is still opened, via a real navigation whose spinner
  // pageshow will clear. `opts.cursor` passes a pagination cursor through to
  // the swap, so the selection survives turning the page.
  function openFolder(href, opts){
    opts = opts || {};
    loadMailbox(href, { push: true, cursor: opts.cursor }).then(function(ok){
      if (ok) { clearBusy(); return; }
      win.location.href = href;
    }).catch(function(){
      win.location.href = href;
    });
  }

  // --- Row selection --------------------------------------------------------
  // The picked UIDs, keyed by uid. `var` and not a property on win because this
  // is module state the rest of the file reads directly, and because a `var`
  // declared BELOW the boot call would still be undefined when pickReset() runs
  // for the first time. So it sits above wireMailbox() on purpose.
  var pickSet = {};
  // The last row the user picked, and whether the click that led to the next
  // pick was a shift-click. Both are module state on purpose: the swap throws
  // the rows away, and pickReset() is the only thing that clears them.
  var pickLastUid = null;
  var pickShift = false;
  function pickReset(){
    pickLastUid = null;
    pickShift = false;
    pickSet = {};
    var rows = doc.querySelectorAll('.mail.picked');
    for (var i = 0; i < rows.length; i++) rows[i].classList.remove('picked');
    // The row boxes too, not only the head one: `checked` is the box's own state,
    // so dropping .picked alone left a ticked box on a deselected row, and a
    // later hover painted that stale tick.
    var boxes = doc.querySelectorAll('.mail-pick');
    for (var b = 0; b < boxes.length; b++) boxes[b].checked = false;
    var all = doc.querySelectorAll('[data-pick-all]');
    // Both properties: the tri-state is a DOM property, not a CSS one, so
    // clearing `checked` alone leaves the dash on the head with nothing picked.
    for (var j = 0; j < all.length; j++) { all[j].checked = false; all[j].indeterminate = false; }
    pickBar();
  }
  function pickCount(){
    var n = 0;
    for (var k in pickSet) { if (Object.prototype.hasOwnProperty.call(pickSet, k)) n++; }
    return n;
  }
  function pickUids(){
    // Ascending, so the request and the rollback walk the rows in one order
    // regardless of the order the user clicked in.
    return Object.keys(pickSet).sort(function(a, b){ return parseInt(a, 10) - parseInt(b, 10); });
  }
  function pickBar(){
    // The bar's visibility is a CSS fact (:has() on .bulk-bar:not(.hidden)).
    // The client only maintains the class; it never asks whether the bar is up,
    // so the two layers cannot disagree about it.
    var bar = doc.querySelector('.bulk-bar');
    if (!bar) return;
    var n = pickCount();
    // The select-all label is the count, so it says what a click on it will do
    // when nothing is picked, and how many are picked otherwise.
    var c = doc.querySelectorAll('[data-bulk-count]');
    for (var i = 0; i < c.length; i++) {
      c[i].textContent = n ? n + ' selected' : 'Select all';
    }
    if (n) bar.classList.remove('hidden');
    else bar.classList.add('hidden');
  }
  function pickToggle(uid, on){
    var k = String(uid);
    if (on) pickSet[k] = true; else delete pickSet[k];
    var row = doc.querySelector('.mail[data-uid="' + k + '"]');
    if (row) row.classList.toggle('picked', !!on);
    var box = row ? row.querySelectorAll('[data-pick-uid]')[0] : null;
    if (box && box.checked !== !!on) box.checked = !!on;
    // The head checkbox is a tri-state: it shows partial, so ticking three rows
    // does not leave it looking like all of them are picked.
    var all = doc.querySelectorAll('[data-pick-all]');
    for (var i = 0; i < all.length; i++) {
      var boxes = doc.querySelectorAll('.mail-pick');
      var total = 0, ticked = 0;
      for (var j = 0; j < boxes.length; j++) {
        if (boxes[j].closest && !boxes[j].closest('.mail')) continue;
        total++;
        if (boxes[j].checked) ticked++;
      }
      all[i].checked = total > 0 && ticked === total;
      all[i].indeterminate = ticked > 0 && ticked < total;
    }
    pickBar();
  }
  function pickRange(fromUid, toUid){
    // Shift-click, with the row order the DOM already has. A selection made
    // across a page turn cannot be honoured -- the rows it spans are gone -- so
    // the range is clamped to what is on screen and the selection is replaced.
    var rows = doc.querySelectorAll('.mail[data-uid]');
    var uids = [];
    for (var i = 0; i < rows.length; i++) uids.push(parseInt(rows[i].getAttribute('data-uid'), 10));
    var a = uids.indexOf(parseInt(fromUid, 10)), b = uids.indexOf(parseInt(toUid, 10));
    if (a < 0 || b < 0) return;
    if (a > b) { var t = a; a = b; b = t; }
    for (var j = a; j <= b; j++) pickToggle(uids[j], true);
  }

  // Bindings that live inside the mailbox section, which the in-place folder
  // switch replaces wholesale. Element-bound listeners die with the section, so
  // this re-wires the fresh one after every swap and once at boot. The row and
  // paging clicks and the round-trip surfaces are delegated off the section's
  // own elements, so they stay wired even though the nodes change.
  function wireMailbox(){
    // A swap replaces every row, so the selection is not ours any more. This is
    // the first line because it owns all three parts: the map, the .picked class
    // on the rows, and the head checkbox. Folder switch, page change and
    // popstate all arrive here through loadMailbox(), so none of them needs its
    // own reset -- and a selection can never outlive the rows it named.
    pickReset();
    // Every control in these costs a round trip and none of them can be
    // optimistic about it: the reader's toolbar (a reply has to fetch the
    // message to quote it), the blocked-images bar, the search pill, and the
    // hint offering the server-side search, which is a full IMAP SEARCH.
    //
    // One list, so adding a surface is one string and none of them drift apart.
    // Two are deliberately absent: `.attachments`, because a download does not
    // navigate and nothing would ever clear the spinner, and `.mail-list`,
    // whose rows report themselves by spinning their avatar instead.
    ['.actions-bar', '.images-cta', '.search', '.hint'].forEach(function(sel){
      $(sel).on('click', 'a', roundTripClick);
      $(sel).on('click', 'button', roundTripClick);
    });
    // Enter submits the search without ever clicking the button, which is how
    // search is normally used, so the click binding above would miss it.
    $('.search').on('submit', function(){
      markBusy(this.querySelectorAll('.search-btn')[0]);
    });

    // Opening a message is the slowest navigation in the app, so the click is
    // acknowledged in the frame it happens in: the row marks itself, and the
    // progress bar starts. Both are cleared on pageshow, which is the event that
    // fires when the browser restores this page from its cache after Back -- a
    // restored page would otherwise show a row that is still opening.
    $('.mail-list').on('click', 'a.mail-main', function(ev){
      // A modified click opens the message somewhere else and this page stays put,
      // so acknowledging it here would be a lie.
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || (ev.button && ev.button !== 0)) return;
      var row = this.parentNode;
      while (row && !(row.classList && row.classList.contains('mail'))) row = row.parentNode;
      if (row) row.classList.add('opening');
      $navBar.addClass('loading');
    });

    // Picking a row is delegated off .mailbox rather than bound per checkbox,
    // because a swap replaces every checkbox and the section survives.
    // Only the click carries shiftKey -- a change event does not -- so it is
    // recorded here and read on change, because click always fires first.
    $('.mailbox').on('click', '.mail-pick', function(ev){
      pickShift = !!(ev.shiftKey);
    });
    $('.mailbox').on('change', '.mail-pick', function(){
      var uid = this.getAttribute('data-pick-uid');
      if (!uid) return;
      // The keyboard layer treats any input as editable, and this is now the
      // only focusable control on a row: leaving focus here would kill
      // j/k/x/#/e after one mouse click. The pattern pickers blur the same way.
      if (this.blur) this.blur();
      if (pickShift && pickLastUid !== null) pickRange(pickLastUid, uid);
      pickShift = false;
      pickLastUid = uid;
      // A conversation's box stands for all its in-folder members, so a pick on
      // it selects the whole set the bulk actions then carry.
      var pickRow = this.closest ? this.closest('.mail') : null;
      var memberUids = pickRow ? (pickRow.getAttribute('data-thread-uids') || '') : '';
      if (memberUids) {
        var members = memberUids.split(',');
        for (var mi = 0; mi < members.length; mi++) {
          if (members[mi]) pickToggle(members[mi], this.checked);
        }
      } else {
        pickToggle(uid, this.checked);
      }
    });
    $('.mailbox').on('change', '[data-pick-all]', function(){
      var on = this.checked;
      var boxes = doc.querySelectorAll('.mail-pick');
      for (var i = 0; i < boxes.length; i++) {
        var uid = boxes[i].getAttribute('data-pick-uid');
        if (uid) { boxes[i].checked = on; pickToggle(uid, on); }
      }
    });
    $('.mailbox').on('click', '[data-bulk-action]', function(){
      var act = this.getAttribute('data-bulk-action');
      if (!act || !pickCount()) return;
      if (act === 'move') { win.bulkMoveTo(); return; }
      if (act === 'delete') { win.bulkDelete(); return; }
      // An explicit direction, not a per-row toggle: rowAction's flag mode reads
      // .unread off each row, which is unambiguous for one row and meaningless
      // for N. Per-row, "Mark read" on a mixed set would mark the ALREADY-READ
      // rows unread, painted instantly.
      win.bulkAction(act, act === 'read' ? false : true);
    });

    // Pagination is a folder listing too: swap it in place. Search pagination is
    // a different page type, so it stays a full navigation.
    $('.pagination').each(function(){
      $(this.querySelectorAll('a')).on('click', function(ev){
        if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || (ev.button && ev.button !== 0)) return;
        var href = this.getAttribute('href');
        if (!href || href.indexOf('page=folder') === -1) return;
        ev.preventDefault();
        markBusy(this);
        // Keep the keyboard cursor on the same row across the page turn.
        openFolder(href, { cursor: kbCursor });
      });
    });

    // The move dialog is rendered inside the mailbox, so its Cancel/Confirm
    // wiring has to survive a swap with the section.
    var mdlg = doc.querySelector('#move-dialog');
    if (mdlg) {
      $(mdlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        mdlg.close();
      });
      $(mdlg.querySelectorAll('.btn-confirm')[0]).on('click', function(){
        var sel = mdlg.querySelectorAll('.move-dest')[0];
        var dest = sel ? sel.value : '';
        // `_uids` is the bulk case: today this guard closes silently on anything
        // without a single _uid, which is every bulk move.
        if (!dest || (!mdlg._uid && !mdlg._uids)) { mdlg.close(); return; }
        var folder = mdlg._folder;
        mdlg.close();
        // Bulk: one request for the whole selection, patched the same way.
        if (mdlg._uids && mdlg._uids.length) {
          var uids = mdlg._uids;
          mdlg._uids = null;
          bulkRemove(uids, folder, '?page=move', { dest: dest });
          return;
        }
        var uid = mdlg._uid;
        mdlg._uids = null;
        // From the list: the row is the only thing that changes, so patch it and
        // stay put. From the reader there is no row, and the message is no longer
        // in this folder, so reloading it would 404 -- follow it instead.
        if (doc.querySelector('.mail[data-uid="' + uid + '"]')) {
          win.rowAction(uid, folder, '?page=move', 'remove', { dest: dest });
          return;
        }
        // Confirmed, so the Move button is genuinely working now.
        markBusy(readerControl('[data-move]'));
        $.post('?page=move', { uid: uid, folder: folder, dest: dest }).then(function(){
          win.location.href = '?page=folder&folder=' + dest;
        }).catch(function(){
          clearBusy();
          rowError('Offline. Nothing was changed.');
        });
      });
    }

    wireSync();
  }

  // --- The poll chain --------------------------------------------------------
  // New mail has to reach a tab that is just sitting there, so every signed-in
  // page polls, not only the listings. One chain for the page, and it lives here
  // at module level: wireMailbox() re-runs after every folder swap, so a chain
  // built inside it would be one chain per swap, all posting to the same server.
  var POLL_IDLE_STOP_SECONDS = 7200;  // two hours without a keystroke or a click
  var syncTimer = null;
  var syncBusy = false;
  var syncStale = false;
  var syncStopped = false;
  var syncIdle = false;
  var lastInput = 0;

  // The open folder, read fresh every time. A swap replaces the chip, so a
  // folder captured at wire time is the folder the user has already left. A page
  // with no chip (reader, compose, settings) has no open folder to sync, and
  // INBOX is the one folder the chain always wants.
  function syncFolder(){
    var box = doc.querySelector('.sync');
    return box ? (box.getAttribute('data-folder') || 'INBOX') : 'INBOX';
  }
  // Whether the open listing is flagged stale by the server (sync_is_stale()).
  // A page with no chip (reader, compose, settings) has no listing to correct.
  function syncIsStaleAtBoot(){
    var box = doc.querySelector('.sync');
    return !!box && box.getAttribute('data-sync-stale') === '1';
  }
  // The period comes from the topbar, the one element render_page() puts on
  // every signed-in page. It is SYNC_CLIENT_MIN_PERIOD (a minute) or 0, from the
  // automatic-check switch; no topbar means not signed in, so nothing to poll.
  // Nothing is floored here on purpose: the server owns the interval, and two
  // places holding a copy of a minute is two places to forget.
  function syncPeriod(){
    var bar = doc.querySelector('.topbar');
    if (!bar) return 0;
    var n = parseInt(bar.getAttribute('data-period') || '0', 10);
    return isNaN(n) || n <= 0 ? 0 : n;
  }
  function syncSchedule(){
    if (syncTimer !== null) { win.clearTimeout(syncTimer); syncTimer = null; }
    var period = syncPeriod();
    if (!period || syncStopped || syncIdle) return;
    syncTimer = win.setTimeout(function(){ syncTimer = null; syncPoll(false); }, period * 1000);
  }

  // A reload of the open listing throws away whatever the user is in the middle
  // of, so it happens only when there is nothing to lose. A tick that finds new
  // mail but cannot swap now is remembered, and a later tick swaps it even when
  // that sync has nothing new to report.
  function syncCanSwap(){
    if (doc.querySelector('dialog[open]')) return false;
    if (doc.querySelector('.row-gone')) return false;
    if (doc.querySelector('.busy')) return false;
    if (doc.querySelector('.opening')) return false;
    if (kbCursor >= 0) return false;
    // A selection is work in progress: reloading throws away every ticked row,
    // and the batch that was about to act on them has gone with it. A blocked
    // swap does not stall the chain -- the tick sets syncStale and a later tick
    // swaps even if that sync reports nothing new -- so the page reloads up to
    // SYNC_CLIENT_MIN_PERIOD after the selection clears.
    if (pickCount() > 0) return false;
    if ((win.scrollY || 0) !== 0) return false;
    // Page 1 only: reloading a later page would throw the earlier ones away.
    var m = /[?&]p=(\d+)/.exec(win.location.search || '');
    if (m && parseInt(m[1], 10) > 1) return false;
    return true;
  }
  // The current URL, not a rebuilt ?page=folder&folder=X: a reload keeps the
  // pagination, the sort and anything else the URL carries.
  function syncSwap(){
    syncStale = false;
    win.location.href = win.location.href;
  }

  // The chip, on the pages that have one. Every call is guarded, because the
  // pages that matter most (reader, compose, settings) have no chip and poll
  // all the same.
  function syncChip(busy){
    var box = doc.querySelector('.sync');
    if (!box) return;
    var label = box.querySelector('.sync-text');
    var use = box.querySelector('.sync-btn use');
    if (busy) {
      box.classList.add('syncing');
      box.classList.remove('sync-error');
      if (label) label.textContent = 'Syncing...';
      if (use) use.setAttribute('href', '#tabler-progress');
      return;
    }
    box.classList.remove('syncing');
    if (use) use.setAttribute('href', '#tabler-progress-check');
  }
  function syncLabel(text, failed){
    var box = doc.querySelector('.sync');
    if (!box) return;
    var label = box.querySelector('.sync-text');
    if (failed) box.classList.add('sync-error'); else box.classList.remove('sync-error');
    if (label) label.textContent = text;
  }

  function syncPoll(force){
    if (syncBusy || syncStopped) return;
    // A tab nobody has touched for two hours is a tab nobody is reading. Stop
    // asking, so a forgotten window does not hold the session (and the mail
    // password in it) open for ever; PHP ends the session as usual from there.
    if (!force && (Date.now() - lastInput) > POLL_IDLE_STOP_SECONDS * 1000) {
      syncIdle = true;
      return;
    }
    var folder = syncFolder();
    syncBusy = true;
    syncChip(true);
    var data = { folder: folder };
    if (force) data.force = '1';
    $.post('?page=sync', data).then(function(r){
      // The session is gone, whether the server says so or hands back the login
      // page instead. Asking again cannot help, and retrying for ever would be
      // a loop against a session that no longer exists.
      if (r.status === 401 || r.status === 403 || r.redirected) {
        syncStopped = true;
        syncChip(false);
        syncLabel('Signed out', false);
        return null;
      }
      return r.json();
    }).then(function(d){
      syncBusy = false;
      if (!d) return;
      syncChip(false);
      if (!d.ok) { syncLabel(d.error || 'Sync failed', true); return; }
      syncLabel(d.synced || 'updated', false);
      // The server's view of every counter, from STATUS. Replaces any
      // optimistic value, so drift never outlives one sync.
      if (d.counts) {
        for (var name in d.counts) {
          if (!Object.prototype.hasOwnProperty.call(d.counts, name)) continue;
          var badge = badgeFor(name);
          if (badge) setBadge(badge, Math.max(0, parseInt(d.counts[name], 10) || 0));
        }
      }
      syncNotify(d.arrivals);
      // `changed` belongs to the folder that was open when the request went out.
      // If the user has moved on since, a swap would drag them back there.
      if (folder !== syncFolder()) return;
      // Nothing to refresh: the reader, compose and settings have no open
      // listing, and reloading the composer would throw away the draft in it.
      if (!doc.querySelector('.sync')) return;
      if (d.changed > 0 || syncStale) {
        if (syncCanSwap()) syncSwap();
        else syncStale = true;
      }
    }).catch(function(){
      syncBusy = false;
      syncChip(false);
      syncLabel('Offline', true);
    }).then(function(){
      syncSchedule();
    });
  }

  // Only the chip. The chain above is module level, so re-wiring after a folder
  // swap cannot add a second one, and the chip it binds is the fresh one.
  function wireSync(){
    var $box = $('.sync');
    if (!$box.length) return;
    $box.find('.sync-btn').on('click', function(){ syncPoll(true); });
  }

  // --- The tab title ---------------------------------------------------------
  // The Inbox unread count, in the title, so a tab among twenty says what it
  // holds. Pure, and idempotent: it is applied to a title that may already carry
  // a count, and must never stack one on another.
  function titleWithCount(title, n){
    var base = String(title === undefined || title === null ? '' : title).replace(/^\(\d+\)\s+/, '');
    return n > 0 ? '(' + n + ') ' + base : base;
  }
  function syncTitle(){
    var badge = badgeFor('INBOX');
    if (!badge) return;
    doc.title = titleWithCount(doc.title, parseInt(badge.textContent, 10) || 0);
  }
  win.titleWithCount = titleWithCount;

  // --- Notifications ---------------------------------------------------------
  // Opt-in per browser, Inbox only, and a count and nothing else: the point is
  // to learn that there is mail, not to read it in a system notification.
  var NOTIFY_KEY = 'emmmail-notify';
  var notifyTotal = 0;
  var notifyLive = null;
  function notifyFlag(){
    try { return win.localStorage.getItem(NOTIFY_KEY) === 'on'; } catch (e) { return false; }
  }
  function notifyStore(on){
    try {
      if (on) win.localStorage.setItem(NOTIFY_KEY, 'on');
      else win.localStorage.removeItem(NOTIFY_KEY);
    } catch (e) {}
  }
  // A fixed tag, so a second tab or a second poll replaces the banner instead of
  // stacking a queue of them.
  function notifySay(body){
    try {
      if (notifyLive) notifyLive.close();
      var n = new win.Notification('New mail', { body: body, tag: 'emmmail-inbox' });
      n.onclick = function(){ win.focus(); notifyClear(); };
      notifyLive = n;
    } catch (e) { notifyLive = null; }
  }
  function notifyClear(){
    notifyTotal = 0;
    if (notifyLive) { notifyLive.close(); notifyLive = null; }
  }
  function syncNotify(arrivals){
    var n = parseInt(arrivals, 10) || 0;
    // Nothing arrived, or the user never asked, or this browser will not do it.
    if (n <= 0 || !notifyFlag()) return;
    if (!win.Notification || win.Notification.permission !== 'granted') return;
    // A notification for a tab the user is looking at is noise, and a permission
    // the browser has since withdrawn must not throw on every tick.
    if (!(doc.hidden || !doc.hasFocus())) return;
    notifyTotal += n;
    notifySay(notifyTotal + ' new message' + (notifyTotal === 1 ? '' : 's'));
  }

  // Any real interaction means the tab is in use, so the clock restarts and a
  // chain that stopped for idleness comes back.
  function syncTouch(){
    lastInput = Date.now();
    if (!syncIdle) return;
    syncIdle = false;
    syncPoll(false);
  }
  $(doc).on('pointerdown', syncTouch);
  $(doc).on('keydown', syncTouch);
  // A tab that comes back has been away for an unknown time, and the period is a
  // minute at best, so check now instead of waiting for the next tick. Coming
  // back also means the user has seen the list, so the running count is over.
  $(doc).on('visibilitychange', function(){
    if (doc.hidden) return;
    notifyClear();
    syncTouch();
    syncPoll(false);
  });
  $(win).on('focus', notifyClear);

  $(win).on('pageshow', function(ev){
    $('.mail.opening').removeClass('opening');
    clearBusy();
    // A page restored from the cache starts clean: no stale cursor on a row
    // that may not be there any more.
    kbCursorClear();
    // A page restored from the back/forward cache was frozen mid-request, and
    // the request went with it: the timer is gone, the chain is wedged, and the
    // mail that arrived in between is unseen. Start over, and check now.
    if (!ev || !ev.persisted) return;
    syncBusy = false;
    syncStale = false;
    syncStopped = false;
    lastInput = Date.now();
    syncPoll(false);
  });

  // A sidebar folder link is a full navigation, and a cold folder cache means
  // the server has to LIST + SELECT + fetch before it can paint. Acknowledge
  // the click in the same frame by turning the folder's icon into a spinner.
  // markBusy() remembers the icon and pageshow (above) restores it, so a page
  // coming back from the browser cache never shows a stuck spinner. On a folder
  // listing the navigation is intercepted and swapped in place instead.
  $('.sidebar').on('click', '.folder-link', function(ev){
    // A modified click opens the folder somewhere else and this page stays put,
    // so acknowledging it here would be a lie.
    if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || (ev.button && ev.button !== 0)) return;
    markBusy(this);
    // Only a folder listing swaps in place; from the reader, compose or settings
    // a folder click is a normal full navigation.
    if (!doc.querySelector('.sync')) return;
    ev.preventDefault();
    openFolder(this.getAttribute('href'));
  });

  // Back/Forward through the in-place folder history stays in place when the
  // target is a listing. A read, compose or settings entry needs the server, so
  // it is left to a real navigation. If the swap itself fails (a blip), reload
  // so the URL and the content cannot drift apart.
  $(win).on('popstate', function(){
    if (doc.querySelector('.sync')) {
      loadMailbox(win.location.href, {}).catch(function(){
        win.location.reload();
      });
    } else {
      win.location.href = win.location.href;
    }
  });

  wireMailbox();

  // The chain starts here, once, on every signed-in page. The topbar carries the
  // period, so a turned-off check and the login page both leave it unscheduled.
  // A listing whose cached headers are older than that period syncs the moment it
  // opens, then the chain carries on: waiting a whole period is what left a fresh
  // login showing a six-day-old mailbox.
  lastInput = Date.now();
  if (syncPeriod() > 0 && syncIsStaleAtBoot()) syncPoll(false);
  else syncSchedule();

  // --- Keyboard layer: a Gmail-flavoured, Superhuman-feeling layer -----------
  // One document-level keydown, gated so it never fires where it must not.
  // The ?-sheet and this dispatcher read the same PHP-owned table
  // (#kb-help-dialog[data-kb-list]), so the keys and their labels cannot drift.
  var kbPrefix = null;
  var kbPrefixTimer = null;
  var kbCursor = -1;

  function kbEnabled(){
    try { return win.localStorage.getItem('emmmail-kb') !== 'off'; } catch (e) { return true; }
  }
  function kbAuthed(){
    return !!doc.querySelector('.topbar');
  }
  // The one guard every surface key passes. Editable targets and modified keys
  // (except the composer's own Cmd+Enter, bound separately) are left alone, but
  // Esc always passes: it is the escape hatch — blur a field, close the
  // autocomplete dropdown, clear the cursor, reset a pending g.
  function kbEditable(ev){
    if (ev.metaKey || ev.ctrlKey || ev.altKey) return true;
    if (ev.key === 'Escape') return false;
    var t = ev.target;
    if (!t || !t.tagName) return false;
    var tag = t.tagName.toLowerCase();
    if (tag === 'input' && t.type === 'checkbox') {
      // The row picker is the one input on a row, and the row's own shortcut keys
      // (j/k/x/#/e) are the fastest way to work through a mailbox. Typing does not
      // apply to a checkbox, so it must not swallow them: only Space and Enter,
      // which activate it, count as editable here. The picker also blurs itself
      // on change, so focus does not sit here after a click.
      return ev.key === ' ' || ev.key === 'Enter';
    }
    return tag === 'input' || tag === 'textarea' || tag === 'select'
      || (t.getAttribute && t.getAttribute('contenteditable') === 'true');
  }
  function kbModalOpen(){
    return !!doc.querySelector('dialog[open]');
  }
  function kbRows(){
    // The :not() form is avoided on purpose: the js harness matches() cannot
    // parse it, so the filter is a plain JS classList check.
    var rows = doc.querySelectorAll('.mail[data-uid]');
    var out = [];
    for (var i = 0; i < rows.length; i++) {
      if (!rows[i].classList.contains('row-gone')) out.push(rows[i]);
    }
    return out;
  }
  function kbCursorClear(){
    kbCursor = -1;
    var cur = doc.querySelector('.mail.cursor');
    if (cur) cur.classList.remove('cursor');
  }
  function kbCursorSet(i){
    var rows = kbRows();
    if (!rows.length) { kbCursorClear(); return; }
    i = Math.max(0, Math.min(i, rows.length - 1));
    kbCursorClear();
    kbCursor = i;
    var row = rows[i];
    row.classList.add('cursor');
    // Rove real focus so a screen reader announces the message for free.
    var link = row.querySelector('.mail-main');
    if (link && link.focus) link.focus();
    if (row.scrollIntoView) row.scrollIntoView({ block: 'nearest' });
  }
  function kbCursorMove(delta){
    var rows = kbRows();
    if (!rows.length) return;
    var i = kbCursor < 0 ? (delta > 0 ? 0 : rows.length - 1) : kbCursor + delta;
    kbCursorSet(i);
  }
  function kbCursorOpen(){
    var row = kbCursorRow();
    if (!row) return;
    var link = row.querySelector('.mail-main');
    if (link && link.click) link.click();
  }
  function kbCursorRow(){
    var rows = kbRows();
    return (kbCursor >= 0 && kbCursor < rows.length) ? rows[kbCursor] : null;
  }
  function kbCursorAdvance(){
    // A row that just left (archive/spam/delete/move) frees its index; land on
    // the row that took its place.
    kbCursorSet(kbCursor);
  }
  // x selects the cursor's row. On a page where the user has not pressed j yet
  // there is no cursor, and a no-op here would be the least discoverable key in
  // the layer -- so it places one first, on the first row.
  function kbSelectCursor(){
    var row = kbCursorRow();
    if (!row) kbCursorSet(0);
    row = kbCursorRow();
    if (!row) return;
    var uid = row.getAttribute('data-uid');
    var box = row.querySelector('.mail-pick');
    if (!uid) return;
    // Toggled, not set: x on an already-selected row takes it back out, which is
    // what a modifier-free toggle should do.
    if (box) { box.checked = !box.checked; }
    pickToggle(uid, box ? box.checked : false);
  }
  // Space is x for a user who is already walking the list. It acts only when a
  // cursor exists, and never on a focused button or a link other than the row
  // link: there Space is how the page scrolls and how a button is pressed, and
  // taking that away would break the page for everybody who never pressed j.
  function kbSpaceSelect(ev){
    if (!kbCursorRow()) return;
    var t = ev.target;
    var tag = t && t.tagName ? t.tagName.toLowerCase() : '';
    if (tag === 'button' || tag === 'summary') return;
    if (tag === 'a' && !(t.classList && t.classList.contains('mail-main'))) return;
    ev.preventDefault();
    kbSelectCursor();
  }
  function kbRowFields(){
    var row = kbCursorRow();
    if (!row) return null;
    return { uid: row.getAttribute('data-uid'), folder: row.getAttribute('data-folder') || '' };
  }
  function kbFolderMap(){
    if (kbFolderMap._map) return kbFolderMap._map;
    var body = doc.body;
    var raw = body ? (body.getAttribute('data-kb-map') || '') : '';
    var m = {};
    try { m = JSON.parse(raw); } catch (e) {}
    kbFolderMap._map = m;
    return m;
  }
  function kbHelp(){
    var dlg = doc.querySelector('#kb-help-dialog');
    if (!dlg) return;
    // The toggle reads the current state, so a disabled user sees "Enable".
    var toggle = dlg.querySelectorAll('[data-kb-disable]')[0];
    if (toggle) toggle.textContent = kbEnabled() ? 'Disable shortcuts' : 'Enable shortcuts';
    if (typeof dlg.showModal === 'function') dlg.showModal();
  }
  function kbPrefixReset(){
    kbPrefix = null;
    win.clearTimeout(kbPrefixTimer);
  }
  // The sidebar link for a folder, so a keyboard jump can spin the same icon a
  // click would. Matches on the decoded name: the href is PHP urlencode()d, so
  // spaces are "+" and a raw string compare would miss.
  function kbFolderLink(name){
    var links = doc.querySelectorAll('.folder-link');
    for (var i = 0; i < links.length; i++) {
      var href = links[i].getAttribute('href') || '';
      var m = href.match(/[?&]folder=([^&]*)/);
      if (!m) continue;
      var decoded = m[1].replace(/\+/g, ' ');
      try { decoded = decodeURIComponent(decoded); } catch (e) {}
      if (decoded === name) return links[i];
    }
    return null;
  }
  // The reader toolbar link that points at `href` (flat query: the js harness
  // matches() has no descendant combinator).
  function kbLinkWithHref(href){
    var bar = doc.querySelector('.actions-bar');
    if (!bar) return null;
    var links = bar.querySelectorAll('a');
    for (var i = 0; i < links.length; i++) {
      if (links[i].getAttribute('href') === href) return links[i];
    }
    return null;
  }
  // Acknowledge a keyboard navigation the way its mouse equivalent does: spin
  // the matching control when there is one, and always run the chrome bar.
  function kbBusy(el){
    if (el) markBusy(el);
    else $navBar.addClass('loading');
  }
  function kbGoFolder(role){
    var name = kbFolderMap()[role];
    kbPrefixReset();
    if (!name) { win.toast('No such folder.', 'error'); return; }
    kbBusy(kbFolderLink(name));
    openFolder('?page=folder&folder=' + encodeURIComponent(name));
  }
  function kbBack(){
    // Reader: the open message's own folder (the Back link's href). Elsewhere:
    // Inbox, Superhuman-style. The two-step query keeps the selector flat: the
    // js harness matches() has no descendant combinator.
    var bar = doc.querySelector('.actions-bar');
    var back = bar ? bar.querySelector('a[data-tip="Back"]') : null;
    if (back) { markBusy(back); win.location.href = back.getAttribute('href'); return; }
    // Listing: spin the Inbox link when the sidebar is on screen.
    kbBusy(kbFolderLink('INBOX'));
    win.location.href = '?page=inbox';
  }
  function kbFocusSearch(){
    var search = doc.querySelector('.search');
    var input = search ? search.querySelector('input[name="q"]') : null;
    if (input && input.focus) input.focus();
  }
  // Close any open contact-suggestion dropdown. Returns true when one was open,
  // so Esc stops completing an address before it discards the whole message.
  function kbCloseAutocomplete(){
    var dds = doc.querySelectorAll('.ac-dropdown');
    var open = false;
    for (var i = 0; i < dds.length; i++) {
      if (dds[i].style.display === 'block') { dds[i].style.display = 'none'; open = true; }
    }
    return open;
  }
  // Esc on the composer discards, exactly like the Discard control: nothing is
  // saved, so navigating away throws the draft away.
  function kbDiscard(){
    var discard = doc.querySelector('.compose-discard');
    win.location.href = (discard && discard.getAttribute('href')) || '?page=inbox';
  }
  // Blur a focused field (the search box, a settings input) so Esc steps out of
  // the field before it acts on the page behind it. Returns true when it did.
  function kbBlurField(){
    var el = doc.activeElement;
    if (!el || !el.tagName || !el.blur) return false;
    var tag = el.tagName.toLowerCase();
    var editable = tag === 'input' || tag === 'textarea' || tag === 'select'
      || (el.getAttribute && el.getAttribute('contenteditable') === 'true');
    if (!editable) return false;
    el.blur();
    return true;
  }
  // Trigger the same force-sync a click on the sync chip runs. Nothing to do on
  // a page without the chip (the reader, compose, settings).
  function kbSync(){
    var btn = doc.querySelector('.sync-btn');
    if (btn && btn.click) btn.click();
  }
  // Form actions, each just clicking the control a user would. They carry a
  // modifier so they work while typing and never collide with the mail keys.
  function kbSaveDraft(){
    var btn = doc.querySelector('button[formaction="?page=saveDraft"]');
    if (btn && btn.click) btn.click();
  }
  function kbAttach(){
    var label = doc.querySelector('.compose-attach-btn');
    var input = label ? label.querySelector('input[type="file"]') : null;
    if (input && input.click) input.click();
  }
  function kbSaveSettings(){
    // The main settings form is the one carrying the folder-map JSON.
    var marker = doc.querySelector('.folder-map-json');
    var form = marker && marker.closest ? marker.closest('form') : null;
    if (!form) return;
    var btn = form.querySelectorAll('.btn-primary')[0];
    if (btn && btn.click) btn.click();
  }
  function kbClearCache(){
    if (win.confirmClearCache) win.confirmClearCache();
  }

  // A row action, dispatched from the cursor. Reuses the exact globals the
  // clickable buttons call, so the two paths can never disagree.
  function kbCursorAction(action){
    var f = kbRowFields();
    if (!f) return;
    if (action === 'toggleRead') { win.toggleRead(f.uid, f.folder); return; }
    if (action === 'move') { win.moveTo(f.uid, f.folder); return; }
    if (action === 'delete') {
      win.confirmDelete(f.uid, f.folder, 'Delete this message?');
      // The row leaves only once the user confirms, so the advance hooks the
      // confirm, not the dialog opening.
      var dlg = doc.querySelector('#delete-dialog');
      if (dlg) {
        var run = dlg._run;
        dlg._run = function(){ if (run) run(); kbCursorAdvance(); };
      }
      return;
    }
    if (action === 'spam') {
      win.rowAction(f.uid, f.folder, '?page=spam', 'remove');
      kbCursorAdvance();
      return;
    }
    if (action === 'archive') {
      var dest = kbFolderMap()['a'];
      if (!dest) { win.toast('No archive folder.', 'error'); return; }
      win.rowAction(f.uid, f.folder, '?page=move', 'remove', { dest: dest });
      kbCursorAdvance();
    }
  }
  function kbCursorReply(key){
    var f = kbRowFields();
    if (!f) return;
    var page = key === 'r' ? 'reply' : (key === 'a' ? 'replyAll' : 'forward');
    // data-folder is already URL-encoded (view_mailbox encodes it), exactly as
    // view_read()'s own reply hrefs carry it, so pass it through unchanged.
    win.location.href = '?page=' + page + '&uid=' + f.uid + '&folder=' + f.folder;
  }

  // Listing keys: the cursor and its row actions. ArrowUp/ArrowDown mirror k/j.
  function kbListKey(ev, key){
    switch (key) {
      case 'j': case 'ArrowDown': ev.preventDefault(); kbCursorMove(1); return;
      case 'k': case 'ArrowUp': ev.preventDefault(); kbCursorMove(-1); return;
      case 'Enter': case 'o': kbCursorOpen(); return;
      // x picks the row, it does not open it: the shortcut nobody would try is
      // the one that needs a cursor to exist first, so it makes one.
      case 'x': kbSelectCursor(); return;
      case ' ': kbSpaceSelect(ev); return;
      case 's': kbCursorAction('toggleRead'); return;
      case 'v': kbCursorAction('move'); return;
      case '#': kbCursorAction('delete'); return;
      case '!': kbCursorAction('spam'); return;
      case 'e': kbCursorAction('archive'); return;
      case 'r': case 'a': case 'f': kbCursorReply(key); return;
    }
  }

  // Reader keys: the same actions against the open message.
  function kbReaderKey(ev, key){
    var card = activeThreadCard() || doc.querySelector('.card');
    if (key === 'j' || key === 'k') {
      var href = card ? card.getAttribute(key === 'k' ? 'data-prev' : 'data-next') : '';
      if (href) { $navBar.addClass('loading'); win.location.href = href; }
      return;
    }
    var uid = card ? card.getAttribute('data-uid') : '';
    var folder = card ? (card.getAttribute('data-folder') || '') : '';
    if (!uid || !folder) return;
    if (key === 'r' || key === 'a' || key === 'f') {
      var attr = key === 'r' ? 'data-reply' : (key === 'a' ? 'data-replyall' : 'data-forward');
      var link = card.getAttribute(attr);
      if (link) { kbBusy(kbLinkWithHref(link)); win.location.href = link; }
      return;
    }
    if (key === 's') { win.markUnreadBack(uid, folder); return; }
    if (key === '#') { win.confirmDelete(uid, folder, 'Delete this message?'); return; }
    if (key === '!') { win.rowAction(uid, folder, '?page=spam', 'remove'); return; }
    if (key === 'v') { win.moveTo(uid, folder); return; }
    if (key === 'e') {
      var dest = kbFolderMap()['a'];
      if (!dest) { win.toast('No archive folder.', 'error'); return; }
      win.rowAction(uid, folder, '?page=move', 'remove', { dest: dest });
    }
  }

  // Only the movement keys auto-repeat: holding j/k or an arrow walks the list,
  // while a held e/#/! must not fire its action again.
  function kbNavKey(key){
    return key === 'j' || key === 'k' || key === 'ArrowDown' || key === 'ArrowUp';
  }

  function kbDispatch(ev){
    if (!kbAuthed()) return;
    if (kbModalOpen()) return;
    if (kbEditable(ev)) return;
    var key = ev.key;
    if (ev.repeat && !kbNavKey(key)) return;

    // The ?-sheet is reachable even when shortcuts are off: it is the way back,
    // so a disabled user can always re-enable from the sheet or the avatar menu.
    if (key === '?') { kbHelp(); return; }
    if (!kbEnabled()) return;

    // A pending g prefix consumes the next key: letters map to a folder,
    // everything else cancels it and falls through to the key's own shortcut.
    if (kbPrefix) {
      if (key === 'Escape') { kbPrefixReset(); return; }
      var role = /^[itdsar]$/i.test(key) ? key.toLowerCase() : '';
      if (role) { kbGoFolder(role); return; }
      kbPrefixReset();
    }
    if (key === 'g') {
      kbPrefix = 'g';
      kbPrefixTimer = win.setTimeout(kbPrefixReset, 1500);
      return;
    }

    switch (key) {
      case 'c': kbBusy(doc.querySelector('.compose-btn')); win.location.href = '?page=compose'; return;
      // Focus search, but stop the keydown's default action: without the
      // preventDefault the focused field also receives the "/" itself.
      case '/': ev.preventDefault(); kbFocusSearch(); return;
      case 'u': kbBack(); return;
      case 'y': kbSync(); return;
      // Esc is the one key that reaches here from inside a field. It unwinds the
      // nearest thing first: a suggestion dropdown, then the composer (discard),
      // then the list cursor, then the reader (back).
      case 'Escape':
        if (kbCloseAutocomplete()) return;
        if (doc.querySelector('.compose-form')) { kbDiscard(); return; }
        if (kbBlurField()) return;
        if (doc.querySelector('.mail-list')) { pickReset(); kbCursorClear(); return; }
        if (doc.querySelector('.actions-bar')) { kbBack(); return; }
        return;
    }

    if (doc.querySelector('.mail-list')) { kbListKey(ev, key); return; }
    if (doc.querySelector('.actions-bar')) { kbReaderKey(ev, key); }
  }

  // The ?-sheet's Close, the disable toggle, and the account-menu Help item.
  var kbDlg = doc.querySelector('#kb-help-dialog');
  if (kbDlg) {
    var kbClose = kbDlg.querySelectorAll('.btn-cancel')[0];
    if (kbClose) $(kbClose).on('click', function(){ kbDlg.close(); });
    $(kbDlg).on('click', '[data-kb-disable]', function(ev){
      ev.preventDefault();
      // A toggle, so the sheet is also the way back from a disabled state.
      try {
        if (kbEnabled()) win.localStorage.setItem('emmmail-kb', 'off');
        else win.localStorage.removeItem('emmmail-kb');
      } catch (e) {}
      win.location.reload();
    });
  }
  $('[data-kb-help]').on('click', function(){ kbHelp(); });

  // --- The "This browser" card ----------------------------------------------
  // Outside the settings form, like the cache card: that form posts on Save, and
  // a switch inside it that acts at once would be a lie. The button acts at once,
  // the way the keyboard layer's own off-switch does.
  var notifyCard = doc.querySelector('.notify-card');
  if (notifyCard) {
    // No API, or not a secure context (and on iOS, no installed app): a switch
    // that cannot work is worse than no switch, so the card is not shown at all.
    if (!win.Notification || !win.isSecureContext) {
      if (notifyCard.parentNode) notifyCard.parentNode.removeChild(notifyCard);
    } else {
      var notifyBtn = notifyCard.querySelector('[data-notify-toggle]');
      if (notifyBtn) {
        var notifyLabel = function(on){ notifyBtn.textContent = on ? 'Turn off notifications' : 'Enable notifications'; };
        notifyLabel(notifyFlag());
        $(notifyBtn).on('click', function(){
          if (notifyFlag()) { notifyStore(false); notifyLabel(false); return; }
          // requestPermission() only counts inside the user gesture, so it is
          // called here and not after a promise.
          var ask;
          try { ask = win.Notification.requestPermission(); }
          catch (e) { notifyStore(false); notifyLabel(false); win.toast('Not supported on this device', 'error'); return; }
          Promise.resolve(ask).then(function(state){
            if (state !== 'granted') {
              notifyStore(false); notifyLabel(false);
              win.toast('Blocked in browser settings', 'error');
              return;
            }
            notifyStore(true); notifyLabel(true);
            // Granted is not the same as working: Android Chrome grants and then
            // throws on the constructor. Prove it before promising anything, or
            // every later poll throws for the rest of the session.
            try { new win.Notification('New mail', { body: 'Notifications are on.', tag: 'emmmail-test' }); }
            catch (e) {
              notifyStore(false); notifyLabel(false);
              win.toast('Not supported on this device', 'error');
            }
          }, function(){
            notifyStore(false); notifyLabel(false);
            win.toast('Not supported on this device', 'error');
          });
        });
      }
    }
  }

  // Form-action shortcuts. A modifier keeps them out of the way of typing (the
  // editable guard ignores modified keys) and off the single-letter mail keys.
  // Context decides which page they act on.
  $(doc).on('keydown', function(ev){
    if (!(ev.metaKey || ev.ctrlKey) || ev.repeat) return;
    var k = String(ev.key || '').toLowerCase();
    if (doc.querySelector('.compose-form')) {
      if (k === 's' && !ev.shiftKey) { ev.preventDefault(); kbSaveDraft(); return; }
      if (k === 'a' && ev.shiftKey) { ev.preventDefault(); kbAttach(); }
      return;
    }
    if (doc.querySelector('.folder-map-json')) {
      if (k === 's' && !ev.shiftKey) { ev.preventDefault(); kbSaveSettings(); return; }
      if (k === 'x' && ev.shiftKey) { ev.preventDefault(); kbClearCache(); }
    }
  });

  // Composer: Cmd/Ctrl+Enter sends, but only once per send (no auto-repeat, no
  // second click while the first send is still in flight).
  $('.compose-form').on('keydown', function(ev){
    if (!(ev.metaKey || ev.ctrlKey) || ev.key !== 'Enter') return;
    if (ev.repeat) return;
    var btn = this.querySelectorAll('.btn-primary')[0];
    if (!btn || btn.classList.contains('busy')) return;
    ev.preventDefault();
    btn.click();
  });

  doc.addEventListener('keydown', kbDispatch);

  // Contact autocomplete on the compose To/Cc/Bcc fields.
  $('[data-autocomplete]').each(function(){
    var input = this;
    var $dd = $(doc.createElement('div'));
    $dd.addClass('ac-dropdown');
    input.parentNode.appendChild($dd[0]);
    var timer = null;

    $(input).on('input', function(){
      win.clearTimeout(timer);
      var q = this.value;
      if (q.length < 2) { $dd.hide(); return; }
      timer = win.setTimeout(function(){
        $.get('?page=contacts&q=' + encodeURIComponent(q)).then(function(j){
          $dd.html('');
          var rows = j.results || [];
          rows.forEach(function(c){
            var item = doc.createElement('div');
            item.className = 'ac-item';
            item.innerHTML = '<span class="nm">' + $.escapeHtml(c.name || '') + '</span> <span class="em">' + $.escapeHtml(c.email) + '</span>';
            $(item).on('mousedown', function(ev){
              ev.preventDefault();
              input.value = c.email;
              $dd.hide();
            });
            $dd.append(item);
          });
          $dd.css('display', rows.length ? 'block' : 'none');
        });
      }, 250);
    });
    $(doc).on('click', function(){ $dd.hide(); });
  });

  // One confirm dialog, reused: it carries the endpoint and the fields to post,
  // so message delete, folder delete, mailbox wipes and the cache wipe share the
  // whole mechanism. No action anywhere uses a native confirm().
  function openConfirm(url, fields, title, desc, confirmLabel) {
    var dlg = doc.querySelector('#delete-dialog');
    if (!dlg) return;
    var titleEl = dlg.querySelectorAll('.modal-title')[0];
    var descEl = dlg.querySelectorAll('.modal-desc')[0];
    var okEl = dlg.querySelectorAll('.btn-confirm')[0];
    if (titleEl) titleEl.textContent = title || 'Delete?';
    if (descEl) { descEl.textContent = desc || ''; descEl.style.display = desc ? '' : 'none'; }
    if (okEl) {
      var lbl = okEl.querySelectorAll('.btn-label')[0];
      if (lbl) lbl.textContent = confirmLabel || 'Delete';
      else okEl.textContent = confirmLabel || 'Delete';
    }
    dlg._url = url;
    dlg._fields = fields;
    dlg._run = null;
    if (typeof dlg.showModal === 'function') dlg.showModal();
  }

  win.confirmDelete = function(uid, folder, title) {
    if (!uid) return;
    openConfirm(null, null, title || 'Delete this message?', '', 'Delete');
    // A message delete has a row to patch, so it runs the optimistic path on
    // confirm instead of posting and reloading.
    var dlg = doc.querySelector('#delete-dialog');
    if (dlg) {
      dlg._run = function(){
        markBusy(readerControl('[data-delete]'));
        win.rowAction(uid, folder, '?page=delete', 'remove');
      };
    }
  };

  // Deleting a mailbox destroys the mail inside it, so say so.
  win.deleteFolder = function(folder, display) {
    if (!folder) return;
    openConfirm('?page=deleteFolder', { folder: folder },
      'Delete the folder "' + (display || folder) + '"?',
      'Everything in it is permanently deleted.');
  };

  // Wiping a whole mailbox is the most destructive thing here, so it gets the
  // same modal as the rest rather than browser chrome the user cannot read.
  win.confirmEmpty = function(folder, label) {
    if (!folder) return;
    openConfirm('?page=emptyFolder', { folder: folder },
      'Empty ' + (label || folder) + ' permanently?',
      'Every message in it is deleted for good.', 'Empty ' + (label || folder));
  };

  // Filing a message into an ordinary folder. Its own dialog, because it needs a
  // target, not a yes/no.
  win.moveTo = function(uid, folder) {
    var dlg = doc.querySelector('#move-dialog');
    if (!dlg || !uid) return;
    dlg._uid = uid;
    dlg._folder = folder;
    if (typeof dlg.showModal === 'function') dlg.showModal();
  };

  win.confirmClearCache = function() {    openConfirm('?page=settings', { clear_cache: '1' },
      'Clear the local cache?',
      'Folders are re-downloaded as you open them. No mail is lost.', 'Clear cache');
  };

  win.newFolder = function() {    var dlg = doc.querySelector('#folder-dialog');
    if (!dlg) return;
    var input = dlg.querySelectorAll('.folder-name')[0];
    if (input) { input.value = ''; }
    if (typeof dlg.showModal === 'function') dlg.showModal();
    if (input && typeof input.focus === 'function') input.focus();
  };

  // Shared folder-map UI. buildFolderRows() renders the five role dropdowns
  // into a container (the settings modal or the onboarding dialog), and
  // collectFolderMap() reads them back into a role->name map. Both dialogs
  // reuse them so behaviour can never drift apart.
  win.buildFolderRows = function(container, folders, defaultMap) {
    container.innerHTML = '';
    var roles = {sent:'Sent',drafts:'Drafts',spam:'Spam',trash:'Trash',archive:'Archive'};
    var icons = {sent:'send',drafts:'pencil',spam:'alert-triangle',trash:'trash',archive:'archive'};
    for (var key in roles) {
      var row = doc.createElement('div');
      row.className = 'form-group';
      row.style.cssText = 'display:flex;align-items:center;gap:12px;margin-bottom:12px';
      var lbl = doc.createElement('label');
      lbl.style.cssText = 'min-width:80px;display:flex;align-items:center;gap:6px';
      lbl.innerHTML = '<svg class="ti ti-' + icons[key] + '" width="16" height="16"><use href="#tabler-' + icons[key] + '"/></svg> ' + roles[key];
      var sel = doc.createElement('select');
      sel.className = 'folder-map-select';
      sel.setAttribute('data-role', key);
      sel.style.flex = '1';
      var def = doc.createElement('option');
      def.value = '';
      def.textContent = '-- Default --';
      sel.appendChild(def);
      for (var i = 0; i < folders.length; i++) {
        var opt = doc.createElement('option');
        opt.value = folders[i].name;
        opt.textContent = folders[i].name;
        if (defaultMap[key] === folders[i].name) opt.selected = true;
        sel.appendChild(opt);
      }
      row.appendChild(lbl);
      row.appendChild(sel);
      container.appendChild(row);
    }
  };

  win.collectFolderMap = function(container) {
    var map = {};
    var sels = container.querySelectorAll('.folder-map-select');
    for (var i = 0; i < sels.length; i++) {
      var role = sels[i].getAttribute('data-role');
      var val = sels[i].value;
      if (val !== '') map[role] = val;
    }
    return map;
  };

  win.openFolderMap = function(folders, map, defaultMap) {
    var dlg = doc.querySelector('#folder-map-dialog');
    if (!dlg) return;
    var rows = dlg.querySelector('.folder-map-rows');
    if (!rows) return;
    // The detected map only pre-fills when the user has no saved map yet: their
    // own choices are the single source of truth once set.
    var preselect = (map && Object.keys(map).length) ? map : (defaultMap || {});
    win.buildFolderRows(rows, folders, preselect);
    if (typeof dlg.showModal === 'function') dlg.showModal();
  };

  win.openOnboarding = function() {
    var dlg = doc.querySelector('#onboarding-dialog');
    if (!dlg) return;
    var folders = [];
    var defaultMap = {};
    try { folders = JSON.parse(dlg.getAttribute('data-folders') || '[]'); } catch(e) {}
    try { defaultMap = JSON.parse(dlg.getAttribute('data-default-map') || '{}'); } catch(e) {}
    var rows = dlg.querySelector('.folder-map-rows');
    if (!rows) return;
    win.buildFolderRows(rows, folders, defaultMap);
    // A failed IMAP (cold start) leaves the list empty: only "Later" works.
    var save = dlg.querySelectorAll('.btn-confirm-primary')[0];
    if (save && folders.length === 0) save.disabled = true;
    if (typeof dlg.showModal === 'function') dlg.showModal();
  };

  $.ready(function(){
    // Server-rendered toasts (a flash after a redirect) get the same schedule
    // as JS-created ones, so a page load never leaves a toast to rot.
    var toastList = doc.querySelectorAll('.toast-container .toast');
    for (var t = 0; t < toastList.length; t++) {
      var tt = toastList[t];
      var tType = tt.classList.contains('toast-success') ? 'success' : 'error';
      var tMs = (win.__toastMs && win.__toastMs[tType]) || (tType === 'success' ? 4000 : 6000);
      (function(el, ms){
        var leaving = false;
        setTimeout(function(){
          if (leaving) return;
          leaving = true;
          el.classList.add('hiding');
          setTimeout(function(){ if (el.parentNode) el.parentNode.removeChild(el); }, 220);
        }, ms);
      })(tt, tMs);
    }
    var dlg = doc.querySelector('#delete-dialog');
    if (dlg) {
      $(dlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        dlg.close();
      });
      $(dlg.querySelectorAll('.btn-confirm')[0]).on('click', function(){
        // A caller that owns the follow-up (an optimistic row patch) runs it
        // here; everything else posts and reloads, which is right when the whole
        // page changes, as it does for a folder delete or a mailbox wipe.
        if (dlg._run) {
          var run = dlg._run;
          dlg._run = null;
          dlg.close();
          run();
          return;
        }
        var url = dlg._url;
        var fields = dlg._fields || {};
        if (!url) { dlg.close(); return; }
        markBusy(this);
        fields.csrf_token = $.token;
        $.post(url, fields).then(function(){
          win.location.reload();
        });
      });
    }
    var fdlg = doc.querySelector('#folder-dialog');
    if (fdlg) {
      $(fdlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        fdlg.close();
      });
      $(fdlg.querySelectorAll('.btn-confirm')[0]).on('click', function(){
        var input = fdlg.querySelectorAll('.folder-name')[0];
        var name = input ? input.value.replace(/^\s+|\s+$/g, '') : '';
        if (!name) { return; }
        markBusy(this);
        $.post('?page=createFolder', { csrf_token: $.token, name: name }).then(function(){
          win.location.reload();
        });
      });
    }
    var mapdlg = doc.querySelector('#folder-map-dialog');
    if (mapdlg) {
      $(mapdlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        mapdlg.close();
      });
      $(mapdlg.querySelectorAll('.btn-confirm-primary')[0]).on('click', function(){
        var input = $('.folder-map-json')[0];
        if (input) input.value = JSON.stringify(win.collectFolderMap(mapdlg));
        mapdlg.close();
      });
    }
    var obdlg = doc.querySelector('#onboarding-dialog');
    if (obdlg) {
      $(obdlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        // "Later" dismisses without saving; onboarding reappears next session.
        obdlg.close();
      });
      $(obdlg.querySelectorAll('.btn-confirm-primary')[0]).on('click', function(){
        var map = win.collectFolderMap(obdlg);
        if (!Object.keys(map).length) return; // require at least one selection
        var btn = this;
        markBusy(btn);
        $.post('?page=saveOnboarding', { csrf_token: $.token, protected_folders: JSON.stringify(map) })
          .then(function(r){ return r.json(); })
          .then(function(d){
            clearBusy();
            if (d.ok) win.location.reload();
          });
      });
    }
    var tsdlg = doc.querySelector('#trusted-senders-dialog');
    if (tsdlg) {
      $(tsdlg.querySelectorAll('.btn-cancel')[0]).on('click', function(){
        tsdlg.close();
      });
    }
    if (win.__openOnboarding) { win.openOnboarding(); }
    $('[data-open-folder-map]').on('click', function(){
      var folders = [];
      var map = {};
      var defaultMap = {};
      try { folders = JSON.parse(this.getAttribute('data-folders') || '[]'); } catch(e) {}
      try { map = JSON.parse(this.getAttribute('data-map') || '{}'); } catch(e) {}
      try { defaultMap = JSON.parse(this.getAttribute('data-default-map') || '{}'); } catch(e) {}
      win.openFolderMap(folders, map, defaultMap);
    });
    $('[data-open-trusted-senders]').on('click', function(){
      var senders = [];
      try { senders = JSON.parse(this.getAttribute('data-senders') || '[]'); } catch(e) {}
      var dlg = doc.querySelector('#trusted-senders-dialog');
      if (!dlg) return;
      var list = dlg.querySelector('.trusted-senders-list');
      if (!list) return;
      list.innerHTML = '';
      if (senders.length === 0) {
        list.innerHTML = '<p style="color:var(--text-muted)">No trusted senders yet.</p>';
      } else {
        var ul = doc.createElement('ul');
        ul.style.cssText = 'list-style:none;padding:0;margin:0';
        for (var i = 0; i < senders.length; i++) {
          var li = doc.createElement('li');
          li.style.cssText = 'padding:8px 0;border-bottom:1px solid var(--border-soft);display:flex;align-items:center;gap:8px';
          li.innerHTML = '<svg class="ti ti-mail" width="16" height="16"><use href="#tabler-mail"/></svg> '
            + '<span style="flex:1">' + $.escapeHtml(senders[i]) + '</span>'
            + '<button type="button" class="btn btn-sm btn-danger-soft" data-revoke-sender="' + $.escapeHtml(senders[i]) + '">Revoke</button>';
          ul.appendChild(li);
        }
        list.appendChild(ul);
      }
      if (typeof dlg.showModal === 'function') dlg.showModal();
    });
    $(doc).on('click', '[data-revoke-sender]', function(){
      var sender = this.getAttribute('data-revoke-sender');
      if (!sender) return;
      var row = this.closest('li');
      markBusy(this);
      $.post('?page=revokeSender', { csrf_token: $.token, sender: sender }).then(function(r){
        return r.json();
      }).then(function(d){
        if (d.ok && row) row.remove();
        clearBusy();
      });
    });
  });
})();
JS;
}

// [8b] Individual view builders -----------------------------------------------

// The credit line, built once so the login page and the app sidebar can never
// disagree on the version. Markup is literal: `#<version>` is a version marker,
// not a Markdown heading.
function view_footer(): string
{
  $bareUrl = 'https://usebare.org?ref=Emmmail&utm_source=Emmmail&utm_media=Instance&utm_content=' . APP_VERSION;
  $blank = ' target="_blank" rel="noopener noreferrer"';
  return '<p>Designed and built by '
    . '<a href="https://x.com/rogeriotaques"' . $blank . '>Rogerio Taques</a>, the guy behind '
    . '<a href="' . h($bareUrl) . '"' . $blank . '>Use Bare</a>.</p>'
    . '<p>#' . h(APP_VERSION) . ' &copy; Abtz Labs</p>';
}

function view_login(string $csrf): string
{
  $post  = $_POST;
  $email = trim($post['email'] ?? '');

  // Prefill custom server fields from stored settings for a known email.
  $stored = [
    'imap_server' => '',
    'imap_port' => MAIL_IMAP_PORT,
    'imap_enc' => IMAP_ENCRYPTION,
    'smtp_server' => '',
    'smtp_port' => MAIL_SMTP_PORT,
    'smtp_enc' => SMTP_ENCRYPTION,
  ];
  if ($email !== '') {
    $stored = [
      'imap_server' => (string)(db_get_setting($email, 'imap_server', '') ?: MAIL_SERVER),
      'imap_port'   => (int)(db_get_setting($email, 'imap_port', MAIL_IMAP_PORT) ?: MAIL_IMAP_PORT),
      'imap_enc'    => (string)(db_get_setting($email, 'imap_enc', IMAP_ENCRYPTION) ?: IMAP_ENCRYPTION),
      'smtp_server' => (string)(db_get_setting($email, 'smtp_server', '') ?: MAIL_SERVER),
      'smtp_port'   => (int)(db_get_setting($email, 'smtp_port', MAIL_SMTP_PORT) ?: MAIL_SMTP_PORT),
      'smtp_enc'    => (string)(db_get_setting($email, 'smtp_enc', SMTP_ENCRYPTION) ?: SMTP_ENCRYPTION),
    ];
  }

  $preset = $post['preset'] ?? '';
  if ($preset === '' && $stored['imap_server'] !== '') {
    $preset = 'custom'; // returning user with saved servers -> show custom fields
  } elseif ($preset === '') {
    $preset = 'migadu'; // default selection
  }

  $presetHtml = '';
  foreach (server_presets() as $key => $p) {
    $sel = ($preset === $key) ? ' selected' : '';
    $presetHtml .= '<option value="' . h($key) . '"' . $sel . '>' . h($p['label']) . '</option>';
  }

  $f = function ($k) use ($post, $stored) {
    return h($post[$k] ?? $stored[$k] ?? '');
  };

  $customShow = ($preset === 'custom') ? ' style="display:block"' : ' style="display:none"';

  // A locked deployment signs in against the operator's servers only, so the
  // picker is not rendered at all. A broken lock says so up front, in the box,
  // where a fading toast would not survive the time it takes to read it.
  $lock = server_lock();
  $servers = $lock['locked'] ? '' : '<div class="form-group"><label>Mail server</label>'
    . '<select name="preset" id="login-preset">'
    . $presetHtml
    . '<option value="custom"' . (($preset === 'custom') ? ' selected' : '') . '>Custom settings…</option>'
    . '</select></div>'
    . '<div class="custom-servers"' . $customShow . '>'
    . '<div class="fieldset-title">IMAP (receiving)</div>'
    . '<div class="form-group"><label>IMAP server</label>'
    . '<input type="text" name="imap_server" value="' . $f('imap_server') . '" placeholder="imap.example.com"></div>'
    . '<div class="row2">'
    . '<div class="form-group"><label>IMAP port</label>'
    . '<input type="number" name="imap_port" value="' . $f('imap_port') . '"></div>'
    . '<div class="form-group"><label>Encryption</label>'
    . '<select name="imap_enc">'
    . '<option value="ssl"'    . (($f('imap_enc') === 'ssl')    ? ' selected' : '') . '>SSL / TLS</option>'
    . '<option value="tls"'    . (($f('imap_enc') === 'tls')    ? ' selected' : '') . '>STARTTLS</option>'
    . '<option value="notls"'  . (($f('imap_enc') === 'notls')  ? ' selected' : '') . '>None</option>'
    . '</select></div></div>'
    . '<div class="fieldset-title">SMTP (sending)</div>'
    . '<div class="form-group"><label>SMTP server</label>'
    . '<input type="text" name="smtp_server" value="' . $f('smtp_server') . '" placeholder="smtp.example.com"></div>'
    . '<div class="row2">'
    . '<div class="form-group"><label>SMTP port</label>'
    . '<input type="number" name="smtp_port" value="' . $f('smtp_port') . '"></div>'
    . '<div class="form-group"><label>Encryption</label>'
    . '<select name="smtp_enc">'
    . '<option value="tls"' . ((($f('smtp_enc') === 'tls') || $f('smtp_enc') === '') ? ' selected' : '') . '>STARTTLS</option>'
    . '</select></div></div>'
    . '</div>';
  $alert = ($lock['error'] !== '') ? '<p class="login-alert" role="alert">' . h($lock['error']) . '</p>' : '';

  return '<div class="login-box"><h1>' . icon('mail', 22) . ' ' . APP_NAME . '</h1>'
    . '<p class="sub">Sign in to your mailbox</p>'
    . $alert
    . '<form method="POST" action="?page=login">'
    . '<input type="hidden" name="csrf_token" value="' . h($csrf) . '">'
    . '<div class="form-group"><label>Email</label>'
    . '<input type="email" name="email" id="login-email" required'
    . (($email === '') ? ' autofocus' : '') . ' value="' . h($email) . '" autocomplete="email"></div>'
    . '<div class="form-group"><label>Password</label>'
    . '<input type="password" name="password" required'
    . (($email !== '') ? ' autofocus' : '') . ' id="login-password" autocomplete="current-password"></div>'
    . $servers
    . '<button class="btn btn-primary btn-block" type="submit">' . icon('arrow-right') . ' Sign in</button>'
    . '</form>'
    . '</div>'
    . '<footer class="login-footer">' . view_footer() . '</footer>';
}

function view_sidebar(array $folders, string $currentFolder, ?array $folderMap = null, array $counts = []): string
{
  $newFolder = '<button type="button" class="btn btn-sm btn-block new-folder" onclick="newFolder()">'
    . icon('folder-plus') . ' New folder</button>';
  $h  = '<div class="layout"><aside class="sidebar">';
  $h .= '<a href="?page=compose" class="btn btn-primary btn-block compose-btn">' . icon('plus') . ' New message</a>';
  // A cold or unreadable folder-list cache used to draw the heading over an empty
  // <ul> and a New folder button, which reads as broken rather than as a cache
  // miss. Name the condition instead; the inbox repopulates the cache on its next
  // visit. New folder stays, because it needs no list to be useful.
  if (!$folders) {
    return $h . '<nav><h3>Folders</h3><p class="sidebar-empty">Folders are unavailable'
      . ' right now. Open your Inbox to load them again.</p>'
      . $newFolder . '</nav>'
      . '<footer class="sidebar-footer">' . view_footer() . '</footer></aside>';
  }
  $h .= '<nav><h3>Folders</h3><ul>';
  $prevGroup = '';
  foreach ($folders as $f) {
    $group = $f['group'] ?? '';
    // Insert a divider before Trash and before the "other" group.
    if ($prevGroup !== '' && $group !== $prevGroup) {
      $h .= '<li class="group-sep"></li>';
    }
    $prevGroup = $group;
    $active = ($f['name'] === $currentFolder) ? 'active' : '';
    $display = $f['displayName'];
    $icon = folder_icon($f['name']);
    // A zero badge is rendered hidden, so the optimistic JS can bump it.
    $badge = folder_badge($f['name'], $counts[$f['name']] ?? null, $folderMap);
    $count = $badge === null ? ''
      : '<span class="folder-count" data-kind="' . $badge['kind'] . '"' . ($badge['n'] > 0 ? '' : ' hidden') . '>'
      . $badge['n'] . '</span>';
    $h .= '<li class="' . $active . '"><a class="folder-link" href="?page=folder&folder=' . urlencode($f['name'])
      . '" data-folder="' . h(urlencode($f['name'])) . '">'
      . $icon . $display . $count . '</a>';
    // Only folders the handler would actually delete get the affordance.
    if (!folder_is_protected($f['name'], $folderMap)) {
      $h .= '<button type="button" class="icon-btn folder-del tip" data-delete-folder data-tip="Delete folder" aria-label="Delete folder" '
        . 'onclick="deleteFolder(\'' . h(urlencode($f['name'])) . '\', \'' . h($display) . '\')">'
        . icon('trash', 14) . '</button>';
    }
    $h .= '</li>';
  }
  $h .= '</ul>';
  $h .= $newFolder;
  $h .= '</nav>';
  $h .= '<footer class="sidebar-footer">' . view_footer() . '</footer>';
  $h .= '</aside>';
  return $h;
}

/**
 * <option> list of folders a message may be moved into, or '' when there are
 * none. Shared by the reader and the message list so the picker can never offer
 * a move that folder_move_check() would refuse on the way in.
 */
function move_target_options(array $folders, string $from): string
{
  $out = '';
  foreach ($folders as $f) {
    $name = (string)($f['name'] ?? '');
    if (folder_move_check($from, $name, $folders) !== 'ok') {
      continue;
    }
    $out .= '<option value="' . h(urlencode($name)) . '">'
      . h((string)($f['displayName'] ?? $name)) . '</option>';
  }
  return $out;
}

// Whether deleting from $folder is a HARD delete. It is when handle_delete()
// takes the Trash branch, AND when moveManyToTrash() cannot find a Trash and
// falls back to deleteManyByUid() -- which is what a server whose nested
// "Deleted Items" is not recognised as one does (docs/knowledge/imap.md:136).
// So the question needs the folder LIST, not just the folder name: on such an
// account the name says INBOX while the delete is permanent for 200 messages
// with no undo. No list, or no Trash in it, proves nothing, so it answers yes.
function delete_is_permanent(string $folder, array $folders = [], ?array $folderMap = null): bool
{
  return MailImap::isTrashFolder($folder) || MailImap::findTrashFolderIn($folders, $folderMap) === null;
}

// The wording for a delete confirm. It says "permanently" exactly when
// delete_is_permanent() says the delete cannot be undone.
// The same sentence with the count left open, for markup that does not know
// the count yet (the bar, before a click). One source for the wording.
function confirm_delete_copy(string $folder, array $folders = [], ?array $folderMap = null): string
{
  if (delete_is_permanent($folder, $folders, $folderMap)) {
    return 'Permanently delete %d messages? This cannot be undone.';
  }
  return 'Delete %d messages? They will move to Trash.';
}

function confirm_delete_message(int $count, string $folder, array $folders = [], ?array $folderMap = null): string
{
  $n = max(1, $count);
  $one = $n === 1;
  if (delete_is_permanent($folder, $folders, $folderMap)) {
    return 'Permanently delete ' . $n . ' ' . ($one ? 'message' : 'messages') . '? This cannot be undone.';
  }
  if ($one) {
    return 'Delete this message?';
  }
  return 'Move ' . $n . ' messages to Trash?';
}

// The picker itself. One copy, rendered by whichever view needs it.
function view_move_dialog(string $options): string
{
  if ($options === '') {
    return '';
  }
  return '<dialog class="modal" id="move-dialog">'
    . '<h3 class="modal-title">Move to folder</h3>'
    . '<select class="move-dest">' . $options . '</select>'
    . '<div class="modal-actions">'
    . '<button class="btn-cancel" type="button">Cancel</button>'
    . '<button class="btn-confirm" type="button">Move</button>'
    . '</div></dialog>';
}

// A row/toolbar button that opens the picker for one message.
function view_move_button(int $uid, string $encFolder, string $class): string
{
  // data-move marks this as dialog-first: the client must not show it as working,
  // because nothing is in flight until the picker is confirmed.
  return '<button type="button" class="' . $class . ' tip" data-move data-tip="Move to" aria-label="Move to" '
    . 'onclick="moveTo(' . $uid . ', \'' . $encFolder . '\')">' . icon('folder') . '</button>';
}

function view_inbox(array $d): string
{
  return view_sidebar($d['folders'], $d['current_folder'] ?? '', $d['folder_map'] ?? null, $d['folder_counts'] ?? [])
    . view_mailbox($d) . '</div>';
}

// The folder-listing section on its own. view_inbox() composes this with the
// sidebar for a full page; the in-place folder switch fetches ?page=folder
// with ajax=1 and swaps only this section, so it must render standalone.
function view_mailbox(array $d): string
{
  $h  = '<section class="mailbox"><div class="mailbox-head"><h2>' . h($d['title'])
    . ' <small class="mail-count">(' . $d['total'] . ')</small></h2>'
    // Select-all lives in the head, before the toolbar. The visible text names it:
    // a bare box beside the title read as decoration.
    . (!empty($d['emails'])
      ? '<label class="pick-all"><input type="checkbox" class="mail-pick-all" data-pick-all aria-label="Select all messages">'
      // The text is also the live count: "Select all" with nothing picked,
      // "18 selected" after, so the bar needs no count of its own.
      . ' <span class="pick-all-text" data-bulk-count role="status">Select all</span></label>'
      : '');
  // Selection needs rows to select. An empty mailbox gets no select-all and no
  // bar, because a control that can only ever select nothing is a dead control.
  // The bar sits IN the head, in the free space before the toolbar: as a row of
  // its own under the head it pushed the whole list down on the first pick.
  if (!empty($d['emails'])) {
    $h .= '<div class="bulk-bar hidden" data-bulk-folder="'
      . h(urlencode($d['current_folder'])) . '"'
      // The delete copy is decided here, not in the client: only the server
      // knows whether this folder is Trash, and only it can say "permanently".
      . ' data-bulk-delete-copy="' . h(confirm_delete_copy(
        $d['current_folder'],
        $d['folders'] ?? [],
        $d['folder_map'] ?? null
      )) . '">'
      . '<button type="button" class="btn btn-sm" data-bulk-action="read" aria-label="Mark selected as read">'
      . icon('mail-opened') . ' <span class="btn-label">Read</span></button>'
      . '<button type="button" class="btn btn-sm" data-bulk-action="unread" aria-label="Mark selected as unread">'
      . icon('mail') . ' <span class="btn-label">Unread</span></button>'
      . '<button type="button" class="btn btn-sm" data-bulk-action="move" aria-label="Move selected">'
      . icon('folder') . ' <span class="btn-label">Move</span></button>'
      // Delete is the only irreversible one, so it takes the confirm dialog like
      // every other destructive action. The copy is built in PHP because whether
      // it is reversible depends on the folder, which only the server knows.
      . '<button type="button" class="btn btn-sm btn-danger-soft" data-bulk-action="delete" aria-label="Delete selected">'
      . icon('trash') . ' <span class="btn-label">Delete</span></button>'
      . '</div>';
  }
  $h .= '<div class="toolbar">'
    // js_inline() drives the background sync off `.sync[data-folder]` and writes
    // the age into `.sync-text`. Both are load-bearing: without the wrapper
    // $('.sync') is empty and nothing ever syncs. data-sync-stale is the leaf the
    // boot poll reads: the cache survives logout, so a fresh login would show old
    // rows and wait a whole period before correcting them.
    . '<div class="sync" data-folder="' . h(urlencode($d['current_folder'])) . '"'
    . (sync_is_stale((int)($d['last_sync_ts'] ?? 0), time(), SYNC_CLIENT_MIN_PERIOD)
      ? ' data-sync-stale="1"' : '') . '>'
    . '<button class="btn btn-sm sync-btn tip" type="button" data-tip="Check for new mail">'
    . icon('progress-check') . ' <span class="sync-text">'
    . h(relative_time((int)($d['last_sync_ts'] ?? 0), time())) . '</span></button></div>';
  // Between the sync chip and the search field, styled like every other
  // destructive action: an ordinary pill that only reddens on hover.
  if ((int)$d['total'] > 0 && (!empty($d['is_trash']) || !empty($d['is_junk']))) {
    $emptyLabel = !empty($d['is_junk']) ? 'Empty Spam' : 'Empty Trash';
    $h .= '<form method="POST" action="?page=emptyFolder" onsubmit="confirmEmpty(\''
      . h(urlencode($d['current_folder'])) . '\', \'' . ($emptyLabel === 'Empty Spam' ? 'Spam' : 'Trash')
      . '\'); return false;">'
      . '<input type="hidden" name="csrf_token" value="' . h(csrf_page_token()) . '">'
      . '<input type="hidden" name="folder" value="' . h(urlencode($d['current_folder'])) . '">'
      . '<button class="btn btn-sm btn-pill btn-danger-soft">' . icon('trash') . ' ' . $emptyLabel . '</button></form>';
  }
  $h .= // A GET form discards the action's query string and sends only its fields,
    // so "page" must be a field or the router falls back to the inbox.
    '<form method="GET" action="?page=search" class="search">'
    . '<input type="hidden" name="page" value="search">'
    . '<input type="hidden" name="folder" value="' . h(urlencode($d['current_folder'])) . '">'
    . '<input type="text" name="q" placeholder="Search..." value="' . h($d['search_query'] ?? '') . '">'
    . '<button class="btn btn-sm search-btn tip" data-tip="Search" aria-label="Search">'
    . icon('search') . '</button></form>';
  $h .= '</div></div>';

  // Failures float in as toasts (win.toast), which render_page() mounts. There
  // is no static error box to keep in sync with the views.

  // Local results come from cached headers, which cannot match body text of
  // messages that were never opened. Offer the server-side full-text search.
  if (!empty($d['server_search'])) {
    $h .= '<div class="hint">Showing local matches. '
      . '<a href="?page=search&amp;server=1&amp;folder=' . h(urlencode($d['current_folder']))
      . '&amp;q=' . h(urlencode($d['search_query'] ?? '')) . '">Search message bodies on the server</a></div>';
  }

  if (empty($d['emails'])) {
    $h .= '<div class="empty">No messages here.</div>';
  } else {
    $now = time();
    // One picker for the whole list, built once from the sidebar folder list.
    $moveOptions = move_target_options($d['folders'] ?? [], (string)($d['current_folder'] ?? ''));
    $encFolder = h(urlencode($d['current_folder']));
    // A threaded listing groups replies; a flat one (live tail, Drafts) renders
    // one row per message exactly as before.
    $threaded = !empty($d['threads']);
    $h .= '<div class="mail-list" data-threads="' . ($threaded ? '1' : '0') . '">';
    foreach ($d['emails'] as $e) {
      $unread = !empty($e['unread']) ? 'unread' : '';
      $clip   = !empty($e['has_attachment']) ? icon('paperclip') : '';
      $from   = !empty($d['is_sent']) ? ('To: ' . ($e['to'] ?: $e['to_address'])) : $e['from'];
      $avatarName = !empty($d['is_sent']) ? ($e['to_address'] ?: $e['to']) : ($e['from_address'] ?: $e['from']);
      // A conversation of more than one message gets its own reader, its count
      // chip, and the key + member uids the client reads.
      $threadCount = $threaded ? (int)($e['thread_count'] ?? 1) : 1;
      $isThread    = $threadCount > 1;
      $rowMove = $moveOptions === '' ? ''
        : view_move_button((int)$e['uid'], $encFolder, 'icon-btn');
      // A draft is unfinished work, so it opens where you left off, not read-only.
      $link   = !empty($d['is_drafts'])
        ? '?page=compose&draft=' . $e['uid']
        : ($isThread
          ? '?page=thread&key=' . rawurlencode((string)($e['thread_key'] ?? '')) . '&folder=' . urlencode($d['current_folder'])
          : '?page=read&uid=' . $e['uid'] . '&folder=' . urlencode($d['current_folder']));
      $threadAttrs = $isThread
        ? ' data-thread="' . h((string)($e['thread_key'] ?? '')) . '"'
        . ' data-thread-uids="' . h(implode(',', array_map('intval', (array)($e['thread_uids'] ?? [])))) . '"'
        : '';
      $countChip = $isThread
        ? '<span class="mail-thread-count" aria-label="' . $threadCount . ' messages in this conversation">' . $threadCount . '</span>'
        : '';
      $h .= '<div class="mail ' . $unread . '" data-uid="' . (int)$e['uid'] . '" data-folder="' . h(urlencode($d['current_folder'])) . '"' . $threadAttrs . '>'
        // The picker is the LAST child of the avatar so it paints over the
        // letter (CSS centres it absolutely) and nothing paints over it.
        . '<span class="mail-avatar ' . h(avatar_tone($avatarName)) . '">' . h(avatar_initial($avatarName))
        . '<input type="checkbox" class="mail-pick" data-pick-uid="' . (int)$e['uid'] . '"'
        . ' aria-label="Select message from ' . h($from) . '"></span>'
        . '<a class="mail-main" href="' . h($link) . '">'
        . '<div class="mail-line">'
        . '<span class="mail-from">' . h($from) . '</span>'
        // The count and the date share one right-hand group: as three siblings
        // in a space-between row the chip drifted into the middle.
        . '<span class="mail-line-right">' . $countChip
        . '<span class="mail-date">' . h(list_date((int)$e['timestamp'], $now)) . '</span></span>'
        . '</div>'
        . '<div class="mail-subject">' . $clip . ' ' . h($e['subject']) . '</div></a>'
        . '<span class="mail-actions">'
        // One button both ways: toggleRead() reads .unread off the row, so the
        // markup never has to rewrite its own onclick.
        . '<button type="button" class="icon-btn tip" data-read-toggle data-tip="' . ($unread ? 'Mark read' : 'Mark unread') . '" aria-label="' . ($unread ? 'Mark read' : 'Mark unread') . '" '
        . 'onclick="toggleRead(' . (int)$e['uid'] . ', \'' . urlencode($d['current_folder']) . '\')">'
        . ($unread ? icon('mail') : icon('mail-opened')) . '</button>'
        . $rowMove;
      if (!empty($d['is_trash'])) {
        $h .= '<form method="POST" action="?page=restore" style="display:inline" '
          . 'onsubmit="rowAction(' . (int)$e['uid'] . ', \'' . urlencode($d['current_folder']) . '\', \'?page=restore\', \'remove\'); return false;">'
          . '<input type="hidden" name="csrf_token" value="' . h(csrf_page_token()) . '">'
          . '<input type="hidden" name="uid" value="' . $e['uid'] . '">'
          . '<input type="hidden" name="folder" value="' . h(urlencode($d['current_folder'])) . '">'
          . '<button class="icon-btn tip" data-tip="Restore" aria-label="Restore">' . icon('rotate') . '</button></form>';
      }
      $h .= '<form method="POST" action="?page=delete" style="display:inline" data-uid="' . (int)$e['uid'] . '" data-folder="' . h(urlencode($d['current_folder'])) . '" '
        . 'onsubmit="confirmDelete(' . (int)$e['uid'] . ', \'' . urlencode($d['current_folder']) . '\', \'Delete this message?\'); return false;">'
        . '<input type="hidden" name="csrf_token" value="' . h(csrf_page_token()) . '">'
        . '<input type="hidden" name="uid" value="' . $e['uid'] . '">'
        . '<input type="hidden" name="folder" value="' . h(urlencode($d['current_folder'])) . '">'
        . '<button class="icon-btn tip" data-delete data-tip="Delete" aria-label="Delete">' . icon('trash') . '</button></form>';
      $h .= '</span></div>';
    }
    $h .= '</div>';
    if ((int)$d['pages'] > 1) {
      $base = '?page=' . (!empty($d['search_query']) ? 'search&q=' . urlencode($d['search_query']) : 'folder') . '&folder=' . urlencode($d['current_folder']) . '&p=';
      $h .= '<div class="pagination">';
      if ($d['current_page'] > 1) $h .= '<a class="btn btn-sm" href="' . h($base . ($d['current_page'] - 1)) . '">' . icon('arrow-left') . '</a>';
      $h .= '<span class="page-info">Page ' . $d['current_page'] . ' of ' . $d['pages'] . '</span>';
      if ($d['current_page'] < $d['pages']) $h .= '<a class="btn btn-sm" href="' . h($base . ($d['current_page'] + 1)) . '">' . icon('arrow-right') . '</a>';
      $h .= '</div>';
    }
    $h .= view_move_dialog($moveOptions);
  }
  $h .= '</section>';
  return $h;
}

// Render a Tabler icon from the external sprite (current-color outline).
// Injects the embedded Tabler sprite once (contains all <symbol> defs).
// Call once near the top of <body> so same-document <use> works.
function tabler_sprite(): string
{
  static $rendered = false;
  if ($rendered) return '';
  $rendered = true;
  return TABLER_SPRITE;
}

// Render a Tabler icon from the embedded sprite (current-color outline).
function icon(string $name, int $size = 18): string
{
  return '<svg class="ti" width="' . $size . '" height="' . $size
    . '" viewBox="0 0 24 24" aria-hidden="true"><use href="#tabler-' . h($name) . '"/></svg>';
}

function folder_display_name(string $name): string
{
  $map = [
    'INBOX' => 'Inbox',
    'Wysłane' => 'Sent',
    'Wyslane' => 'Sent',
    'Wiadomości wysłane' => 'Sent',
    'Kosz' => 'Trash',
    'Kosz na śmieci' => 'Trash',
    'Junk' => 'Spam',
    'Spam' => 'Spam',
    'Drafts' => 'Drafts',
    'Sent' => 'Sent',
    'Trash' => 'Trash',
    'Archive' => 'Archive',
    'Archived' => 'Archive',
    'Archiwum' => 'Archive',
  ];
  if (isset($map[$name])) return $map[$name];
  return ucfirst(strtolower($name));
}

// First letter of a sender, for the list avatar. Pure.
function avatar_initial(string $sender): string
{
  $sender = trim($sender);
  if ($sender === '') {
    return '?';
  }
  $name = trim(preg_replace('/<[^>]*>/', '', $sender));
  $name = trim($name, "\" \t");
  if ($name === '') {
    $name = $sender;
  }
  return mb_strtoupper(mb_substr($name, 0, 1));
}

// Deterministic avatar tint (one of eight Nord hues). Pure.
function avatar_tone(string $sender): string
{
  return 'av-' . (crc32(strtolower(trim($sender))) % 8);
}

// Short list date: time for today, month/day this year, full date otherwise. Pure.
function list_date(int $ts, int $now): string
{
  if ($ts <= 0) {
    return '';
  }
  if (date('Y-m-d', $ts) === date('Y-m-d', $now)) {
    return date('H:i', $ts);
  }
  if (date('Y', $ts) === date('Y', $now)) {
    return date('M j', $ts);
  }
  return date('M j, Y', $ts);
}

/**
 * The address a reply should go to. Pure.
 *
 * Reply-To wins over From: that is the whole point of the header, and ignoring it
 * sends list replies and no-reply-sender replies to a black hole. An unusable
 * Reply-To is discarded rather than mailed.
 */
function reply_sender_address(array $email): string
{
  $replyTo = header_address_list((string)($email['reply_to_address'] ?? ''));
  if ($replyTo !== '') {
    return $replyTo;
  }
  return (string)($email['from_address'] ?? '');
}

/**
 * Who a reply-all goes to, split into its two groups. Pure.
 *
 * The sender joins the To group along with everyone else who was in To; the
 * original Cc group stays Cc. The user's own address is dropped from both -- a
 * reply-all that mails you a copy of your own reply is a bug -- and an address is
 * never listed twice, nor in Cc when it is already in To.
 *
 * Comparison is case-insensitive on the whole address. The local part is
 * technically case-sensitive per RFC 5321, but no real mail system treats it that
 * way, and matching case-sensitively here would put the user back in their own
 * recipient list.
 */
function reply_all_recipients(array $email, string $me): array
{
  $seen = [];
  $meKey = strtolower(trim($me));
  if ($meKey !== '') {
    $seen[$meKey] = true;
  }

  $collect = function (string $header) use (&$seen) {
    $out = [];
    foreach (explode(', ', header_address_list($header)) as $addr) {
      $addr = trim($addr);
      if ($addr === '') {
        continue;
      }
      $key = strtolower($addr);
      if (isset($seen[$key])) {
        continue;
      }
      $seen[$key] = true;
      $out[] = $addr;
    }
    return $out;
  };

  // The sender first: a reply is addressed to them above all.
  $to = $collect(reply_sender_address($email));
  $to = array_merge($to, $collect((string)($email['to'] ?? '')));
  $cc = $collect((string)($email['cc'] ?? ''));

  return ['to' => implode(', ', $to), 'cc' => implode(', ', $cc)];
}

/**
 * Which reply button leads in the read view. Pure.
 *
 * Reply all earns the default slot only when it would address somebody a plain
 * reply leaves out -- a message sent to you alone gets Reply. Counting raw
 * recipients would be wrong: your own address is a recipient of every message
 * you receive, and reply_all_recipients() already drops it.
 */
function reply_default_is_all(array $email, string $me): bool
{
  $all = reply_all_recipients($email, $me);
  if ($all['cc'] !== '') {
    return true;
  }
  return strcasecmp($all['to'], reply_sender_address($email)) !== 0;
}

/**
 * Everything the composer needs to answer a message. Pure.
 *
 * Exists as its own function because the threading headers were the bug: the send
 * path has always read `in_reply_to_id` from the POST, but nothing ever produced
 * it, so every reply started a new thread. A pure seam means that wiring is now
 * covered by tests rather than by eyeballing a handler.
 */
function strip_html_for_quote(string $html): string
{
  $clean = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html);
  $clean = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $clean);
  $text = strip_tags($clean);
  $text = preg_replace('/[ \t]+/', ' ', $text);
  $text = preg_replace('/\n\s*\n/', "\n\n", $text);
  return trim($text);
}

/**
 * The original message as plain text, for a reply or a forward. Pure.
 *
 * A text part is used exactly as it is: strip_html_for_quote() would drop
 * `<ann@x.io>` and collapse indentation. Only an HTML-only message is stripped,
 * and then its entities are decoded, because the result is text again.
 */
function quote_source_text(array $email): string
{
  $text = (string)($email['body_text'] ?? '');
  if ($text !== '') {
    return $text;
  }
  return html_entity_decode(strip_html_for_quote((string)($email['body_html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * The composer body for a forward. Pure.
 *
 * Everything below the blank line is someone else's text, so all of it is
 * Markdown-escaped: their `![](...)` must not load, and their `* note` must not
 * change shape. A forward is not quoted with `>`. The leading blank line is the
 * "room on top" to type; the signature must not add a second one.
 */
function forward_quote(array $email): string
{
  return "\n\n" . md_escape("----- Forwarded Message -----\nFrom: " . ($email['from'] ?? '')
    . "\nDate: " . ($email['date'] ?? '') . "\n\n" . quote_source_text($email));
}

function reply_form_data(array $email, string $me, bool $all): array
{
  // The sender's text is escaped line by line, and its own `>` markers are kept
  // as real markers, so an earlier quote nests one level deeper.
  $quoted = [];
  foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", quote_source_text($email))) as $line) {
    preg_match('/^((?:[ \t]*>)*)(.*)$/s', $line, $m);
    $depth = substr_count($m[1], '>');
    $rest = md_escape($depth > 0 && ($m[2][0] ?? '') === ' ' ? substr($m[2], 1) : $m[2]);
    // `> > x`, spaced, is also what the editor's serializer writes, so an
    // untouched reply goes out byte for byte as it was prefilled.
    $quoted[] = rtrim(str_repeat('> ', $depth + 1) . $rest);
  }
  // The quote carries its own leading blank line ("room on top" to type), so the
  // signature must not add a second one.
  $quote = "\n\n" . md_escape('On ' . ($email['date'] ?? '') . ', ' . ($email['from'] ?? '') . ' wrote:')
    . "\n" . implode("\n", $quoted);
  $sigPrefix = signature_prefix();
  $subject = (string)($email['subject'] ?? '');
  $subject = (stripos($subject, 'Re:') === 0) ? $subject : 'Re: ' . $subject;

  $groups = $all
    ? reply_all_recipients($email, $me)
    : ['to' => reply_sender_address($email), 'cc' => ''];

  // RFC 5322: References is the parent's own References plus its Message-ID. With
  // no chain on the parent, the parent alone is enough for every client to thread.
  $parent = trim((string)($email['message_id'] ?? ''));
  $chain  = trim((string)($email['references'] ?? ''));
  $references = $parent === '' ? '' : trim($chain . ' ' . $parent);

  return [
    'to'          => $groups['to'],
    'cc'          => $groups['cc'],
    'bcc'         => '',
    'subject'     => $subject,
    'body'        => $sigPrefix . $quote,
    'in_reply_to' => $parent,
    'references'  => $references,
    'forward_attachments' => [],
  ];
}

function folder_icon(string $folder): string
{
  $n = strtoupper($folder);
  if ($n === 'INBOX') return icon('inbox');
  if (strpos($n, 'SENT') !== false) return icon('send');
  if (strpos($n, 'DRAFT') !== false) return icon('pencil');
  if (strpos($n, 'TRASH') !== false || strpos($n, 'KOSZ') !== false || strpos($n, 'DELETED') !== false) return icon('trash');
  if (strpos($n, 'JUNK') !== false || strpos($n, 'SPAM') !== false) return icon('alert-triangle');
  if (strpos($n, 'ARCHIV') !== false) return icon('archive');
  return icon('folder');
}

/**
 * Folders the user must not be able to delete. Pure.
 *
 * Reuses the special-folder detection that already exists rather than adding a
 * second hardcoded list: folder_display_name() maps the localized names
 * (Junk -> Spam, Wysłane -> Sent, Kosz -> Trash) onto the canonical labels.
 */
/**
 * Folders the user must not be able to delete. Pure.
 *
 * When a custom folder map is provided, only the mapped names are protected
 * (plus INBOX). Without a map, falls back to the hard-coded detection.
 */
function folder_is_protected(string $name, ?array $folderMap = null): bool
{
  if (trim($name) === '') {
    return false; // an empty name is invalid, not protected
  }
  if (strcasecmp($name, 'INBOX') === 0) {
    return true;
  }
  // Custom map: only the mapped names are protected.
  if ($folderMap !== null) {
    foreach (['sent', 'drafts', 'spam', 'trash', 'archive'] as $role) {
      if (isset($folderMap[$role]) && strcasecmp($folderMap[$role], $name) === 0) {
        return true;
      }
    }
    return false;
  }
  // No custom map: use hard-coded detection.
  if (MailImap::isSentFolder($name) || MailImap::isTrashFolder($name) || MailImap::isArchiveFolder($name)) {
    return true;
  }
  return in_array(folder_display_name($name), ['Inbox', 'Drafts', 'Sent', 'Spam', 'Trash', 'Archive'], true);
}

/**
 * The folder each keyboard role jumps to, keyed by the shortcut letter: i =
 * Inbox, t = Sent, d = Drafts, s = Spam, a = Archive, r = Trash. A role the
 * user mapped in protected_folders wins; otherwise the same detection the
 * sidebar and onboarding use. A role with no folder is left out, so a keyboard
 * jump never targets a folder that does not exist. Pure.
 *
 * Inbox is the one exception: RFC 3501 requires a folder by that name, and the
 * rest of the app already treats it as unconditional -- syncTitle() reads the
 * INBOX badge and the poll chain names INBOX on every page without a sync chip.
 * Without that fallback a cold folder-list cache emptied the map, `g i` toasted
 * "No such folder." on a page that was merely old, and every other role kept its
 * honest refusal.
 */
function kb_folder_map(array $folders, ?array $folderMap = null): array
{
  $roleFor = [
    'i' => 'inbox',
    't' => 'sent',
    'd' => 'drafts',
    's' => 'spam',
    'a' => 'archive',
    'r' => 'trash',
  ];
  $display = [
    'inbox' => 'Inbox',
    'sent' => 'Sent',
    'drafts' => 'Drafts',
    'spam' => 'Spam',
    'archive' => 'Archive',
    'trash' => 'Trash',
  ];
  $out = [];
  foreach ($roleFor as $letter => $role) {
    $name = '';
    if ($folderMap !== null && !empty($folderMap[$role])) {
      $name = (string)$folderMap[$role];
    }
    // A mapped name that is not in the list is a stale mapping: fall back to
    // detection rather than jumping to a folder that cannot be opened.
    if ($name === '' || !in_array($name, array_column($folders, 'name'), true)) {
      $name = '';
      foreach ($folders as $f) {
        $n = (string)($f['name'] ?? '');
        if ($letter === 'i') {
          if (strcasecmp($n, 'INBOX') === 0) {
            $name = $n;
            break;
          }
          continue;
        }
        $dn = (string)($f['displayName'] ?? folder_display_name($n));
        $hit = ($dn === $display[$role]);
        if (!$hit) {
          $hit = match ($role) {
            'sent'    => MailImap::isSentFolder($n),
            'drafts'  => MailImap::isDraftsFolder($n),
            'spam'    => MailImap::isJunkFolder($n),
            'archive' => MailImap::isArchiveFolder($n),
            'trash'   => MailImap::isTrashFolder($n),
            default   => false,
          };
        }
        if ($hit) {
          $name = $n;
          break;
        }
      }
    }
    if ($name !== '') {
      $out[$letter] = $name;
      continue;
    }
    if ($letter === 'i') $out['i'] = 'INBOX';
  }
  return $out;
}

/**
 * Gate for deleting a folder: 'ok', 'protected' or 'invalid'. Pure.
 *
 * Split out from the handler so the rule is testable without a session or a
 * connection, and so the refusal happens before any IMAP cost.
 */
function folder_delete_check(string $name, ?array $folderMap = null): string
{
  if (!imap_folder_name_is_safe($name) || trim($name) === '') {
    return 'invalid';
  }
  if (folder_is_protected($name, $folderMap)) {
    return 'protected';
  }
  return 'ok';
}

/**
 * Gate for moving a message: 'ok' | 'same' | 'unknown' | 'noselect' | 'invalid'.
 * Pure, so it runs before any connection is spent.
 *
 * $folders is getFolders() output. An unlisted target is refused rather than
 * created: a typo would otherwise scatter mail into new mailboxes. \Noselect
 * targets are refused because they are hierarchy nodes -- SELECT fails on them,
 * so the move would half-succeed and the message would be in neither place.
 */
function folder_move_check(string $from, string $to, array $folders): string
{
  if (!imap_folder_name_is_safe($to) || trim($to) === '') {
    return 'invalid';
  }
  if (strcasecmp(trim($from), trim($to)) === 0) {
    return 'same';
  }
  foreach ($folders as $f) {
    if (strcasecmp((string)($f['name'] ?? ''), $to) !== 0) {
      continue;
    }
    return ((int)($f['attributes'] ?? 0) & LATT_NOSELECT) ? 'noselect' : 'ok';
  }
  return 'unknown';
}

/**
 * Gate for wiping a whole mailbox: 'ok' or 'refused'. Pure.
 *
 * emptyFolder() deletes by SEQUENCE NUMBER across the entire mailbox, so this is
 * the only thing standing between a stray folder name and total data loss. Only
 * the two mailboxes whose contents are already discarded may be emptied.
 */
function folder_empty_check(string $folder): string
{
  if (trim($folder) === '') {
    return 'refused';
  }
  return (MailImap::isTrashFolder($folder) || MailImap::isJunkFolder($folder)) ? 'ok' : 'refused';
}

// Gate for creating a folder: 'ok' or 'invalid'. Pure. A name that collides with
// an existing mailbox is left to the server, which answers NO.
function folder_create_check(string $name): string
{
  return (imap_folder_name_is_safe($name) && trim($name) !== '') ? 'ok' : 'invalid';
}

/**
 * Reduce an address header to a comma-separated list of bare addresses. Pure.
 *
 * A draft reopened in the composer arrives as header text ("Name <a@b.c>"), and
 * the send path validates with FILTER_VALIDATE_EMAIL, which rejects that form.
 * Splitting has to respect quoted display names, which may contain commas.
 */
function header_address_list(string $header): string
{
  $out = [];
  $buf = '';
  $inQuotes = false;
  $len = strlen($header);
  for ($i = 0; $i < $len; $i++) {
    $ch = $header[$i];
    if ($ch === '"') {
      $inQuotes = !$inQuotes;
      $buf .= $ch;
      continue;
    }
    if ($ch === ',' && !$inQuotes) {
      $out[] = $buf;
      $buf = '';
      continue;
    }
    $buf .= $ch;
  }
  $out[] = $buf;

  $addresses = [];
  foreach ($out as $part) {
    $part = trim($part);
    if ($part === '') {
      continue;
    }
    if (preg_match('/<([^>]+)>/', $part, $m)) {
      $part = trim($m[1]);
    }
    // Anything that is still not an address (a group name, a bare label) is
    // dropped rather than handed to the send path to choke on.
    if (filter_var($part, FILTER_VALIDATE_EMAIL)) {
      $addresses[] = $part;
    }
  }
  return implode(', ', $addresses);
}

// The message's action bar: Back, Reply, Reply all, Forward, Unread, Move,
// Spam, Delete. Shared by the single-message reader and each message inside a
// conversation. $moveOptions is the caller's once-built picker (see
// view_move_dialog); the dialog itself is emitted once by the caller, not here.
function view_message_actions(array $email, int $uid, string $folder, string $me, string $moveOptions, bool $withBack = true): string
{
  $enc  = urlencode($folder);
  $csrf = h(csrf_page_token());
  // The reply that reaches everybody leads only when there is somebody extra to
  // reach; otherwise it is the same message as a plain reply, twice.
  $allLeads    = reply_default_is_all($email, $me);
  $replyCls    = 'btn btn-sm' . ($allLeads ? '' : ' btn-primary');
  $replyAllCls = 'btn btn-sm' . ($allLeads ? ' btn-primary' : '');
  $moveButton = $moveOptions === '' ? ''
    : view_move_button($uid, $enc, 'btn btn-sm btn-icon');
  // A conversation has one Back for the whole thread, so its per-message bars
  // drop it. A refused action floats in as a toast (win.toast), which
  // render_page() mounts — the reader needs no static box of its own.
  // Every labelled button also carries data-tip + aria-label, because the phone
  // breakpoint hides .btn-label and turns the whole row into icons.
  $back = $withBack
    ? '<a class="btn btn-sm tip" data-tip="Back" aria-label="Back" href="?page=folder&folder=' . $enc . '">'
    . icon('arrow-back') . ' <span class="btn-label">Back</span></a>'
    : '';
  return '<div class="actions-bar">'
    . $back
    . '<div class="actions-bar-right">'
    . '<a class="' . $replyCls . ' tip" data-tip="Reply" aria-label="Reply" href="?page=reply&uid=' . $uid . '&folder=' . $enc . '">'
    . icon('corner-up-left') . ' <span class="btn-label">Reply</span></a>'
    . '<a class="' . $replyAllCls . ' tip" data-tip="Reply all" aria-label="Reply all" href="?page=replyAll&uid=' . $uid . '&folder=' . $enc . '">'
    . icon('corner-up-left-double') . ' <span class="btn-label">Reply all</span></a>'
    . '<a class="btn btn-sm tip" data-tip="Forward" aria-label="Forward" href="?page=forward&uid=' . $uid . '&folder=' . $enc . '">'
    . icon('corner-up-right') . ' <span class="btn-label">Forward</span></a>'
    . '<span class="actions-sep"></span>'
    // Icon-only from here on: with text labels the row needs 810px and the card
    // gives 808, so it wrapped to two lines on every wide screen.
    //
    // Reloading would re-mark it read (handle_read does that on every view), so
    // this one posts and then leaves for the folder listing.
    . '<button type="button" class="btn btn-sm btn-icon tip" data-tip="Unread" aria-label="Unread" '
    . 'onclick="markUnreadBack(' . $uid . ', \'' . $enc . '\')">' . icon('mail') . '</button>'
    . $moveButton
    . '<span class="actions-sep"></span>'
    . '<form method="POST" action="?page=spam" style="display:inline" '
    . 'onsubmit="rowAction(' . $uid . ', \'' . $enc . '\', \'?page=spam\', \'remove\'); return false;">'
    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
    . '<input type="hidden" name="uid" value="' . $uid . '">'
    . '<input type="hidden" name="folder" value="' . h($enc) . '">'
    . '<button class="btn btn-sm btn-icon btn-warn-soft tip" data-tip="Spam" aria-label="Spam">'
    . icon('alert-triangle') . '</button></form>'
    . '<form method="POST" action="?page=delete" style="display:inline" data-uid="' . (int)$uid . '" data-folder="' . h($enc) . '" '
    . 'onsubmit="confirmDelete(' . (int)$uid . ', \'' . $enc . '\', \'Delete this message?\'); return false;">'
    . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
    . '<input type="hidden" name="uid" value="' . $uid . '">'
    . '<input type="hidden" name="folder" value="' . h($enc) . '">'
    . '<button class="btn btn-sm btn-icon btn-danger-soft tip" data-tip="Delete" aria-label="Delete" data-delete>'
    . icon('trash') . '</button></form>'
    . '</div></div>';
}

// The message itself: avatar + subject, the meta block, attachments, the
// blocked-image call to action and the sandboxed body iframe. Shared by the
// reader and each message inside a conversation.
function view_message_body(array $email, int $uid, string $folder, array $opts = []): string
{
  $enc  = urlencode($folder);
  $csrf = h(csrf_page_token());
  // A conversation shows its subject once, and each message's summary already
  // carries the sender and date, so its messages drop this block.
  $h = '';
  if (empty($opts['no_title'])) {
    $h .= '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px">'
      . '<span class="avatar ' . h(avatar_tone(($email['from_address'] ?? '') ?: ($email['from'] ?? ''))) . '" style="width:44px;height:44px;font-size:1.1rem">'
      . h(avatar_initial(($email['from_address'] ?? '') ?: ($email['from'] ?? ''))) . '</span>'
      . '<div><h1 style="margin:0">' . h($email['subject']) . '</h1></div></div>';
  }
  $h .= '<div class="meta">'
    . '<div><strong>From:</strong> ' . h($email['from']) . '</div>'
    . '<div><strong>To:</strong> ' . h($email['to']) . '</div>';
  if (!empty($email['cc'])) $h .= '<div><strong>Cc:</strong> ' . h($email['cc']) . '</div>';
  // Only ever set on your own copy in Sent: Bcc is stripped in transit.
  if (!empty($email['bcc'])) $h .= '<div><strong>Bcc:</strong> ' . h($email['bcc']) . '</div>';
  $h .= '<div><strong>Date:</strong> ' . h($email['date']) . '</div></div>';
  if (!empty($email['attachments'])) {
    $h .= '<div class="attachments"><strong>Attachments (' . count($email['attachments']) . ')</strong><div class="attachment-list">';
    foreach ($email['attachments'] as $att) {
      $h .= '<a class="attachment-item" href="?page=download&uid=' . $uid . '&part=' . h($att['part_number']) . '&folder=' . urlencode($folder) . '">'
        . icon('file') . ' ' . h($att['filename']) . ' <span style="color:#8a97a6">(' . round($att['size'] / 1024, 1) . ' KB)</span></a>';
    }
    $h .= '</div></div>';
  }
  $html = !empty($email['body_html']) ? $email['body_html'] : nl2br(h($email['body_text']));
  $blockImages = !empty($opts['block_images']);
  $blocked = 0;
  $html = sanitize_html($html, $blockImages, $blocked);
  // Only offered when something was actually held back, or the bar would cry
  // wolf on every plain-text message.
  if ($blocked > 0) {
    $sender = ($email['from_address'] ?? '') ?: ($email['from'] ?? '');
    $sender = trim(header_address_list((string)$sender)) ?: (string)$sender;
    $h .= '<div class="images-cta">'
      . '<span class="images-cta-text">' . icon('photo') . ' '
      . $blocked . ' remote image' . ($blocked === 1 ? '' : 's') . ' blocked</span>'
      . '<a class="btn btn-sm" href="?page=read&uid=' . $uid . '&folder=' . $enc . '&images=1">Show images</a>'
      . '<form method="POST" action="?page=trustSender" style="display:inline">'
      . '<input type="hidden" name="csrf_token" value="' . $csrf . '">'
      . '<input type="hidden" name="uid" value="' . $uid . '">'
      . '<input type="hidden" name="folder" value="' . h($enc) . '">'
      . '<input type="hidden" name="sender" value="' . h($sender) . '">'
      . '<button class="btn btn-sm">Always trust ' . h($sender) . '</button></form>'
      . '</div>';
  }
  // Sandboxed iframe to isolate email HTML from the app DOM.
  //
  // allow-same-origin is required and is NOT a hole here: the frame is sized by
  // reading its own body height from the parent, and a bare sandbox="" gives the
  // frame an opaque origin, which makes that read throw and leaves the body
  // clipped at the browser's default 150px. Scripts stay off -- it is
  // allow-scripts TOGETHER WITH allow-same-origin that would let the content
  // reach out and remove its own sandbox, so that pair must never appear.
  $sandboxed = '<iframe class="body-iframe srcdoc" sandbox="allow-same-origin" srcdoc="' . h($html) . '"></iframe>';
  return $h . '<div class="body">' . $sandboxed . '</div>';
}

function view_read(array $email, int $uid, string $folder, string $me = '', array $folders = [], array $opts = []): string
{
  $enc  = urlencode($folder);
  // Only folders folder_move_check() would accept: not the current one, and no
  // \Noselect hierarchy node. With nothing eligible, the affordance is not shown.
  $moveOptions = move_target_options($folders, $folder);
  $h  = '<div class="card" data-uid="' . (int)$uid . '" data-folder="' . h($enc) . '"'
    // Keyboard hooks: r/a/f reuse the toolbar's own hrefs (the only copy), and
    // j/k jump to the neighbours view_read() rendered via read_prev_next().
    . ' data-reply="?page=reply&amp;uid=' . (int)$uid . '&amp;folder=' . $enc . '"'
    . ' data-replyall="?page=replyAll&amp;uid=' . (int)$uid . '&amp;folder=' . $enc . '"'
    . ' data-forward="?page=forward&amp;uid=' . (int)$uid . '&amp;folder=' . $enc . '"'
    . (!empty($opts['prev_uid']) ? ' data-prev="?page=read&amp;uid=' . (int)$opts['prev_uid'] . '&amp;folder=' . $enc . '"' : '')
    . (!empty($opts['next_uid']) ? ' data-next="?page=read&amp;uid=' . (int)$opts['next_uid'] . '&amp;folder=' . $enc . '"' : '')
    . '>';
  $h .= view_message_actions($email, $uid, $folder, $me, $moveOptions);
  $h .= view_message_body($email, $uid, $folder, $opts);
  $h .= '</div>';
  // The picker lives with the reader, not in render_page: only this view knows
  // which folders are eligible targets for this message.
  $h .= view_move_dialog($moveOptions);
  return $h;
}

// A conversation: the subject, a Back control, and each message as a
// collapsible <details>, oldest first with the newest open. $messages is a list
// of ['email' => array, 'uid' => int, 'folder' => string, 'block_images' => bool].
// The move dialog is emitted once here, not per message.
function view_thread(array $messages, string $folder, string $subject, string $me, array $folders, array $opts = []): string
{
  $enc = urlencode($folder);
  $moveOptions = move_target_options($folders, $folder);
  // Newest is last; render at most the newest THREAD_MAX_MESSAGES and say how
  // many older ones were left out, so a 300-message conversation cannot render
  // 300 sandboxed iframes.
  $total = count($messages);
  $shown = $total > THREAD_MAX_MESSAGES ? array_slice($messages, -THREAD_MAX_MESSAGES) : $messages;
  // handle_thread() fetches only the newest cap, so it passes the count of the
  // older members it never loaded; a direct caller's total is the whole list.
  $omitted = array_key_exists('omitted', $opts) ? max(0, (int)$opts['omitted']) : ($total - count($shown));

  $h = '<div class="card thread">'
    . '<div class="thread-top">'
    . '<a class="btn btn-sm tip" data-tip="Back" aria-label="Back" href="?page=folder&folder=' . h($enc) . '">'
    . icon('arrow-back') . ' <span class="btn-label">Back</span></a>'
    . '</div>'
    . '<h1 style="margin:0 0 14px">' . h($subject) . '</h1>';
  if ($omitted > 0) {
    $h .= '<div class="hint thread-more">' . $omitted . ' older message'
      . ($omitted === 1 ? '' : 's') . ' not shown.</div>';
  }
  $last = count($shown) - 1;
  foreach ($shown as $i => $m) {
    $email = $m['email'] ?? [];
    $uid = (int)($m['uid'] ?? 0);
    $mfolder = (string)($m['folder'] ?? $folder);
    $avatarName = ($email['from_address'] ?? '') ?: ($email['from'] ?? '');
    $threadUrl = urlencode($mfolder);
    $h .= '<details class="thread-msg"' . ($i === $last ? ' open' : '') . ' data-uid="' . $uid . '"'
      . ' data-folder="' . h($mfolder) . '"'
      // The keyboard layer reads these off the active message, like a card's.
      . ' data-reply="?page=reply&amp;uid=' . $uid . '&amp;folder=' . $threadUrl . '"'
      . ' data-replyall="?page=replyAll&amp;uid=' . $uid . '&amp;folder=' . $threadUrl . '"'
      . ' data-forward="?page=forward&amp;uid=' . $uid . '&amp;folder=' . $threadUrl . '"'
      . '>'
      . '<summary>'
      . '<span class="avatar ' . h(avatar_tone($avatarName)) . '">' . h(avatar_initial($avatarName)) . '</span>'
      . '<span class="thread-msg-from">' . h($email['from'] ?? '') . '</span>'
      . '<span class="thread-msg-date">' . h($email['date'] ?? '') . '</span>'
      . '</summary>'
      . '<div class="thread-msg-body">'
      // Actions lead, like the single-message reader: the bar sits above the
      // message it acts on.
      . view_message_actions($email, $uid, $mfolder, $me, $moveOptions, false)
      . view_message_body($email, $uid, $mfolder, ['block_images' => !empty($m['block_images']), 'no_title' => true])
      . '</div></details>';
  }
  $h .= '</div>';
  $h .= view_move_dialog($moveOptions);
  return $h;
}

// The reader for one conversation. Hands the rendered page the thread view plus
// the folder list its move picker needs, all from the local cache.
function render_thread(array $messages, string $folder, string $me, int $omitted = 0): void
{
  $subject = $messages ? (string)($messages[count($messages) - 1]['email']['subject'] ?? '') : 'Conversation';
  render_page($subject, view_thread(
    $messages,
    $folder,
    $subject,
    $me,
    cached_folder_list($me ?: current_user()),
    ['omitted' => $omitted]
  ), true);
}

function view_compose(array $d): string
{
  $h = '<div class="card">';
  $h .= '<form method="POST" action="?page=compose" class="compose-form" enctype="multipart/form-data">'
    . '<input type="hidden" name="csrf_token" value="' . h($d['csrf']) . '">'
    . '<input type="hidden" name="undo_period_seconds" value="' . (int)($d['undo_period_seconds'] ?? 0) . '">'
    . (!empty($d['draft_uid']) ? '<input type="hidden" name="draft_uid" value="' . (int)$d['draft_uid'] . '">' : '')
    . (!empty($d['in_reply_to']) ? '<input type="hidden" name="in_reply_to_id" value="' . h($d['in_reply_to']) . '">' : '')
    . (!empty($d['references']) ? '<input type="hidden" name="references" value="' . h($d['references']) . '">' : '')
    . (isset($_SESSION['forward_src']) ? '<input type="hidden" name="__hasfwd" value="1">' : '');
  // --- To / Cc / Bcc row (Gmail-style: inline labels, Cc/Bcc toggled) ---
  $showCc  = !empty($d['cc']);
  $showBcc = !empty($d['bcc']);
  // Focus To on a blank compose; on a reply/forward/draft it is already filled,
  // so the body is the first field the user must type in.
  $focusTo = $d['to'] === '';
  $h .= '<div class="compose-row compose-to autocomplete-wrap">'
    . '<label>To</label>'
    . '<input type="text" name="to" required' . ($focusTo ? ' autofocus' : '') . ' value="' . h($d['to']) . '" data-autocomplete="to">'
    . '<span class="compose-cc-tog' . ($showCc ? ' hidden' : '') . '"><button type="button" onclick="this.closest(\'form\').querySelector(\'.compose-cc\').classList.remove(\'hidden\');this.classList.add(\'hidden\')">Cc</button></span>'
    . '<span class="compose-bcc-tog' . ($showBcc ? ' hidden' : '') . '"><button type="button" onclick="this.closest(\'form\').querySelector(\'.compose-bcc\').classList.remove(\'hidden\');this.classList.add(\'hidden\')">Bcc</button></span>'
    . '</div>';
  $h .= '<div class="compose-row compose-cc autocomplete-wrap' . ($showCc ? '' : ' hidden') . '">'
    . '<label>Cc</label>'
    . '<input type="text" name="cc" value="' . h($d['cc']) . '" data-autocomplete="cc"></div>';
  $h .= '<div class="compose-row compose-bcc autocomplete-wrap' . ($showBcc ? '' : ' hidden') . '">'
    . '<label>Bcc</label>'
    . '<input type="text" name="bcc" value="' . h($d['bcc']) . '" data-autocomplete="bcc"></div>';
  // --- Subject ---
  $h .= '<div class="compose-row compose-subject">'
    . '<input type="text" name="subject" required value="' . h($d['subject']) . '" placeholder="Subject"></div>';
  // --- Body ---
  // The textarea is the form field and the JS-off composer. The editor above it
  // starts hidden; the JS shows it only where the browser can edit, and writes
  // its Markdown back into the textarea. The server never reads the editor.
  $h .= '<div class="compose-body">'
    . '<div class="md-editor hidden" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Message"'
    . ' data-placeholder="Message">'
    . md_to_html($d['body']) . '</div>'
    . '<textarea name="body"' . ($focusTo ? '' : ' autofocus') . ' rows="10" placeholder="Message">' . h($d['body']) . '</textarea></div>';
  // --- Attachments (newly picked files appear here via JS) ---
  $h .= '<div class="compose-attach-list"></div>';
  // --- Attachments ---
  if (!empty($d['forward_attachments'])) {
    $h .= '<div class="compose-attachments"><span class="compose-att-label">Forward:</span>';
    foreach ($d['forward_attachments'] as $att) {
      $h .= '<label class="compose-att-item"><input type="checkbox" name="forward_parts[]" value="' . h($att['part_number']) . '" checked> '
        . h($att['filename']) . ' (' . round($att['size'] / 1024, 1) . ' KB)</label>';
    }
    $h .= '</div>';
  }
  // --- Markdown help, right above the actions. Hidden until the JS finds
  // <dialog> support; the modal itself is below the form.
  $h .= '<div class="md-help-row hidden"><button type="button" class="md-help" data-md-help>' . icon('markdown', 16)
    . ' Supports simplified Markdown syntax</button></div>';
  // --- Actions ---
  // On a phone only Send keeps its words (the breakpoint hides .btn-label), so
  // each other action also carries data-tip + aria-label, like the reader bar.
  $h .= '<div class="compose-actions">'
    . '<a class="btn btn-sm compose-discard tip" data-tip="Discard" aria-label="Discard" href="?page=inbox">'
    . icon('arrow-back') . ' <span class="btn-label">Discard</span></a>'
    . '<label class="btn btn-sm compose-attach-btn tip" data-tip="Attach files" aria-label="Attach files">'
    . icon('paperclip') . '<input type="file" name="attachments[]" multiple></label>'
    . '<button class="btn btn-sm tip" data-tip="Save draft" aria-label="Save draft" type="submit" formaction="?page=saveDraft" formnovalidate>'
    . icon('device-floppy') . ' <span class="btn-label">Save draft</span></button>'
    . '<button class="btn btn-primary" type="submit">' . icon('send') . ' Send</button>'
    . '</div>'
    . '</form>' . view_md_help_dialog() . '</div>';
  return $h;
}

/**
 * The Markdown options, as a modal table. Pure.
 *
 * Its list duplicates the README "Composer" tables: change both together.
 * Every cell is literal markup written here, so nothing in it is user input.
 */
// The single source of truth for the keyboard layer: the ?-sheet renders it
// and js_inline() dispatches the same keys from the same JSON, so the two can
// never drift. Pure.
function kb_shortcuts(): array
{
  return [
    ['key' => 'j', 'label' => 'Next message (or ↓)'],
    ['key' => 'k', 'label' => 'Previous message (or ↑)'],
    ['key' => 'Enter or o', 'label' => 'Open message'],
    ['key' => 'Esc', 'label' => 'Clear / back / discard'],
    ['key' => 'e', 'label' => 'Archive'],
    ['key' => '#', 'label' => 'Delete'],
    ['key' => '!', 'label' => 'Report spam'],
    ['key' => 'r', 'label' => 'Reply'],
    ['key' => 'a', 'label' => 'Reply all'],
    ['key' => 'f', 'label' => 'Forward'],
    ['key' => 'x or Space', 'label' => 'Select message'],
    ['key' => 's', 'label' => 'Toggle read'],
    ['key' => 'v', 'label' => 'Move to folder'],
    ['key' => 'g i', 'label' => 'Go to Inbox'],
    ['key' => 'g t', 'label' => 'Go to Sent'],
    ['key' => 'g d', 'label' => 'Go to Drafts'],
    ['key' => 'g s', 'label' => 'Go to Spam'],
    ['key' => 'g a', 'label' => 'Go to Archive'],
    ['key' => 'g r', 'label' => 'Go to Trash'],
    ['key' => 'u', 'label' => 'Back to folder'],
    ['key' => 'y', 'label' => 'Check for new mail'],
    ['key' => 'c', 'label' => 'Compose'],
    ['key' => '/', 'label' => 'Search'],
    ['key' => 'Cmd+Enter', 'label' => 'Send'],
    ['key' => 'Cmd+S', 'label' => 'Save draft or settings'],
    ['key' => 'Cmd+Shift+A', 'label' => 'Attach a file'],
    ['key' => 'Cmd+Shift+X', 'label' => 'Clear local cache'],
    ['key' => '?', 'label' => 'Keyboard shortcuts'],
  ];
}

// The ?-sheet. Rendered from kb_shortcuts() and carrying the same table as
// data-kb-list JSON for the dispatcher, so the sheet and the keys cannot drift.
function view_kb_help_dialog(): string
{
  $rows = '';
  foreach (kb_shortcuts() as $s) {
    $rows .= '<tr><td><kbd>' . h($s['key']) . '</kbd></td><td>' . h($s['label']) . '</td></tr>';
  }
  return '<dialog class="modal kb-help-dialog" id="kb-help-dialog" aria-labelledby="kb-help-title"'
    . ' data-kb-list="' . h(json_encode(kb_shortcuts(), JSON_UNESCAPED_UNICODE)) . '">'
    // autofocus on Close: showModal() lands on the first focusable, and Close
    // is the only control, so a keyboard user is never stranded in the sheet.
    . '<h3 class="modal-title" id="kb-help-title">Keyboard shortcuts</h3>'
    . '<table class="kb-help-table"><thead><tr><th scope="col">Key</th><th scope="col">Action</th></tr></thead>'
    . '<tbody>' . $rows . '</tbody></table>'
    . '<p class="modal-desc">These three keys differ from Gmail: <kbd>s</kbd> toggles read, '
    . '<kbd>g s</kbd> opens Spam, <kbd>g a</kbd> opens Archive. '
    . '<a href="#" data-kb-disable>Disable shortcuts</a></p>'
    . '<div class="modal-actions"><button class="btn-cancel" autofocus type="button">Close</button></div>'
    . '</dialog>';
}

function view_md_help_dialog(): string
{
  $c = fn(string ...$codes) => implode(' or ', array_map(fn($x) => '<code>' . h($x) . '</code>', $codes));
  $groups = [
    'Anywhere in a line' => [
      [$c('**bold**'), '<strong>bold</strong>'],
      [$c('*italic*', '_italic_'), '<em>italic</em>'],
      [$c('~~strike~~'), '<s>strike</s>'],
      [$c('`code`'), '<code>code</code>'],
      [$c('[text](https://…)'), 'A link named “text”'],
      [$c('![alt](https://…)'), 'A remote image (https only)'],
      [$c('https://…'), 'A link'],
      [$c('\*'), 'A literal <code>*</code>: a backslash cancels a mark'],
    ],
    'At the start of a line' => [
      [$c('# ', '## ') . ' … ' . $c('###### '), 'A heading, level 1 to 6'],
      [$c('- ', '* ', '• '), 'A bullet list'],
      [$c('1. '), 'A numbered list'],
      [$c('> '), 'A quote'],
      [$c('```') . ' then Space', 'A code block; Enter on an empty last line leaves it'],
      [$c('---') . ' then Enter', 'A horizontal line'],
    ],
  ];
  $rows = '';
  foreach ($groups as $label => $items) {
    $rows .= '<tbody><tr><th colspan="2" scope="rowgroup">' . h($label) . '</th></tr>';
    foreach ($items as [$type, $get]) {
      $rows .= '<tr><td>' . $type . '</td><td>' . $get . '</td></tr>';
    }
    $rows .= '</tbody>';
  }
  return '<dialog class="modal md-help-dialog" id="md-help-dialog" aria-labelledby="md-help-title">'
    // autofocus on the title: showModal() would focus Close, the only control,
    // and on a phone that opens the table scrolled to its end.
    . '<h3 class="modal-title" id="md-help-title" tabindex="-1" autofocus>Markdown in the composer</h3>'
    . '<table class="md-help-table"><thead><tr><th scope="col">You type</th><th scope="col">You get</th></tr></thead>'
    . $rows . '</table>'
    . '<p class="modal-desc">Lists are one level deep. Backspace right after a line-start mark gives the typed'
    . ' characters back. Paste inserts plain text.</p>'
    . '<div class="modal-actions"><span></span><button class="btn-cancel" type="button">Close</button></div>'
    . '</dialog>';
}

function view_settings(array $d): string
{
  $h = '<div class="card"><h1>Settings</h1>';
  $folderMapJson = (string)($d['protected_folders'] ?? '{}');
  // Pre-fill detection only when the user has no saved map: once set, their own
  // choices are the single source of truth, so offering the detected names again
  // could silently overwrite a deliberate mapping.
  $defaultMapAttr = (json_decode($folderMapJson, true) ?: []) === []
    ? ' data-default-map="' . h(json_encode($d['detected_folder_map'] ?? [])) . '"'
    : '';
  $h .= '<form method="POST" action="?page=settings">'
    . '<input type="hidden" name="csrf_token" value="' . h($d['csrf']) . '">'
    . '<input type="hidden" name="protected_folders" class="folder-map-json" value="' . h($folderMapJson) . '">'
    . '<div class="form-group"><label>Messages per page</label>'
    . '<input type="number" name="emails_per_page" min="5" max="200" value="' . h($d['emails_per_page']) . '">'
    . '<div class="settings-note">Between 5 and 200.</div></div>'
    . '<div class="form-group"><label>Display name (shown on sent mail)</label>'
    . '<input type="text" name="display_name" value="' . h($d['display_name']) . '"></div>'
    . '<div class="form-group"><label>Signature</label>'
    . '<textarea name="signature" rows="4" placeholder="Your email signature...">' . h($d['signature'] ?? '') . '</textarea>'
    . '<div class="settings-note">Appended to the bottom of every message you send, before quoted content.</div></div>'
    // Protected folders: button on the right, matching the toggle layout.
    . '<div class="form-group settings-toggle">'
    . '<div class="settings-toggle-text">'
    . '<div class="settings-label">Protected folders</div>'
    . '<div class="settings-note">Map system roles (Sent, Drafts, Spam, Trash, Archive) to your actual folder names.</div>'
    . '</div>'
    . '<button type="button" class="btn btn-pill" data-open-folder-map'
    . ' data-folders="' . h(json_encode($d['folder_list'] ?? [])) . '"'
    . ' data-map="' . h($folderMapJson) . '"'
    . $defaultMapAttr . '>'
    . 'Manage</button>'
    . '</div>'
    // Remote images toggle.
    . '<div class="form-group settings-toggle">'
    . '<div class="settings-toggle-text">'
    . '<label for="show-remote-images">Remote images</label>'
    . '<div class="settings-note">Off by default. Loading an image from the internet tells '
    . 'the sender you opened the message, when, and from which IP address. Senders you '
    . 'have trusted individually always load.</div>'
    . '</div>'
    // The control column: Trusted senders sits beside the switch it qualifies.
    . '<div class="settings-control">';
  $whitelist = trim((string)($d['image_whitelist'] ?? ''));
  if ($whitelist !== '') {
    $senders = array_filter(array_map('trim', preg_split('/[\r\n,]+/', $whitelist) ?: []));
    $h .= '<button type="button" class="btn btn-pill" data-open-trusted-senders'
      . ' data-senders="' . h(json_encode(array_values($senders))) . '">'
      . 'Trusted senders (' . count($senders) . ')</button>';
  }
  $h .= '<label class="switch">'
    . '<input type="checkbox" id="show-remote-images" name="show_remote_images" value="1"'
    . (!empty($d['show_remote_images']) ? ' checked' : '') . '>'
    . '<span class="switch-track"></span></label>'
    . '</div>'
    . '</div>'
    // Automatic check for new mail, on the topbar for every signed-in page.
    // On by default, and the hidden 0 goes first so an unticked box really
    // posts 0 instead of nothing.
    . '<div class="form-group settings-toggle">'
    . '<div class="settings-toggle-text">'
    . '<label for="auto-poll">Check for new mail automatically</label>'
    . '<div class="settings-note">The app checks your inbox once a minute while it is open, '
    . 'and updates the tab title. Turn this off to check only when you ask.</div>'
    . '<input type="hidden" name="auto_poll" value="0">'
    . '</div>'
    . '<label class="switch">'
    . '<input type="checkbox" id="auto-poll" name="auto_poll" value="1"'
    . (!empty($d['auto_poll']) ? ' checked' : '') . '>'
    . '<span class="switch-track"></span></label>'
    . '</div>'
    // Undo send: a settings row like the switches, select on the right.
    . '<div class="form-group settings-toggle">'
    . '<div class="settings-toggle-text">'
    . '<label for="undo-period">Undo send period</label>'
    . '<div class="settings-note">Hold messages in Outbox before sending.</div>'
    . '</div>'
    . '<select id="undo-period" name="undo_period_seconds">'
    . '<option value="0"' . ((int)($d['undo_period_seconds'] ?? 0) === 0 ? ' selected' : '') . '>Off</option>'
    . '<option value="5"' . ((int)($d['undo_period_seconds'] ?? 0) === 5 ? ' selected' : '') . '>5 seconds</option>'
    . '<option value="10"' . ((int)($d['undo_period_seconds'] ?? 0) === 10 ? ' selected' : '') . '>10 seconds</option>'
    . '<option value="15"' . ((int)($d['undo_period_seconds'] ?? 0) === 15 ? ' selected' : '') . '>15 seconds</option>'
    . '<option value="30"' . ((int)($d['undo_period_seconds'] ?? 0) === 30 ? ' selected' : '') . '>30 seconds</option>'
    . '</select>'
    . '</div>'
    . '<div class="form-actions">
<a class="btn" href="?page=inbox">Cancel</a><button class="btn btn-primary" type="submit">' . icon('device-floppy') . ' Save</button></div>'
    . '</form>'
    . '</div>'
    . '<div class="card cache-card">'
    . '<div class="cache-row">'
    . '<div class="cache-info"><h2 class="settings-sub">Local cache</h2>'
    . '<div class="settings-note">Message headers are stored on this server so that '
    . 'folders open without contacting your mail provider. Bodies are never stored. '
    . 'Currently holding ' . (int)$d['cached_messages'] . ' cached message headers.</div></div>'
    . '<div class="cache-action"><form method="POST" action="?page=settings" onsubmit="confirmClearCache(); return false;">'
    . '<input type="hidden" name="csrf_token" value="' . h($d['csrf']) . '">'
    . '<input type="hidden" name="clear_cache" value="1">'
    . '<button class="btn btn-pill btn-danger-soft" type="submit">' . icon('trash') . ' Clear local cache</button>'
    . '</form></div></div>'
    . '</div>'
    // The "This browser" card. js_inline() removes it where notifications cannot
    // work at all (no API, or not a secure context), so the button it holds is
    // never a switch that does nothing.
    . '<div class="card cache-card notify-card">'
    . '<div class="cache-row">'
    . '<div class="cache-info"><h2 class="settings-sub">Notifications</h2>'
    . '<div class="settings-note">Tell me when new mail arrives, on this browser only. '
    . 'The tab must be open: this is a poll, not a push service. Nothing leaves this browser, '
    . 'and the message bodies are never shown.</div></div>'
    . '<div class="cache-action">'
    . '<button class="btn btn-pill" type="button" data-notify-toggle>Enable notifications</button>'
    . '</div></div>'
    . '</div>';
  return $h;
}

// [10] Router/bootstrap ------------------------------------------------------
if (defined('EMMMAIL_TESTING')) return; // tests require this file only

// Expose the CSRF token to the page for inline forms/JS.
csrf_page_token();

$page = $_GET['page'] ?? '';

switch ($page) {
  case '':
  case 'index':
    redirect(empty($_SESSION['logged_in']) ? '?page=login' : '?page=inbox');
    break;
  case 'login':
    handle_login();
    break;
  case 'logout':
    if (empty($_SESSION['logged_in'])) redirect('?page=login');
    handle_logout();
    break;
  case 'inbox':
    handle_inbox();
    break;
  case 'folder':
    handle_folder();
    break;
  case 'read':
    handle_read();
    break;
  case 'thread':
    handle_thread();
    break;
  case 'compose':
    handle_compose();
    break;
  case 'saveDraft':
    handle_save_draft();
    break;
  case 'createFolder':
    handle_create_folder();
    break;
  case 'deleteFolder':
    handle_delete_folder();
    break;
  case 'reply':
    handle_reply();
    break;
  case 'replyAll':
    handle_reply_all();
    break;
  case 'forward':
    handle_forward();
    break;
  case 'delete':
    handle_delete();
    break;
  case 'restore':
    handle_restore();
    break;
  case 'spam':
    handle_spam();
    break;
  case 'move':
    handle_move();
    break;
  case 'emptyFolder':
    handle_empty_folder();
    break;
  case 'download':
    handle_download();
    break;
  case 'markRead':
    handle_mark_read(true);
    break;
  case 'markUnread':
    handle_mark_read(false);
    break;
  case 'sync':
    handle_sync();
    break;
  case 'undoSend':
    handle_undo_send();
    break;
  case 'flushUndo':
    handle_flush_undo();
    break;
  case 'search':
    handle_search();
    break;
  case 'settings':
    handle_settings();
    break;
  case 'saveOnboarding':
    handle_save_onboarding();
    break;
  case 'trustSender':
    handle_trust_sender();
    break;
  case 'revokeSender':
    handle_revoke_sender();
    break;
  case 'contacts':
    handle_contacts_api();
    break;
  default:
    http_response_code(404);
    echo '<h1>404 — Not found</h1>';
    break;
}
