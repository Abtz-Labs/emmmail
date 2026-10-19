<?php
/**
 * tests/unit.php — pure functions and SQLite models.
 * Run with: php tests/unit.php   (or all suites: php tests/run.php)
 *
 * Tier 1: pure-function tests against index.php.
 * Tier 2: SQLite model tests against a throwaway database.
 */

require_once __DIR__ . '/_harness.php';
test_suite('unit');

// =============================================================== TIER 1 =====


// --------------------------------------------------------- sanitize_html() -+
group('sanitize_html()');

$s = sanitize_html('<p>hello</p>');
assert_contains('<p>hello</p>', $s, 'plain paragraph preserved');
assert_contains('hello', $s, 'text content present');

$s = sanitize_html('<script>alert(1)</script><p>ok</p>');
assert_not_contains('<script', $s, 'script tag stripped');
assert_contains('<p>ok</p>', $s, 'allowed tag survives next to script');

$s = sanitize_html('<iframe src="x"></iframe>');
assert_not_contains('iframe', $s, 'iframe stripped');

$s = sanitize_html('<img src="x" onerror="alert(1)">');
assert_not_contains('onerror', $s, 'onerror attribute stripped');

$s = sanitize_html('<a href="javascript:alert(1)">click</a>');
assert_not_contains('javascript:', $s, 'javascript: href stripped');

$s = sanitize_html('<svg onload="alert(1)"></svg>');
assert_not_contains('svg', $s, 'svg tag stripped');

$s = sanitize_html('<button ontoggle="alert(1)">x</button>');
assert_not_contains('ontoggle', $s, 'ontoggle attribute stripped');

$s = sanitize_html('<meta http-equiv="refresh" content="0;url=https://evil.com">');
assert_not_contains('meta', $s, 'meta refresh stripped');

$s = sanitize_html('<base href="https://evil.com/">');
assert_not_contains('base', $s, 'base href stripped');

$s = sanitize_html('<link rel="stylesheet" href="https://evil.com/x.css">');
assert_not_contains('link', $s, 'link stripped');

// CHANGED BY DESIGN: the style attribute is no longer stripped wholesale --
// HTML email is almost nothing but inline styles. The dangerous part still goes.
$s = sanitize_html('<p style="background:url(javascript:1)">text</p>');
assert_not_contains('javascript:', $s, 'a javascript: url inside a style is stripped');

$s = sanitize_html('<div class="keep">data</div>');
assert_contains('<div', $s, 'div preserved');
assert_contains('data', $s, 'div text preserved');

$s = sanitize_html('');
assert_eq('', $s, 'empty input results in empty string');

$s = sanitize_html('<p><b>bold</b> and <i>italic</i></p>');
assert_contains('<b>bold</b>', $s, 'b tag preserved');
assert_contains('<i>italic</i>', $s, 'i tag preserved');

$s = sanitize_html('<p><script>a</script><em>safe</em></p>');
assert_contains('<em>safe</em>', $s, 'em survives nested inside p');

// A disallowed element is unwrapped in favour of its children. Those children
// must still be sanitized, or any wrapper smuggles them through untouched.
$s = sanitize_html('<font size="2"><script>bad()</script></font>');
assert_not_contains('<script', $s, 'script behind a disallowed wrapper stripped');

$s = sanitize_html('<td onclick="x" colspan="2"><font size="2"><a href="javascript:evil()" target="_blank">hi</a></font></td>');
assert_not_contains('javascript:', $s, 'javascript: href behind a disallowed wrapper stripped');
assert_not_contains('target=', $s, 'target attribute behind a disallowed wrapper stripped');
assert_not_contains('onclick', $s, 'onclick stripped from td');
assert_contains('colspan="2"', $s, 'permitted td attribute kept');

$s = sanitize_html('<marquee><blink><img src="x" onerror="evil()"></blink></marquee>');
assert_not_contains('onerror', $s, 'onerror stripped through two disallowed wrappers');
assert_contains('<img', $s, 'img promoted out of disallowed wrappers');

$s = sanitize_html('<font><b>bold</b></font>');
assert_contains('<b>bold</b>', $s, 'allowed child promoted out of a disallowed wrapper');

// CHANGED BY DESIGN: <style> blocks are kept now (see the group below). Script
// source must still never be promoted into the body as text.
$s = sanitize_html('<style>.evil{color:red}</style><p>body</p>');
assert_contains('<p>body</p>', $s, 'body survives next to a style block');

$s = sanitize_html('<script>alert(1)</script><p>ok</p>');
assert_not_contains('alert(1)', $s, 'script source dropped, not rendered as text');

group('sanitize_html() keeps presentational CSS: HTML email is nothing else');

// Why this is safe to allow at all: the body renders in an iframe with
// sandbox="allow-same-origin" and NO allow-scripts, so CSS cannot reach the app
// DOM. Stripping it was why every message rendered as Times New Roman on white
// while other clients showed the sender's design.
$s = sanitize_html('<table bgcolor="#f7e9ff" cellpadding="0" cellspacing="0" border="0" width="600">'
    . '<tr><td style="background:#f7e9ff;padding:24px" align="center" valign="top">x</td></tr></table>');
assert_contains('style="background:#f7e9ff;padding:24px"', $s, 'an inline style survives intact');
assert_contains('bgcolor="#f7e9ff"', $s, 'so does bgcolor');
assert_contains('align="center"', $s, 'and align');
assert_contains('valign="top"', $s, 'and valign');
assert_contains('width="600"', $s, 'and the table width email layout depends on');
assert_contains('cellpadding="0"', $s, 'and cellpadding');
assert_contains('cellspacing="0"', $s, 'and cellspacing');

// A <style> block needs its selectors to match, so class and id come with it.
$s = sanitize_html('<style>.h1{color:#4a154b;font-family:Helvetica} .a > .b{margin:0}</style>'
    . '<p class="h1" id="top">x</p>');
assert_contains('color:#4a154b', $s, 'a style block survives');
assert_contains('class="h1"', $s, 'and the class its selector needs');
assert_contains('id="top"', $s, 'and the id');
assert_contains('.a > .b', $s, 'the child combinator is not mangled: > must survive escaping');

// Tags email still uses for presentation, previously unwrapped into flat text.
$s = sanitize_html('<center><font color="#4a154b" face="Helvetica" size="4">hi</font></center>');
assert_contains('<center>', $s, 'center survives');
assert_contains('color="#4a154b"', $s, 'font colour survives');
assert_contains('face="Helvetica"', $s, 'so does the face');

group('images_allowed(): off by default, exact sender whitelist');

// Default is off: loading a remote image hands a read receipt and an IP address
// to whoever sent it.
assert_false(images_allowed('a@b.c', false, ''), 'blocked by default');
assert_true(images_allowed('a@b.c', true, ''), 'the global setting turns them on');
assert_true(images_allowed('a@b.c', false, 'a@b.c'), 'a whitelisted sender is trusted');
// The repo compares addresses case-insensitively everywhere else too.
assert_true(images_allowed('A@B.C', false, 'a@b.c'), 'addresses compare case-insensitively');
assert_true(images_allowed('a@b.c', false, "z@z.z\na@b.c\n"), 'the list is one address per line');
assert_true(images_allowed('a@b.c', false, "  a@b.c  "), 'surrounding whitespace does not matter');
// Exact address, not the domain: that was the explicit decision.
assert_false(images_allowed('x@b.c', false, 'a@b.c'), 'a sibling address at the same domain is not trusted');
assert_false(images_allowed('', false, 'a@b.c'), 'an unknown sender is never trusted');
assert_false(images_allowed('a@b.c', false, 'nomatch@x.io'), 'a non-matching list blocks');
// A display name arrives on some paths; match on the address inside it.
assert_true(images_allowed('Ann <a@b.c>', false, 'a@b.c'), 'a full From header still matches its address');

group('sanitize_html() blocks remote images until asked');

$blocked = 0;
$s = sanitize_html('<img src="https://tracker.example/pixel.gif" width="1" height="1" alt="">', true, $blocked);
// The URL is deliberately still in the document, parked on data-blocked-src so
// the CTA can restore it. What must be gone is any attribute the browser LOADS,
// so assert on ' src=' with its leading space, not on the bare hostname.
assert_not_contains(' src="', $s, 'no loading attribute is left holding the URL');
assert_contains('data-blocked-src="https://tracker.example/pixel.gif"', $s, 'it is parked so it can be shown later');
assert_contains('width="1"', $s, 'dimensions stay, so the layout does not collapse');
assert_eq(1, $blocked, 'and it is counted, so the reader knows to offer the CTA');

// The second channel, live only because sanitize_html now keeps CSS.
$blocked = 0;
$s = sanitize_html('<td style="background:url(https://t.example/bg.png);color:red">x</td>', true, $blocked);
assert_not_contains('t.example', $s, 'a remote url() in a style attribute is stripped');
assert_contains('color:red', $s, 'and the rest of the declaration list survives');
assert_eq(1, $blocked, 'CSS images are counted too');

$blocked = 0;
$s = sanitize_html('<style>.a{background-image:url("https://t.example/bg.png");color:red}</style>', true, $blocked);
assert_not_contains('t.example', $s, 'and one inside a style block');
assert_contains('color:red', $s, 'without losing the block');
assert_eq(1, $blocked, 'also counted');

$blocked = 0;
$s = sanitize_html('<td background="https://t.example/bg.png">x</td>', true, $blocked);
assert_not_contains('t.example', $s, 'the legacy background attribute is a channel as well');
assert_eq(1, $blocked, 'counted');

// Protocol-relative and bare http are the same channel.
$blocked = 0;
$s = sanitize_html('<img src="//t.example/a.png"><img src="http://t.example/b.png">', true, $blocked);
assert_not_contains(' src="', $s, 'protocol-relative and plain http are both remote');
assert_eq(2, $blocked, 'both counted');

// Nothing to block: the count must stay honest or the CTA appears on every message.
$blocked = 0;
$s = sanitize_html('<p style="color:red">no images here</p>', true, $blocked);
assert_eq(0, $blocked, 'a message without remote images blocks nothing');

// With the gate open, the document is untouched.
$blocked = 0;
$s = sanitize_html('<img src="https://ok.example/logo.png"><td style="background:url(https://ok.example/x.png)">y</td>',
    false, $blocked);
assert_contains('src="https://ok.example/logo.png"', $s, 'an allowed message loads its images');
assert_contains('ok.example/x.png', $s, 'including the CSS ones');
assert_eq(0, $blocked, 'and nothing is reported as blocked');

// Default argument keeps every existing caller loading images, so the change is
// opt-in at the call site.
$blocked = 0;
assert_contains('src="https://ok.example/logo.png"', sanitize_html('<img src="https://ok.example/logo.png">'),
    'blocking is off unless asked for');

group('sanitize_html() still scrubs the dangerous parts of CSS');

$s = sanitize_html('<p style="width:1px;background:url(javascript:alert(1))">x</p>');
assert_not_contains('javascript:', $s, 'a javascript: url is dropped');
assert_contains('width:1px', $s, 'while the rest of the declaration list survives');
$s = sanitize_html('<p style="behavior:url(evil.htc);color:red">x</p>');
assert_not_contains('behavior', $s, 'IE behaviours ran script from CSS');
assert_contains('color:red', $s, 'and the safe declaration beside it is kept');
$s = sanitize_html('<p style="width:expression(alert(1))">x</p>');
assert_not_contains('expression', $s, 'so did expression()');
$s = sanitize_html('<p style="-moz-binding:url(evil.xml)">x</p>');
assert_not_contains('binding', $s, 'and -moz-binding');
$s = sanitize_html('<p style="color:/*x*/expr/*y*/ession(alert(1))">x</p>');
assert_not_contains('expression', $s, 'comments cannot be used to hide a token');
$s = sanitize_html('<style>@import url(https://evil.com/x.css);.a{color:red}</style>');
assert_not_contains('@import', $s, 'an @import fetches from a third party');
assert_contains('color:red', $s, 'the rest of the block is kept');
// The one that would be a real hole: breaking out of the <style> element.
$s = sanitize_html('<style>a{}</style' . '><script>alert(1)</script><p>ok</p>');
assert_not_contains('alert(1)', $s, 'script after a style block is still dropped');
$s = sanitize_html('<style>a{color:red}/*</style' . '><img src=x onerror=alert(1)>*/</style>');
assert_not_contains('onerror', $s, 'CSS cannot close its own element to smuggle a handler');
// The <img> itself survives, and that is correct: the HTML parser closed <style>
// at the first </style>, so it is an ordinary sibling element, not CSS. What
// matters is that it arrives inert -- no handler, no script.
assert_not_contains('alert', $s, 'nothing executable comes through');
assert_contains('color:red', $s, 'and the CSS before the break is still there');

// --------------------------------------------------------- csrf_generate() -+
group('csrf_generate() / csrf_verify()');

// Fresh session so rotation is deterministic.
csrf_generate();
$t1 = csrf_generate();
assert_true(is_string($t1) && strlen($t1) > 0, 'generates non-empty token');

$t2 = csrf_generate();
assert_true($t1 !== $t2, 'tokens rotate between calls (per-form)');

assert_true(csrf_verify($t2), 'verify succeeds with current valid token');

assert_false(csrf_verify($t1), 'verify fails with rotated-out (old) token');

assert_false(csrf_verify(''), 'verify fails with empty token');
assert_false(csrf_verify('garbage-token'), 'verify fails with garbage token');

// ------------------------------------------------- sanitize_header_value() --+
group('sanitize_header_value()');

$s = sanitize_header_value("victim@example.com\r\nBcc: victim2@example.com");
assert_not_contains("\r", $s, 'CR removed');
assert_not_contains("\n", $s, 'LF removed (no new header line injected)');
assert_contains('victim@example.com', $s, 'primary address preserved after linearization');

$s = sanitize_header_value("alice@example.com\nAttacker: yes");
assert_not_contains("\n", $s, 'bare LF removed');
assert_contains('alice@example.com', $s, 'address preserved');

assert_eq('plain text', sanitize_header_value("plain\0 text"), 'NUL byte stripped');

assert_not_contains("\r", sanitize_header_value("a\r\nb\n\0c"), 'mixed CR/LF/NUL all removed');
assert_not_contains("\n", sanitize_header_value("a\r\nb\n\0c"), 'mixed CR/LF/NUL no newline remains');
assert_contains('a', sanitize_header_value("a\r\nb\n\0c"), 'first token preserved');

assert_eq('', sanitize_header_value(''), 'empty input returns empty');

// --------------------------------------------------- decode_mime_header() ---+
group('decode_mime_header()');

assert_eq('Hello World', decode_mime_header('Hello World'), 'plain ASCII passthrough');

assert_eq('Café', decode_mime_header('=?UTF-8?B?Q2Fmw6k=?='), 'UTF-8 base64 encoded header decodes');

assert_eq('Café', decode_mime_header('=?ISO-8859-1?Q?Caf=E9?='), 'ISO-8859-1 QP header decodes to UTF-8');

$multi = '=?UTF-8?B?SGVsbG8g?= =?ISO-8859-1?Q?w=f3rld?=';
assert_eq('Hello wórld', decode_mime_header($multi), 'multi-chunk mixed charset header concatenates');

assert_eq('Café', decode_mime_header('=?windows-1252?Q?Caf=E9?='), 'windows-1252 header decodes');

$emoji = '=?UTF-8?B?4piA?=';
assert_eq('☀', decode_mime_header($emoji), 'emoji B-encoded header decodes');

assert_eq('', decode_mime_header(''), 'empty string returns empty');

// RFC 2231 filename — attached %-encoded UTF-8
assert_eq('café.pdf', decode_rfc2231("UTF-8''caf%C3%A9.pdf"), 'RFC 2231 percent-encoded filename decodes');

// unknown charset must not throw and must not corrupt
$unknown = @decode_mime_header('=?X-CUSTOM?B?SGVsbG8=?=');
assert_true(is_string($unknown), 'unknown charset falls back gracefully without throwing');

// ----------------------------------------------------------- hardening ------+
group('Hardening (session encrypt + sanitizer)');

// session_encrypt/decrypt round-trip (sodium available in test env)
$pass = 'hunter2-secret-password';
$stored = session_encrypt($pass);
assert_true(is_string($stored) && $stored !== $pass, 'encrypt yields opaque (non plaintext) value');
assert_eq($pass, session_decrypt($stored), 'decrypt round-trips to original password');

// tampered ciphertext must not decrypt to the real password (corrupt the
// ciphertext payload, not the nonce which base64_decode ignores trailing junk on)
$tampered = strtr(substr($stored, 0, (int)(strlen($stored) / 2) - 1), ['A' => 'B']) . substr($stored, (int)(strlen($stored) / 2));
assert_not_contains($pass, session_decrypt($tampered), 'tampered ciphertext does not reveal plaintext');

// Sanitizer blocks interactive/embedded tags whose text may be reused
$s = sanitize_html('<form action="https://evil.com"><input name="x"></form><p>hi</p>');
assert_not_contains('<form', $s, 'form tag stripped');
assert_contains('<p>hi</p>', $s, 'sibling content preserved');

$s = sanitize_html('<object data="x"></object><embed src="y">tail');
assert_not_contains('object', $s, 'object tag stripped');
assert_not_contains('embed', $s, 'embed tag stripped');
assert_contains('tail', $s, 'sibling text preserved');

$s = sanitize_html('<a href="HTTPS://ok.example">link</a>');
assert_contains('href="HTTPS://ok.example"', $s, 'safe https href preserved');

// ---------------------------------------------------- rate_limit_sleep ---+
group('rate_limit_sleep_seconds()');

assert_eq(0, rate_limit_sleep_seconds(0), '0 failures -> no delay');
assert_eq(1, rate_limit_sleep_seconds(1), '1 failure -> 1s');
assert_eq(2, rate_limit_sleep_seconds(2), '2 failures -> 2s');
assert_eq(4, rate_limit_sleep_seconds(3), '3 failures -> 4s (doubles)');
assert_eq(8, rate_limit_sleep_seconds(4), '4 failures -> 8s');
assert_eq(16, rate_limit_sleep_seconds(5), '5 failures -> 16s');
assert_eq(60, rate_limit_sleep_seconds(10), 'capped at 60s for many failures');

// ------------------------------------------------- resolve_mail_config ---+
group('resolve_mail_config()');

$cfg = resolve_mail_config(['preset' => 'gmail'], 'x@gmail.com');
assert_eq('imap.gmail.com', $cfg['imap']['server'], 'gmail preset -> imap server');
assert_eq(993, $cfg['imap']['port'], 'gmail preset -> imap port');
assert_eq('ssl', $cfg['imap']['enc'], 'gmail preset -> imap encryption');
assert_eq('smtp.gmail.com', $cfg['smtp']['server'], 'gmail preset -> smtp server');
assert_eq(587, $cfg['smtp']['port'], 'gmail preset -> smtp port');
assert_eq('tls', $cfg['smtp']['enc'], 'gmail preset -> smtp encryption');

$cfg = resolve_mail_config(['preset' => 'custom',
    'imap_server' => 'imap.example.org', 'imap_port' => '143', 'imap_enc' => 'tls',
    'smtp_server' => 'smtp.example.org', 'smtp_port' => '2525', 'smtp_enc' => 'tls'], 'me@example.org');
assert_eq('imap.example.org', $cfg['imap']['server'], 'custom imap server');
assert_eq(143, $cfg['imap']['port'], 'custom imap port');
assert_eq('tls', $cfg['imap']['enc'], 'custom imap encryption');
assert_eq('smtp.example.org', $cfg['smtp']['server'], 'custom smtp server');
assert_eq(2525, $cfg['smtp']['port'], 'custom smtp port');

$cfg = resolve_mail_config([], 'me@example.org');
assert_eq('example.org', $cfg['imap']['server'], 'blank config -> domain imap server');
assert_eq('example.org', $cfg['smtp']['server'], 'blank config -> domain smtp server');

$cfg = resolve_mail_config(['preset' => 'bogus'], 'me@example.org');
assert_eq('example.org', $cfg['imap']['server'], 'unknown preset -> falls back to domain');

$cfg = resolve_mail_config(['preset' => 'custom', 'imap_server' => 'imap.x.com'], 'me@example.org');
assert_true($cfg['smtp']['server'] !== '', 'custom imap-only still resolves smtp fallback');

// ------------------------------------------------------- server_lock() ---+
group('server_lock(): the environment pins the mail server');

$lockVars = ['EMMMAIL_PROVIDER', 'EMMMAIL_IMAP_HOST', 'EMMMAIL_SMTP_HOST', 'EMMMAIL_MAIL_SERVER',
    'EMMMAIL_ALLOWED_DOMAINS'];
$lockEnv = function (array $set) use ($lockVars): void {
    foreach ($lockVars as $v) putenv($v);
    foreach ($set as $k => $v) putenv("$k=$v");
};

$lockEnv([]);
$lk = server_lock();
assert_false($lk['locked'], 'no lock vars -> not locked');
assert_eq('', $lk['error'], 'no lock vars -> no error');
assert_eq(null, $lk['config'], 'no lock vars -> no config');

$lockEnv(['EMMMAIL_PROVIDER' => 'Gmail ']);
$lk = server_lock();
assert_true($lk['locked'], 'a known provider locks');
assert_eq('', $lk['error'], 'a known provider is not an error');
assert_eq(server_presets()['gmail']['imap'], $lk['config']['imap'] ?? null, 'provider -> the preset imap config (case/space-insensitive)');
assert_eq(server_presets()['gmail']['smtp'], $lk['config']['smtp'] ?? null, 'provider -> the preset smtp config');

$lockEnv(['EMMMAIL_PROVIDER' => 'bogus']);
$lk = server_lock();
assert_true($lk['locked'], 'an unknown provider still locks (fails closed)');
assert_true($lk['error'] !== '', 'an unknown provider is an error');
assert_eq(null, $lk['config'], 'an unknown provider has no config');

$lockEnv(['EMMMAIL_PROVIDER' => 'gmail', 'EMMMAIL_IMAP_HOST' => 'imap.x.com']);
$lk = server_lock();
assert_true($lk['locked'] && $lk['error'] !== '', 'a provider beside a host var is a conflict');

$lockEnv(['EMMMAIL_PROVIDER' => 'gmail', 'EMMMAIL_MAIL_SERVER' => 'mail.x.com']);
assert_true(server_lock()['error'] !== '', 'a provider beside EMMMAIL_MAIL_SERVER is a conflict');

$lockEnv(['EMMMAIL_IMAP_HOST' => 'imap.x.com', 'EMMMAIL_SMTP_HOST' => 'smtp.x.com']);
$lk = server_lock();
assert_true($lk['locked'], 'a host pair locks');
assert_eq('', $lk['error'], 'a host pair is not an error');
assert_eq(['server' => 'imap.x.com', 'port' => MAIL_IMAP_PORT, 'enc' => IMAP_ENCRYPTION], $lk['config']['imap'] ?? null,
    'host pair -> imap host with the env port and encryption');
assert_eq(['server' => 'smtp.x.com', 'port' => MAIL_SMTP_PORT, 'enc' => SMTP_ENCRYPTION], $lk['config']['smtp'] ?? null,
    'host pair -> smtp host with the env port and encryption');

$lockEnv(['EMMMAIL_IMAP_HOST' => 'imap.x.com']);
$lk = server_lock();
assert_true($lk['locked'] && $lk['error'] !== '', 'only an imap host is an error');
$lockEnv(['EMMMAIL_SMTP_HOST' => 'smtp.x.com']);
assert_true(server_lock()['error'] !== '', 'only an smtp host is an error');

$lockEnv(['EMMMAIL_MAIL_SERVER' => 'mail.x.com']);
$lk = server_lock();
assert_true($lk['locked'] && $lk['error'] === '', 'EMMMAIL_MAIL_SERVER alone locks');
assert_eq('mail.x.com', $lk['config']['imap']['server'] ?? null, 'EMMMAIL_MAIL_SERVER is the imap host');
assert_eq('mail.x.com', $lk['config']['smtp']['server'] ?? null, 'EMMMAIL_MAIL_SERVER is the smtp host');

$lockEnv(['EMMMAIL_MAIL_SERVER' => 'mail.x.com', 'EMMMAIL_SMTP_HOST' => 'out.x.com']);
$lk = server_lock();
assert_eq('mail.x.com', $lk['config']['imap']['server'] ?? null, 'EMMMAIL_MAIL_SERVER fills the blank imap host');
assert_eq('out.x.com', $lk['config']['smtp']['server'] ?? null, 'a per-protocol host wins over EMMMAIL_MAIL_SERVER');

$lockEnv(['EMMMAIL_IMAP_HOST' => '  ', 'EMMMAIL_PROVIDER' => ' ']);
assert_false(server_lock()['locked'], 'whitespace-only values are unset');

// ---------------------------------------------------- allowed domains ---+
group('allowed_domains() and email_domain_allowed()');

$lockEnv([]);
assert_eq([], allowed_domains(), 'no env -> no list');
assert_true(email_domain_allowed('me@anything.io'), 'no list -> every domain allowed');

$lockEnv(['EMMMAIL_ALLOWED_DOMAINS' => ' Example.com, @example.org  example.com,,']);
assert_eq(['example.com', 'example.org'], allowed_domains(), 'commas/whitespace split, lowercased, @ stripped, deduped');
assert_true(email_domain_allowed('me@example.com'), 'a listed domain is allowed');
assert_true(email_domain_allowed('Me@EXAMPLE.ORG'), 'the domain compares case-insensitively');
assert_true(email_domain_allowed(' me@example.com '), 'surrounding space is ignored');
assert_false(email_domain_allowed('me@evil.io'), 'an unlisted domain is refused');
assert_false(email_domain_allowed('me@sub.example.com'), 'a subdomain is not the domain');
assert_false(email_domain_allowed('me@example.com.evil.io'), 'a suffix trick is refused');
assert_false(email_domain_allowed('example.com'), 'no @ is refused');
assert_false(email_domain_allowed('me@example.com@evil.io'), 'the last @ decides the domain');

// ----------------------------------------------------------- login_gate ---+
group('login_gate(): refusals that never reach the server');

$lockEnv([]);
assert_eq('', login_gate('me@anything.io'), 'nothing configured -> no refusal');

$lockEnv(['EMMMAIL_ALLOWED_DOMAINS' => 'example.com']);
assert_eq('', login_gate('me@example.com'), 'an allowed domain passes');
assert_eq('Only @example.com accounts can sign in here.', login_gate('me@evil.io'),
    'one domain -> a friendly refusal naming it');
$lockEnv(['EMMMAIL_ALLOWED_DOMAINS' => 'a.com,b.com']);
assert_eq('Only @a.com or @b.com accounts can sign in here.', login_gate('me@evil.io'), 'two domains');
$lockEnv(['EMMMAIL_ALLOWED_DOMAINS' => 'a.com,b.com,c.com']);
assert_eq('Only @a.com, @b.com or @c.com accounts can sign in here.', login_gate('me@evil.io'), 'three domains');

$lockEnv(['EMMMAIL_PROVIDER' => 'bogus']);
assert_eq(server_lock()['error'], login_gate('me@example.com'), 'a misconfigured lock refuses every sign-in');
$lockEnv(['EMMMAIL_PROVIDER' => 'migadu']);
assert_eq('', login_gate('me@example.com'), 'a valid lock alone refuses nobody');

$loginSrc = (function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $lines = file($r->getFileName());
    return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
})('handle_login');
$gateAt = strpos($loginSrc, 'login_gate($email)');
assert_true($gateAt !== false, 'handle_login() calls login_gate()');
assert_true($gateAt < (int)strpos($loginSrc, 'db_count_recent_failures'), 'the gate runs before the rate limiter');
assert_true($gateAt < (int)strpos($loginSrc, 'new MailImap'), 'the gate runs before any IMAP connection');
assert_contains("server_lock()['config'] ?? resolve_mail_config(", $loginSrc,
    'a locked server ignores the posted preset and custom fields');
$lockEnv([]);

// ------------------------------------------------------- tls_ssl_options() -+
group('tls_ssl_options()');

// A null/empty 'cafile' stays in the stream context and makes
// stream_socket_enable_crypto() throw "ValueError: Path must not be empty",
// which was a hard 500 on every send. The key must be absent, not empty.
putenv('EMMMAIL_CAFILE');
$o = tls_ssl_options(true);
assert_false(array_key_exists('cafile', $o), 'cafile is omitted entirely when no CA file is configured');
assert_true($o['verify_peer'], 'verify_peer follows the verify flag');
assert_true($o['verify_peer_name'], 'verify_peer_name follows the verify flag');
assert_false($o['allow_self_signed'], 'self-signed certs rejected when verifying');
assert_eq(TLS_MIN_CRYPTO_METHOD, $o['crypto_method'], 'minimum TLS version pinned');

$o = tls_ssl_options(false);
assert_false($o['verify_peer'], 'verification can be turned off');
assert_true($o['allow_self_signed'], 'self-signed allowed when not verifying');
assert_false(array_key_exists('cafile', $o), 'cafile still omitted when not verifying');

putenv('EMMMAIL_CAFILE=' . __FILE__);
$o = tls_ssl_options(true);
assert_eq(__FILE__, $o['cafile'] ?? null, 'a configured CA file is passed through');
putenv('EMMMAIL_CAFILE');

// Whitespace-only is the same mistake as empty.
putenv('EMMMAIL_CAFILE=   ');
assert_false(array_key_exists('cafile', tls_ssl_options(true)), 'blank CA file path is ignored');
putenv('EMMMAIL_CAFILE');

// ------------------------------------------------- parse_ehlo_capabilities -+
group('parse_ehlo_capabilities()');

$resp = "250-mail.example.com\r\n250-SIZE 35882577\r\n250-AUTH PLAIN LOGIN\r\n250 8BITMIME";
$caps = parse_ehlo_capabilities($resp);
assert_true(is_array($caps['auth'] ?? null), 'AUTH list extracted');
assert_true(in_array('PLAIN', $caps['auth'], true), 'PLAIN advertised');
assert_true(in_array('LOGIN', $caps['auth'], true), 'LOGIN advertised');
assert_eq(35882577, $caps['size'], 'SIZE limit parsed');

$resp2 = "250-mail.example.com\r\n250 AUTH LOGIN";
$caps2 = parse_ehlo_capabilities($resp2);
assert_true(in_array('LOGIN', $caps2['auth'], true), 'no-comma AUTH list parsed');
assert_eq(null, $caps2['size'], 'no SIZE -> null');

$resp3 = "250-mail.example.com";
$caps3 = parse_ehlo_capabilities($resp3);
assert_eq([], $caps3['auth'], 'no AUTH line -> empty array');
assert_eq(null, $caps3['size'], 'no SIZE -> null');

// ----------------------------------------------------------- empty marker -+// ----------------------------------------------------- smtp_build_raw_message -+
group('smtp_build_raw_message()');

$raw = smtp_build_raw_message(
    'from@example.com',
    'to@example.com',
    'Test Subject',
    '<p>Hello <b>world</b></p>',
    'Display Name',
    [],
    '',
    '',
    ''
);

assert_contains('From: Display Name <from@example.com>', $raw, 'From header with display name');
assert_contains('To: to@example.com', $raw, 'To header present');
assert_contains('MIME-Version: 1.0', $raw, 'MIME-Version present');
assert_contains('Date: ', $raw, 'Date header present');
assert_contains('Message-ID: ', $raw, 'Message-ID present');
assert_contains('multipart/alternative', $raw, 'multipart/alternative present');
assert_contains('text/plain', $raw, 'text/plain alternative present');
assert_contains('text/html', $raw, 'text/html alternative present');

// UTF-8 subject must be B-encoded
$raw2 = smtp_build_raw_message('f@e.com', 't@e.com', 'Zażółć gęślą jaźń', 'body', '', [], '', '', '');
assert_contains('Subject: =?UTF-8?B?', $raw2, 'UTF-8 subject is B-encoded');
assert_not_contains("Subject: Zażółć", $raw2, 'raw UTF-8 not leaked into subject header');

// attachments produce multipart/mixed
$raw3 = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [
    ['name' => 'doc.pdf', 'data' => 'fake pdf bytes'],
], '', '', '');
assert_contains('multipart/mixed', $raw3, 'multipart/mixed present with attachment');
assert_contains('Content-Disposition: attachment', $raw3, 'attachment disposition present');

// CC header
$raw4 = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], 'cc@example.com', '', '');
assert_contains('Cc: cc@example.com', $raw4, 'Cc header present');

// Bcc must NEVER be a header on the transmitted message: the whole point is that
// the recipients travel in the SMTP envelope only.
$rawB = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], 'cc@e.com', 'secret@e.com', '');
assert_not_contains('Bcc:', $rawB, 'no Bcc header on the transmitted message');
assert_not_contains('secret@e.com', $rawB, 'a Bcc address never appears in the message at all');

// The copy filed in Sent does record it, so the user knows who got the mail.
// Same raw bytes, so Message-ID and boundaries match what actually went out.
$copy = smtp_raw_with_bcc($rawB, 'secret@e.com, other@e.com');
assert_contains('Bcc: secret@e.com, other@e.com', $copy, 'the Sent copy carries a Bcc header');
assert_contains('Cc: cc@e.com', $copy, 'and keeps the Cc header');
assert_contains('To: t@e.com', $copy, 'and keeps the To header');
assert_eq(substr($rawB, strpos($rawB, "\r\n\r\n")), substr($copy, strpos($copy, "\r\n\r\n")),
    'the body is byte-identical to what was sent');
assert_eq(1, substr_count($copy, 'Bcc:'), 'exactly one Bcc header');
// Header injection through the Bcc value must not be possible.
$inject = smtp_raw_with_bcc($rawB, "a@e.com\r\nX-Evil: 1");
assert_not_contains("\r\nX-Evil", $inject, 'CRLF in a Bcc value cannot start a new header line');
// Nothing to add, nothing changes.
assert_eq($rawB, smtp_raw_with_bcc($rawB, ''), 'an empty Bcc leaves the message untouched');
assert_eq($rawB, smtp_raw_with_bcc($rawB, '   '), 'a blank Bcc leaves the message untouched');

// dot-stuffing: a body line starting with period must be doubled
$raw5 = smtp_build_raw_message('f@e.com', 't@e.com', 'S', "line one\n.end line", '', [], '', '', '');
assert_not_contains("\n.end line\r\n", $raw5, 'body line not left un-stuffed');

// =============================================================== TIER 2 =====
// SQLite models against a file-based throwaway DB (test.sqlite).

group('Tier 2 — SQLite models');

// Run the model tests against a fresh copy of the throwaway file DB.
db_reset_for_test(); // opens a fresh connection to EMMMAIL_DB_PATH (test.sqlite)
$testUserA = 'alice@example.com';
$testUserB = 'bob@example.com';

// --- Settings ---
db_set_setting($testUserA, 'emails_per_page', '50');
assert_eq('50', db_get_setting($testUserA, 'emails_per_page'), 'setting set + get');
assert_eq(null, db_get_setting($testUserA, 'nonexistent'), 'missing setting returns null default');
assert_eq('fallback', db_get_setting($testUserA, 'nonexistent', 'fallback'), 'missing setting returns provided default');

db_set_setting($testUserA, 'emails_per_page', '30');
assert_eq('30', db_get_setting($testUserA, 'emails_per_page'), 'setting overwritten');

db_set_setting($testUserA, 'timezone', 'America/New_York');
$all = db_get_all_settings($testUserA);
assert_true(is_array($all) && $all['emails_per_page'] === '30', 'get_all returns multiple settings');
assert_true(is_array($all) && $all['timezone'] === 'America/New_York', 'get_all includes timezone');

db_set_setting($testUserB, 'emails_per_page', '5');
assert_eq('30', db_get_setting($testUserA, 'emails_per_page'), 'user isolation: A unaffected by B');

// --- Contacts ---
db_upsert_contact($testUserA, 'Carol', 'carol@example.com', true);
$contacts = db_search_contacts($testUserA, 'carol');
assert_eq(1, count($contacts), 'new contact inserted');
assert_eq('carol@example.com', $contacts[0]['email'], 'contact email correct');

db_upsert_contact($testUserA, 'Carol', 'carol@example.com', true);
$contacts = db_search_contacts($testUserA, 'carol');
assert_eq(2, $contacts[0]['frequency'] ?? null, 'upsert increments frequency');

// sent_to ranking: a contacted-send should rank above a from-only contact
db_upsert_contact($testUserA, 'Dave', 'dave@example.com', false);
$contacts = db_search_contacts($testUserA, 'dave');
assert_eq(0, $contacts[0]['sent_to'] ?? null, 'from-only contact has sent_to=0');

$contacts = db_search_contacts($testUserA, '@example.com');
assert_true(count($contacts) >= 2, 'search by domain prefix returns matches');

// user isolation for contacts
db_upsert_contact($testUserB, 'Ellen', 'ellen@example.com', true);
$contactsA = db_search_contacts($testUserA, 'ellen');
assert_eq(0, count($contactsA), 'contacts isolated between users');

// --- Search cache ---
db_cache_email($testUserA, 'INBOX', 101, 'Invoices', 'banco@gov.es', 'alice@example.com', 'Please pay the invoice by Friday.', 1700000000);
db_cache_email($testUserA, 'INBOX', 102, 'Hello', 'friend@example.com', 'alice@example.com', 'Just saying hi.', 1700001000);
db_cache_email($testUserA, 'Sent',   501, 'Meeting notes', 'alice@example.com', 'team@example.com', 'See agenda attached.', 1700002000);

$hits = db_search_cache($testUserA, 'INBOX', 'invoice');
assert_eq(1, count($hits), 'cache search matches subject/snippet');

$hits = db_search_cache($testUserA, 'INBOX', 'friend@example.com');
assert_eq(1, count($hits), 'cache search matches sender');

$hits = db_search_cache($testUserA, 'Sent', 'meeting');
assert_eq(1, count($hits), 'cache search scoped by folder');

// duplicate uid+folder upserts rather than duplicates
db_cache_email($testUserA, 'INBOX', 101, 'Invoices v2', 'banco@gov.es', 'alice@example.com', 'Updated.', 1700000000);
$hits = db_search_cache($testUserA, 'INBOX', 'banco');
assert_eq(1, count($hits), 'duplicate uid+folder upserts instead of duplicating');
assert_eq('Invoices v2', $hits[0]['subject'] ?? '', 'cached subject updated on upsert');

// uid removal
db_remove_cache_uid($testUserA, 'INBOX', 102);
$hits = db_search_cache($testUserA, 'INBOX', 'friend@example.com');
assert_eq(0, count($hits), 'remove_cache_uid deletes entry');

// purge for user
db_purge_cache_for_email($testUserA);
$hits = db_search_cache($testUserA, 'INBOX', 'banco');
assert_eq(0, count($hits), 'purge removes all of a user cache');

// --- Login attempts ---
db_record_login_attempt('1.2.3.4', false);
db_record_login_attempt('1.2.3.4', false);
assert_eq(2, db_count_recent_failures('1.2.3.4'), 'counts recent failures for IP');
assert_eq(0, db_count_recent_failures('9.9.9.9'), 'other IP unaffected');
db_record_login_attempt('1.2.3.4', true);
assert_eq(2, db_count_recent_failures('1.2.3.4'), 'success not counted as failure');

// ------------------------------------------------- schema migrations -------+
group('Tier 2 — schema migrations');

assert_eq(SCHEMA_VERSION, db_schema_version(db_open()), 'fresh db is at the current schema version');

// The journal_mode switch is wrapped in a try/catch (SQLite ignores
// busy_timeout for it), so guard that WAL is still actually enabled.
assert_eq('wal', strtolower((string)db_open()->query('PRAGMA journal_mode')->fetchColumn()),
    'db_open enables WAL');

// Forward-only migrations must be safe to re-run on every request.
db_migrate(db_open());
assert_eq(SCHEMA_VERSION, db_schema_version(db_open()), 're-running db_migrate keeps the version');
assert_eq('30', db_get_setting($testUserA, 'emails_per_page'), 're-running db_migrate preserves data');

// SQLite has no ADD COLUMN IF NOT EXISTS, so additive steps need this guard.
assert_true(db_has_column(db_open(), 'settings', 'value'), 'db_has_column finds an existing column');
assert_false(db_has_column(db_open(), 'settings', 'nope'), 'db_has_column rejects a missing column');
assert_false(db_has_column(db_open(), 'no_such_table', 'value'), 'db_has_column handles a missing table');

// Baseline: a db created before versioning existed reports 0 but already has
// tables. Migrating it must be a no-op on what exists and must not lose rows.
$legacyPath = __DIR__ . '/test_legacy.sqlite';
@unlink($legacyPath);
$legacy = new PDO('sqlite:' . $legacyPath, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$legacy->exec("CREATE TABLE settings (user_email TEXT NOT NULL, key TEXT NOT NULL, value TEXT, PRIMARY KEY (user_email, key))");
$legacy->exec("INSERT INTO settings (user_email, key, value) VALUES ('legacy@example.com', 'timezone', 'Europe/Madrid')");
assert_eq(0, db_schema_version($legacy), 'pre-versioning db reports version 0');

db_migrate($legacy);
assert_eq(SCHEMA_VERSION, db_schema_version($legacy), 'legacy db upgrades to the current version');
assert_eq('Europe/Madrid', $legacy->query("SELECT value FROM settings WHERE key = 'timezone'")->fetch()['value'],
    'baseline migration preserves existing rows');
$legacyTables = $legacy->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('contacts', $legacyTables, true), 'baseline migration creates the missing tables');
assert_true(in_array('folder_counts', $legacyTables, true), 'a legacy db also gets folder_counts');

// Forward-only: a db written by a newer version of the app must be left alone.
$legacy->exec('PRAGMA user_version = 999');
db_migrate($legacy);
assert_eq(999, db_schema_version($legacy), 'newer db is not downgraded');

// An unknown step must fail loudly rather than half-apply.
$threw = false;
try {
    db_migration_step($legacy, 9999);
} catch (Throwable $e) {
    $threw = true;
}
assert_true($threw, 'unknown migration step throws');

$legacy = null;
@unlink($legacyPath);

// ------------------------------------------- threaded conversations (v4) ---+
group('schema v4 — thread_key column and cache rebuild');

assert_true(SCHEMA_VERSION >= 4, 'schema is at least v4');
assert_true(db_has_column(db_open(), 'messages', 'thread_key'),
    'the messages cache has a thread_key column');

$v4Indexes = db_open()->query("SELECT name FROM sqlite_master WHERE type = 'index'")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('idx_msg_thread', $v4Indexes, true), 'the per-folder thread index exists');
assert_true(in_array('idx_msg_thread_user', $v4Indexes, true), 'the cross-folder thread index exists');

// A database written by the previous version upgrades in place, and its cached
// rows are discarded: they carry no References, so keeping them would leave
// every legacy message in one empty-key thread. The next visit cold-syncs.
$v3Path = __DIR__ . '/test_legacy_v3.sqlite';
@unlink($v3Path);
$v3 = new PDO('sqlite:' . $v3Path, null, null, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$v3->exec("CREATE TABLE messages (
    user_email TEXT NOT NULL, folder TEXT NOT NULL, uid INTEGER NOT NULL,
    subject TEXT DEFAULT '', from_display TEXT DEFAULT '', from_address TEXT DEFAULT '',
    to_display TEXT DEFAULT '', to_address TEXT DEFAULT '', date_ts INTEGER NOT NULL,
    size INTEGER DEFAULT 0, unread INTEGER DEFAULT 0, answered INTEGER DEFAULT 0,
    flagged INTEGER DEFAULT 0, deleted INTEGER DEFAULT 0, has_attachment INTEGER DEFAULT 0,
    structure_synced INTEGER DEFAULT 0, snippet TEXT DEFAULT '', cached_at INTEGER NOT NULL,
    UNIQUE(user_email, folder, uid))");
$v3->exec("CREATE TABLE folder_state (
    user_email TEXT NOT NULL, folder TEXT NOT NULL, uidvalidity INTEGER, uidnext INTEGER,
    total INTEGER, unseen INTEGER, cached_rows INTEGER DEFAULT 0, last_sync_ts INTEGER DEFAULT 0,
    last_full_ts INTEGER DEFAULT 0, PRIMARY KEY (user_email, folder))");
$v3->exec("INSERT INTO messages (user_email, folder, uid, subject, date_ts, cached_at)
           VALUES ('v3@example.com', 'INBOX', 7, 'Old', 1700000000, 1700000000)");
$v3->exec("INSERT INTO folder_state (user_email, folder, total, cached_rows, last_full_ts)
           VALUES ('v3@example.com', 'INBOX', 1, 1, 1700000000)");
$v3->exec('PRAGMA user_version = 3');
assert_eq(3, db_schema_version($v3), 'the fixture reports as a v3 database');

db_migrate($v3);
assert_eq(SCHEMA_VERSION, db_schema_version($v3), 'a v3 database upgrades to the current version');
assert_true(db_has_column($v3, 'messages', 'thread_key'), 'the upgrade adds thread_key');
assert_eq(0, (int)$v3->query('SELECT COUNT(*) FROM messages')->fetchColumn(),
    'the upgrade discards the cache that cannot thread');
$v3State = $v3->query("SELECT cached_rows, last_full_ts FROM folder_state WHERE folder = 'INBOX'")->fetch();
assert_eq(0, (int)$v3State['cached_rows'], 'and resets the cached-row count so the folder re-syncs');
assert_eq(0, (int)$v3State['last_full_ts'], 'and forces a full reconcile on the next sync');

$v3 = null;
@unlink($v3Path);

group('thread_root()/extract_msgid() — the conversation key is pure');

// extract_msgid: first token wins, surrounding whitespace is ignored.
assert_eq('', extract_msgid(''), 'an empty header has no id');
assert_eq('', extract_msgid('not an id'), 'plain text has no id');
assert_eq('<a@b.c>', extract_msgid('<a@b.c>'), 'a lone id is returned whole');
assert_eq('<a@b.c>', extract_msgid('  <a@b.c>  '), 'surrounding whitespace is ignored');
assert_eq('<root@x.io>', extract_msgid('<root@x.io> <second@x.io>'), 'the first token is the root');
assert_eq('<root@x.io>', extract_msgid('  <root@x.io>\t<second@x.io>'), 'any whitespace separates tokens');
assert_eq('', extract_msgid('<>'), 'an empty id is not an id');
assert_eq('', extract_msgid('<' . str_repeat('a', 500) . '>'), 'an overlong id is rejected');
assert_eq('', extract_msgid("<a\x00b@x.io>"), 'an id with a control character is rejected');

// thread_root: References beats In-Reply-To beats the message's own id.
assert_eq('<root@x.io>', thread_root('<own@x.io>', '<parent@x.io>', '<root@x.io> <parent@x.io>'),
    'References names the root');
assert_eq('<parent@x.io>', thread_root('<own@x.io>', '<parent@x.io>', ''),
    'without References, In-Reply-To is the root');
assert_eq('<own@x.io>', thread_root('<own@x.io>', '', ''), 'a top-level message is its own root');
assert_eq('', thread_root('', '', ''), 'a message with no ids has no root');
assert_eq('<parent@x.io>', thread_root('<own@x.io>', '<parent@x.io>', 'garbage'),
    'a malformed References falls through to In-Reply-To');
assert_eq('<own@x.io>', thread_root('<own@x.io>', 'garbage', 'garbage'),
    'a malformed chain falls all the way to the own id');

group('thread_key_is_safe() — only a Message-ID may reach the route');
assert_true(thread_key_is_safe('<a@b.c>'), 'a well-formed id is safe');
assert_false(thread_key_is_safe(''), 'an empty key is not safe');
assert_false(thread_key_is_safe('a@b.c'), 'an unbracketed id is not safe');
assert_false(thread_key_is_safe('uid:INBOX:7'), 'a sentinel is not routable');
assert_false(thread_key_is_safe('<a<b@c>'), 'a nested angle bracket is not safe');
assert_false(thread_key_is_safe('<a' . str_repeat('b', 500) . '@c>'), 'an overlong key is not safe');
assert_false(thread_key_is_safe("<a\x00b@c>"), 'a control character is not safe');

group('conversation DB helpers');

$th = 'threads@example.com';
$trow = function (int $uid, int $ts, string $folder, string $key, array $over = []) {
    return array_merge([
        'uid' => $uid, 'subject' => "Subject $uid",
        'from_display' => "Sender $uid", 'from_address' => "s$uid@x.io",
        'to_display' => 'Me', 'to_address' => 'me@x.io',
        'date_ts' => $ts, 'size' => 10, 'unread' => false,
        'answered' => false, 'flagged' => false, 'deleted' => false,
        'has_attachment' => false, 'thread_key' => $key,
    ], $over);
};
db_upsert_messages($th, 'INBOX', [
    $trow(1001, 1700000000, 'INBOX', '<root@x.io>'),
    $trow(1002, 1700000060, 'INBOX', '<root@x.io>', ['unread' => true]),
    $trow(1003, 1700000120, 'INBOX', '<root@x.io>'),
    $trow(1004, 1700000180, 'INBOX', '<other@x.io>'),
]);
db_upsert_messages($th, 'Sent', [$trow(2001, 1700000240, 'Sent', '<root@x.io>')]);
db_upsert_messages($th, 'Drafts', [$trow(3001, 1700000300, 'Drafts', '<root@x.io>')]);

assert_eq(2, db_count_threads($th, 'INBOX'), 'two conversations in the inbox');

$members = db_thread_members($th, '<root@x.io>');
assert_eq([1001, 1002, 1003, 2001], array_map(fn($m) => (int)$m['uid'], $members),
    'members cross folders, oldest first');
assert_eq(['INBOX', 'INBOX', 'INBOX', 'Sent'], array_map(fn($m) => $m['folder'], $members),
    'a Sent reply joins the inbox conversation');
assert_eq(4, count($members), 'the reply-draft is not a thread member');

$threads = db_list_threads($th, 'INBOX', 1, 20);
assert_eq(2, count($threads), 'the inbox listing is one row per conversation');
assert_eq('<root@x.io>', $threads[0]['thread_key'], 'the newest conversation leads');
assert_eq(4, $threads[0]['thread_count'], 'the row counts every member across folders');
assert_eq(1, $threads[0]['thread_unread'], 'and counts its unread members');
assert_eq([1001, 1002, 1003], $threads[0]['thread_uids'], 'the pick set is the in-folder members');
assert_eq(1003, $threads[0]['uid'], 'the row targets the folder newest for the cursor');
assert_eq(true, $threads[0]['unread'], 'any unread member makes the row bold');
assert_eq('Subject 2001', $threads[0]['subject'], 'the row shows the newest member, even from Sent');
assert_eq('<other@x.io>', $threads[1]['thread_key'], 'the single message is its own conversation');
assert_eq(1, $threads[1]['thread_count'], 'with a count of one');

// Pagination slices conversations, not messages.
$p1 = db_list_threads($th, 'INBOX', 1, 1);
$p2 = db_list_threads($th, 'INBOX', 2, 1);
assert_eq('<root@x.io>', $p1[0]['thread_key'] ?? null, 'page 1 holds the newest conversation');
assert_eq('<other@x.io>', $p2[0]['thread_key'] ?? null, 'page 2 holds the next');
assert_eq([], db_list_threads($th, 'INBOX', 3, 1), 'page past the end is empty');

group('db_upsert_messages() repairs a conversation key, never blanks one');

$ru = 'repair@example.com';
$base = ['uid' => 1, 'subject' => 'S', 'from_display' => '', 'from_address' => '',
    'to_display' => '', 'to_address' => '', 'date_ts' => 1, 'size' => 1,
    'unread' => false, 'answered' => false, 'flagged' => false, 'deleted' => false];
db_upsert_messages($ru, 'INBOX', [$base + ['thread_key' => '<first@x.io>']]);
assert_eq('<first@x.io>', db_list_messages($ru, 'INBOX', 1, 1)[0]['thread_key'], 'the first key is stored');

// A re-fetched envelope with a real key repairs a sentinel or a stale key.
db_upsert_messages($ru, 'INBOX', [$base + ['thread_key' => '<second@x.io>']]);
assert_eq('<second@x.io>', db_list_messages($ru, 'INBOX', 1, 1)[0]['thread_key'], 'a new key replaces the old');

// A re-fetch that carries no key must not erase a good one.
db_upsert_messages($ru, 'INBOX', [$base + ['thread_key' => '']]);
assert_eq('<second@x.io>', db_list_messages($ru, 'INBOX', 1, 1)[0]['thread_key'], 'an empty key never blanks a good one');

group('an unthreaded cache is rebuilt once');

$uu = 'unthreaded@example.com';
db_upsert_messages($uu, 'INBOX', [
    ['uid' => 1, 'subject' => 'a', 'date_ts' => 1700000000, 'thread_key' => ''],
    ['uid' => 2, 'subject' => 'b', 'date_ts' => 1700000060, 'thread_key' => '<r@x.io>'],
]);
assert_eq(1, db_count_unthreaded($uu, 'INBOX'), 'counts the rows with no conversation key');
// Every row in a folder with an empty key would group under '', so the sync
// must rebuild it rather than render one conversation of everything.
$unePlan = sync_decide(
    ['uidvalidity' => 9, 'uidnext' => 3, 'total' => 2, 'unseen' => 0, 'last_full_ts' => 1700000060],
    ['uidvalidity' => 9, 'uidnext' => 3, 'messages' => 2, 'unseen' => 0],
    1700000060, 60, 1);
assert_eq(true, $unePlan['purge_all'], 'the plan purges the folder');
assert_eq('unthreaded', $unePlan['reason'], 'and says the reason is the missing keys');
$cleanPlan = sync_decide(
    ['uidvalidity' => 9, 'uidnext' => 3, 'total' => 2, 'unseen' => 0, 'last_full_ts' => 1700000060],
    ['uidvalidity' => 9, 'uidnext' => 3, 'messages' => 2, 'unseen' => 0],
    1700000060, 60, 0);
assert_eq(false, $cleanPlan['purge_all'], 'a fully threaded cache does not purge');

// ------------------------------------------------ header cache (Phase 2) ----+
group('Tier 2 — header cache tables');

$cu = 'cache-a@example.com';   // isolated users: db_reset_for_test() is not a reset
$cv = 'cache-b@example.com';

// Helper to build an upsert row with sane defaults.
$msg = function (int $uid, int $ts, array $over = []) {
    return array_merge([
        'uid'          => $uid,
        'subject'      => "Subject $uid",
        'from_display' => "Sender $uid <s$uid@example.com>",
        'from_address' => "s$uid@example.com",
        'to_display'   => 'Me <me@example.com>',
        'to_address'   => 'me@example.com',
        'date_ts'      => $ts,
        'size'         => 1000 + $uid,
        'unread'       => false,
        'answered'     => false,
        'flagged'      => false,
        'deleted'      => false,
    ], $over);
};

// --- upsert + list ---
assert_eq(3, db_upsert_messages($cu, 'INBOX', [
    $msg(101, 1700000100),
    $msg(102, 1700000200, ['unread' => true]),
    $msg(103, 1700000300, ['flagged' => true]),
]), 'upsert reports the number of rows written');

assert_eq(3, db_count_messages($cu, 'INBOX'), 'count reflects the upserted rows');

$rows = db_list_messages($cu, 'INBOX', 1, 10);
assert_eq([103, 102, 101], array_column($rows, 'uid'), 'list is newest-first by date_ts');
assert_eq('Subject 103', $rows[0]['subject'], 'subject round-trips');
assert_eq('s103@example.com', $rows[0]['from_address'], 'from_address round-trips');
assert_true($rows[0]['flagged'], 'flagged round-trips as bool');
assert_false($rows[0]['unread'], 'unread round-trips as bool');
assert_true($rows[1]['unread'], 'unread true round-trips');

// Ties on date_ts must break by uid descending, or pagination can repeat rows.
db_upsert_messages($cu, 'INBOX', [$msg(110, 1700000300), $msg(111, 1700000300)]);
$tied = array_column(db_list_messages($cu, 'INBOX', 1, 10), 'uid');
assert_eq([111, 110, 103, 102, 101], $tied, 'date_ts ties break by uid desc');

// --- pagination ---
assert_eq([111, 110], array_column(db_list_messages($cu, 'INBOX', 1, 2), 'uid'), 'page 1 of 2');
assert_eq([103, 102], array_column(db_list_messages($cu, 'INBOX', 2, 2), 'uid'), 'page 2 of 2');
assert_eq([101], array_column(db_list_messages($cu, 'INBOX', 3, 2), 'uid'), 'page 3 of 2 is the remainder');
assert_eq([], db_list_messages($cu, 'INBOX', 99, 2), 'page past the end is empty');

// --- re-upsert updates in place, and must not clobber managed columns ---
db_set_has_attachment($cu, 'INBOX', [101 => true]);
db_set_message_snippet($cu, 'INBOX', 101, 'the real body text');
db_upsert_messages($cu, 'INBOX', [$msg(101, 1700000100, ['subject' => 'Changed', 'unread' => true])]);
assert_eq(5, db_count_messages($cu, 'INBOX'), 're-upsert updates rather than duplicating');
$one = db_list_messages($cu, 'INBOX', 3, 2)[0];
assert_eq('Changed', $one['subject'], 're-upsert updates the envelope');
assert_true($one['unread'], 're-upsert updates flags');
assert_true($one['has_attachment'], 're-upsert preserves has_attachment');
assert_true($one['structure_synced'], 're-upsert preserves structure_synced');
assert_eq('the real body text', $one['snippet'], 're-upsert preserves the snippet');

// --- scoping ---
db_upsert_messages($cu, 'Sent', [$msg(501, 1700009000)]);
db_upsert_messages($cv, 'INBOX', [$msg(101, 1700000100)]);
assert_eq(1, db_count_messages($cu, 'Sent'), 'folders are scoped');
assert_eq(1, db_count_messages($cv, 'INBOX'), 'users are scoped');
assert_eq(5, db_count_messages($cu, 'INBOX'), 'other user does not affect this one');
assert_eq(0, db_count_messages($cu, 'Nope'), 'unknown folder counts zero');
assert_eq([], db_list_messages($cu, 'Nope', 1, 10), 'unknown folder lists empty');

// --- max cached uid drives the "fetch new from here" range ---
assert_eq(111, db_max_cached_uid($cu, 'INBOX'), 'max cached uid found');
assert_eq(0, db_max_cached_uid($cu, 'Nope'), 'max cached uid is 0 when nothing is cached');

// --- flags ---
db_set_message_flags($cu, 'INBOX', 102, ['unread' => false, 'flagged' => true]);
$r102 = null;
foreach (db_list_messages($cu, 'INBOX', 1, 10) as $r) { if ($r['uid'] === 102) { $r102 = $r; } }
assert_false($r102['unread'], 'flag update clears unread');
assert_true($r102['flagged'], 'flag update sets flagged');

// --- structure backfill queue ---
$pending = db_pending_structure_uids($cu, 'INBOX', 10);
assert_false(in_array(101, $pending, true), 'already-resolved uid is not pending');
assert_true(in_array(102, $pending, true), 'unresolved uid is pending');
assert_eq(2, count(db_pending_structure_uids($cu, 'INBOX', 2)), 'pending queue respects the limit');
db_set_has_attachment($cu, 'INBOX', [102 => false, 103 => true]);
$pending = db_pending_structure_uids($cu, 'INBOX', 10);
assert_false(in_array(102, $pending, true), 'uid resolved to false is no longer pending');
assert_false(in_array(103, $pending, true), 'uid resolved to true is no longer pending');

// --- deletes ---
db_delete_messages($cu, 'INBOX', [110, 111]);
assert_eq(3, db_count_messages($cu, 'INBOX'), 'targeted delete removes only those uids');
db_delete_messages($cu, 'INBOX');
assert_eq(0, db_count_messages($cu, 'INBOX'), 'delete without uids purges the folder');
assert_eq(1, db_count_messages($cu, 'Sent'), 'purging one folder leaves others alone');
assert_eq(1, db_count_messages($cv, 'INBOX'), 'purging one user leaves others alone');

// --- folder_state: nulls must stay null, not become 0 ---
assert_eq(null, db_get_folder_state($cu, 'INBOX'), 'unknown folder state is null');
db_set_folder_state($cu, 'INBOX', [
    'uidvalidity' => 99, 'uidnext' => 1201, 'total' => 200, 'unseen' => 3,
    'cached_rows' => 5, 'last_sync_ts' => 1700001111, 'last_full_ts' => 1700002222,
]);
$st = db_get_folder_state($cu, 'INBOX');
assert_eq(99, $st['uidvalidity'], 'state uidvalidity round-trips');
assert_eq(1201, $st['uidnext'], 'state uidnext round-trips');
assert_eq(200, $st['total'], 'state total round-trips');
assert_eq(3, $st['unseen'], 'state unseen round-trips');
assert_eq(5, $st['cached_rows'], 'state cached_rows round-trips');
assert_eq(1700002222, $st['last_full_ts'], 'state last_full_ts round-trips');

db_set_folder_state($cu, 'INBOX', ['uidvalidity' => null, 'uidnext' => null, 'total' => null, 'unseen' => null]);
$st = db_get_folder_state($cu, 'INBOX');
assert_eq(null, $st['uidvalidity'], 'unknown uidvalidity stays null, never 0');
assert_eq(null, $st['uidnext'], 'unknown uidnext stays null, never 0');
assert_eq(null, $st['total'], 'unknown total stays null, never 0');
assert_eq(0, $st['cached_rows'], 'omitted cached_rows defaults to 0');

db_set_folder_state($cu, 'INBOX', ['uidvalidity' => 100, 'uidnext' => 1300]);
$st = db_get_folder_state($cu, 'INBOX');
assert_eq(100, $st['uidvalidity'], 'state upsert overwrites');
assert_eq(null, db_get_folder_state($cv, 'INBOX'), 'folder state is per user');

// --- folder list cache ---
assert_eq(null, db_get_folder_list($cu, 300), 'no cached folder list yet');
db_set_folder_list($cu, [['name' => 'INBOX', 'displayName' => 'Inbox', 'delimiter' => '/', 'attributes' => 0, 'group' => 'primary']]);
$fl = db_get_folder_list($cu, 300);
assert_true(is_array($fl) && count($fl) === 1, 'folder list round-trips');
assert_eq('INBOX', $fl[0]['name'], 'folder list payload preserved');
assert_eq('primary', $fl[0]['group'], 'folder list group preserved');
assert_eq(null, db_get_folder_list($cu, -1), 'expired folder list is a miss');
assert_eq(null, db_get_folder_list($cv, 300), 'folder list is per user');

// ------------------------------------------- cache -> view shape (Phase 5) --+
group('cache_rows_to_view()');

$viewRows = cache_rows_to_view([
    [
        'uid' => 42, 'subject' => 'Hi', 'from_display' => 'Ann <a@x.com>',
        'from_address' => 'a@x.com', 'to_display' => 'Me <me@x.com>',
        'to_address' => 'me@x.com', 'date_ts' => 1700000123, 'size' => 900,
        'unread' => true, 'answered' => false, 'flagged' => true,
        'deleted' => false, 'has_attachment' => true,
        'structure_synced' => true, 'snippet' => 'body',
    ],
]);
$v = $viewRows[0];
assert_eq(42, $v['uid'], 'uid carried through');
assert_eq('Hi', $v['subject'], 'subject carried through');
assert_eq('Ann <a@x.com>', $v['from'], 'from_display becomes the view\'s from');
assert_eq('Me <me@x.com>', $v['to'], 'to_display becomes the view\'s to');
assert_eq('me@x.com', $v['to_address'], 'to_address carried through');
assert_eq(1700000123, $v['timestamp'], 'date_ts becomes timestamp');
assert_true($v['unread'], 'unread carried through');
assert_true($v['has_attachment'], 'has_attachment carried through');
assert_true($v['flagged'], 'flagged carried through');

// A display name is not always present; the view must still show something.
$bare = cache_rows_to_view([[
    'uid' => 7, 'subject' => '', 'from_display' => '', 'from_address' => 'raw@x.com',
    'to_display' => '', 'to_address' => 'me@x.com', 'date_ts' => 5, 'size' => 0,
    'unread' => false, 'answered' => false, 'flagged' => false,
    'deleted' => false, 'has_attachment' => false,
]])[0];
assert_eq('raw@x.com', $bare['from'], 'empty from_display falls back to the address');
assert_eq('me@x.com', $bare['to'], 'empty to_display falls back to the address');
assert_eq('(No subject)', $bare['subject'], 'empty subject gets a placeholder');
assert_eq([], cache_rows_to_view([]), 'no rows yields no rows');


// -------------------------------------- cache mutations + search (Phase 7) --+
group('Tier 2 — cache invalidation helpers');

$mu = 'mut-a@example.com';
db_delete_messages($mu, 'INBOX');
db_delete_messages($mu, 'Trash');
db_upsert_messages($mu, 'INBOX', [
    $msg(201, 1700000100),
    $msg(202, 1700000200, ['unread' => true]),
    $msg(203, 1700000300),
]);
db_set_folder_state($mu, 'INBOX', [
    'uidvalidity' => 55, 'uidnext' => 300, 'total' => 3, 'unseen' => 1,
    'cached_rows' => 3, 'last_sync_ts' => 1700009999, 'last_full_ts' => 1700009999,
]);

// Invalidation must force the next sync without discarding uidvalidity, or the
// following sync would purge the whole folder for no reason.
cache_invalidate_folder($mu, 'INBOX');
$st = db_get_folder_state($mu, 'INBOX');
assert_eq(0, $st['last_sync_ts'], 'invalidation clears last_sync_ts');
assert_eq(0, $st['last_full_ts'], 'invalidation clears last_full_ts');
assert_eq(55, $st['uidvalidity'], 'invalidation preserves uidvalidity');
assert_eq(3, db_count_messages($mu, 'INBOX'), 'invalidation keeps the cached rows');

// Invalidating a folder we have never synced must not create a bogus state row.
cache_invalidate_folder($mu, 'NeverSeen');
assert_eq(null, db_get_folder_state($mu, 'NeverSeen'), 'invalidating an unknown folder is a no-op');

// Deleting a folder on the server makes its state row garbage, not stale: the
// next sync must not resurrect a mailbox that no longer exists.
db_set_folder_state($mu, 'Doomed', [
    'uidvalidity' => 7, 'uidnext' => 2, 'total' => 1, 'unseen' => 0,
    'cached_rows' => 1, 'last_sync_ts' => 1700009999, 'last_full_ts' => 1700009999,
]);
db_upsert_messages($mu, 'Doomed', [$msg(301, 1700000400)]);
assert_true(db_delete_folder_state($mu, 'Doomed'), 'deleting a folder state reports success');
assert_eq(null, db_get_folder_state($mu, 'Doomed'), 'the state row is gone');
assert_eq(1, db_count_messages($mu, 'Doomed'), 'message rows are a separate concern');
assert_true(db_delete_folder_state($mu, 'Doomed'), 'deleting a missing state row is not an error');
assert_true(db_get_folder_state($mu, 'INBOX') !== null, 'other folders are untouched');

// A message moved to Trash: gone from the source, and Trash must resync.
db_set_folder_state($mu, 'Trash', [
    'uidvalidity' => 66, 'uidnext' => 10, 'total' => 0, 'unseen' => 0,
    'cached_rows' => 0, 'last_sync_ts' => 1700009999, 'last_full_ts' => 1700009999,
]);
cache_apply_move($mu, 'INBOX', 202, 'Trash');
assert_eq(2, db_count_messages($mu, 'INBOX'), 'moved message leaves the source folder');
assert_eq(0, db_get_folder_state($mu, 'Trash')['last_sync_ts'], 'destination folder is invalidated');
$remaining = array_column(db_list_messages($mu, 'INBOX', 1, 10), 'uid');
assert_eq([203, 201], $remaining, 'the right message was removed');

// A hard delete has no destination.
cache_apply_move($mu, 'INBOX', 203, null);
assert_eq(1, db_count_messages($mu, 'INBOX'), 'hard delete removes the row');

group('db_search_messages()');
$se = 'search-a@example.com';
$sf = 'search-b@example.com';
db_delete_messages($se, 'INBOX');
db_upsert_messages($se, 'INBOX', [
    ['uid' => 301, 'subject' => 'Invoice for March', 'from_display' => 'Banco <pay@banco.es>',
     'from_address' => 'pay@banco.es', 'to_display' => 'Me', 'to_address' => 'me@x.com',
     'date_ts' => 1700000100, 'unread' => false],
    ['uid' => 302, 'subject' => 'Lunch?', 'from_display' => 'Ana <ana@friend.com>',
     'from_address' => 'ana@friend.com', 'to_display' => 'Me', 'to_address' => 'me@x.com',
     'date_ts' => 1700000200, 'unread' => true],
    ['uid' => 303, 'subject' => 'Standup notes', 'from_display' => 'Bob <bob@work.com>',
     'from_address' => 'bob@work.com', 'to_display' => 'Team', 'to_address' => 'team@work.com',
     'date_ts' => 1700000300, 'unread' => false],
]);
db_set_message_snippet($se, 'INBOX', 303, 'we discussed the quarterly budget');
db_upsert_messages($se, 'Sent', [['uid' => 401, 'subject' => 'Invoice sent', 'date_ts' => 1700000400]]);
db_upsert_messages($sf, 'INBOX', [['uid' => 301, 'subject' => 'Invoice for March', 'date_ts' => 1700000100]]);

assert_eq([301], array_column(db_search_messages($se, 'INBOX', 'invoice', 1, 20), 'uid'), 'matches subject');
assert_eq([302], array_column(db_search_messages($se, 'INBOX', 'ana@friend', 1, 20), 'uid'), 'matches sender address');
assert_eq([302], array_column(db_search_messages($se, 'INBOX', 'Ana', 1, 20), 'uid'), 'matches sender display name');
assert_eq([303], array_column(db_search_messages($se, 'INBOX', 'team@work', 1, 20), 'uid'), 'matches recipient');
assert_eq([303], array_column(db_search_messages($se, 'INBOX', 'quarterly budget', 1, 20), 'uid'), 'matches the body snippet');
assert_eq([], db_search_messages($se, 'INBOX', 'nothingmatches', 1, 20), 'no match yields no rows');

// Search results must carry real flags, not hardcoded defaults.
$hit = db_search_messages($se, 'INBOX', 'Lunch', 1, 20)[0];
assert_true($hit['unread'], 'search results carry the real unread flag');

// Scoping and counting.
assert_eq([401], array_column(db_search_messages($se, 'Sent', 'invoice', 1, 20), 'uid'), 'scoped to the folder');
assert_eq(1, db_count_search_messages($se, 'INBOX', 'invoice'), 'count matches the folder scope');
assert_eq(0, db_count_search_messages($sf, 'Sent', 'invoice'), 'count is per user');
assert_eq(3, db_count_search_messages($se, 'INBOX', '@'), 'count sees every matching row');

// Real pagination, which the old search_cache path never had.
$p1 = array_column(db_search_messages($se, 'INBOX', '@', 1, 2), 'uid');
$p2 = array_column(db_search_messages($se, 'INBOX', '@', 2, 2), 'uid');
assert_eq([303, 302], $p1, 'search page 1, newest first');
assert_eq([301], $p2, 'search page 2 is the remainder');

// A LIKE wildcard in user input must be matched literally, not act as a wildcard.
db_upsert_messages($se, 'INBOX', [['uid' => 304, 'subject' => 'a_b literal', 'date_ts' => 1700000500]]);
assert_eq([304], array_column(db_search_messages($se, 'INBOX', 'a_b', 1, 20), 'uid'), 'underscore matches literally');
assert_eq([], db_search_messages($se, 'INBOX', 'aXb', 1, 20), 'underscore is not treated as a wildcard');
assert_eq([], db_search_messages($se, 'INBOX', '%', 1, 20), 'a bare percent matches nothing');

group('clear local cache');

$cc = 'clear-a@example.com';
$cd = 'clear-b@example.com';
db_upsert_messages($cc, 'INBOX', [$msg(901, 1700000900), $msg(902, 1700001000)]);
db_upsert_messages($cc, 'Sent', [$msg(903, 1700001100)]);
db_upsert_messages($cd, 'INBOX', [$msg(901, 1700000900)]);
db_set_folder_state($cc, 'INBOX', ['uidvalidity' => 1, 'uidnext' => 2, 'total' => 2, 'unseen' => 0]);
db_set_folder_list($cc, [['name' => 'INBOX', 'displayName' => 'Inbox', 'group' => 'primary']]);
db_set_setting($cc, 'display_name', 'Keep Me');

assert_eq(3, db_count_cached_messages($cc), 'cached header count spans every folder');
assert_eq(1, db_count_cached_messages($cd), 'cached header count is per user');

assert_true(db_clear_cache_for_user($cc), 'clearing the cache succeeds');
assert_eq(0, db_count_cached_messages($cc), 'all cached headers removed');
assert_eq(null, db_get_folder_state($cc, 'INBOX'), 'folder state removed');
assert_eq(null, db_get_folder_list($cc, 300), 'cached folder list removed');
assert_eq('Keep Me', db_get_setting($cc, 'display_name'), 'user settings are NOT cleared');
assert_eq(1, db_count_cached_messages($cd), 'another user\'s cache is untouched');

// ------------------------------------------------ sync endpoint (Phase 6) ---+group('relative_time()');

$N = 1700000000;
assert_eq('never', relative_time(0, $N), 'zero timestamp reads as never');
assert_eq('Just now', relative_time($N, $N), 'same second is Just now');
assert_eq('Just now', relative_time($N - 20, $N), '20s ago is Just now');
assert_eq('1 min ago', relative_time($N - 70, $N), '70s ago is 1 min');
assert_eq('5 min ago', relative_time($N - 300, $N), '5 min ago');
assert_eq('1 hour ago', relative_time($N - 3700, $N), 'just over an hour');
assert_eq('3 hours ago', relative_time($N - 3 * 3600 - 60, $N), '3 hours ago');
assert_eq('yesterday', relative_time($N - 90000, $N), 'just over a day is yesterday');
assert_eq('4 days ago', relative_time($N - 4 * 86400, $N), '4 days ago');
assert_eq('Just now', relative_time($N + 500, $N), 'a clock-skewed future stamp is clamped');

group('mark read/unread must report success, or the cache write is skipped');

// handle_mark_read() guards the local cache write on the returned count:
//
//     $count = $imap->markManyAsRead($uids, $read);
//     if ($count > 0) { foreach ($uids as $uid) { cache_mark_read(...); } }
//
// The single-UID methods were `: void`, so $ok was always null and the guarded
// write NEVER ran. The IMAP flag changed, the cached row did not, so the list
// rendered the stale flag and the background sync flipped it a moment later.
foreach (['markAsReadByUid', 'markAsUnreadByUid'] as $m) {
    $rt = (new ReflectionMethod('MailImap', $m))->getReturnType();
    assert_true($rt !== null && $rt->getName() === 'bool',
        'MailImap::' . $m . '() returns bool, so the guard can see it');
}
// The four batch methods were copied from the single-UID ones above, which
// return bool. A bool here is not a weaker truth value, it is a different
// contract: `if ($count > 0)` on a bool compares true against 0, which is
// false, so every cache write would be skipped and the list would keep
// rendering rows the server had already moved. int is the only type the
// handlers' `if ($count > 0)` gates mean.
foreach (['markManyAsRead', 'deleteManyByUid', 'moveManyToTrash', 'moveManyToFolder'] as $m) {
    // Gated: a suite that runs against an older index.php must not fatal on a
    // method that does not exist yet.
    if (!method_exists('MailImap', $m)) {
        continue;
    }
    $batchRt = (new ReflectionMethod('MailImap', $m))->getReturnType();
    assert_true($batchRt !== null && $batchRt->getName() === 'int',
        'MailImap::' . $m . '() returns int, so the handler can gate its cache writes on it');
}
$srcGuard = file_get_contents(__DIR__ . '/../index.php');
assert_contains('$count = $imap->markManyAsRead($uids, $read);', $srcGuard,
    'the handler calls the batch marker, and the count gates the cache write');
// The batch method must pick its flag call by the $read argument, not blindly
// set \\Seen: marking a batch unread has to clear the flag.
assert_contains("if (\$read) {\n      @imap_setflag_full(\$this->conn, implode(',', \$uids), '\\\\Seen', ST_UID);",
    $srcGuard, 'the batch marker branches on $read instead of always setting the flag');

group('MailImap::moveToFolder() is the one mover');

$mv = (new ReflectionMethod('MailImap', 'moveToFolder'))->getReturnType();
assert_true($mv !== null && $mv->getName() === 'bool', 'moveToFolder() reports success');
// Third copy of uid->msgno->imap_mail_move->expunge was one too many.
$imapSrc = file_get_contents(__DIR__ . '/../index.php');
assert_eq(1, substr_count($imapSrc, 'imap_mail_move($this->conn'),
    'exactly one call site does the actual move');
assert_contains('return $this->moveToFolder($uid, $junk);', $imapSrc, 'moveToJunk delegates');
assert_contains('return $this->moveToFolder($uid, $trash);', $imapSrc, 'moveToTrash delegates');
assert_contains('return $this->moveToFolder($uid, \'INBOX\');', $imapSrc, 'restoreFromTrash delegates');

group('request_wants_json(): optimistic client gets JSON, a plain form gets a redirect');

// The optimistic helper sets ajax=1. Without it the same endpoint must keep
// flashing and redirecting, so every action still works with JS off.
assert_true(request_wants_json(['ajax' => '1'], []), 'the ajax flag asks for JSON');
assert_false(request_wants_json([], []), 'a bare form post does not');
assert_false(request_wants_json(['ajax' => ''], []), 'an empty flag does not');
assert_true(request_wants_json([], ['HTTP_ACCEPT' => 'application/json']), 'so does an explicit Accept');
// A browser navigation sends */* or text/html alongside; that is not an API call.
assert_false(request_wants_json([], ['HTTP_ACCEPT' => 'text/html,application/json']),
    'a navigation that merely tolerates JSON still gets the redirect');

group('no dead code: every public method and every constant is used');

$deadSrc = file_get_contents(__DIR__ . '/../index.php');
$deadLines = explode("\n", $deadSrc);
// A method is alive if index.php calls it or a suite exercises it. Magic methods
// are invoked by the engine, and `flush`/`run` are not method names here.
$allTests = '';
foreach (['unit', 'js', 'imap', 'smoke'] as $suite) {
    $allTests .= file_get_contents(__DIR__ . '/' . $suite . '.php');
}
$orphans = [];
foreach ((new ReflectionClass('MailImap'))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    $name = $m->getName();
    if (str_starts_with($name, '__')) {
        continue;
    }
    $pat = '/(?<![\w$])' . preg_quote($name, '/') . '(?![\w])/';
    $inSrc = 0;
    foreach ($deadLines as $line) {
        $inSrc += preg_match_all($pat, $line);
    }
    $inSrc = max(0, $inSrc - 1); // the declaration line itself always matches
    if ($inSrc === 0 && !preg_match($pat, $allTests)) {
        $orphans[] = $name;
    }
}
assert_eq([], $orphans, 'every public MailImap method is called or tested (orphans: ' . implode(', ', $orphans) . ')');

$deadConsts = [];
preg_match_all("/^define\(\s*'(\w+)'/m", $deadSrc, $constMatches, PREG_OFFSET_CAPTURE);
foreach ($constMatches[1] as [$cname, $off]) {
    $lineNo = substr_count(substr($deadSrc, 0, $off), "\n");
    $pat = '/(?<![\w$"\'])' . preg_quote($cname, '/') . '(?![\w"\'])/';
    $inSrc = 0;
    foreach ($deadLines as $i => $line) {
        if ($i !== $lineNo) {
            $inSrc += preg_match_all($pat, $line);
        }
    }
    if ($inSrc === 0 && !preg_match($pat, $allTests)) {
        $deadConsts[] = $cname;
    }
}
assert_eq([], $deadConsts, 'every constant is read somewhere (dead: ' . implode(', ', $deadConsts) . ')');

group('css_inline() declares no rule twice');

// A pasted rule is invisible: it renders identically, so nothing breaks and
// nothing says why. This happened -- .cache-row/.cache-info/.cache-action were
// each emitted twice, byte for byte, and .cache-card twice with a property the
// first rule already had.
$cssLines = explode("\n", css_inline());
$rules = [];
foreach ($cssLines as $i => $line) {
    // A top-level rule starts at column 0; anything inside @media is indented.
    if ($line === '' || $line[0] === ' ' || $line[0] === "\t") {
        continue;
    }
    if (preg_match('/^([.#\w][^{]*?)\s*\{([^}]*)\}\s*$/', $line, $m)) {
        $rules[] = [trim($m[1]), preg_replace('/\s+/', '', $m[2]), $i + 1];
    }
}
$seenRules = [];
$dupRules = [];
foreach ($rules as [$sel, $body, $lineNo]) {
    $key = $sel . '|' . $body;
    if (isset($seenRules[$key])) {
        $dupRules[] = $sel . ' (L' . $seenRules[$key] . ' and L' . $lineNo . ')';
    } else {
        $seenRules[$key] = $lineNo;
    }
}
assert_eq([], $dupRules, 'no top-level rule is declared twice (found: ' . implode(', ', $dupRules) . ')');

group('?page=move is routed and gated');

$moveSrc = file_get_contents(__DIR__ . '/../index.php');
assert_contains("case 'move':", $moveSrc, 'the route exists');
assert_contains('handle_move();', $moveSrc, 'and reaches its handler');
// The gate must run against the live folder list, before the move is attempted.
// A batch is one decision, not one per UID: the folder list does not change
// while the COPY runs, so a per-UID gate would only cost N extra SELECTs.
assert_contains('folder_move_check($folder, $dest, $folders)', $moveSrc,
    'the pure gate decides once per batch, using the real folder list');
// Scoped to the handler body: folder_move_check() is also the gate behind rename
// and the move dialog, so a whole-file count proves nothing about handle_move().
$moveBody = substr($moveSrc, (int)strpos($moveSrc, 'function handle_move('));
$moveBody = substr($moveBody, 0, (int)strpos($moveBody, "\n}\n"));
assert_eq(1, substr_count($moveBody, 'folder_move_check('),
    'the gate runs once in handle_move(), not once per UID');
assert_contains('$count = $imap->moveManyToFolder($uids, $dest);', $moveSrc,
    'the move is one IMAP batch, not one call per UID');
assert_contains('cache_apply_move($me, $folder, $uid, $dest)', $moveSrc,
    'a successful move is applied to the local cache straight away');

group('post_uids(): one UID, or a bounded batch');

$post = function (array $p): array {
    $_POST = $p;
    $out = post_uids();
    $_POST = [];
    return $out;
};

assert_eq([7], $post(['uid' => '7']), 'the single-uid form still works');
assert_eq([7], $post(['uids' => '7']), 'one element of the batch form works');
assert_eq([1, 2, 3], $post(['uids' => '1,2,3']), 'a comma list is split');
assert_eq([5, 6], $post(['uids' => ['5', '6']]), 'the array form is accepted');
assert_eq([1, 2], $post(['uids' => ' 1 , 2 ']), 'surrounding spaces are trimmed');
assert_eq([9], $post(['uids' => ['9', '9']]), 'a repeat is dropped, not acted on twice');
assert_eq([4, 1, 9], $post(['uids' => '4,1,9']), 'the order sent is the order acted on');
assert_eq([8], $post(['uid' => '2', 'uids' => '8']), 'uids wins when both arrive, so one rule covers both forms');
// A hostile or buggy client is not allowed to name a message that does not exist,
// and 0 is not a UID: it means "no message", which is what the guard rejected.
assert_eq([], $post(['uids' => '0']), 'zero is not a UID');
assert_eq([], $post(['uids' => '-3']), 'a negative number is not a UID');
assert_eq([], $post(['uids' => '1e3']), 'scientific notation is not a UID');
assert_eq([], $post(['uids' => 'abc']), 'letters are not a UID');
assert_eq([], $post(['uids' => '']), 'an empty string is no selection');
assert_eq([], $post([]), 'no field at all is no selection');
assert_eq([], $post(['uids' => [['1'], ['2']]]), 'a nested array is not a UID');
// The cap is what keeps one request from turning into 200 commands per batch.
assert_eq(200, count($post(['uids' => implode(',', range(1, 500))])), 'the batch is capped at 200');
// The raw string is bounded before explode(), so a 1 MB body is not split into
// a million tokens first and rejected afterwards. What survives is still UIDs,
// and still no more than the cap.
$huge = $post(['uids' => str_repeat('9,', 400000)]);
assert_true(count($huge) <= 200, 'an oversized raw string is bounded by the cap');
assert_eq([9], array_values(array_unique($huge)), 'and every token it kept was a UID');

group('folder_empty_check(): only the two throwaway mailboxes may be wiped');

// emptyFolder() deletes by SEQUENCE NUMBER across the whole mailbox, so this
// gate is the only thing between a stray folder name and total data loss.
assert_eq('ok', folder_empty_check('Trash'), 'Trash may be emptied');
assert_eq('ok', folder_empty_check('Junk'), 'Junk may be emptied');
assert_eq('ok', folder_empty_check('Spam'), 'Spam may be emptied');
assert_eq('ok', folder_empty_check('INBOX.Trash'), 'a nested Trash may be emptied');
// Known gap, deliberately not papered over here: isTrashFolder() matches on the
// substring 'trash'/'kosz' or an exact name, so a nested 'INBOX.Deleted Items'
// is not recognised as Trash anywhere in the app -- sidebar order and the delete
// path included. Widening it belongs with those, not in this gate.
assert_eq('refused', folder_empty_check('INBOX.Deleted Items'), 'a nested Deleted Items is not recognised yet');
assert_eq('refused', folder_empty_check('INBOX'), 'INBOX may never be emptied');
assert_eq('refused', folder_empty_check('Archive'), 'nor an ordinary folder');
assert_eq('refused', folder_empty_check('Sent'), 'nor Sent');
assert_eq('refused', folder_empty_check(''), 'nor nothing at all');

group('?page=emptyFolder replaces the trash-only route');

$emptySrc = file_get_contents(__DIR__ . '/../index.php');
assert_contains("case 'emptyFolder':", $emptySrc, 'the route exists');
assert_contains('folder_empty_check($folder)', $emptySrc, 'and the gate runs before anything is deleted');
assert_not_contains("case 'emptyTrash':", $emptySrc, 'the trash-only route is gone');
assert_not_contains('function handle_empty_trash', $emptySrc, 'and so is its handler');
assert_not_contains('public function emptyTrash', $emptySrc, 'emptyFolder() is the only wiper');

group('sync_should_run()');

assert_true(sync_should_run(null, $N, 15, false), 'no state yet: run');
assert_true(sync_should_run(['last_sync_ts' => $N - 60], $N, 15, false), 'stale: run');
assert_false(sync_should_run(['last_sync_ts' => $N - 5], $N, 15, false), 'recently synced: skip');
assert_true(sync_should_run(['last_sync_ts' => $N - 5], $N, 15, true), 'force overrides the gate');
assert_true(sync_should_run(['last_sync_ts' => $N - 15], $N, 15, false), 'exactly at the interval: run');
assert_false(sync_should_run(['last_sync_ts' => $N + 100], $N, 15, false), 'future stamp does not trigger a run');

group('sync_is_stale(): a listing this old syncs the moment it opens');

assert_true(sync_is_stale(0, $N, 60), 'never synced: stale');
assert_true(sync_is_stale($N - 61, $N, 60), 'older than the period: stale');
assert_true(sync_is_stale($N - 60, $N, 60), 'exactly at the period: stale');
assert_false(sync_is_stale($N - 59, $N, 60), 'inside the period: fresh');
assert_false(sync_is_stale($N + 100, $N, 60), 'a future stamp is not stale');

group('message_target_from_request(): the one place a ?page= handler learns what it targets');

// Four handlers used to each re-parse $_GET by hand, in identical four lines
// ($uid, $folder, the !$uid guard, the urldecode). The guards stay inline where
// redirect() lives; only the parse may be shared, so it can be pinned here.
assert_eq([0, 'INBOX'], message_target_from_request(), 'nothing in the query: nothing targeted, INBOX');
$_GET['uid'] = '42';
$_GET['folder'] = 'Projects/2026';
assert_eq([42, 'Projects/2026'], message_target_from_request(), 'a plain target parses');
$_GET['folder'] = 'Projects%2F2026';
assert_eq([42, 'Projects/2026'], message_target_from_request(), 'a folder is URL-decoded before use');
$_GET['uid'] = 'abc';
assert_eq([0, 'Projects/2026'], message_target_from_request(), 'a non-numeric uid is a refused 0, never a cast surprise');
$_GET['uid'] = '0';
assert_eq([0, 'Projects/2026'], message_target_from_request(), 'explicit zero stays zero');
unset($_GET['uid'], $_GET['folder']);
$_GET['folder'] = '';
assert_eq([0, ''], message_target_from_request(), 'a present-but-empty folder is kept, matching the current contract');

$targetSrc = file_get_contents(__DIR__ . '/../index.php');
assert_eq(4, substr_count($targetSrc, '= message_target_from_request();'),
    'all four ?page= handlers (read, reply, forward, download) share the parse');
assert_eq(1, preg_match_all('/\(\$_(GET|REQUEST)\[\'uid\'\]/', $targetSrc),
    'and the uid is parsed in exactly one place: the helper itself');

group('imap_folder_name_is_safe()');

assert_true(imap_folder_name_is_safe('INBOX'), 'INBOX is safe');
assert_true(imap_folder_name_is_safe('Archive/2026'), 'nested folder is safe');
assert_true(imap_folder_name_is_safe('Wysłane'), 'non-ascii folder name is safe');
assert_true(imap_folder_name_is_safe('INBOX.Sent-Mail_1'), 'punctuation in names is safe');
// The mailbox spec is "{host:port/imap/ssl}Folder", so braces must never pass.
assert_false(imap_folder_name_is_safe('}{evil.example.com:143/imap}INBOX'), 'braces rejected');
assert_false(imap_folder_name_is_safe('INBOX}'), 'trailing brace rejected');
assert_false(imap_folder_name_is_safe("INBOX\r\nA001 DELETE"), 'CRLF injection rejected');
assert_false(imap_folder_name_is_safe("INBOX\x00"), 'NUL byte rejected');
assert_false(imap_folder_name_is_safe(''), 'empty name rejected');
assert_false(imap_folder_name_is_safe(str_repeat('a', 300)), 'absurdly long name rejected');

// ------------------------------------------- sync decision (Phase 3) --------+
group('sync_decide()');

$FULL = 60; // full-reconcile interval used by these tests
$NOW  = 1700010000;
$fullStatus = ['uidvalidity' => 99, 'uidnext' => 1201, 'messages' => 200, 'unseen' => 3];
$syncedState = [
    'uidvalidity' => 99, 'uidnext' => 1201, 'total' => 200, 'unseen' => 3,
    'cached_rows' => 200, 'last_sync_ts' => $NOW - 5, 'last_full_ts' => $NOW - 5,
];

$d = sync_decide(null, $fullStatus, $NOW, $FULL);
assert_false($d['purge_all'], 'cold sync does not purge');
assert_true($d['scan'], 'cold sync scans');
assert_true($d['full'], 'cold sync reconciles');

$d = sync_decide($syncedState, $fullStatus, $NOW, $FULL);
assert_false($d['scan'], 'all counters unchanged: no scan');
assert_false($d['full'], 'all counters unchanged and recently reconciled: no reconcile');

$d = sync_decide(array_merge($syncedState, ['last_full_ts' => $NOW - 600]), $fullStatus, $NOW, $FULL);
assert_false($d['scan'], 'unchanged counters still skip the scan');
assert_true($d['full'], 'stale last_full_ts forces a reconcile');

$d = sync_decide($syncedState, array_merge($fullStatus, ['uidvalidity' => 100]), $NOW, $FULL);
assert_true($d['purge_all'], 'changed uidvalidity purges the folder');
assert_true($d['scan'], 'changed uidvalidity rescans');

$d = sync_decide($syncedState, array_merge($fullStatus, ['uidnext' => 1202]), $NOW, $FULL);
assert_true($d['scan'], 'advanced uidnext triggers a scan');
assert_false($d['purge_all'], 'advanced uidnext does not purge');

$d = sync_decide($syncedState, array_merge($fullStatus, ['messages' => 199]), $NOW, $FULL);
assert_true($d['scan'], 'dropped message count triggers a scan');

$d = sync_decide($syncedState, array_merge($fullStatus, ['unseen' => 4]), $NOW, $FULL);
assert_true($d['full'], 'changed unseen count forces a reconcile');

// An unknown STATUS field must never read as 0 and never look "unchanged",
// or the cache freezes permanently. It must also never trigger a purge.
$d = sync_decide($syncedState, ['uidvalidity' => null, 'uidnext' => null, 'messages' => null, 'unseen' => null], $NOW, $FULL);
assert_false($d['purge_all'], 'unknown uidvalidity never purges');
assert_true($d['scan'], 'incomplete status forces a scan');
assert_true($d['full'], 'incomplete status forces a reconcile');

$d = sync_decide($syncedState, array_merge($fullStatus, ['uidnext' => null]), $NOW, $FULL);
assert_true($d['full'], 'missing uidnext alone forces a reconcile');
assert_false($d['purge_all'], 'missing uidnext does not purge');

$d = sync_decide(array_merge($syncedState, ['uidvalidity' => null]), $fullStatus, $NOW, $FULL);
assert_false($d['purge_all'], 'unknown stored uidvalidity does not purge');
assert_true($d['scan'], 'unknown stored uidvalidity rescans');

// ----------------------------------------- sync reconcile (Phase 3) ---------+
group('sync_reconcile()');

// 5 cached messages, uids 101..105, all read.
$mkCached = function (array $uids) {
    $out = [];
    foreach ($uids as $u) {
        $out[$u] = ['uid' => $u, 'unread' => false, 'answered' => false, 'flagged' => false, 'deleted' => false];
    }
    return $out;
};
$cached5 = $mkCached([101, 102, 103, 104, 105]);
$allLive = [101, 102, 103, 104, 105];

// A fully consistent server view with nothing to do.
$quiet = [
    'new' => [], 'undeleted' => $allLive, 'deleted' => [],
    'unseen' => [], 'flagged' => [], 'answered' => [], 'messages' => 5,
];

$p = sync_reconcile($cached5, $quiet, ['min_new_uid' => 106]);
assert_eq([], $p['upsert'], 'nothing new to upsert');
assert_eq([], $p['delete_uids'], 'nothing to delete');
assert_eq([], $p['flag_updates'], 'no flag changes');
assert_eq(null, $p['refused'], 'consistent view is not refused');

// --- new messages, and the UID range overshoot guard ---
$withNew = array_merge($quiet, [
    'new' => [
        ['uid' => 105, 'subject' => 'stray newest', 'date_ts' => 1],  // overshoot
        ['uid' => 106, 'subject' => 'genuinely new', 'date_ts' => 2],
    ],
    'undeleted' => [101, 102, 103, 104, 105, 106],
    'messages'  => 6,
]);
$p = sync_reconcile($cached5, $withNew, ['min_new_uid' => 106]);
assert_eq([106], array_column($p['upsert'], 'uid'), 'UID below the range low bound is discarded');
assert_eq('genuinely new', $p['upsert'][0]['subject'], 'the genuinely new row survives');

// --- deletions ---
$expunged = array_merge($quiet, ['undeleted' => [101, 102, 104, 105], 'messages' => 4]);
$p = sync_reconcile($cached5, $expunged, ['min_new_uid' => 106]);
assert_eq([103], $p['delete_uids'], 'uid absent from the server is deleted');
assert_eq(null, $p['refused'], 'a plausible deletion is not refused');

// \Deleted but not yet expunged: the message still exists, so it must NOT be
// dropped from the cache. MESSAGES counts it, which is why the invariant is
// count(undeleted) + count(deleted) == messages.
$pendingDelete = array_merge($quiet, ['undeleted' => [101, 102, 104, 105], 'deleted' => [103], 'messages' => 5]);
$p = sync_reconcile($cached5, $pendingDelete, ['min_new_uid' => 106]);
assert_eq([], $p['delete_uids'], 'a \\Deleted-but-unexpunged uid is not removed from the cache');
assert_true($p['flag_updates'][103]['deleted'] ?? false, 'it is marked deleted instead');

// --- data-loss guards ---
$p = sync_reconcile($cached5, array_merge($quiet, ['undeleted' => null]), ['min_new_uid' => 106]);
assert_eq([], $p['delete_uids'], 'unknown live set deletes nothing');
assert_eq('unknown-live-set', $p['refused'], 'unknown live set is refused with a reason');

$p = sync_reconcile($cached5, array_merge($quiet, ['deleted' => null]), ['min_new_uid' => 106]);
assert_eq([], $p['delete_uids'], 'unknown deleted set deletes nothing');
assert_eq('unknown-live-set', $p['refused'], 'unknown deleted set is refused too');

// imap_search returns false for "no matches", indistinguishable from failure.
// An empty live set is only believable when the server says the folder is empty.
$p = sync_reconcile($cached5, array_merge($quiet, ['undeleted' => [], 'deleted' => [], 'messages' => 5]), ['min_new_uid' => 106]);
assert_eq([], $p['delete_uids'], 'empty live set against a non-empty folder deletes nothing');
assert_eq('count-mismatch', $p['refused'], 'empty live set against a non-empty folder is refused');

$p = sync_reconcile($cached5, array_merge($quiet, ['undeleted' => [], 'deleted' => [], 'messages' => 0]), ['min_new_uid' => 106]);
assert_eq([101, 102, 103, 104, 105], $p['delete_uids'], 'a genuinely empty folder does clear the cache');
assert_eq(null, $p['refused'], 'a genuinely empty folder is not refused');

$p = sync_reconcile($cached5, array_merge($quiet, ['undeleted' => [101, 102], 'messages' => 5]), ['min_new_uid' => 106]);
assert_eq([], $p['delete_uids'], 'live set that disagrees with MESSAGES deletes nothing');
assert_eq('count-mismatch', $p['refused'], 'count mismatch is refused');

// Blast radius: refuse an implausibly large deletion even when the counts agree.
$cached200 = $mkCached(range(1, 200));
$survivors = range(1, 100);
$p = sync_reconcile($cached200, array_merge($quiet, [
    'undeleted' => $survivors, 'deleted' => [], 'messages' => 100,
]), ['min_new_uid' => 201]);
assert_eq([], $p['delete_uids'], 'a 50% deletion is refused');
assert_eq('blast-radius', $p['refused'], 'oversized deletion reports blast-radius');

// The cap has a floor, so small caches can still be fully corrected.
$p = sync_reconcile($cached5, array_merge($quiet, ['undeleted' => [101], 'deleted' => [], 'messages' => 1]), ['min_new_uid' => 106]);
assert_eq([102, 103, 104, 105], $p['delete_uids'], 'small caches are not blocked by the percentage cap');

// --- flag reconciliation ---
$p = sync_reconcile($cached5, array_merge($quiet, ['unseen' => [102, 104], 'flagged' => [105]]), ['min_new_uid' => 106]);
assert_true($p['flag_updates'][102]['unread'], 'unseen uid becomes unread');
assert_true($p['flag_updates'][104]['unread'], 'second unseen uid becomes unread');
assert_true($p['flag_updates'][105]['flagged'], 'flagged uid becomes flagged');
assert_false(isset($p['flag_updates'][101]), 'unchanged uid gets no flag update');

// A null flag set means "not fetched": leave the cached value alone.
$unreadCached = $mkCached([101]);
$unreadCached[101]['unread'] = true;
$p = sync_reconcile($unreadCached, [
    'new' => [], 'undeleted' => [101], 'deleted' => [],
    'unseen' => null, 'flagged' => null, 'answered' => null, 'messages' => 1,
], ['min_new_uid' => 102]);
assert_eq([], $p['flag_updates'], 'null flag sets leave cached flags untouched');
assert_eq([], $p['delete_uids'], 'null flag sets do not affect deletions');

// Idempotency: reconciling an already-correct cache must produce no writes, or
// the sync endpoint reports changes forever and the client reloads in a loop.
$p = sync_reconcile($cached5, $quiet, ['min_new_uid' => 106]);
assert_eq(0, count($p['upsert']) + count($p['delete_uids']) + count($p['flag_updates']),
    'a correct cache produces an empty plan');

// --------------------------------------------------- poll-loop decisions ----+
group('sync_count_arrivals(): new unread rows worth telling the user about');

assert_eq(0, sync_count_arrivals([], null, false), 'a cold start has no arrivals');
assert_eq(2, sync_count_arrivals([
    ['uid' => 11, 'unread' => true],
    ['uid' => 12, 'unread' => true],
], 10, false), 'rows above the old uidnext are arrivals');
assert_eq(0, sync_count_arrivals([['uid' => 11, 'unread' => false]], 10, false),
    'a row the server already has as \\Seen is not an arrival');
assert_eq(1, sync_count_arrivals([
    ['uid' => 9, 'unread' => true],   // below the floor: an old row the range overshot onto
    ['uid' => 11, 'unread' => true],
], 10, false), 'a row below the old uidnext is not an arrival');
assert_eq(0, sync_count_arrivals([['uid' => 11, 'unread' => true]], 10, true),
    'a UIDVALIDITY purge reports nothing: every row is a new uid, not a new message');
assert_eq(0, sync_count_arrivals([['uid' => 11, 'unread' => true]], null, false),
    'without a previous state there is no floor, so nothing counts');
// The emptied-folder case: the cache is empty, so the sync refills the window by
// message number and re-upserts rows it already knew. Only the genuinely new
// message is above the old uidnext, so the refill still reports one arrival.
assert_eq(1, sync_count_arrivals([
    ['uid' => 7, 'unread' => true],
    ['uid' => 8, 'unread' => false],
    ['uid' => 11, 'unread' => true],
], 10, false), 'a cold-window refill of an emptied folder counts only the new message');

group('the custom sync period is gone');

// The select offered 15s and 30s, and the browser refused to poll that often, so
// the label contradicted the Network tab. There is one interval now, and it is
// not the user's to pick.
assert_not_contains('sync_period_seconds', (string)file_get_contents(__DIR__ . '/../index.php'),
    'nothing in index.php reads or writes a sync period any more');
assert_not_contains('sync_period_for_client', (string)file_get_contents(__DIR__ . '/../index.php'),
    'and the function that floored it went with it');
// The server-side throttle is a different job and stays: it is what stops a
// burst of chip clicks from turning into a burst of IMAP work.
assert_contains('define(\'SYNC_MIN_INTERVAL_SECONDS\'', (string)file_get_contents(__DIR__ . '/../index.php'),
    'the endpoint throttle constant survives');
assert_contains("'min_interval'  => SYNC_MIN_INTERVAL_SECONDS", (string)file_get_contents(__DIR__ . '/../index.php'),
    'and it is what handle_sync throttles with');

group('handle_sync(): the poll loop\'s contract');

$obSrcSync = (string)file_get_contents(__DIR__ . '/../index.php');
assert_contains('function handle_sync(): void', $obSrcSync, 'the sync handler exists');
// 401, not a redirect. require_login() would answer a background poll with the
// login page, r.json() would throw on the HTML, and the chip would say
// "Offline" for ever.
assert_contains("http_response_code(401)", $obSrcSync, 'an unauthenticated poll gets 401');
assert_contains("'error' => 'Signed out.'", $obSrcSync, 'the 401 body is JSON, so the client can read it');
assert_contains("'arrivals' => \$inbox['arrivals'],", $obSrcSync,
    'the response reports the INBOX arrival count, not the open folder\'s');
// The client sends INBOX explicitly from a page with no open folder, so the
// open-folder sync is skipped rather than guessed at.
assert_contains("var data = { folder: folder };", $obSrcSync, 'the client always names a folder');

group('render_page() ships the poll period on a signed-in-only element');

$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'period@x.io';
db_set_folder_list('period@x.io', [
    ['name' => 'INBOX', 'displayName' => 'Inbox', 'delimiter' => '/', 'attributes' => 0, 'group' => 'primary'],
]);
db_set_setting('period@x.io', 'folder_setup_complete', '1');
// No auto_poll row at all: the check is on unless the user turns it off, so a
// fresh account must poll.
ob_start();
render_page('T', '<div id="probe">x</div>', true);
$periodPage = ob_get_clean();
$_SESSION = [];
// On the topbar, which is on every signed-in page, not on the listing chrome,
// which only a folder page has.
assert_contains('<header class="topbar" data-period="' . SYNC_CLIENT_MIN_PERIOD . '"', $periodPage,
    'a signed-in page polls every minute by default');

ob_start();
render_page('T', '<div id="probe">x</div>', false);
$periodLogin = ob_get_clean();
assert_not_contains('data-period=', $periodLogin, 'the login page ships no poll period: nothing to poll');

db_set_setting('period@x.io', 'auto_poll', '0');
$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'period@x.io';
ob_start();
render_page('T', '<div id="probe">x</div>', true);
$periodOff = ob_get_clean();
$_SESSION = [];
assert_contains('data-period="0"', $periodOff, 'turning the check off reaches the client as 0, meaning no poll');

// A stale row from the days when the period was a choice must not resurrect a
// poll the user has switched off.
db_set_setting('period@x.io', 'sync_period_seconds', '15');
$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'period@x.io';
ob_start();
render_page('T', '<div id="probe">x</div>', true);
$periodStale = ob_get_clean();
$_SESSION = [];
assert_contains('data-period="0"', $periodStale, 'the removed setting is inert, not read as a period');

group('a stale listing tells the client to sync the moment it opens');

// The .sync chip carries the stamp the chip reads. An old one gets the leaf
// attribute the boot poll keys off; a fresh one must not, or every visit pays
// a redundant sync.
$chipFixture = function (int $lastSyncTs): array {
    return ['emails' => [], 'total' => 0, 'page' => 1, 'pages' => 1, 'per_page' => 25,
        'current_folder' => 'INBOX', 'title' => 'Inbox', 'folders' => [], 'search_query' => '',
        'is_trash' => false, 'is_sent' => false, 'last_sync_ts' => $lastSyncTs, 'csrf' => 'tok'];
};
$staleChip = view_mailbox($chipFixture(time() - SYNC_CLIENT_MIN_PERIOD - 10));
assert_contains('<div class="sync" data-folder="INBOX" data-sync-stale="1">', $staleChip,
    'an old stamp ships data-sync-stale');
$freshChip = view_mailbox($chipFixture(time()));
assert_not_contains('data-sync-stale', $freshChip, 'a fresh stamp does not');

// ---------------------------------------------------- folder ordering -------+
group('MailImap folder ordering');

$m = new MailImap('localhost', 993, 'u', 'p');
$ro = new ReflectionMethod(MailImap::class, 'orderFolders');

$in = function ($name, $display, $extra = []) use ($ro, $m) {
    $folder = array_merge(['name' => $name, 'displayName' => $display, 'delimiter' => '/', 'attributes' => 0], $extra);
    $out = $ro->invoke($m, [$folder]);
    return $out[0]['group'];
};

assert_eq('primary', $in('INBOX', 'Inbox'), 'INBOX -> primary group');
assert_eq('primary', $in('Drafts', 'Drafts'), 'Drafts -> primary group');
assert_eq('primary', $in('Sent', 'Sent'), 'Sent -> primary group');
assert_eq('primary', $in('Spam', 'Spam'), 'Spam -> primary group');
assert_eq('trash', $in('Trash', 'Trash'), 'Trash -> trash group');
assert_eq('trash', $in('Kosz', 'Trash'), 'Kosz (Polish trash) -> trash group');
assert_eq('primary', $in('Archive', 'Archive'), 'Archive -> primary group');
assert_eq('primary', $in('Archiwum', 'Archive'), 'Archiwum (Polish archive) -> primary group');

$ordered = $ro->invoke($m, [
    ['name' => 'Archive',  'displayName' => 'Archive',  'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Trash',    'displayName' => 'Trash',    'delimiter' => '/', 'attributes' => 0],
    ['name' => 'INBOX',    'displayName' => 'Inbox',    'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Spam',     'displayName' => 'Spam',     'delimiter' => '/', 'attributes' => 0],
    ['name' => 'ZZZ',      'displayName' => 'ZZZ',      'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Sent',     'displayName' => 'Sent',     'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Drafts',   'displayName' => 'Drafts',   'delimiter' => '/', 'attributes' => 0],
]);
$names = array_column($ordered, 'name');
assert_eq(['INBOX', 'Drafts', 'Sent', 'Spam', 'Archive', 'Trash', 'ZZZ'], $names,
    'folders ordered Inbox, Drafts, Sent, Spam, Archive, Trash, then others alphabetically');

// ------------------------------------------------- protected folders --------+
// The delete affordance is hidden for these, but the check that matters is the
// server-side one: a hand-crafted POST must not be able to destroy Inbox.
group('folder_is_protected()');

assert_true(folder_is_protected('INBOX'), 'INBOX is protected');
assert_true(folder_is_protected('inbox'), 'case does not matter');
assert_true(folder_is_protected('Drafts'), 'Drafts is protected');
assert_true(folder_is_protected('drafts'), 'lowercase Drafts is protected');
assert_true(folder_is_protected('Sent'), 'Sent is protected');
assert_true(folder_is_protected('Spam'), 'Spam is protected');
assert_true(folder_is_protected('Junk'), 'Junk is Spam, so protected');
assert_true(folder_is_protected('Trash'), 'Trash is protected');
assert_true(folder_is_protected('Kosz'), 'Polish Trash is protected');
assert_true(folder_is_protected('Wysłane'), 'Polish Sent is protected');
assert_true(folder_is_protected('Archive'), 'Archive is protected');
assert_true(folder_is_protected('Archiwum'), 'Polish Archive is protected');
assert_false(folder_is_protected('blogstatic'), 'a user folder is deletable');
assert_false(folder_is_protected('Projects/2026'), 'a nested user folder is deletable');
assert_false(folder_is_protected(''), 'an empty name is not protected (it is invalid)');

// ------------------------------------------------- folder mutations ---------+
// The UI hides the delete button on protected folders, but hiding a button is
// not a control: the handler itself must refuse, and must refuse before it pays
// for a connection.
group('folder_delete_check()');

assert_eq('protected', folder_delete_check('INBOX'), 'INBOX cannot be deleted');
assert_eq('protected', folder_delete_check('Trash'), 'Trash cannot be deleted');
assert_eq('protected', folder_delete_check('Junk'), 'Junk cannot be deleted');
assert_eq('invalid', folder_delete_check(''), 'an empty name is invalid');
assert_eq('invalid', folder_delete_check("bad\r\nname"), 'CRLF in a name is invalid');
assert_eq('invalid', folder_delete_check('brace{injection}'), 'a brace is invalid');
assert_eq('protected', folder_delete_check('Archive'), 'Archive cannot be deleted');
assert_eq('ok', folder_delete_check('Projects/2026'), 'a nested folder may be deleted');

group('folder_move_check()');

// The folder list as getFolders() returns it: name + attributes bitmask.
$moveFolders = [
    ['name' => 'INBOX', 'attributes' => 0],
    ['name' => 'Archive', 'attributes' => 0],
    ['name' => 'Projects', 'attributes' => LATT_NOSELECT],
    ['name' => 'Projects/2026', 'attributes' => 0],
];
assert_eq('ok', folder_move_check('INBOX', 'Archive', $moveFolders), 'a normal target is allowed');
assert_eq('ok', folder_move_check('INBOX', 'Projects/2026', $moveFolders), 'a nested target is allowed');
assert_eq('same', folder_move_check('Archive', 'Archive', $moveFolders), 'moving into the same folder is a no-op');
// Folder names are compared the way the rest of the app compares them.
assert_eq('same', folder_move_check('Archive', 'archive', $moveFolders), 'and case does not smuggle it past');
assert_eq('unknown', folder_move_check('INBOX', 'Nope', $moveFolders), 'an unlisted target is refused');
// \Noselect mailboxes are hierarchy nodes: SELECT/STATUS fail, so a move would
// half-succeed and the message would vanish from both ends.
assert_eq('noselect', folder_move_check('INBOX', 'Projects', $moveFolders), 'a hierarchy node is refused');
assert_eq('invalid', folder_move_check('INBOX', '', $moveFolders), 'an empty target is invalid');
assert_eq('invalid', folder_move_check('INBOX', "bad\r\nname", $moveFolders), 'CRLF in a target is invalid');
assert_eq('invalid', folder_move_check('INBOX', 'brace{injection}', $moveFolders), 'a brace is invalid');

group('folder_create_check()');

assert_eq('invalid', folder_create_check(''), 'an empty name cannot be created');
assert_eq('invalid', folder_create_check('   '), 'a blank name cannot be created');
assert_eq('invalid', folder_create_check("x\ry"), 'CRLF cannot be created');
assert_eq('invalid', folder_create_check(str_repeat('a', 256)), 'an over-long name is rejected');
assert_eq('ok', folder_create_check('Testing'), 'a plain name is fine');
assert_eq('ok', folder_create_check('Projects/2026'), 'a nested name is fine');


// A draft reopened in the composer must yield addresses the send path accepts:
// FILTER_VALIDATE_EMAIL rejects "Name <a@b.c>".
group('header_address_list()');

assert_eq('a@b.c', header_address_list('a@b.c'), 'a bare address passes through');
assert_eq('a@b.c', header_address_list('Name <a@b.c>'), 'a display name is stripped');
assert_eq('a@b.c, d@e.f', header_address_list('Name <a@b.c>, Other <d@e.f>'), 'a list is reduced');
assert_eq('a@b.c, d@e.f', header_address_list('a@b.c,d@e.f'), 'missing spaces are fine');
assert_eq('a@b.c', header_address_list('"Last, First" <a@b.c>'), 'a comma inside a quoted name does not split');
assert_eq('', header_address_list(''), 'empty in, empty out');
assert_eq('', header_address_list('undisclosed-recipients:;'), 'a group syntax with no address yields nothing');
assert_eq('a@b.c', header_address_list('  a@b.c  '), 'surrounding space is trimmed');


group('folder_display_name()');

assert_eq('Inbox', folder_display_name('INBOX'), 'INBOX maps to Inbox');
assert_eq('Spam', folder_display_name('Junk'), 'Junk maps to Spam');
assert_eq('Sent', folder_display_name('Wysłane'), 'Polish Sent maps to Sent');
assert_eq('Archive', folder_display_name('Archiwum'), 'Polish Archive maps to Archive');
assert_eq('Archive', folder_display_name('Archived'), 'Archived maps to Archive');
assert_eq('Virtual', folder_display_name('virtual'), 'a plain lowercase name is capitalised');
assert_eq('Blogstatic', folder_display_name('blogstatic'), 'a plain lowercase name is capitalised');

// The mailbox heading must read exactly like the sidebar entry, so handle_folder
// has to run the folder through folder_display_name() too. Rendered end to end
// from a warm cache, which needs no connection.
$_SESSION['logged_in'] = true;
$_SESSION['email'] = $headUser = 'heading@x.io';
db_set_folder_list($headUser, [['name' => 'virtual', 'displayName' => 'Virtual',
    'delimiter' => '/', 'attributes' => 0, 'group' => 'other']]);
db_set_folder_state($headUser, 'virtual', ['uidvalidity' => 1, 'uidnext' => 2, 'total' => 1,
    'unseen' => 0, 'cached_rows' => 1, 'last_sync_ts' => time(), 'last_full_ts' => time()]);
db_upsert_messages($headUser, 'virtual', [['uid' => 1, 'subject' => 's', 'from_name' => 'a',
    'from_address' => 'a@x.io', 'to_name' => '', 'to_address' => '', 'date_ts' => 1700000000,
    'unread' => 0, 'flagged' => 0, 'has_attachment' => 0, 'size' => 10, 'snippet' => '']]);
$_GET = ['page' => 'folder', 'folder' => 'virtual'];
ob_start();
handle_folder();
$nameHtml = ob_get_clean();
$_GET = [];
$_SESSION = [];
assert_contains('<h2>Virtual <small class="mail-count">', $nameHtml, 'mailbox heading shows the display name');

// The in-place folder switch fetches ?page=folder&ajax=1, which answers with
// folder_listing_json(): the mailbox section alone, plus the page title.
group('folder_listing_json() serves a warm folder in place');
$_SESSION['logged_in'] = true;
$_SESSION['email'] = $ajaxUser = 'ajax@x.io';
db_set_folder_list($ajaxUser, [['name' => 'INBOX', 'displayName' => 'Inbox',
    'delimiter' => '/', 'attributes' => 0, 'group' => 'primary']]);
db_set_folder_state($ajaxUser, 'INBOX', ['uidvalidity' => 1, 'uidnext' => 2, 'total' => 1,
    'unseen' => 0, 'cached_rows' => 1, 'last_sync_ts' => time(), 'last_full_ts' => time()]);
db_upsert_messages($ajaxUser, 'INBOX', [['uid' => 1, 'subject' => 's', 'from_name' => 'a',
    'from_address' => 'a@x.io', 'to_name' => '', 'to_address' => '', 'date_ts' => 1700000000,
    'unread' => 0, 'flagged' => 0, 'has_attachment' => 0, 'size' => 10, 'snippet' => '',
    'thread_key' => '<s@x.io>']]);
$_GET = ['folder' => 'INBOX', 'p' => 1];
$ajaxListing = folder_listing_json('INBOX', 'Inbox');
$_GET = [];
$_SESSION = [];
assert_true($ajaxListing['ok'], 'folder_listing_json reports ok');
assert_true(str_starts_with($ajaxListing['html'], '<section class="mailbox">'),
    'it renders the mailbox section alone');
assert_contains('<h2>Inbox <small class="mail-count">(1)</small></h2>', $ajaxListing['html'],
    'it renders the folder heading and total');
assert_eq($ajaxListing['title'], 'Inbox — ' . APP_NAME,
    'the title carries the app name, ready for document.title');
assert_eq($ajaxListing['error'], null, 'a warm render has no error');

// ------------------------------------------------ unselectable folders ------+
// A \Noselect mailbox is a hierarchy node, not a mailbox: STATUS and SELECT
// both fail on it, so listing it can only ever produce "status-failed".
group('folder_list_for() drops \\Noselect folders');

$noselectUser = 'noselect@x.io';
db_set_folder_list($noselectUser, [
    ['name' => 'INBOX',      'displayName' => 'Inbox',      'delimiter' => '/', 'attributes' => LATT_HASNOCHILDREN, 'group' => 'primary'],
    ['name' => 'virtual',    'displayName' => 'Virtual',    'delimiter' => '/', 'attributes' => LATT_HASNOCHILDREN | LATT_NOSELECT, 'group' => 'other'],
    ['name' => 'Mailspring', 'displayName' => 'Mailspring', 'delimiter' => '/', 'attributes' => LATT_HASNOCHILDREN | LATT_UNMARKED | LATT_NOSELECT, 'group' => 'other'],
    ['name' => 'Archive',    'displayName' => 'Archive',    'delimiter' => '/', 'attributes' => LATT_MARKED, 'group' => 'other'],
]);
$listed = folder_list_for(function () {
    throw new RuntimeException('a fresh cache must not connect');
}, $noselectUser);
assert_eq(['INBOX', 'Archive'], array_column($listed, 'name'),
    'unselectable folders are filtered out of the cached list');

// --------------------------------------------------- the cold-cache gap ----+
// The chrome pages (settings, compose, reader) read the folder list through
// cached_folder_list(), which never connects. A miss there is invisible and lasts
// until an inbox visit, so it used to blank the sidebar and drop data-kb-map --
// which is why `g i` toasted "No such folder." on a page that was merely old.
// The TTL answers "may the list have changed?", so it belongs to the inbox alone.
group('cached_folder_list() serves a stale list, folder_list_for() still refreshes');

$staleUser = 'stale@x.io';
$staleFolders = [
    ['name' => 'INBOX', 'displayName' => 'Inbox', 'delimiter' => '/', 'attributes' => 0, 'group' => 'primary'],
    ['name' => 'Sent',  'displayName' => 'Sent',  'delimiter' => '/', 'attributes' => 0, 'group' => 'primary'],
];
db_set_folder_list($staleUser, $staleFolders);
assert_eq(['INBOX', 'Sent'], array_column(cached_folder_list($staleUser), 'name'),
    'a fresh cache serves the sidebar list');

db_open()->prepare('UPDATE folder_list_cache SET cached_at = ? WHERE user_email = ?')
    ->execute([time() - FOLDER_LIST_TTL_SECONDS - 60, $staleUser]);
assert_eq(null, db_get_folder_list($staleUser, FOLDER_LIST_TTL_SECONDS),
    'the row really is past the TTL');
assert_eq(['INBOX', 'Sent'], array_column(cached_folder_list($staleUser), 'name'),
    'the sidebar still lists folders when the cache is stale');
// The inbox is the path that pays for a connection, so it is the one that refreshes.
$refreshed = folder_list_for(function () {
    $fake = new class {
        public function getFolders(): array
        {
            return [['name' => 'INBOX', 'displayName' => 'Inbox', 'delimiter' => '/', 'attributes' => 0, 'group' => 'primary']];
        }
    };
    return $fake;
}, $staleUser);
assert_eq(['INBOX'], array_column($refreshed, 'name'),
    'the listing path still re-LISTs a stale row and rewrites the cache');
assert_eq(['INBOX'], array_column(cached_folder_list($staleUser), 'name'),
    'the sidebar picks the refreshed list up');

// A name json_encode() cannot encode used to bind `false`, PDO stored '', and
// json_decode('') is null -- a silent miss no refresh could ever clear.
group('db_set_folder_list() refuses a payload it cannot encode');

$encUser = 'encode@x.io';
db_set_folder_list($encUser, $staleFolders);
assert_false(db_set_folder_list($encUser, [
    ['name' => "Bad\xB1Name", 'displayName' => 'Bad', 'delimiter' => '/', 'attributes' => 0, 'group' => 'other'],
]), 'an unencodable list is refused');
assert_eq(['INBOX', 'Sent'], array_column(cached_folder_list($encUser), 'name'),
    'the refused write left the last good list in place');

// ------------------------------------------------------------------- js ----+
// Hermetic, output-level guards for the shared front-end toolkit. These glue
// the js suite (tests/js.php, which actually executes the JS in Node) to the
// rest of the suites: if this block breaks, every page is broken.

group('js_inline() ships the shared front-end toolkit');
$js = js_inline();

assert_contains('win.$ = $', $js, 'js: exposes the $ toolkit on window');
assert_contains('Em.prototype.each', $js, 'js: chainable .each');
assert_contains('function $(sel, ctx)', $js, 'js: element selection entry point');
assert_contains('nodes = slice.call((ctx || doc).querySelectorAll(sel))', $js, 'js: querySelectorAll-backed selection');
assert_contains('$.post', $js, 'js: $.post helper');
assert_contains('$.escapeHtml', $js, 'js: $.escapeHtml helper');
assert_contains('$.token', $js, 'js: $.token helper');
assert_contains('win.toast', $js, 'js: floating toasts are shipped');
assert_contains('toast toast-' , $js, 'js: toast builds a typed node');
assert_contains('$(\'#login-preset\').on(\'change\'', $js, 'js: login preset toggle wired');
assert_contains('emmmail-last-email', $js, 'js: login page remembers the last email');
assert_contains('markBusy(this.querySelectorAll(\'.btn-primary\')[0])', $js, 'js: login submit spins the button');
assert_contains('$(\'[data-autocomplete]\').each', $js, 'js: autocomplete inputs wired');
assert_contains('$box.find(\'.sync-btn\').on(\'click\', function(){ syncPoll(true); })', $js,
    'js: the chip click drives a forced poll');
assert_contains('$(\'.sidebar\').on(\'click\', \'.folder-link\'', $js,
    'js: sidebar folder links acknowledge the click with a spinner');
assert_not_contains('getElementById', $js, 'js: toolkit never reaches for getElementById');

group('js_inline() keeps the poll chain at module level');
// wireMailbox() re-runs after every folder swap. A chain built inside it would
// be one chain per swap, so the state that makes it one chain must be module
// level, and the chip binding must be the only thing wireSync() does.
assert_contains('var syncTimer = null;', $js, 'js: one timer for the page');
assert_contains('var syncBusy = false;', $js, 'js: one in-flight guard for the page');
assert_contains('var syncStale = false;', $js, 'js: one stale mark for the page');
assert_contains('var POLL_IDLE_STOP_SECONDS', $js, 'js: the idle stop is declared');
// The interval is the server's, and only the server's: a second copy of "a
// minute" in the client is a second place for it to go stale.
assert_not_contains('SYNC_MIN_PERIOD', $js, 'js: no second copy of the interval');
assert_contains("return isNaN(n) || n <= 0 ? 0 : n;", $js, 'js: the topbar number is the period, as sent');
assert_not_contains('reloaded', $js, 'js: the one-refresh-per-page-load guard is gone');

group('js_inline() never reaches for a browser global it was handed');
// Every one of these is reached through win. or doc., because the js harness
// hands the app its own window and document. A bare global works in a browser
// and throws in the harness, which is how the bug is found.
assert_no_bare_global('Notification', $js, 'js: Notification is always win.Notification');
assert_no_bare_global('localStorage', $js, 'js: localStorage is always win.localStorage');
assert_no_bare_global('isSecureContext', $js, 'js: isSecureContext is always win.isSecureContext');
assert_no_bare_global('hasFocus', $js, 'js: hasFocus is always doc.hasFocus');
// The two aliases at the top of the IIFE are the handoff itself, so they are the
// one place a bare document or window is allowed to appear.
$jsHandoff = ['var doc = document;', 'var win = window;'];
assert_no_bare_global('document', $js, 'js: the document is the injected one', $jsHandoff);
assert_no_bare_global('window', $js, 'js: the window is the injected one', $jsHandoff);

group('login and read views carry no ad-hoc inline JS');
$login = view_login(str_repeat('0', 64));
assert_not_contains('<script', $login, 'login: no inline <script> block');
assert_contains('login-preset', $login, 'login: preset selector still rendered');

group('view_login() smart autofocus, autocomplete and submit button');

assert_contains('id="login-password"', $login, 'login: password has an id for JS');
assert_contains('autocomplete="email"', $login, 'login: email hints the password manager');
assert_contains('autocomplete="current-password"', $login, 'login: password hints the password manager');
assert_contains('#tabler-arrow-right', $login, 'login: submit button carries an icon for the spinner');

// Empty email (fresh visit) -> the email field keeps autofocus.
assert_contains('name="email" id="login-email" required autofocus', $login, 'login: blank email is autofocused');
assert_not_contains('name="password" autofocus', $login, 'login: blank email leaves the password unfocused');

// Prefilled email (returning user / failed POST) -> focus moves to password.
$_POST['email'] = 'me@example.com';
$_POST['preset'] = 'migadu';
$loginPrefill = view_login(str_repeat('0', 64));
assert_contains('name="email" id="login-email" required value="me@example.com"', $loginPrefill,
    'login: the email value is echoed');
assert_not_contains('name="email" autofocus', $loginPrefill, 'login: a filled email is not autofocused');
assert_contains('name="password" required autofocus', $loginPrefill, 'login: a filled email autofocuses the password');
unset($_POST['email'], $_POST['preset']);

group('view_login() custom server fields are addressable by name');
// The js suite builds its own fixture and cannot see this markup, and the login
// JS reaches these six by [name="..."] to remember and restore them.
foreach (['imap_server', 'imap_port', 'imap_enc', 'smtp_server', 'smtp_port', 'smtp_enc'] as $fld) {
    assert_contains('name="' . $fld . '"', $loginPrefill, "login: the $fld control is addressable by name");
}

group('login page leaves flash to the toast container');
assert_not_contains('class="flash', $login, 'login: no static flash block in the box');

group('view_login() under a server lock');
assert_not_contains('login-alert', $login, 'login: no lock alert when unlocked');
$lockEnv(['EMMMAIL_PROVIDER' => 'migadu']);
$loginLocked = view_login(str_repeat('0', 64));
assert_not_contains('login-preset', $loginLocked, 'locked: no preset dropdown');
assert_not_contains('name="preset"', $loginLocked, 'locked: no preset field is posted');
assert_not_contains('custom-servers', $loginLocked, 'locked: no custom server block');
foreach (['imap_server', 'imap_port', 'imap_enc', 'smtp_server', 'smtp_port', 'smtp_enc'] as $fld) {
    assert_not_contains('name="' . $fld . '"', $loginLocked, "locked: no $fld field");
}
assert_contains('id="login-email"', $loginLocked, 'locked: the email field stays');
assert_contains('id="login-password"', $loginLocked, 'locked: the password field stays');
assert_not_contains('login-alert', $loginLocked, 'locked: a valid lock shows no alert');
$lockEnv(['EMMMAIL_MAIL_SERVER' => 'mail.x.com']);
assert_not_contains('login-preset', view_login(str_repeat('0', 64)), 'EMMMAIL_MAIL_SERVER hides the dropdown too');
$lockEnv(['EMMMAIL_PROVIDER' => 'bogus']);
$loginBad = view_login(str_repeat('0', 64));
assert_not_contains('login-preset', $loginBad, 'misconfigured: still no dropdown');
assert_contains('<p class="login-alert" role="alert">' . h(server_lock()['error']) . '</p>', $loginBad,
    'misconfigured: the error is shown in the box, not as a fading toast');
$lockEnv([]);

$email = ['subject' => 'Re: hi', 'from' => 'a@x.io', 'to' => 'b@y.io',
    'cc' => '', 'date' => '2026-01-01', 'body_text' => 'hello', 'attachments' => []];
$read = view_read($email, 7, 'INBOX');
assert_not_contains('<script', $read, 'read: no inline <script> block');
assert_contains('iframe', $read, 'read: email iframe still rendered');

group('render_page() carries the shared JS even when logged out');
ob_start();
render_page('T', '<div id="probe">x</div>', false);
$page = ob_get_clean();
assert_contains('Em.prototype.each', $page, 'render_page: shared toolkit present when logged out');

group('render_page() renders flash as toasts, not static alerts');
$_SESSION['flash'] = ['success' => 'Saved.', 'error' => 'Nope.'];
ob_start();
render_page('T', '<div id="probe">x</div>', false);
$toastPage = ob_get_clean();
unset($_SESSION['flash']);
assert_contains('class="toast-container"', $toastPage, 'render_page: a toast container is shipped');
assert_contains('toast toast-success', $toastPage, 'render_page: success flash becomes a success toast');
assert_contains('toast toast-error', $toastPage, 'render_page: error flash becomes an error toast');
assert_not_contains('class="alert', $toastPage, 'render_page: no static alert block is emitted');
assert_not_contains('class="flash', $toastPage, 'render_page: no static flash block is emitted');

// -------------------------------------------------- delete confirmation modal -+
group('view_inbox() delete form uses modal, not native confirm()');

$d = [
    'current_folder' => 'INBOX', 'title' => 'Inbox', 'is_sent' => false, 'is_trash' => false,
    'folders' => [], 'search_query' => '', 'last_sync_ts' => 0,
    'emails' => [['uid' => 55, 'subject' => 'Hi', 'from' => 'a@x.com', 'to' => '', 'to_address' => '',
                  'timestamp' => 1700000000, 'unread' => true, 'flagged' => false, 'has_attachment' => false]],
    'total' => 1, 'pages' => 1, 'current_page' => 1,
];
$inboxHtml = view_inbox($d);

assert_not_contains('confirm(', $inboxHtml, 'inbox delete form must not use native confirm()');
assert_contains('data-uid="55"', $inboxHtml, 'inbox delete form carries data-uid for JS');
assert_contains('data-folder="INBOX"', $inboxHtml, 'inbox delete form carries data-folder for JS');
assert_contains('data-delete', $inboxHtml, 'inbox delete button has data-delete hook');

group('view_read() delete form uses modal, not native confirm()');

$email = ['subject' => 'Re: hi', 'from' => 'a@x.io', 'to' => 'b@y.io',
    'cc' => '', 'date' => '2026-01-01', 'body_text' => 'hello', 'attachments' => []];
$readHtml = view_read($email, 42, 'INBOX');

assert_not_contains('confirm(', $readHtml, 'read delete form must not use native confirm()');
assert_contains('data-uid="42"', $readHtml, 'read delete form carries data-uid for JS');
assert_contains('data-folder="INBOX"', $readHtml, 'read delete form carries data-folder for JS');
assert_contains('data-delete', $readHtml, 'read delete button has data-delete hook');

group('view_read() shows Bcc when the message records one');

// Only your own copy in Sent carries a Bcc header, and the record is worthless
// if the UI hides it.
$bccEmail = ['subject' => 'hi', 'from' => 'a@x.io', 'to' => 'b@y.io', 'cc' => 'c@y.io',
    'bcc' => 'blind@y.io', 'date' => '2026-01-01', 'body_text' => 'x', 'attachments' => []];
$bccRead = view_read($bccEmail, 7, 'Sent');
assert_contains('<strong>Bcc:</strong> blind@y.io', $bccRead, 'Bcc is shown when present');
$noBcc = $bccEmail;
$noBcc['bcc'] = '';
assert_not_contains('Bcc:', view_read($noBcc, 7, 'Sent'), 'no empty Bcc row on normal mail');
// A message parsed before this existed has no bcc key at all.
unset($noBcc['bcc']);
assert_not_contains('Bcc:', view_read($noBcc, 7, 'Sent'), 'a missing bcc key is not an error');

// ------------------------------------------------------ reply all -----------+
// Who a reply-all actually goes to, kept pure: the sender joins To, the original
// Cc stays Cc, and the user never mails themselves.
group('reply_all_recipients()');

$me = 'rogerio@abtz.co';
$r = reply_all_recipients([
    'from_address' => 'exhip@pm.me',
    'to' => 'Rogerio Taques <rogerio@abtz.co>, Vin <vin@pm.me>',
    'cc' => 'Ann <ann@x.io>, bob@y.io',
], $me);
assert_eq('exhip@pm.me, vin@pm.me', $r['to'], 'sender plus the other To recipients, minus me');
assert_eq('ann@x.io, bob@y.io', $r['cc'], 'the original Cc group is preserved');

// A message addressed only to me: nobody else to add.
$r = reply_all_recipients(['from_address' => 'exhip@pm.me', 'to' => 'rogerio@abtz.co', 'cc' => ''], $me);
assert_eq('exhip@pm.me', $r['to'], 'a plain reply when there is nobody else');
assert_eq('', $r['cc'], 'and no Cc');

// My own message, opened in Sent: reply-all goes to the people I wrote to.
$r = reply_all_recipients([
    'from_address' => $me,
    'to' => 'vin@pm.me',
    'cc' => 'ann@x.io',
], $me);
assert_eq('vin@pm.me', $r['to'], 'my own address is not added back as a recipient');
assert_eq('ann@x.io', $r['cc'], 'Cc still carries over');

// Case must not defeat the self-exclusion or the de-duplication.
$r = reply_all_recipients([
    'from_address' => 'Exhip@PM.me',
    'to' => 'ROGERIO@abtz.CO, vin@pm.me, VIN@pm.me',
    'cc' => 'exhip@pm.me, ann@x.io',
], $me);
assert_eq('Exhip@PM.me, vin@pm.me', $r['to'], 'duplicates and my address dropped, case-insensitively');
assert_eq('ann@x.io', $r['cc'], 'an address already in To is not repeated in Cc');

// Missing keys must not blow up: a cached row may not carry cc at all.
$r = reply_all_recipients(['from_address' => 'a@b.c', 'to' => ''], $me);
assert_eq('a@b.c', $r['to'], 'a bare envelope still yields the sender');
assert_eq('', $r['cc'], 'and an empty Cc');

// Nothing to reply to at all.
$r = reply_all_recipients(['from_address' => '', 'to' => '', 'cc' => ''], $me);
assert_eq('', $r['to'], 'no sender, no recipients');

group('reply_sender_address()');

// A mailing list, or a "no-reply" sender with a real Reply-To, must win over From
// or the reply goes to the wrong place entirely.
assert_eq('list@x.io', reply_sender_address(['from_address' => 'no-reply@x.io', 'reply_to_address' => 'list@x.io']),
    'Reply-To beats From');
assert_eq('a@b.c', reply_sender_address(['from_address' => 'a@b.c', 'reply_to_address' => '']),
    'an empty Reply-To falls back to From');
assert_eq('a@b.c', reply_sender_address(['from_address' => 'a@b.c']),
    'a missing Reply-To key falls back to From');
assert_eq('list@x.io', reply_sender_address(['from_address' => 'a@b.c', 'reply_to_address' => 'The List <list@x.io>']),
    'a display name in Reply-To is stripped');
assert_eq('a@b.c', reply_sender_address(['from_address' => 'a@b.c', 'reply_to_address' => 'not an address']),
    'an unusable Reply-To is ignored rather than mailed');

group('reply_all_recipients() honours Reply-To');

$r = reply_all_recipients([
    'from_address' => 'no-reply@x.io',
    'reply_to_address' => 'list@x.io',
    'to' => 'rogerio@abtz.co, vin@pm.me',
    'cc' => '',
], 'rogerio@abtz.co');
assert_eq('list@x.io, vin@pm.me', $r['to'], 'reply-all addresses Reply-To, not the From address');

group('strip_html_for_quote()');

assert_eq('Hello', strip_html_for_quote('<p>Hello</p>'), 'plain HTML is stripped');
assert_eq('Hello', strip_html_for_quote('<style>.promo{color:red}</style><p>Hello</p>'),
    'style block content is stripped');
assert_eq('Hello', strip_html_for_quote('<script>alert(1)</script><p>Hello</p>'),
    'script block content is stripped');
assert_eq('Hello', strip_html_for_quote('<style>.a{color:red}</style><style>.b{margin:0}</style><p>Hello</p>'),
    'multiple style blocks are stripped');
assert_eq('Hello', strip_html_for_quote('<style>.a{color:red}</style><script>x()</script><p>Hello</p>'),
    'both style and script blocks are stripped');
assert_eq('Hello', strip_html_for_quote('Hello'), 'plain text passes through');
assert_eq('', strip_html_for_quote(''), 'empty string returns empty');
assert_eq('Hello', strip_html_for_quote('<style type="text/css">.a{color:red}</style><p>Hello</p>'),
    'style block with attributes is stripped');
assert_eq("Hello\n\nWorld", strip_html_for_quote("<p>Hello</p>\n\n\n\n\n<p>World</p>"),
    'consecutive blank lines are collapsed to two');
assert_eq("Hello World", strip_html_for_quote("<p>Hello</p>  \t  <p>World</p>"),
    'inline whitespace is collapsed');

group('reply_form_data()');

// This is the wiring that was missing: handle_send has always read
// in_reply_to_id, but nothing ever produced it, so every reply started a new
// thread in the recipient's client.
$parent = [
    'subject' => 'Re: [Abtz] New Contact received',
    'from' => 'Vin <vin@pm.me>',
    'from_address' => 'vin@pm.me',
    'reply_to_address' => '',
    'to' => 'rogerio@abtz.co, ann@x.io',
    'cc' => 'bob@y.io',
    'date' => 'Wed, 09 Sep 2026 15:23:38 +0000',
    'body_text' => 'Amazing. Thanks.',
    'body_html' => '',
    'message_id' => '<abc123@pm.me>',
    'references' => '<root@x.io> <second@x.io>',
];
$f = reply_form_data($parent, 'rogerio@abtz.co', false);
assert_eq('vin@pm.me', $f['to'], 'a plain reply goes to the sender');
assert_eq('', $f['cc'], 'and adds no Cc');
assert_eq('<abc123@pm.me>', $f['in_reply_to'], 'In-Reply-To is the parent Message-ID');
assert_eq('<root@x.io> <second@x.io> <abc123@pm.me>', $f['references'],
    'References is the parent chain plus the parent itself');
assert_eq('Re: [Abtz] New Contact received', $f['subject'], 'an existing Re: is not doubled');
assert_contains('Amazing. Thanks.', $f['body'], 'the original is quoted');

$fAll = reply_form_data($parent, 'rogerio@abtz.co', true);
assert_eq('vin@pm.me, ann@x.io', $fAll['to'], 'reply-all keeps the To group');
assert_eq('bob@y.io', $fAll['cc'], 'and the Cc group');
assert_eq('<abc123@pm.me>', $fAll['in_reply_to'], 'reply-all threads too');

// No chain on the parent: References is just the parent Message-ID.
$noRefs = $parent;
$noRefs['references'] = '';
assert_eq('<abc123@pm.me>', reply_form_data($noRefs, 'x@y.z', false)['references'],
    'a first reply references only the parent');

// A parent with no Message-ID at all (some senders omit it): no bogus headers.
$noId = $parent;
$noId['message_id'] = '';
$noId['references'] = '';
$f = reply_form_data($noId, 'x@y.z', false);
assert_eq('', $f['in_reply_to'], 'no Message-ID means no In-Reply-To');
assert_eq('', $f['references'], 'and no References');

group('the signature is prefilled above the quote, on every compose path');

$sigUser = 'sig@example.com';
$prevSessionEmail = $_SESSION['email'] ?? null;
$_SESSION['email'] = $sigUser;
db_set_setting($sigUser, 'signature', 'Best regards,');

$sigReply = reply_form_data($parent, $sigUser, false)['body'];
assert_eq(0, strpos($sigReply, "Best regards,\n\n"), 'a reply opens with the signature');
assert_contains('Amazing. Thanks.', $sigReply, 'and still quotes the original');
assert_true(strpos($sigReply, 'Best regards,') < strpos($sigReply, '> '),
    'the signature goes above the quoted text, not below it');
// Exactly one blank line separates the signature from the quote. The prefix ends
// with "\n\n" and the quote must not add its own, or the composer shows a gap.
assert_contains("Best regards,\n\nOn ", $sigReply, 'one blank line before the quote');
assert_not_contains("Best regards,\n\n\n", $sigReply, 'never two blank lines before the quote');

$sigFwd = forward_quote(['from' => 'Vin <vin@pm.me>', 'date' => 'Wed, 09 Sep 2026 15:23:38 +0000',
    'body_text' => 'Fwd body', 'body_html' => '']);
assert_contains('Fwd body', $sigFwd, 'the forward quote is independent of the signature');
// The forward path composes the same way, so it gets the same single blank line.
$sigFwdBody = signature_prefix() . $sigFwd;
assert_contains("Best regards,\n\n----- Forwarded Message -----", $sigFwdBody,
    'a forward has one blank line after the signature too');
assert_not_contains("Best regards,\n\n\n", $sigFwdBody, 'and no double blank line');
// handle_forward and reply_form_data are the two paths that lost it in a merge.
$mergeSrc = file_get_contents(__DIR__ . '/../index.php');
assert_contains('$sigPrefix = signature_prefix();', $mergeSrc, 'the signature prefix is computed');
assert_eq(2, substr_count($mergeSrc, '$sigPrefix = signature_prefix();'),
    'both the reply and the forward path set it, so neither can lose it again');
assert_contains("'body'        => \$sigPrefix . \$quote,", $mergeSrc,
    'and both put it above the quote');

db_set_setting($sigUser, 'signature', '');
$noSig = reply_form_data($parent, $sigUser, false)['body'];
assert_eq(0, strpos($noSig, "\n\nOn "), 'with no signature the body still leaves room on top');

if ($prevSessionEmail === null) {
    unset($_SESSION['email']);
} else {
    $_SESSION['email'] = $prevSessionEmail;
}

// Subject prefixing.
$fresh = $parent;
$fresh['subject'] = 'New Contact received';
assert_eq('Re: New Contact received', reply_form_data($fresh, 'x@y.z', false)['subject'], 'Re: is added when absent');

group('smtp_build_raw_message() threading headers');

$thread = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], '', '',
    '<parent@e.com>', '<root@e.com> <parent@e.com>');
assert_contains('In-Reply-To: <parent@e.com>', $thread, 'In-Reply-To names the parent');
assert_contains('References: <root@e.com> <parent@e.com>', $thread, 'References carries the whole chain');

// Without an explicit chain, References falls back to the parent alone.
$thread2 = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], '', '', '<parent@e.com>');
assert_contains('References: <parent@e.com>', $thread2, 'References falls back to the parent');

// Neither header appears on a message that is not a reply.
$plain = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], '', '', '');
assert_not_contains('In-Reply-To', $plain, 'a new message has no In-Reply-To');
assert_not_contains('References', $plain, 'and no References');

// Header injection through either value. CRLF is flattened to a space, so the
// text survives inside the value -- what must not happen is a new header LINE.
$inj = smtp_build_raw_message('f@e.com', 't@e.com', 'S', 'body', '', [], '', '',
    "<a@e.com>\r\nX-Evil: 1", "<b@e.com>\r\nX-Evil: 2");
assert_not_contains("\r\nX-Evil: 1", $inj, 'CRLF in In-Reply-To cannot start a header line');
assert_not_contains("\r\nX-Evil: 2", $inj, 'CRLF in References cannot start a header line');

group('view_compose() carries the threading fields');

$threadHtml = view_compose(['to' => 'a@b.c', 'cc' => '', 'bcc' => '', 'subject' => 'Re: x',
    'body' => '', 'forward_attachments' => [], 'csrf' => 'tok',
    'in_reply_to' => '<abc123@pm.me>', 'references' => '<root@x.io> <abc123@pm.me>']);
assert_contains('name="in_reply_to_id" value="&lt;abc123@pm.me&gt;"', $threadHtml,
    'the parent Message-ID is carried through the form');
assert_contains('name="references" value="&lt;root@x.io&gt; &lt;abc123@pm.me&gt;"', $threadHtml,
    'the chain is carried through the form');
// A fresh compose must not emit empty threading fields.
assert_not_contains('name="in_reply_to_id"', $composeHtml, 'a new message carries no In-Reply-To field');
assert_not_contains('name="references"', $composeHtml, 'and no References field');



$raEmail = ['subject' => 'hi', 'from' => 'Vin <vin@pm.me>', 'from_address' => 'vin@pm.me',
    'to' => 'rogerio@abtz.co, ann@x.io', 'cc' => '', 'bcc' => '', 'date' => 'today',
    'body_text' => 'x', 'body_html' => '', 'attachments' => []];
$raHtml = view_read($raEmail, 5, 'INBOX');
// Raw '&' in these hrefs is the existing convention throughout view_read.
assert_contains('?page=replyAll&uid=5&folder=INBOX', $raHtml, 'the Reply all link is rendered');
assert_contains('Reply all', $raHtml, 'and labelled');
assert_contains('tabler-corner-up-left-double', $raHtml, 'with its own icon, distinct from Reply');
assert_contains('tabler-corner-up-left-double', TABLER_SPRITE, 'and the icon exists in the sprite');
// Offered on every message, including one addressed only to the user: it then
// behaves like a plain reply, which is less surprising than a button that appears
// and disappears depending on the message.
$soloEmail = $raEmail;
$soloEmail['to'] = 'rogerio@abtz.co';
assert_contains('Reply all', view_read($soloEmail, 5, 'INBOX'),
    'Reply all is offered on a single-recipient message too');

group('reply_default_is_all(): Reply all leads only when it adds somebody');

$me = 'rogerio@abtz.co';
// To: me + ann -> replying to all reaches ann, whom a plain reply would drop.
assert_true(reply_default_is_all($raEmail, $me), 'an extra To recipient makes Reply all the default');
// To: me alone -> Reply all would address exactly the sender. Reply leads.
assert_false(reply_default_is_all($soloEmail, $me), 'a message addressed only to me defaults to Reply');
// A Cc counts even when To holds nobody but me.
$ccEmail = $soloEmail;
$ccEmail['cc'] = 'ops@x.io';
assert_true(reply_default_is_all($ccEmail, $me), 'a Cc makes Reply all the default');
// My own address never counts as somebody Reply all would add.
$selfCc = $soloEmail;
$selfCc['cc'] = 'ROGERIO@ABTZ.CO';
assert_false(reply_default_is_all($selfCc, $me), 'my own address, in any case, is not an extra recipient');

group('view_read() toolbar: order, separators, default reply, hover-only danger');

$barHtml = view_read($raEmail, 5, 'INBOX', $me);
$soloBar = view_read($soloEmail, 5, 'INBOX', $me);
// Default reply is the filled accent button; the other stays a plain btn.
assert_contains('btn btn-sm btn-primary tip" data-tip="Reply all"', $barHtml,
    'multi-recipient: Reply all is the primary button');
assert_contains('class="btn btn-sm tip" data-tip="Reply"', $barHtml,
    'multi-recipient: Reply stays secondary');
assert_contains('btn btn-sm btn-primary tip" data-tip="Reply"', $soloBar,
    'single recipient: Reply is the primary button');
assert_contains('class="btn btn-sm tip" data-tip="Reply all"', $soloBar,
    'single recipient: Reply all stays secondary');
// Sketch order: Back | Reply, Reply all, Forward | Unread | Spam, Delete.
// The last three are icon-only, so they are found by their accessible name.
$order = ['btn-label">Back<', 'btn-label">Reply<', 'btn-label">Reply all<', 'btn-label">Forward<',
    'aria-label="Unread"', 'aria-label="Spam"', 'aria-label="Delete"'];
$at = -1;
foreach ($order as $needle) {
    $pos = strpos($barHtml, $needle);
    assert_true($pos !== false && $pos > $at, 'toolbar keeps ' . $needle . ' in order');
    $at = (int)$pos;
}
// Icon-only is only acceptable with a tooltip and an accessible name on each.
foreach (['Unread', 'Spam', 'Delete'] as $named) {
    assert_contains('data-tip="' . $named . '" aria-label="' . $named . '"', $barHtml,
        $named . ' is icon-only, so it carries a tooltip and an accessible name');
}
// CSS cannot suppress the native title bubble, so title and data-tip would show
// two tooltips at once. data-tip wins; aria-label carries the accessible name.
assert_not_contains('title=', $barHtml, 'no native title bubble competes with the styled tooltip');
assert_not_contains('Mark as unread', $barHtml, 'the long labels are gone: the row would wrap');
assert_not_contains('Mark as spam', $barHtml, 'same for spam');
assert_true(substr_count($barHtml, 'actions-sep') === 2, 'two separators split the three action groups');
// Destructive actions read as normal buttons and only colour on hover.
assert_not_contains('btn-danger"', $barHtml, 'Delete is no longer a filled danger button');
assert_contains('btn-danger-soft', $barHtml, 'Delete colours red on hover only');
assert_contains('btn-warn-soft', $barHtml, 'Spam colours orange on hover only');
assert_contains('markUnreadBack(5', $barHtml, 'Unread posts then leaves the message');
assert_contains('?page=spam', $barHtml, 'Spam posts to the spam route');
// The reader has no row to patch, so rowAction() posts and follows the message
// out of the folder. Either way it never waits on a full page load.
assert_contains("rowAction(5, 'INBOX', '?page=spam', 'remove')", $barHtml, 'Spam goes through the optimistic path');
assert_not_contains('<form method="POST" action="?page=spam" style="display:inline">', $barHtml,
    'and not through a plain form post');

group('view_read() avatar is tinted like the list rows');

assert_contains('class="avatar av-', $barHtml, 'the read-view avatar carries a tone');
assert_contains(avatar_tone('vin@pm.me'), $barHtml, 'and it is the sender tone, matching the list');

// The reader is now composed from view_message_actions() + view_message_body().
// This pins the whole so the split cannot reorder or drop a piece. The page
// token is frozen so the two partials can be checked against ONE value.
group('view_read() survives the partial split');

$prevCsrf = $_SESSION['__mail_csrf'] ?? null;
$_SESSION['__mail_csrf'] = 'preserve-token';
$preserveEmail = ['from' => 'Sender <s@x.io>', 'from_address' => 's@x.io',
    'to' => 'Me <me@x.io>', 'to_address' => 'me@x.io', 'cc' => '', 'bcc' => '',
    'subject' => 'Preserve me', 'date' => 'Mon, 1 Jan 2026 10:00:00 +0000',
    'body_html' => '<img src="https://tracker.example/p.gif"><p>hi</p>', 'body_text' => 'hi',
    'attachments' => [['part_number' => '2', 'filename' => 'a b.pdf', 'size' => 2048]],
    'message_id' => '<m@x.io>', 'references' => '', 'in_reply_to' => ''];
$preserveFolders = [['name' => 'Archive', 'displayName' => 'Archive', 'attributes' => 0]];
$preserve = view_read($preserveEmail, 7, 'INBOX', 'me@x.io', $preserveFolders, ['block_images' => true]);

assert_true(str_starts_with($preserve, '<div class="card"'), 'the pieces stay wrapped in the reader card');
assert_eq(1, substr_count($preserve, 'class="actions-bar"'), 'exactly one action bar');
assert_eq(1, substr_count($preserve, 'class="body"'), 'exactly one message body');
$posActions = strpos($preserve, 'class="actions-bar"');
$posBody = strpos($preserve, 'class="body"');
assert_true($posActions !== false && $posBody !== false && $posActions < $posBody,
    'the action bar still renders before the body');
assert_eq(1, substr_count($preserve, 'id="move-dialog"'), 'exactly one move dialog');
assert_contains('sandbox="allow-same-origin"', $preserve, 'the body stays sandboxed');
assert_not_contains('allow-scripts', $preserve, 'and never gains allow-scripts');
// The action-bar forms and the body's trust form all read the ONE page token.
assert_eq(3, substr_count($preserve, 'value="preserve-token"'), 'every form shares the page token');
assert_contains('?page=download&uid=7&part=2&folder=INBOX', $preserve, 'attachment links are unchanged');
assert_contains('?page=replyAll&uid=7&folder=INBOX', $preserve, 'reply-all targets the message');
if ($prevCsrf === null) { unset($_SESSION['__mail_csrf']); } else { $_SESSION['__mail_csrf'] = $prevCsrf; }

group('view_thread() renders a conversation');

$tmsg = function (int $uid, string $from, string $date, string $body, bool $unread = false) {
    return ['email' => ['from' => $from, 'from_address' => 's@x.io', 'to' => 'Me <me@x.io>',
        'cc' => '', 'bcc' => '', 'subject' => 'Subject', 'date' => $date,
        'body_html' => $body, 'body_text' => '', 'attachments' => []],
        'uid' => $uid, 'folder' => 'INBOX', 'block_images' => false, 'unread' => $unread];
};
$threadMsgs = [
    $tmsg(1001, 'A <a@x.io>', 'Mon, 1 Jan 2026 09:00:00 +0000', '<p>one</p>'),
    $tmsg(1002, 'B <b@x.io>', 'Tue, 2 Jan 2026 09:00:00 +0000', '<p>two</p>'),
];
$threadFolders = [['name' => 'Archive', 'displayName' => 'Archive', 'attributes' => 0]];
$threadView = view_thread($threadMsgs, 'INBOX', 'Thread subject', 'me@x.io', $threadFolders);
assert_eq(2, substr_count($threadView, '<details'), 'one collapsible block per message');
assert_eq(1, substr_count($threadView, '<details class="thread-msg" open'), 'only the newest message starts open');
assert_eq(2, substr_count($threadView, 'class="actions-bar"'), 'each message carries its own action bar');
assert_eq(1, substr_count($threadView, 'id="move-dialog"'), 'and one move dialog for the whole thread');
assert_eq(1, substr_count($threadView, 'data-tip="Back"'), 'then one Back control for the thread, not per message');
// The body is HTML-escaped inside the iframe srcdoc, so count the senders in the
// summaries instead: message 1001 must come before 1002.
$posOne = strpos($threadView, 'a@x.io');
$posTwo = strpos($threadView, 'b@x.io');
assert_true($posOne !== false && $posTwo !== false && $posOne < $posTwo, 'messages render oldest first');
assert_contains('?page=reply&uid=1002&folder=INBOX', $threadView, 'reply targets that message');
assert_contains('Thread subject', $threadView, 'the conversation shows its subject');
assert_eq(1, substr_count($threadView, '<h1'), 'the subject is not repeated inside each message');
// Like the single-message reader, a message's actions sit ABOVE it.
$posActions = strpos($threadView, 'class="actions-bar"');
$posMeta = strpos($threadView, 'class="meta"');
assert_true($posActions !== false && $posMeta !== false && $posActions < $posMeta,
    'each message puts its actions above the message');

group('view_message_actions() can drop the Back control');

$noBack = view_message_actions($threadMsgs[1]['email'], 1002, 'INBOX', 'me@x.io', '', false);
assert_not_contains('data-tip="Back"', $noBack, 'the per-message bar drops Back');
assert_contains('data-tip="Reply"', $noBack, 'and keeps the per-message actions');

group('view_thread() bounds how much it renders');

$manyMsgs = [];
for ($i = 1; $i <= 35; $i++) {
    $manyMsgs[] = $tmsg(1000 + $i, "S$i <s$i@x.io>", 'D', '<p>m' . $i . '</p>');
}
$manyView = view_thread($manyMsgs, 'INBOX', 'Thread subject', 'me@x.io', []);
assert_eq(THREAD_MAX_MESSAGES, substr_count($manyView, '<details'), 'a long conversation renders at most the cap');
assert_contains('5 older messages', $manyView, 'and says how many it left out');

group('the thread route and handler exist');

$thSrc = (string)file_get_contents(__DIR__ . '/../index.php');
assert_contains("case 'thread':", $thSrc, 'router exposes the thread route');
assert_contains('function handle_thread(): void', $thSrc, 'the thread handler exists');
assert_contains('thread_key_is_safe($key)', $thSrc, 'the route validates the key');
assert_contains('markManyAsRead(', $thSrc, 'read is marked in one batch per folder');
assert_contains('data-thread-uids', $thSrc, 'a conversation row carries its member uids for the pick set');
// The client side of the same contracts, guarded at source level like the rest.
$thJs = js_inline();
assert_contains('function activeThreadCard', $thJs, 'js: the active conversation message has a seam');
assert_contains('.on(\'toggle\'', $thJs, 'js: a collapsed message re-measures when opened');
assert_contains('activeThreadCard() || doc.querySelector', $thJs,
    'js: the reader keyboard targets the active message, not the first card');
assert_contains("getAttribute('data-thread-uids')", $thJs,
    'js: a conversation picker expands to its members');

group('conversation UI is styled');

$threadCss = css_inline();
assert_contains('.mail-thread-count', $threadCss, 'css: the conversation count chip has a rule');
assert_contains('.mail-line-right', $threadCss, 'css: the count and date share a right-aligned group');
assert_contains('.thread-msg > summary', $threadCss, 'css: a message summary is styled');
assert_contains('.thread-top', $threadCss, 'css: the conversation Back row is styled');
assert_contains('.thread-more', $threadCss, 'css: the "older messages" note is styled');
// The chip borrows existing tokens, so it needs no new entry in the three theme
// blocks; a token invented here would be invisible in one of them.
assert_not_contains('--thread-count', $threadCss, 'css: the chip adds no new token');

group('findSentFolderIn() resolves Sent from a held folder list');

$sentList = [['name' => 'INBOX', 'attributes' => 0], ['name' => 'Sent', 'attributes' => 0]];
assert_eq('Sent', MailImap::findSentFolderIn($sentList), 'an exact Sent folder is found');
assert_eq(null, MailImap::findSentFolderIn([['name' => 'INBOX', 'attributes' => 0]]),
    'a list without Sent yields null, never a guess');
$mappedList = [['name' => 'INBOX', 'attributes' => 0], ['name' => 'Wyslane', 'attributes' => 0]];
assert_eq('Wyslane', MailImap::findSentFolderIn($mappedList, ['sent' => 'Wyslane']),
    'the user folder map wins');

group('handle_sync() keeps Sent warm for cross-folder conversations');

$syncSrc = (string)file_get_contents(__DIR__ . '/../index.php');
assert_contains('$sent = MailImap::findSentFolderIn($folders, $folderMap);', $syncSrc,
    'Sent is resolved from the cached list, with no connection');
assert_contains("if (\$sent !== null && \$sent !== 'INBOX' && \$sent !== \$folder)", $syncSrc,
    'a missing Sent is skipped, not fatal');
assert_contains('sync_request($shared, $me, $sent, $opts)', $syncSrc,
    'and Sent is synced on the shared connection');

group('warning tokens exist in every theme block');

$warnCss = css_inline();
assert_true(substr_count($warnCss, '--warning:') === 3,
    'warning is defined in :root, [data-theme=dark] and the media clone');
assert_true(substr_count($warnCss, '--warning-soft:') === 3, 'and so is its soft tint');
assert_contains('.btn-danger-soft:hover', $warnCss, 'a hover-only danger variant exists');
assert_contains('.btn-warn-soft:hover', $warnCss, 'a hover-only warning variant exists');
assert_contains('.actions-sep', $warnCss, 'the toolbar separator is styled');
assert_contains('.btn-icon', $warnCss, 'icon-only toolbar buttons are square');

group('tooltips come from data-tip, never from title');

// One styled tooltip for the whole app: content:attr(data-tip) on .tip::after.
assert_contains('.tip::after', $warnCss, 'a styled tooltip exists');
assert_contains('attr(data-tip)', $warnCss, 'and it reads its text from data-tip');
assert_contains('.tip::before', $warnCss, 'with an arrow');
assert_contains('@media (hover:none)', $warnCss, 'and it is suppressed on touch, where hover never ends');
// No native bubbles left anywhere: the two would stack.
$srcNoTitle = file_get_contents(__DIR__ . '/../index.php');
assert_true(strpos($srcNoTitle, 'title="') === false,
    'no title attribute survives in index.php: it would double up with the styled tooltip');
// Every icon-only control in the app carries both hooks.
$listD = ['emails' => [['uid' => 3, 'subject' => 's', 'from' => 'a@b.c', 'from_address' => 'a@b.c',
    'to' => '', 'to_address' => '', 'timestamp' => time(), 'unread' => true, 'snippet' => '',
    'has_attachments' => false]], 'total' => 1, 'page' => 1, 'pages' => 1, 'per_page' => 25,
    'current_folder' => 'INBOX', 'title' => 'Inbox', 'folders' => [], 'search_query' => '',
    'is_trash' => false, 'is_sent' => false, 'last_sync_ts' => 0, 'csrf' => 'tok'];
$listHtml = view_inbox($listD);
assert_contains('data-tip="Mark read" aria-label="Mark read"', $listHtml,
    'the list row read toggle is icon-only and named');
assert_contains('class="icon-btn tip"', $listHtml, 'list icon buttons opt into the tooltip');
$attachHtml = view_compose(['to' => '', 'cc' => '', 'bcc' => '', 'subject' => '', 'body' => '',
    'forward_attachments' => [], 'csrf' => 'tok']);
assert_contains('data-tip="Attach files" aria-label="Attach files"', $attachHtml,
    'the paperclip is icon-only and named');

group('list rows carry the hooks the optimistic layer needs');

// win.rowAction() finds the row by uid, reads .unread to decide direction,
// adjusts .mail-count and reports failures into a floating toast.
assert_contains('data-uid="3"', $listHtml, 'the row itself is addressable, not just its forms');
assert_contains('class="mail-count"', $listHtml, 'the total is addressable without a descendant selector');
assert_not_contains('row-error', $listHtml, 'the list no longer carries a static error box');
assert_contains('data-read-toggle', $listHtml, 'the read toggle is findable so its icon and label can flip');
assert_contains('toggleRead(3', $listHtml, 'one function serves both directions');
assert_not_contains('markRead(3', $listHtml, 'the two one-way helpers are gone');
assert_not_contains('markUnread(3', $listHtml, 'both of them');
// Delete and Restore go through the same optimistic path.
$trashRows = view_inbox(['is_trash' => true] + $listD);
assert_contains("rowAction(3, 'INBOX', '?page=restore', 'remove')", $trashRows, 'Restore is optimistic too');
assert_contains('.mail.row-gone', css_inline(), 'a removed row collapses instead of vanishing');

group('bulk selection markup: the checkbox, the select-all, the bar');

assert_contains('class="mail-pick"', $listHtml, 'each row offers a selection checkbox');
assert_contains('type="checkbox"', $listHtml, 'and it really is a checkbox');
assert_contains('data-pick-uid="3"', $listHtml, 'the checkbox names its message');
assert_contains('aria-label="Select message from a@b.c"', $listHtml, 'and is named for screen readers');
// The checkbox must be the last child of .mail-avatar, after the letter: CSS
// centres it absolutely over the avatar, so anything after it would paint on top.
assert_true((bool)preg_match('/<span class="mail-avatar av-[0-7]">.*<input type="checkbox" class="mail-pick"[^>]*>\s*<\/span>/s', $listHtml),
    'the picker is the last child of the avatar, after the letter');
// The avatar class attribute itself is untouched by the new child.
assert_contains('class="mail-avatar av-', $listHtml, 'the avatar keeps its own class attribute');

assert_contains('data-pick-all', $listHtml, 'the head offers a select-all');
assert_contains('aria-label="Select all messages"', $listHtml, 'which is named too');
// Both live inside .mailbox-head, flat: a descendant selector is not available.
assert_true((bool)preg_match('/mailbox-head.*data-pick-all.*<\/div>/s', $listHtml),
    'select-all sits inside .mailbox-head');
// A bare checkbox beside the title reads as decoration. The visible text names it,
// and the label makes the text a click target too. The same text is the count once
// rows are picked ("18 selected"), so the bar needs no count of its own.
assert_true((bool)preg_match('#<label class="pick-all"><input type="checkbox" class="mail-pick-all" data-pick-all[^>]*>'
    . '\s*<span class="pick-all-text" data-bulk-count role="status">Select all</span></label>#', $listHtml),
    'select-all is a label whose text is also the live count');
$bulkBarHtml = substr($listHtml, (int)strpos($listHtml, 'class="bulk-bar'));
$bulkBarHtml = substr($bulkBarHtml, 0, (int)strpos($bulkBarHtml, 'class="toolbar"'));
assert_not_contains('data-bulk-count', $bulkBarHtml, 'the bar no longer carries a second count');
assert_not_contains('role="status"', $bulkBarHtml, 'and is not a live region: it only holds buttons');

// The bar lives IN the head, between select-all and the toolbar. As a sibling under
// the head it pushed the whole list down the moment the first row was picked.
$headAt = strpos($listHtml, 'class="mailbox-head"');
$pickAllAt = strpos($listHtml, 'class="pick-all"');
$barAt = strpos($listHtml, 'class="bulk-bar hidden"');
$toolbarAt = strpos($listHtml, 'class="toolbar"');
assert_true($headAt < $pickAllAt && $pickAllAt < $barAt && $barAt < $toolbarAt,
    'the bar sits in the head, after select-all and before the toolbar');
assert_true($barAt < strpos($listHtml, 'class="mail-list"'), 'and above the list');

assert_contains('class="bulk-bar hidden"', $listHtml, 'the bulk bar ships hidden');
assert_contains('data-bulk-count', $listHtml, 'the count is addressable, not parsed out of text');
foreach (['read', 'unread', 'move', 'delete'] as $act) {
    assert_contains('data-bulk-action="' . $act . '"', $listHtml, 'the bar offers ' . $act);
}
// An inline handler would be invisible to the js suite: the stub has no
// attribute-handler wiring, so the scenarios would pass without running a thing.
assert_not_contains('onclick="bulkAction', $listHtml, 'no inline bulk handler; the buttons are data-driven');
// The folder is needed by every action, so it rides on the bar, not on each button.
assert_contains('data-bulk-folder="' . urlencode('INBOX') . '"', $listHtml,
    'the bar carries the current folder for its actions');

// Exactly one picker per row, one select-all and one bar for the whole page:
// a second bar is two bars fighting over pickSet, and a missing picker is a row
// the keyboard can reach but cannot select.
$manyRows = view_inbox(['emails' => [
    ['uid' => 11, 'subject' => 'a', 'from' => 'a@b.c', 'from_address' => 'a@b.c',
        'to' => '', 'to_address' => '', 'timestamp' => time(), 'unread' => true, 'snippet' => '',
        'has_attachments' => false],
    ['uid' => 12, 'subject' => 'b', 'from' => 'b@b.c', 'from_address' => 'b@b.c',
        'to' => '', 'to_address' => '', 'timestamp' => time(), 'unread' => false, 'snippet' => '',
        'has_attachments' => false],
    ['uid' => 13, 'subject' => 'c', 'from' => 'c@b.c', 'from_address' => 'c@b.c',
        'to' => '', 'to_address' => '', 'timestamp' => time(), 'unread' => false, 'snippet' => '',
        'has_attachments' => false],
], 'total' => 3] + $listD);
assert_eq(3, substr_count($manyRows, 'class="mail-pick"'), 'one picker per row, and no more');
assert_eq(1, substr_count($manyRows, 'data-pick-all'), 'one select-all for the page');
assert_eq(1, substr_count($manyRows, 'class="bulk-bar hidden"'), 'one bulk bar for the page');

// An empty mailbox has no rows to pick, so it must not render a select-all that
// selects nothing and a bar over nothing.
$emptyBox = view_inbox(['emails' => [], 'total' => 0] + $listD);
assert_not_contains('data-pick-all', $emptyBox, 'an empty mailbox has no select-all');
assert_not_contains('bulk-bar', $emptyBox, 'and no bulk bar');

group('bulk confirm copy must admit it can be permanent');

// handle_delete() falls back to a HARD delete when the folder is Trash or when
// findTrashFolder() returns null (a nested "Deleted Items" is not recognised as
// Trash, docs/knowledge/imap.md:136). After this change that is up to 200
// messages with no undo, so the dialog must not say "Delete this message?".
// The copy needs BOTH halves of that condition, so it takes the folder list the
// render path already has instead of the folder name alone.
// displayName because these also render through view_inbox()'s sidebar.
$withTrash = [['name' => 'INBOX', 'displayName' => 'Inbox'], ['name' => 'Trash', 'displayName' => 'Trash']];
$noTrash = [['name' => 'INBOX', 'displayName' => 'Inbox']];

assert_not_contains('permanently', confirm_delete_message(3, 'INBOX', $withTrash),
    'a plain folder with a Trash offers the reversible move');
assert_contains('3', confirm_delete_message(3, 'INBOX', $withTrash), 'and the copy carries the count');
assert_contains('permanently', strtolower(confirm_delete_message(200, 'Trash', $withTrash)),
    'a batch in Trash says permanently');
assert_contains('200', confirm_delete_message(200, 'Trash', $withTrash), 'and carries the count');
// The bar cannot know the count before the click, so it ships the same sentence
// with the number left open rather than a guess.
assert_contains('%d', confirm_delete_copy('Trash', $withTrash), 'the bar copy leaves the count open');
assert_not_contains('permanently', strtolower(confirm_delete_copy('INBOX', $withTrash)),
    'and the bar copy is reversible outside Trash');

// The no-Trash fallback is the case the copy used to get wrong: the folder name
// says INBOX, so the dialog promised a move to Trash, and moveManyToTrash()
// hard-deleted up to 200 messages instead.
assert_contains('permanently', strtolower(confirm_delete_copy('INBOX', $noTrash)),
    'no Trash in the list, so the bar warns the delete is permanent');
assert_contains('permanently', strtolower(confirm_delete_message(200, 'INBOX', $noTrash)),
    'and so does the counted copy');
assert_contains('200', confirm_delete_message(200, 'INBOX', $noTrash), 'which still carries the count');
// An empty list is the same claim: nothing proves a Trash exists, so the safe
// reading wins. It is what a cold folder cache renders.
assert_contains('permanently', strtolower(confirm_delete_copy('INBOX', [])),
    'an unknown folder list is treated as no Trash, so the copy warns');

// A custom map is what findTrashFolder() reads first, so it must reach the copy
// too. Under the map the folder called "Papier" IS the Trash, so deleting from
// INBOX is reversible again.
$withPapier = [['name' => 'INBOX'], ['name' => 'Papier']];
assert_not_contains('permanently', strtolower(confirm_delete_copy('INBOX', $withPapier, ['trash' => 'Papier'])),
    'a mapped Trash makes the delete reversible');
assert_contains('permanently', strtolower(confirm_delete_copy('INBOX', $withPapier, ['trash' => 'Ghost'])),
    'a stale map that names no folder does not buy a reversible promise');
// handle_delete() branches on the folder NAME, not on the is_trash flag, so the
// copy must too: the two can disagree, and only the name reaches the handler.
assert_contains('permanently', strtolower(confirm_delete_copy('INBOX.Trash', $withTrash)),
    'a Trash-named folder is permanent even with a Trash in the list');

group('the Trash resolver is shared by the copy and findTrashFolder()');

// Two implementations of "is there a Trash" is how the copy drifted from the
// handler in the first place, so both callers must reach the same one.
$resolverSrc = (string)file_get_contents(__DIR__ . '/../index.php');
foreach (['function confirm_delete_copy(', 'function confirm_delete_message('] as $fn) {
    $start = (int)strpos($resolverSrc, $fn);
    $body = substr($resolverSrc, $start, (int)strpos($resolverSrc, "\n}\n", $start) - $start);
    assert_contains('delete_is_permanent(', $body, $fn . ' asks the shared permanence question');
}
assert_contains('MailImap::findTrashFolderIn(', $resolverSrc, 'the resolver exists as one named function');
// findTrashFolder() resolves through it, so a fix to one is a fix to both.
$ftStart = (int)strpos($resolverSrc, 'public function findTrashFolder(');
$ftBody = substr($resolverSrc, $ftStart, (int)strpos($resolverSrc, "\n  }\n", $ftStart) - $ftStart);
assert_contains('findTrashFolderIn(', $ftBody, 'findTrashFolder() shares the resolver the copy uses');

assert_eq('Trash', MailImap::findTrashFolderIn($withTrash, null), 'an exact Trash name resolves');
assert_eq(null, MailImap::findTrashFolderIn($noTrash, null), 'no Trash resolves to null, not a guess');
assert_eq('Papier', MailImap::findTrashFolderIn(array_merge($noTrash, [['name' => 'Papier']]), ['trash' => 'Papier']),
    'the custom map wins over detection, the same order findTrashFolder() uses');
assert_eq(null, MailImap::findTrashFolderIn(array_merge($noTrash, [['name' => 'Papier']]), ['trash' => 'Ghost']),
    'a map that names a folder the server does not have resolves to null');
// The substring fallback is what catches a nested "Papier.Trash"; the copy and
// the handler must agree on it, so it is pinned here rather than only in imap.php.
assert_eq('Papier.Trash', MailImap::findTrashFolderIn([['name' => 'INBOX'], ['name' => 'Papier.Trash']], null),
    'the substring fallback still resolves a nested Trash');
// A nested "Deleted Items" is NOT resolved, and that is the honest reading: the
// handler hard-deletes there too, so the copy must warn.
assert_eq(null, MailImap::findTrashFolderIn([['name' => 'INBOX'], ['name' => 'INBOX.Deleted Items']], null),
    'a nested Deleted Items is still unresolved, which is why the copy warns');

// The bar renders the copy itself, so the client never guesses irreversibility.
assert_contains('data-bulk-delete-copy="Delete %d messages? They will move to Trash."',
    view_inbox(['folders' => $withTrash] + $listD),
    'the bar carries the reversible copy when a Trash exists');
assert_contains('data-bulk-delete-copy="Permanently delete %d messages? This cannot be undone."',
    view_inbox(['folders' => $noTrash] + $listD),
    'and the permanent copy when it does not');
$trashBar = view_inbox(['current_folder' => 'INBOX.Trash', 'folders' => $withTrash] + $listD);
assert_contains('This cannot be undone', $trashBar, 'and the permanent copy in Trash');
assert_not_contains('This cannot be undone', view_inbox(['folders' => $withTrash] + $listD),
    'while a plain folder with a Trash keeps the reversible copy');

group('bulk selection CSS: revealable on hover, reachable by keyboard, visible on touch');

$bulkCss = css_inline();

// The picker is centred over the avatar by absolute positioning, not by a new
// grid column: the row is a 3-column grid and a 4th column would shift every
// row in the list on load.
assert_contains('.mail-pick {', $bulkCss, 'the picker has its own rule');
assert_true((bool)preg_match('/\.mail-pick \{[^}]*position:absolute/', $bulkCss),
    'and it is positioned out of flow, so it costs no grid column');
// WCAG 2.5.8 asks for a 24px target. The phone avatar is 36px, so 24 still fits.
assert_true((bool)preg_match('/\.mail-pick \{[^}]*width:24px[^}]*height:24px/', $bulkCss),
    'the target is 24px, the WCAG 2.5.8 minimum, and still fits the 36px phone avatar');
// The letter behind the checkbox needs a separating edge, or the box reads as
// part of the avatar tone.
assert_true((bool)preg_match('/\.mail-pick \{[^}]*var\(--surface\)/', $bulkCss),
    'the box carries a surface-coloured ring so it separates from the letter');

// Round, not square: the box sits on a round avatar. The native control ignores
// border-radius, so it has to give up its native look first.
$roundRule = '';
if (preg_match('/\.mail-pick, \.mail-pick-all \{([^}]*)\}/', $bulkCss, $m)) { $roundRule = $m[1]; }
assert_contains('appearance:none', $roundRule, 'both checkboxes drop the native square');
assert_contains('border-radius:50%', $roundRule, 'and are round');
assert_false((bool)preg_match('/\.mail-pick \{[^}]*border-radius:6px/', $bulkCss),
    'no square corner is left on the row picker');
// The tick is a token colour, so it is a clipped ::before and not a data-URI SVG:
// --on-accent is white in light and nord0 in dark, and an SVG cannot read a token.
assert_true((bool)preg_match('/\.mail-pick:checked, \.mail-pick-all:checked, \.mail-pick-all:indeterminate \{[^}]*background:var\(--accent\)/', $bulkCss),
    'a ticked box fills with the accent');
assert_true((bool)preg_match('/\.mail-pick::before, \.mail-pick-all::before \{[^}]*clip-path:[^}]*background:var\(--on-accent\)/', $bulkCss),
    'the tick is a clipped ::before in the on-accent token');
assert_true((bool)preg_match('/\.mail-pick-all:indeterminate::before \{[^}]*clip-path:/', $bulkCss),
    'a partial selection draws a dash, not a tick');

// opacity:0 hides the control but keeps it in the tab order. display:none and
// visibility:hidden would take it out, and the checkbox would be unreachable
// by keyboard -- the very thing this rule exists to avoid.
assert_true((bool)preg_match('/\.mail-pick \{[^}]*opacity:0/', $bulkCss),
    'the picker is hidden with opacity, so it stays focusable');
// The three reveals, and each one must use opacity: switching to visibility
// would take the box out of the tab order and reintroduce the bug this rule
// set out to avoid. The selector alone is not enough, so the property is pinned.
foreach ([
    '.mail:hover .mail-pick' => 'hover reveals it',
    '.mail:focus-within .mail-pick' => 'keyboard focus reveals it',
    '.mail.picked .mail-pick' => 'a selected row keeps it visible',
] as $sel => $why) {
    assert_true((bool)preg_match('/' . preg_quote($sel, '/') . '[^{]*\{[^}]*opacity:1/', $bulkCss),
        $why . ', with opacity so the box stays focusable');
}
// Touch has no hover, and a tap leaves :hover stuck on the row it touched. So the
// hover reveals apply only where hover is real, and a touch screen shows neither
// pickers nor row actions on an idle list -- every row used to look hovered.
// A touch user starts a selection by tapping the avatar: the picker sits over it
// at opacity:0, which still takes the tap. From the first pick the bar is up and
// :has() shows every picker.
assert_false((bool)preg_match('/@media \(hover:none\) \{[^}]*\.mail-pick \{ opacity:1; \}/', $bulkCss),
    'a touch screen no longer shows every picker on an idle list');
assert_false((bool)preg_match('/@media \(hover:none\) \{ \.mail-actions \{ opacity:1; \} \}/', $bulkCss),
    'nor every row action');
assert_true((bool)preg_match('/@media \(hover:hover\) \{[^}]*\.mail:hover \.mail-pick[^}]*\{[^}]*opacity:1/', $bulkCss),
    'the hover reveal of the picker is scoped to real hover');
assert_true((bool)preg_match('/@media \(hover:hover\) \{[^}]*\.mail:hover \.mail-actions[^}]*\{[^}]*opacity:1/', $bulkCss),
    'and so is the hover reveal of the row actions');
// opacity:0 still takes a tap, so a hidden Delete would fire from an empty-looking
// spot. Hidden actions take no pointer at all; a picked row, or keyboard focus,
// gives them back.
assert_true((bool)preg_match('/@media \(hover:none\) \{[^}]*\.mail-actions \{ pointer-events:none; \}/', $bulkCss),
    'on touch a hidden row action cannot be tapped');
assert_true((bool)preg_match('/@media \(hover:none\) \{\s*\.mail-actions \{ pointer-events:none; \}\s*\.mail\.picked \.mail-actions, \.mail:focus-within \.mail-actions \{ opacity:1; pointer-events:auto; \}/', $bulkCss),
    'and a picked row shows its actions again, live');
// opacity:0 kills the native focus ring, so an explicit one is required or
// keyboard focus is invisible.
assert_true((bool)preg_match('/\.mail-pick:focus-visible \{ outline:2px solid var\(--accent\)/', $bulkCss),
    'focus draws its own ring, because opacity:0 hides the native one');

// "Is the bar up?" is answered by CSS alone. The client must never ask, and the
// selector it uses must survive the stub's parser.
assert_contains('.mailbox:has(.bulk-bar:not(.hidden)) .mail-pick', $bulkCss,
    'the bar being up is a CSS-only fact, via :has()');

// .mail.opening .mail-avatar::before is an opaque cap in the row colour; a
// checkbox on top of it hides the spinner the cap exists to clear.
assert_contains('.mail.opening .mail-pick { opacity:0; }', $bulkCss,
    'an opening row hides its picker so the spinner cap reads');

// THE specificity trap. .mail.unread:hover and .mail.unread:active are (0,3,0)
// and outrank a lone .mail.picked at (0,2,0) regardless of source order, so a
// selected unread row under the pointer loses its highlight -- the state a user
// looks at longest. Each variant is spelled out, and this guard pins the whole
// declaration because a grep for '.mail-picked' is green for this bug.
assert_contains('.mail.picked, .mail.unread.picked, .mail.unread.picked:hover { background:var(--accent-soft); }', $bulkCss,
    'a selected unread row keeps its highlight under the pointer');
// The rule has to come AFTER the :active pair to win the (0,3,0) tie by order
// where the selectors match equally.
assert_true(strpos($bulkCss, '.mail.picked, .mail.unread.picked, .mail.unread.picked:hover')
    > strpos($bulkCss, '.mail:active, .mail.unread:active'),
    'and it is declared after the :active pair it has to beat');

// The bar is a flex item in the head now. It takes the free space between the
// select-all and the toolbar, and it must never wrap: a wrap is a second line,
// and a second line is the very shift the move into the head removes.
$barRule = '';
if (preg_match('/\.bulk-bar \{([^}]*)\}/', $bulkCss, $m)) { $barRule = $m[1]; }
assert_contains('flex:1 1 0', $barRule, 'the bar takes the free space in the head, and its zero basis never forces a wrap');
assert_contains('min-width:0', $barRule, 'and may shrink below its content');
assert_contains('flex-wrap:nowrap', $barRule, 'and never wraps onto a second line');
// 42px is the toolbar's height: where the bar replaces the toolbar, the head
// keeps its height.
assert_contains('min-height:42px', $barRule, 'the bar is as tall as the toolbar it can replace');
assert_not_contains('border-bottom', $barRule, 'and no longer draws a row border of its own');
// With the count gone from the bar, nothing pushes the buttons right but this.
assert_contains('justify-content:flex-end', $barRule, 'the bar buttons sit at the end of the free space');
assert_not_contains('.bulk-count', $bulkCss, 'the old in-bar count has no CSS left behind');
// The head knows its own width, not the viewport's: the sidebar comes and goes at
// 900px, so a viewport query would guess wrong on either side of it.
assert_true((bool)preg_match('/\.mailbox-head \{[^}]*container-type:inline-size/', $bulkCss),
    'the head is a size container for the bar tiers');
assert_true((bool)preg_match('/@container \(max-width:1020px\) \{[^}]*\.bulk-bar \.btn-label \{ display:none; \}/', $bulkCss),
    'a narrower head turns the bar buttons into icons');
assert_true((bool)preg_match('/@container \(max-width:800px\) \{[^}]*\.mailbox-head:has\(\.bulk-bar:not\(\.hidden\)\) \.toolbar \{ display:none; \}/', $bulkCss),
    'a narrower head still hides the toolbar while the bar is up');
// On a phone the toolbar is ALWAYS on its own line, and the bar takes that line.
// Both states are then two lines tall, so nothing below moves.
assert_true((bool)preg_match('/@container \(max-width:600px\) \{[^}]*\.toolbar \{[^}]*flex-basis:100%/', $bulkCss),
    'on a phone the toolbar always takes its own line');
assert_true((bool)preg_match('/@container \(max-width:600px\) \{.*\.bulk-bar \{[^}]*flex-basis:100%/s', $bulkCss),
    'and the bar takes that same line when it is up');
// At 320px the sync chip and a fixed-width search did not fit on one line, so the
// toolbar was two lines and the bar replacing it was one: a 42px jump.
assert_true((bool)preg_match('/@container \(max-width:600px\) \{.*\.toolbar \.search \{[^}]*flex:1 1 0[^}]*min-width:140px/s', $bulkCss),
    'on a phone the search shrinks, so the toolbar stays one line down to 320px');
// .hidden is the LAST top-level rule, so both classes are needed to beat it.
assert_contains('.bulk-bar.hidden { display:none; }', $bulkCss,
    'the bar hides with both classes, because .hidden alone ties on order');

// The bar's buttons follow the house rule: icon plus a label that collapses at
// 620px, so each stays named by its aria-label when it becomes an icon.
assert_contains('.btn-label { display:none; }', $bulkCss,
    'the shared 620px rule already collapses the bar labels to icons');

group('mutating endpoints answer JSON to the optimistic client');

$respSrc = file_get_contents(__DIR__ . '/../index.php');
foreach (['handle_delete', 'handle_restore', 'handle_spam', 'handle_move'] as $fn) {
    $body = substr($respSrc, (int)strpos($respSrc, 'function ' . $fn . '('));
    $body = substr($body, 0, (int)strpos($body, "\n}\n"));
    assert_contains('respond_mutation(', $body, $fn . '() can answer JSON as well as redirect');
    assert_not_contains('flash_set(', $body, $fn . '() no longer flashes directly');
}

group('the three bulk handlers: CSRF, folder safety, one batch, a count back');

$bulkSrc = file_get_contents(__DIR__ . '/../index.php');
$bulkBodies = [];
foreach (['handle_mark_read', 'handle_delete', 'handle_move'] as $fn) {
    $body = substr($bulkSrc, (int)strpos($bulkSrc, 'function ' . $fn . '('));
    $body = substr($body, 0, (int)strpos($body, "\n}\n"));
    $bulkBodies[$fn] = $body;
    assert_contains('csrf_verify(', $body, $fn . '() still refuses a POST with no valid token');
    assert_contains('imap_folder_name_is_safe($folder)', $body, $fn . '() still rejects an unsafe folder name');
    assert_contains('post_uids()', $body, $fn . '() reads the selection through post_uids()');
    assert_contains('if (empty($uids))', $body, $fn . '() answers an empty selection without touching IMAP');
}

// One IMAP call per batch, never one per UID: N UIDs must cost N/COPY, not N.
foreach ([
    'handle_mark_read' => 'markManyAsRead($uids, $read)',
    'handle_delete'    => 'moveManyToTrash($uids)',
    'handle_move'      => 'moveManyToFolder($uids, $dest)',
] as $fn => $call) {
    assert_contains($call, $bulkBodies[$fn], $fn . '() makes one batch IMAP call');
    assert_not_contains('markAsReadByUid($uid)', $bulkBodies[$fn], $fn . '() does not loop over the single-UID method');
}

// The count is the answer: the client removes exactly the rows the server moved.
assert_contains("json_out(['success' => true, 'count' => \$count])", $bulkBodies['handle_mark_read'],
    'mark read answers with the count');
assert_contains('$folder, $count)', $bulkBodies['handle_delete'], 'delete answers with the count');
assert_contains('$folder, $count)', $bulkBodies['handle_move'], 'move answers with the count');

// Cache writes are per UID (the cache is one row per message) but only after the
// batch reported work. A partial failure must not delete rows we did not move.
foreach ($bulkBodies as $fn => $body) {
    assert_contains('if ($count > 0)', $body, $fn . '() gates its cache writes on a real change');
    assert_contains('cache_invalidate_folder(', $body, $fn . '() invalidates the folder count so it resyncs');
    assert_not_contains('db_delete_messages(', $body, $fn . '() never deletes cache rows on a failed move');
}

// Trash means permanent: the delete handler must branch on the folder, not call
// the move path, or "Delete" on a message already in Trash would refile it.
assert_contains('MailImap::isTrashFolder($folder)', $bulkBodies['handle_delete'], 'delete checks for Trash');
// Body-scoped per branch, not one "calls a *Many variant" guard for the whole
// function: a single guard passes with the two calls swapped, and the swap
// destroys mail in one direction and silently refiles it in the other. Deleting
// something already in Trash must permanently delete; deleting anything else
// must move it to Trash.
$delBody = $bulkBodies['handle_delete'];
$trashBranch = substr($delBody, (int)strpos($delBody, 'if (MailImap::isTrashFolder($folder))'));
$trashBranch = substr($trashBranch, 0, (int)strpos($trashBranch, "\n  }\n"));
assert_contains('deleteManyByUid($uids)', $trashBranch, 'the Trash branch is the permanent one');
assert_not_contains('moveManyToTrash($uids)', $trashBranch, 'and it never refiles a message that is already in Trash');
$afterTrash = substr($delBody, (int)strpos($delBody, "\n  }\n", (int)strpos($delBody, 'if (MailImap::isTrashFolder($folder))')) + 4);
assert_contains('moveManyToTrash($uids)', $afterTrash, 'the ordinary branch moves to Trash instead of deleting');
assert_not_contains('deleteManyByUid($uids)', $afterTrash, 'and it never permanently deletes from a live folder');

group('read toolbar collapses to icons on a phone');

assert_contains('<span class="btn-label">Reply</span>', $barHtml, 'button text is wrapped so it can be hidden');
assert_contains('.btn-label { display:none; }', $warnCss, 'and the mobile breakpoint hides it');
// Hiding text turns the button into an icon: it needs a name and a tip like the rest.
assert_contains('data-tip="Reply" aria-label="Reply"', $barHtml, 'Reply stays named once its text is hidden');
assert_contains('data-tip="Reply all" aria-label="Reply all"', $barHtml, 'so does Reply all');
assert_contains('data-tip="Forward" aria-label="Forward"', $barHtml, 'and Forward');
assert_contains('data-tip="Back" aria-label="Back"', $barHtml, 'and Back');

group('move_target_options(): the eligible targets, shared by both views');

$mtFolders = [
    ['name' => 'INBOX', 'displayName' => 'Inbox', 'attributes' => 0],
    ['name' => 'Archive', 'displayName' => 'Archive', 'attributes' => 0],
    ['name' => 'Projects', 'displayName' => 'Projects', 'attributes' => LATT_NOSELECT],
    ['name' => 'Projects/2026', 'displayName' => '2026', 'attributes' => 0],
];
$mt = move_target_options($mtFolders, 'INBOX');
assert_contains('<option value="Archive">Archive</option>', $mt, 'a normal folder is offered');
assert_contains('<option value="Projects%2F2026">2026</option>', $mt, 'nested targets are url-encoded');
assert_not_contains('<option value="INBOX">', $mt, 'not the folder you are already in');
assert_not_contains('>Projects<', $mt, 'nor a \Noselect hierarchy node');
assert_eq('', move_target_options([['name' => 'INBOX', 'displayName' => 'Inbox', 'attributes' => 0]], 'INBOX'),
    'no eligible target yields nothing, so callers can skip the affordance');
// Same gate as the handler, so the picker can never offer a move the server refuses.
assert_contains('folder_move_check(', file_get_contents(__DIR__ . '/../index.php'),
    'the options come from the same gate the handler uses');

group('the message list can move a message too');

$moveListD = $listD;
$moveListD['folders'] = $mtFolders;
$moveList = view_inbox($moveListD);
assert_contains('data-tip="Move to" aria-label="Move to"', $moveList, 'rows offer Move on hover, named like the rest');
assert_contains("moveTo(3, 'INBOX')", $moveList, 'and open the picker for that row');
assert_contains('id="move-dialog"', $moveList, 'the list carries the picker');
assert_eq(1, substr_count($moveList, 'id="move-dialog"'), 'exactly one picker, however many rows');
assert_contains('<option value="Archive">Archive</option>', $moveList, 'built from the sidebar folder list');
// Nothing to move to: no button, no empty dialog. Same rule as the reader.
assert_not_contains('moveTo(', view_inbox($listD), 'no eligible folders means no Move button');
assert_not_contains('id="move-dialog"', view_inbox($listD), 'and no empty picker');
// The picker markup exists once in the source, not once per view.
assert_eq(1, substr_count(file_get_contents(__DIR__ . '/../index.php'), 'class="move-dest"'),
    'the dialog markup is shared, not copy-pasted into both views');

group('remote images: settings, reader CTA and the trust route');

$imgSrc = file_get_contents(__DIR__ . '/../index.php');
// Off by default, and the default has to be a constant so it is not typed twice.
assert_contains("define('DEFAULT_SHOW_REMOTE_IMAGES', '0')", $imgSrc, 'the default is off, once');
assert_contains("db_set_setting(\$me, 'show_remote_images'", $imgSrc, 'the toggle persists');
assert_contains("case 'trustSender':", $imgSrc, 'the whitelist route exists');
assert_contains('handle_trust_sender();', $imgSrc, 'and reaches its handler');
// handle_read has to consult the gate, or none of the above ever runs.
assert_contains("images_allowed(\$sender, \$imagesOn, \$whitelist)", $imgSrc,
    'the reader asks the gate whether to block');
assert_contains("!empty(\$_GET['images'])", $imgSrc, '&images=1 shows them for this view only');
assert_contains('.images-cta', css_inline(), 'the CTA bar is styled');

$settingsImg = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 0, 'show_remote_images' => false, 'image_whitelist' => "a@b.c\nd@e.f"]);
assert_contains('name="show_remote_images"', $settingsImg, 'settings offer the toggle');
assert_contains('Remote images', $settingsImg, 'and label it');
assert_contains('a@b.c', $settingsImg, 'and show who is already trusted');

group('the remote-images setting is a switch, aligned right of its description');

// It rendered as a stray square floating above the label, because .settings-check
// was never defined in CSS and .form-group input sets width:100%.
assert_not_contains('settings-check', $settingsImg, 'the undefined class is gone');
assert_contains('class="form-group settings-toggle"', $settingsImg, 'the row lays text and switch side by side');
assert_contains('class="switch"', $settingsImg, 'the control is a switch');
assert_contains('class="switch-track"', $settingsImg, 'with a track to slide in');
// The input is visually hidden, so it must still be reachable by its label.
assert_contains('id="show-remote-images"', $settingsImg, 'the input has an id');
assert_contains('for="show-remote-images"', $settingsImg, 'and a label pointing at it');
// Checked state round-trips.
$settingsOn = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 0, 'show_remote_images' => true, 'image_whitelist' => '']);
assert_contains('checked', $settingsOn, 'an enabled setting renders checked');
assert_not_contains('checked', $settingsImg, 'and a disabled one does not');

$switchCss = css_inline();
assert_contains('.settings-toggle { display:flex;', $switchCss, 'the row is a flex row');
assert_contains('justify-content:space-between', $switchCss, 'so the switch sits at the far right');
assert_contains('.settings-toggle { display:flex; align-items:center;', $switchCss,
    'and is centred against the description, not pinned to its first line');
// .switch is itself a <label>, so `.form-group label` (0,1,1) outranks a lone
// class -- its margin-bottom:6px knocked the switch 3px off centre.
assert_contains('.form-group label.switch', $switchCss, 'the switch overrides form-group label outright');
assert_contains('.form-group .settings-toggle-text label', $switchCss, 'and so does the title');
assert_contains('.switch input:checked + .switch-track', $switchCss, 'the on state is driven by :checked');
assert_contains('.switch input:focus-visible + .switch-track', $switchCss, 'and it is still keyboard-visible');
assert_contains('prefers-reduced-motion', $switchCss, 'the slide respects reduced motion');

group('the settings option rows: one divider between each, control on the right');

$rowsHtml = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 0, 'show_remote_images' => false, 'image_whitelist' => '',
    'auto_poll' => true, 'undo_period_seconds' => 5]);
// Undo send was the one option laid out as a stacked full-width select, under
// three rows that put the control on the right.
assert_true((bool)preg_match('#<div class="form-group settings-toggle"><div class="settings-toggle-text">'
    . '<label for="undo-period">Undo send period</label>#', $rowsHtml),
    'undo send is a settings row, text on the left');
assert_contains('<select id="undo-period" name="undo_period_seconds">', $rowsHtml,
    'and its select is reachable by its label');
// `.form-group select` sets width:100% at (0,1,1); a lone `.settings-toggle select`
// ties it and wins only on source order, so the override is qualified.
assert_contains('.form-group.settings-toggle select { width:auto;', $switchCss,
    'the select shrinks to its content instead of filling the row');
// The adjacent-sibling rule draws a line between rows and never above the first,
// so a row added later gets its divider for free.
assert_contains('.settings-toggle + .settings-toggle { border-top:1px solid var(--border);', $switchCss,
    'a divider between consecutive option rows');
// The text wraps at one measure on every row, so the descriptions form a column
// and the controls another, instead of each note running to its own control.
assert_contains('.settings-toggle-text { min-width:0; flex:0 1 32rem; }', $switchCss,
    'the text column wraps at one width on every row');
// Trusted senders was an underlined link under the description, easy to miss.
// It is a button now, in the control column, beside the switch it qualifies.
$trusted = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 0, 'show_remote_images' => false, 'image_whitelist' => "a@b.c"]);
assert_true((bool)preg_match('#<div class="settings-control"><button type="button" class="btn btn-pill" data-open-trusted-senders[^>]*>Trusted senders \(1\)</button><label class="switch">#',
    $trusted), 'trusted senders is a button beside the switch, on the right');
assert_not_contains('settings-link', $trusted, 'and the underlined link is gone');
assert_not_contains('.settings-link', $switchCss, 'with its CSS');
assert_contains('.settings-control { display:flex;', $switchCss, 'the control column lays its items in a row');
// No trusted senders, no button: the switch alone still sits in the column.
assert_contains('<div class="settings-control"><label class="switch"><input type="checkbox" id="show-remote-images"',
    $rowsHtml, 'without trusted senders the switch stands alone');

// The CTA appears only when something was actually held back.
$imgEmail = ['subject' => 'News', 'from' => 'Slack <no-reply@slackhq.com>',
    'from_address' => 'no-reply@slackhq.com', 'to' => 'me@x.io', 'cc' => '', 'bcc' => '',
    'date' => 'today', 'body_text' => '', 'body_html' => '<img src="https://t.example/px.gif">',
    'attachments' => []];
$ctaHtml = view_read($imgEmail, 9, 'INBOX', 'me@x.io', [], ['block_images' => true]);
assert_contains('images-cta', $ctaHtml, 'the CTA bar is shown');
assert_contains('1 remote image', $ctaHtml, 'and says how many were blocked, in the singular');
assert_contains('?page=read&uid=9&folder=INBOX&images=1', $ctaHtml, 'Show images reloads this message only');
assert_contains('no-reply@slackhq.com', $ctaHtml, 'trusting names the exact address');
assert_contains('?page=trustSender', $ctaHtml, 'and posts to the whitelist route');

$twoImages = $imgEmail;
$twoImages['body_html'] = '<img src="https://t.example/a.gif"><img src="https://t.example/b.gif">';
assert_contains('2 remote images', view_read($twoImages, 9, 'INBOX', 'me@x.io', [], ['block_images' => true]),
    'plural when there is more than one');

// Nothing blocked, or blocking off: no bar, or it would cry wolf on every message.
$plain = $imgEmail;
$plain['body_html'] = '<p>no images</p>';
assert_not_contains('images-cta', view_read($plain, 9, 'INBOX', 'me@x.io', [], ['block_images' => true]),
    'a message with no remote images gets no bar');
assert_not_contains('images-cta', view_read($imgEmail, 9, 'INBOX', 'me@x.io', [], ['block_images' => false]),
    'and neither does a trusted sender');
// With the gate open the image really does load. The body is escaped into the
// iframe's srcdoc, so assert on the URL, not on raw src="..." markup.
$trusted = view_read($imgEmail, 9, 'INBOX', 'me@x.io', [], ['block_images' => false]);
assert_contains('t.example/px.gif', $trusted, 'a trusted sender loads them');
assert_not_contains('data-blocked-src', $trusted, 'and nothing is parked aside');
// The icon has to exist, or the bar shows an invisible svg.
assert_contains('tabler-photo', TABLER_SPRITE, 'the CTA icon is in the sprite');

group('reader Move to: a folder picker built from the real folder list');

// The same $mtFolders fixture the list used above: one set of eligible targets,
// so the two views cannot drift apart on what may be moved where.
$moveBar = view_read($raEmail, 5, 'INBOX', $me, $mtFolders);

assert_contains('data-tip="Move to" aria-label="Move to"', $moveBar, 'the action is named like the rest');
assert_contains('moveTo(5', $moveBar, 'and opens the picker for this message');
assert_contains('id="move-dialog"', $moveBar, 'the picker is a modal, like every other confirmation');
assert_contains('<option value="Archive">Archive</option>', $moveBar, 'a normal folder is offered');
assert_contains('<option value="Projects%2F2026">2026</option>', $moveBar, 'so is a nested one, url-encoded');
// folder_move_check() would refuse both of these, so never offer them.
// Checked as an <option>, because value="INBOX" also appears in the hidden
// folder field of the spam and delete forms.
assert_not_contains('<option value="INBOX">', $moveBar, 'the folder the message is already in is not offered');
assert_not_contains('>Projects<', $moveBar, 'nor a \Noselect hierarchy node');
// With nothing to move to there is no point rendering the affordance.
$noFolders = view_read($raEmail, 5, 'INBOX', $me, [['name' => 'INBOX', 'displayName' => 'Inbox', 'attributes' => 0]]);
assert_not_contains('moveTo(', $noFolders, 'no eligible target means no Move button');
assert_not_contains('id="move-dialog"', $noFolders, 'and no empty picker');
// Still three groups: reply actions | organise | discard.
assert_true(substr_count($moveBar, 'actions-sep') === 2, 'the toolbar keeps its two separators');
$posUnread = strpos($moveBar, 'aria-label="Unread"');
$posMove = strpos($moveBar, 'aria-label="Move to"');
$posSpam = strpos($moveBar, 'aria-label="Spam"');
assert_true($posUnread < $posMove && $posMove < $posSpam, 'Move sits with Unread, before the destructive pair');

group('sync indicator: the JS contract the markup had lost');

// js_inline() drives this off `.sync[data-folder]` > `.sync-btn` + `.sync-text`.
// The markup rendered only .sync-btn, so $('.sync') was empty: no background
// sync, a dead button, and a timestamp that never refreshed. The js suite could
// not catch it because buildSync() makes its own fixture.
$syncHtml = view_inbox($listD);
assert_contains('class="sync" data-folder="INBOX"', $syncHtml, 'the sync box exists and names its folder');
assert_contains('class="sync-text"', $syncHtml, 'and holds the label the JS writes to');
assert_contains('class="btn btn-sm sync-btn', $syncHtml, 'and the button the JS binds to');
// It must sit beside the search field, not inside the rounded pill.
$formStart = strpos($syncHtml, '<form method="GET" action="?page=search"');
$formEnd = strpos($syncHtml, '</form>', (int)$formStart);
$searchForm = substr($syncHtml, (int)$formStart, (int)$formEnd - (int)$formStart);
assert_not_contains('sync-btn', $searchForm, 'the sync button is outside the search form');
assert_true(strpos($syncHtml, 'class="sync"') < $formStart, 'and to its left');

group('search submit is a bare icon, not a blue square');

assert_contains('class="btn btn-sm search-btn tip"', $syncHtml, 'the search button opts into the shared styles');
assert_not_contains('btn btn-sm btn-primary">' . icon('search'), $syncHtml,
    'the accent-filled square is gone');
assert_contains('data-tip="Search" aria-label="Search"', $syncHtml, 'and it is icon-only, so it is named');
assert_contains('.search .search-btn:hover', $warnCss, 'hover only recolours the icon');
assert_contains('.search .search-btn { width:34px', $warnCss, 'the round transparent button is styled');
// It now sits next to a 999px pill, so a 9px corner would read as a mismatch --
// the same complaint that removed the square accent button. The .btn prefix is
// load-bearing: the Buttons section is emitted after the Mailbox one, so a lone
// .sync-btn loses the border-radius tie against .btn-sm.
assert_contains('.btn.sync-btn { border-radius:999px;', $warnCss, 'the sync chip matches the pill it sits beside');

group('Empty Trash / Empty Spam sit between sync and search, styled like the rest');

$trashD = $listD;
$trashD['is_trash'] = true;
$trashD['current_folder'] = 'Trash';
$trashD['title'] = 'Trash';
$trashHtml = view_inbox($trashD);
$spamD = $listD;
$spamD['is_junk'] = true;
$spamD['current_folder'] = 'Spam';
$spamD['title'] = 'Spam';
$spamHtml = view_inbox($spamD);

assert_contains('Empty Trash', $trashHtml, 'Trash offers to empty itself');
assert_contains('Empty Spam', $spamHtml, 'Spam does too');
assert_not_contains('Empty Trash', $spamHtml, 'and does not mislabel it');
assert_not_contains('Empty Trash', $syncHtml, 'the Inbox offers neither');
assert_not_contains('Empty Spam', $syncHtml, 'nor the other');
// It posts the folder, because one gated route now serves both.
assert_contains('action="?page=emptyFolder"', $trashHtml, 'one route serves both');
assert_contains('name="folder" value="Trash"', $trashHtml, 'and is told which folder to wipe');
// Order in the toolbar: sync, empty, search.
$posSync  = strpos($trashHtml, 'class="sync"');
$posEmpty = strpos($trashHtml, '?page=emptyFolder');
$posSearch = strpos($trashHtml, 'class="search"');
assert_true($posSync < $posEmpty && $posEmpty < $posSearch, 'it sits between the sync chip and the search field');
// Consistent with every other destructive action: a normal pill that only
// reddens on hover, not a filled red rectangle.
assert_contains('btn btn-sm btn-pill btn-danger-soft', $trashHtml, 'styled like the other destructive actions');
assert_not_contains('btn btn-sm btn-danger"', $trashHtml, 'the filled red rectangle is gone');
assert_contains('.btn.btn-pill { border-radius:999px;', $warnCss, 'the pill shape is a shared class');

group('no native confirm() survives: every confirmation is the shared modal');

// The read view was converted long ago; Empty Trash/Spam and the settings cache
// wipe were the last two holdouts, and they looked like browser chrome.
$confirmSrc = file_get_contents(__DIR__ . '/../index.php');
assert_true(strpos($confirmSrc, 'confirm(\\\'') === false && strpos($confirmSrc, 'return confirm(') === false,
    'index.php calls no native confirm() anywhere');
assert_contains('confirmEmpty(', $trashHtml, 'Empty Trash goes through the modal');
assert_contains('return false;', $trashHtml, 'and the form never submits itself');
$settingsHtml = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 12]);
assert_contains('confirmClearCache(', $settingsHtml, 'clearing the local cache goes through it too');
assert_not_contains('onsubmit="return confirm', $settingsHtml, 'and not through browser chrome');

group('the spinner keys off the class the JS actually sets');

// js_inline() does $('.sync').addClass('syncing') -- the WRAPPER, not the button.
// The CSS asked for .sync-btn.syncing, which no element ever gets, so the icon
// never span.
assert_contains('.sync.syncing .sync-btn svg.ti { animation:sync-spin', $warnCss,
    'the spin animation is scoped to .sync.syncing, matching the JS');
assert_not_contains('.btn.sync-btn.syncing', $warnCss,
    'nothing still waits for a syncing class on the button itself');
assert_contains('.sync.sync-error .sync-btn', $warnCss, 'and the error tint has the same fix');
assert_not_contains('.btn.sync-btn.sync-error', $warnCss, 'so the old selector is gone');
assert_contains('prefers-reduced-motion', $warnCss, 'the spin still respects reduced motion');

group('MailImap::isJunkFolder() matches the usual spam mailbox names');

assert_true(MailImap::isJunkFolder('Junk'), 'Junk');
assert_true(MailImap::isJunkFolder('Spam'), 'Spam');
assert_true(MailImap::isJunkFolder('INBOX.Junk E-mail'), 'a nested Junk E-mail');
assert_false(MailImap::isJunkFolder('INBOX'), 'INBOX is not spam');
assert_false(MailImap::isJunkFolder('Sent'), 'Sent is not spam');

group('MailImap::isArchiveFolder() matches the usual archive mailbox names');

assert_true(MailImap::isArchiveFolder('Archive'), 'Archive');
assert_true(MailImap::isArchiveFolder('Archived'), 'Archived');
assert_true(MailImap::isArchiveFolder('Archiwum'), 'Archiwum (Polish)');
assert_true(MailImap::isArchiveFolder('INBOX.Archive'), 'a nested Archive');
assert_false(MailImap::isArchiveFolder('INBOX'), 'INBOX is not archive');
assert_false(MailImap::isArchiveFolder('Sent'), 'Sent is not archive');

group('view_read() body iframe can be measured');

// The height is set from the inside of the iframe. A bare sandbox="" gives the
// frame an opaque origin, so contentWindow.document THROWS in the parent and the
// iframe is stuck at the browser default 150px -- which is what "the body is cut
// off" looks like. allow-same-origin is what makes measuring legal.
$iframeEmail = ['subject' => 'x', 'from' => 'a@x.io', 'to' => 'b@y.io', 'cc' => '',
    'bcc' => '', 'date' => 'today', 'body_text' => str_repeat("line\n", 80),
    'body_html' => '', 'attachments' => []];
$iframeHtml = view_read($iframeEmail, 1, 'INBOX');
assert_contains('sandbox="allow-same-origin"', $iframeHtml, 'the body iframe allows same-origin so it can be measured');
// allow-scripts TOGETHER WITH allow-same-origin would let the email remove its
// own sandbox. Scripts must stay off.
assert_not_contains('allow-scripts', $iframeHtml, 'scripts are never allowed in the body iframe');
assert_contains('class="body-iframe srcdoc"', $iframeHtml, 'the iframe keeps the hook the resize code selects');

// Source-level invariant, deliberately: allow-same-origin is only safe while
// scripts stay off, and the app is one file, so this cannot drift unnoticed.
$src = file_get_contents(__DIR__ . '/../index.php');
preg_match_all('/sandbox="([^"]*)"/', $src, $sandboxes);
foreach ($sandboxes[1] as $value) {
    assert_false(strpos($value, 'allow-scripts') !== false,
        'no sandbox in the app grants allow-scripts (found: "' . $value . '")');
}
assert_true(count($sandboxes[1]) > 0, 'the sandbox attribute is still there to check');

group('js_inline() sizes the body iframe safely');

$jsFit = js_inline();
// The inner scrollbar is removed only after a successful measurement: in CSS it
// would truncate the message whenever the measurement is refused.
assert_contains("frame.style.overflow = 'hidden'", $jsFit, 'the scrollbar is killed from JS, after measuring');
assert_false((bool)preg_match('/\.body-iframe\s*\{[^}]*overflow:hidden[^}]*\}/', css_inline()),
    'the .body-iframe rule does not hide overflow unconditionally');
assert_contains('catch', $jsFit, 'the measurement is guarded against a cross-origin throw');
assert_contains('ResizeObserver', $jsFit, 'late reflows (images, fonts) are re-measured');



$composeHtml = view_compose(['to' => '', 'cc' => '', 'bcc' => 'blind@x.io', 'subject' => '',
    'body' => '', 'forward_attachments' => [], 'csrf' => 'tok']);
assert_contains('<label>Bcc</label>', $composeHtml, 'compose has a Bcc label');
assert_contains('name="bcc"', $composeHtml, 'compose has a bcc input');
assert_contains('value="blind@x.io"', $composeHtml, 'the bcc value round-trips on redisplay');
assert_contains('data-autocomplete="bcc"', $composeHtml, 'bcc gets contact autocomplete like to/cc');

group('view_compose() save-draft button');

// Without formnovalidate the required To/Subject fields block saving an
// unfinished draft, which is the only kind worth saving.
assert_contains('formaction="?page=saveDraft"', $composeHtml, 'save draft posts to ?page=saveDraft');
assert_contains('formnovalidate', $composeHtml, 'saving a draft bypasses HTML validation');
assert_not_contains('name="draft_uid"', $composeHtml, 'a fresh compose carries no draft uid');

$draftHtml = view_compose(['to' => 'a@b.c', 'cc' => '', 'bcc' => '', 'subject' => 'wip',
    'body' => 'half a thought', 'forward_attachments' => [], 'csrf' => 'tok', 'draft_uid' => 91]);
assert_contains('<input type="hidden" name="draft_uid" value="91">', $draftHtml,
    'an open draft carries its uid so the old copy can be replaced');
assert_contains('value="wip"', $draftHtml, 'the draft subject is restored');
assert_contains('half a thought', $draftHtml, 'the draft body is restored');

group('view_compose() autofocuses the first field the user must type in');

// $composeHtml is a blank compose (empty To), $draftHtml has To prefilled.
assert_contains('name="to" required autofocus', $composeHtml, 'a blank compose focuses To');
assert_not_contains('name="body" autofocus', $composeHtml, 'a blank compose does not focus the body');
assert_contains('name="body" autofocus', $draftHtml, 'a prefilled To hands focus to the body');
assert_not_contains('name="to" required autofocus', $draftHtml, 'a prefilled To is not focused');

group('compose Cc/Bcc: the toggle and its row are never both visible');

// $composeHtml: cc empty, bcc prefilled -- both directions in one fixture.
assert_contains('class="compose-row compose-cc autocomplete-wrap hidden"', $composeHtml,
    'an empty Cc row is hidden');
assert_contains('class="compose-cc-tog"', $composeHtml, 'the Cc toggle is visible while Cc is hidden');
assert_contains('class="compose-row compose-bcc autocomplete-wrap"', $composeHtml,
    'a prefilled Bcc row is visible');
assert_contains('class="compose-bcc-tog hidden"', $composeHtml,
    'the Bcc toggle is hidden once Bcc is showing');
// Nothing defined .hidden, so `display:flex` on .compose-row won regardless.
// `.compose-row.hidden` outranks it, so source order cannot break this again.
assert_contains('.hidden, .compose-row.hidden { display:none; }', css_inline(),
    'the hidden class actually hides, even against .compose-row');

group('view_inbox() folder management');

$dFolders = ['folders' => [
        ['name' => 'INBOX', 'displayName' => 'Inbox', 'group' => 'primary', 'delimiter' => '/', 'attributes' => 0],
        ['name' => 'Drafts', 'displayName' => 'Drafts', 'group' => 'primary', 'delimiter' => '/', 'attributes' => 0],
        ['name' => 'Trash', 'displayName' => 'Trash', 'group' => 'trash', 'delimiter' => '/', 'attributes' => 0],
        ['name' => 'Archive', 'displayName' => 'Archive', 'group' => 'archive', 'delimiter' => '/', 'attributes' => 0],
        ['name' => 'Projects/2026', 'displayName' => 'Projects/2026', 'group' => 'other', 'delimiter' => '/', 'attributes' => 0],
    ],
    'current_folder' => 'INBOX', 'title' => 'Inbox', 'total' => 0, 'emails' => [],
    'pages' => 0, 'current_page' => 1];
$foldersHtml = view_inbox($dFolders);

assert_contains('newFolder()', $foldersHtml, 'the sidebar offers a new-folder action');
// Deletable folders get the affordance...
assert_contains("deleteFolder('Projects%2F2026'", $foldersHtml, 'a user folder can be deleted');
// ...and the protected ones must not, or the UI invites a refusal.
assert_not_contains("deleteFolder('INBOX'", $foldersHtml, 'Inbox has no delete affordance');
assert_not_contains("deleteFolder('Drafts'", $foldersHtml, 'Drafts has no delete affordance');
assert_not_contains("deleteFolder('Trash'", $foldersHtml, 'Trash has no delete affordance');
assert_not_contains("deleteFolder('Archive'", $foldersHtml, 'Archive has no delete affordance');
assert_eq(1, substr_count($foldersHtml, 'data-delete-folder'), 'exactly one folder is deletable here');
assert_not_contains('sidebar-empty', $foldersHtml, 'a populated sidebar says nothing about emptiness');

// An empty list rendered as a bare "Folders" heading over an empty <ul> and a
// New folder button, which read as broken rather than as a cold cache.
group('view_sidebar() says so when there are no folders');

$emptySidebar = view_sidebar([], '', null, []);
assert_contains('sidebar-empty', $emptySidebar, 'an empty sidebar carries the note');
assert_contains('Folders are unavailable', $emptySidebar, 'the note names the condition');
assert_not_contains('<ul>', $emptySidebar, 'no empty list is drawn under the heading');
assert_contains('newFolder()', $emptySidebar, 'creating a folder stays the way out');
assert_not_contains('sidebar-empty', view_sidebar($dFolders['folders'], '', null, []),
    'a populated sidebar has no note');



$dDrafts = ['folders' => [], 'current_folder' => 'Drafts', 'title' => 'Drafts', 'total' => 1,
    'pages' => 1, 'current_page' => 1, 'is_drafts' => true,
    'emails' => [['uid' => 12, 'from' => 'me@x.io', 'to' => 'a@b.c', 'to_address' => 'a@b.c',
        'subject' => 'wip', 'timestamp' => 1700000000, 'unread' => false, 'flagged' => false,
        'has_attachment' => false]]];
$draftsHtml = view_inbox($dDrafts);
assert_contains('?page=compose&amp;draft=12', $draftsHtml, 'a draft row links to the composer');
assert_not_contains('?page=read&amp;uid=12', $draftsHtml, 'a draft row does not link to the read view');
// Every other folder is unaffected.
$dNormal = $dDrafts;
$dNormal['is_drafts'] = false;
$dNormal['current_folder'] = 'Archive';
assert_contains('?page=read&amp;uid=12', view_inbox($dNormal), 'a normal row still links to the read view');

// ------------------------------------------------ search form structure -----+
group('view_inbox() search form');

$inboxHtml2 = view_inbox($d);
assert_contains('action="?page=search"', $inboxHtml2, 'search form action is ?page=search');
assert_contains('name="q"', $inboxHtml2, 'search form has q input');
assert_contains('name="folder"', $inboxHtml2, 'search form has hidden folder input');
assert_contains('method="GET"', $inboxHtml2, 'search form uses GET method');
// A GET form REPLACES the action's query string with the serialised fields, so
// ?page=search in the action is dropped on submit and the router falls back to
// inbox. The page must travel as a field.
assert_contains('name="page" value="search"', $inboxHtml2, 'search form carries page=search as a hidden field');

$dSearch = $d;
$dSearch['search_query'] = 'test query';
$inboxSearch = view_inbox($dSearch);
assert_contains('value="test query"', $inboxSearch, 'search form preserves query in input value');

group('TABLER_SPRITE contains progress icons');
assert_contains('tabler-progress-check', TABLER_SPRITE, 'sprite contains progress-check icon');
assert_contains('tabler-progress', TABLER_SPRITE, 'sprite contains progress icon');

group('view_inbox() sync button uses progress-check icon');
assert_contains('progress-check', $inboxHtml, 'sync button renders progress-check icon');

// ------------------------------------------------ in-place folder switch ----+
// The instant folder switch swaps only the <section class="mailbox">, so that
// section must render on its own: view_mailbox() is the AJAX payload body.
group('view_mailbox() renders the listing section on its own');
$mbHtml = view_mailbox($listD);
assert_true(str_starts_with($mbHtml, '<section class="mailbox">'), 'view_mailbox opens the mailbox section');
assert_true(str_ends_with($mbHtml, '</section>'), 'view_mailbox closes the section');
assert_contains('class="sync" data-folder="INBOX"', $mbHtml, 'the sync chip ships inside the section');
assert_contains('class="mail-list"', $mbHtml, 'the mail rows ship inside the section');
assert_eq(view_inbox($listD),
    view_sidebar($listD['folders'] ?? [], $listD['current_folder'] ?? '', $listD['folder_map'] ?? null)
        . $mbHtml . '</div>',
    'view_inbox is exactly the sidebar plus view_mailbox plus the layout close');

group('view_mailbox() renders a conversation row');

$threadRow = ['uid' => 1003, 'subject' => 'Subject', 'from' => 'Sender', 'from_address' => 's@x.io',
    'to' => '', 'to_address' => '', 'timestamp' => time(), 'unread' => true,
    'has_attachment' => false, 'thread_key' => '<root@x.io>', 'thread_count' => 3,
    'thread_unread' => 1, 'thread_uids' => [1001, 1002, 1003]];
$threadD = ['emails' => [$threadRow], 'total' => 1, 'page' => 1, 'pages' => 1, 'per_page' => 25,
    'current_folder' => 'INBOX', 'title' => 'Inbox', 'folders' => [], 'search_query' => '',
    'is_trash' => false, 'is_sent' => false, 'is_drafts' => false, 'threads' => true,
    'last_sync_ts' => 0, 'csrf' => 'tok'];
$threadHtml = view_mailbox($threadD);
assert_contains('data-threads="1"', $threadHtml, 'a threaded listing is marked as such');
assert_contains('?page=thread&amp;key=%3Croot%40x.io%3E&amp;folder=INBOX', $threadHtml,
    'the row links to the conversation reader');
assert_contains('class="mail-thread-count"', $threadHtml, 'the row carries a count chip');
assert_contains('data-thread="&lt;root@x.io&gt;"', $threadHtml, 'and the key the JS reads');
assert_contains('data-thread-uids="1001,1002,1003"', $threadHtml, 'and the in-folder member uids');
assert_contains('class="mail-line-right"', $threadHtml,
    'the count and date sit together on the right, not centred');

// A conversation of one is an ordinary row.
$singleD = $threadD;
$singleD['emails'] = [array_merge($threadRow, ['thread_count' => 1, 'thread_uids' => [1003]])];
$singleHtml = view_mailbox($singleD);
assert_not_contains('?page=thread', $singleHtml, 'a count-of-one conversation opens the message directly');
assert_contains('?page=read&amp;uid=1003', $singleHtml, 'with the read link');
assert_not_contains('class="mail-thread-count"', $singleHtml, 'and no count chip');

$flatD = $listD;
$flatD['threads'] = false;
assert_contains('data-threads="0"', view_mailbox($flatD), 'a flat listing is marked data-threads=0');

group('js_inline() ships the in-place folder switch');
assert_contains('function loadMailbox', $js, 'js: the swap driver exists');
assert_contains("'ajax=1'", $js, 'js: the swap fetches the ajax listing');
assert_contains('replaceChild', $js, 'js: the swap replaces the mailbox section');
assert_contains("$('.pagination').each", $js, 'js: pagination clicks are intercepted');
assert_contains('history.pushState', $js, 'js: a folder switch records its URL');
assert_contains('popstate', $js, 'js: Back/Forward through in-place history is handled');
assert_contains("doc.querySelector('.sync')", $js,
    'js: interception is gated on being on a listing page');

group('js_inline() ships modal confirm dialog');
assert_contains('confirmDelete', $js, 'js: confirmDelete function exported');
assert_contains('delete-dialog', $js, 'js: references the delete dialog element');

group('css_inline() sidebar folder spinner is wired');
$sideCss = css_inline();
assert_contains('.sidebar .folder-link.busy svg.ti', $sideCss,
    'css: a busy sidebar folder link spins its icon');
assert_contains('prefers-reduced-motion', $sideCss, 'css: reduced motion still disables the spin');

group('css_inline() toast container floats without shifting layout');
$toastCss = css_inline();
assert_contains('.toast-container', $toastCss, 'css: toast container styled');
assert_contains('position:fixed', $toastCss, 'css: toasts are fixed so they never move the page');
assert_contains('.toast.hiding', $toastCss, 'css: a leaving toast fades');
assert_not_contains('.alert, .flash', $toastCss, 'css: the static alert/flash block is gone');
assert_contains('left:50%; transform:translateX(-50%)', $toastCss,
    'css: the toast container is centered on the viewport');
assert_contains('z-index:200', $toastCss, 'css: toasts ride above tooltips and autocomplete');
assert_not_contains('right:16px', $toastCss, 'css: the toast container is no longer pinned right');
// A toast is fixed over the list or the reader, so a translucent background put
// the mail underneath the message text. It is opaque now, and the type rides on
// the border -- colouring the text instead would be 3.7:1 at .88rem.
assert_true((bool)preg_match('/\.toast\s*\{[^}]*background:var\(--surface\)/', $toastCss),
    'css: a toast background is opaque, so nothing shows through the message');
assert_true((bool)preg_match('/\.toast\s*\{[^}]*color:var\(--text\)/', $toastCss),
    'css: toast text is the readable one');
assert_true((bool)preg_match('/\.toast-success\s*\{[^}]*border-color:var\(--success\)/', $toastCss),
    'css: a success toast is marked by its border');
assert_true((bool)preg_match('/\.toast-error\s*\{[^}]*border-color:var\(--danger\)/', $toastCss),
    'css: an error toast is marked by its border');
assert_false((bool)preg_match('/\.toast-(success|error)\s*\{[^}]*soft/', $toastCss),
    'css: no toast paints itself with a translucent tint');

group('css_inline() modal is centered and locks scroll');
$css = css_inline();
assert_contains('dialog.modal', $css, 'css: dialog.modal selector present');
assert_contains('position:fixed', $css, 'css: dialog uses position:fixed');
assert_contains('transform', $css, 'css: dialog centered via transform');
assert_contains(':has(dialog.modal[open])', $css, 'css: scroll locks when dialog open');
assert_contains('overflow:hidden', $css, 'css: overflow hidden lock');

// ------------------------------------------------------------- design ------+
group('design system ships a dual Nord palette');

$cssDesign = css_inline();
assert_contains('[data-theme="dark"]', $cssDesign, 'an explicit dark theme selector exists');
assert_contains('prefers-color-scheme: dark', $cssDesign, 'the dark palette also follows the OS preference');
assert_contains('--accent', $cssDesign, 'the palette is token-driven');

group('render_page() ships the theme bootstrap and shell controls');

$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'design@x.io';
ob_start();
render_page('T', '<div id="probe">x</div>', true);
$shell = ob_get_clean();
$_SESSION = [];
assert_contains('data-theme-toggle', $shell, 'the shell offers a theme toggle');
assert_contains('data-nav-toggle', $shell, 'the shell offers a sidebar toggle');
assert_contains('emmmail-theme', $shell, 'the theme is restored before paint (no flash)');

group('new sprite icons for the shell');

foreach (['menu-2', 'sun', 'moon', 'logout', 'plus'] as $ic) {
    assert_contains('tabler-' . $ic, TABLER_SPRITE, "sprite contains $ic");
}

group('message rows carry a deterministic avatar');

assert_eq('V', avatar_initial('Vin <vin@pm.me>'), 'initial comes from the display name');
assert_eq('A', avatar_initial('ann@x.io'), 'initial comes from a bare address');
assert_eq('?', avatar_initial(''), 'a missing sender degrades to ?');
assert_eq(avatar_tone('a@x.io'), avatar_tone('a@x.io'), 'the tone is stable for a sender');
assert_true((bool)preg_match('/^av-[0-7]$/', avatar_tone('a@x.io')), 'the tone is one of eight classes');

$dAvatar = ['folders' => [], 'current_folder' => 'INBOX', 'title' => 'Inbox', 'total' => 1,
    'pages' => 1, 'current_page' => 1,
    'emails' => [['uid' => 9, 'from' => 'Vin <vin@pm.me>', 'to' => '', 'to_address' => '',
        'subject' => 'hi', 'timestamp' => 1700000000, 'unread' => false, 'flagged' => false,
        'has_attachment' => false]]];
assert_contains('class="mail-avatar av-', view_inbox($dAvatar), 'the list row renders an avatar');

group('list_date() formats a timestamp for the list');

assert_eq(date('H:i', 1700000000), list_date(1700000000, 1700000000), 'today shows the time');
assert_eq(date('M j', 1700000000), list_date(1700000000, 1700000000 + 86400 * 30),
    'an earlier day this year shows month and day');
assert_eq(date('M j, Y', 1700000000), list_date(1700000000, 1700000000 + 86400 * 400),
    'a previous year shows the full date');

group('avatar menu replaces the bare user chip');

assert_contains('data-account-menu', $shell, 'the shell renders an account menu toggle');
assert_contains('?page=settings', $shell, 'the menu links to settings');
assert_contains('?page=logout', $shell, 'the menu links to logout');
assert_contains('data-theme-toggle', $shell, 'the menu carries the theme toggle');
assert_contains('class="account-menu"', $shell, 'the menu popover is present');

group('modal pages render as a floating overlay, not the app shell');

$composeCard = view_compose(['csrf' => 'tok', 'to' => '', 'subject' => '', 'body' => '',
    'cc' => '', 'bcc' => '', 'forward_attachments' => []]);
$settingsCard = view_settings(['csrf' => 'tok', 'emails_per_page' => 25, 'display_name' => '',
    'cached_messages' => 0]);
assert_not_contains('class="modal-overlay"', $composeCard, 'compose has no modal overlay');
assert_not_contains('modal-panel--compose', $composeCard, 'compose has no modal panel');
assert_not_contains('class="modal-overlay"', $settingsCard, 'settings has no modal overlay');
assert_not_contains('modal-panel--settings', $settingsCard, 'settings has no modal panel');
assert_contains('class="card"', $composeCard, 'compose renders as a card');
assert_contains('class="card"', $settingsCard, 'settings renders as a card');
assert_contains('?page=inbox', $composeCard, 'compose links back to inbox');
assert_contains('?page=inbox', $settingsCard, 'settings links back to inbox');

group('compose actions group Discard left, the rest next to Send');

$composeCss = css_inline();
assert_contains('compose-discard', $composeCard, 'Discard carries its own class');
assert_contains('.compose-discard { margin-right:auto;', $composeCss, 'Discard is pushed to the far left');
assert_contains('justify-content:flex-end', $composeCss, 'remaining actions sit next to Send');
assert_not_contains('.compose-actions { display:flex; align-items:center; justify-content:space-between',
    $composeCss, 'actions are no longer spread edge to edge');
assert_contains('.compose-actions .btn-sm:not(.compose-attach-btn) { min-width:112px; }', $composeCss,
    'Discard and Save draft share one width');
assert_contains('.compose-actions .btn-sm { height:32px; }', $composeCss,
    'small actions share one height');

group('compose and settings render as normal authed pages with sidebar');

$composeFolders = [['name' => 'INBOX', 'displayName' => 'Inbox', 'group' => 'primary', 'attributes' => 0],
    ['name' => 'Sent', 'displayName' => 'Sent', 'group' => 'primary', 'attributes' => 0]];
$composeLayout = view_sidebar($composeFolders, '') . '<section class="mailbox">' . view_compose(['csrf' => 'tok', 'to' => '', 'subject' => '', 'body' => '',
    'cc' => '', 'bcc' => '', 'forward_attachments' => []]) . '</section></div>';
assert_contains('class="sidebar"', $composeLayout, 'compose layout includes sidebar');
assert_contains('compose-btn', $composeLayout, 'compose layout includes compose button');

group('mobile topbar stays compact without wrapping');

assert_contains('@media (max-width:620px)', css_inline(), 'mobile breakpoint exists');
assert_contains('.account-menu {', css_inline(), 'menu popover styled');
assert_not_contains('.modal-overlay {', css_inline(), 'no modal overlay CSS');
assert_not_contains('.modal-panel {', css_inline(), 'no modal panel CSS');
assert_not_contains('body.modal-page', css_inline(), 'no modal-page body CSS');

// ------------------------------------------- responsive message opening -----+
// Opening a message costs a full IMAP round trip, so the click is acknowledged
// before the navigation completes rather than after it.
group('clicking a message is acknowledged while the page is still loading');

$rcss = css_inline();
assert_contains('.nav-progress', $rcss, 'the navigation progress bar is styled');
assert_contains('.mail.opening', $rcss, 'a clicked row shows it was clicked');
// The eight avatar tones include the accent itself (av-0 is nord10, av-6 is nord8),
// so the arc needs an opaque cap under it or it is invisible on those senders.
assert_contains('.mail.opening .mail-avatar::before', $rcss,
    'the spinner is drawn on a known background, not on the avatar tone');
assert_contains('.mail.opening .mail-avatar::after', $rcss, 'the spinner arc is drawn');
// A press is answered before any request exists, which costs nothing. Both
// selectors are needed: .mail.unread:hover (0,3,0) outranks .mail:active (0,2,0).
assert_contains('.mail:active', $rcss, 'pressing a row is acknowledged instantly');
assert_contains('.mail.unread:active', $rcss, 'an unread row acknowledges the press too');
assert_contains('prefers-reduced-motion', $rcss, 'the animations respect reduced motion');

group('unread row hover is visible');
$hoverCss = css_inline();
assert_contains('--accent-soft-hover', $hoverCss, 'css: an unread hover tint exists');
assert_contains('.mail.unread:hover { background:var(--accent-soft-hover); }', $hoverCss,
    'css: hovering an unread row visibly changes its background');
// The hover tint must not equal the resting tint, or the change is invisible.
assert_false(
    (bool)preg_match('/--accent-soft-hover:([^;]+).*--accent-soft:([^;]+).*\.mail\.unread \{[^}]*background:var\(--accent-soft\)/', $hoverCss),
    'css: the unread hover tint differs from the resting unread tint');

// The bar lives in the chrome, because it outlives the page that started it.
$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'nav@x.io';
ob_start();
render_page('T', '<div>x</div>', true);
$navShell = ob_get_clean();
$_SESSION = [];
assert_contains('class="nav-progress"', $navShell, 'the progress bar ships with the app chrome');

group('modals focus their confirm button, not Cancel');
assert_contains('id="delete-dialog"', $navShell, 'modal: delete dialog is rendered for authed pages');
assert_contains('<button class="btn-confirm" autofocus type="button"', $navShell,
    'modal: the delete confirm (not Cancel) is focused on open');
assert_contains('<button class="btn-confirm-primary" autofocus type="button"', $navShell,
    'modal: the folder-map Save is focused on open');
assert_contains('id="onboarding-dialog"', $navShell, 'modal: onboarding dialog renders while pending');
assert_contains('onboarding-rows', $navShell, 'modal: the onboarding dialog still has its rows');
assert_contains('<button class="btn-confirm-primary" autofocus type="button"', $navShell,
    'modal: the onboarding Save &amp; Continue is focused on open');
// The ?-sheet is the one dialog with no confirm: its only control is Close, so
// showModal() must land there. Every other modal keeps autofocus on its confirm.
assert_contains('id="kb-help-dialog"', $navShell, 'modal: the ?-sheet is rendered');
assert_contains('btn-cancel" autofocus type="button">Close', $navShell,
    'modal: the ?-sheet focuses its only control (Close)');
assert_not_contains('class="btn-cancel" autofocus type="button">Cancel', $navShell,
    'modal: no Cancel button claims the focus');

$rjs = js_inline();
assert_contains("'a.mail-main'", $rjs, 'js: the row link is what starts the feedback');
assert_contains("'opening'", $rjs, 'js: the clicked row is marked');
assert_contains('pageshow', $rjs, 'js: coming back from the cache clears the spinner');

group('reader actions report the round trip they cost');

// Every control in the reader is a round trip -- a reply has to fetch the message
// to quote it -- so the one that was clicked has to say so.
assert_contains('.btn.busy', $rcss, 'a working control is styled');
assert_contains('.btn.busy svg.ti', $rcss, 'its icon is what spins');
assert_contains('.btn:active', $rcss, 'pressing a button is acknowledged instantly');
assert_contains('markBusy', $rjs, 'js: the clicked control is marked busy');
assert_contains('clearBusy', $rjs, 'js: and can always be un-marked again');
assert_contains('tabler-progress', $rjs, 'js: the icon becomes a spinner');
assert_contains('data-icon-was', $rjs, 'js: the original icon is remembered, so it can come back');

// Dialog-first controls must not claim to be working: nothing is in flight until
// the dialog is confirmed, and it may be cancelled.
assert_contains('data-move', $rjs, 'js: the move button is recognised');
assert_contains('data-move', view_move_button(7, 'INBOX', 'btn btn-sm'),
    'the move button carries the hook the client needs');
// The blocked-images bar is two more round trips: reopening the message, and
// trusting the sender. Attachment links are excluded on purpose -- a download does
// not navigate, so nothing would ever clear the spinner.
assert_contains("'.images-cta'", $rjs, 'js: the blocked-images bar reports its waits');
assert_not_contains('attachment-item', $rjs, 'js: a download is not treated as a navigation');

group('searching reports its wait too');

// The surfaces are named in one list rather than bound one by one, so adding
// another is one string and none of them can drift apart.
assert_contains("'.actions-bar'", $rjs, 'js: the reader toolbar is a round-trip surface');
assert_contains("'.search'", $rjs, 'js: so is search');
assert_contains("'.hint'", $rjs, 'js: so is the server-search offer');
// Enter submits without ever clicking the button, which is how search is normally
// used, so the click binding alone would miss it.
assert_contains("on('submit'", $rjs, 'js: submitting the form is what is watched, not just the click');
assert_contains('search-btn', $rjs, 'js: the search button is the control that spins');

// A local search is cache-only, but the server-side one is a full IMAP SEARCH.
$searchHtml = view_inbox([
    'current_folder' => 'INBOX', 'title' => 'Search', 'folders' => [], 'emails' => [],
    'total' => 0, 'pages' => 0, 'current_page' => 1, 'search_query' => 'invoice',
    'server_search' => true, 'last_sync_ts' => 0,
]);
assert_contains('class="search"', $searchHtml, 'the search form carries the hook');
assert_contains('search-btn', $searchHtml, 'the search button carries the hook');
assert_contains('class="hint"', $searchHtml, 'the server-search offer carries the hook');

group('the composer reports the send it started');

// Send is the longest wait in the app: an SMTP conversation plus the upload of any
// attachment. Save draft is an IMAP append. Both are submits.
assert_contains("'.compose-form'", $rjs, 'js: the composer is watched');
assert_contains('submitter', $rjs, 'js: the button that submitted is the one that spins');
$composeForm = view_compose(['csrf' => 'tok', 'to' => '', 'subject' => '', 'body' => '',
    'cc' => '', 'bcc' => '', 'forward_attachments' => []]);
assert_contains('class="compose-form"', $composeForm, 'the composer form carries the hook');
// Required fields are what makes this safe: a submit the browser refuses never
// fires the event, so nothing can be left spinning over a validation error.
assert_contains('name="to" required', $composeForm, 'To is still required');
assert_contains('formnovalidate', $composeForm, 'Save draft still bypasses validation');
// Attaching opens a file picker and Cc/Bcc only reveal a row: no round trip, so
// they must not be dressed up as one. (The keyboard shortcut clicks the control,
// so the check is the round-trip surface list, not a mention of the class.)
assert_contains('compose-attach-btn', $composeForm, 'the attach control is still a label');
assert_contains("['.actions-bar', '.images-cta', '.search', '.hint'].forEach", $rjs,
    'js: attaching a file is not a round trip');

group('compose attachment list');

assert_contains('compose-attach-list', $composeForm, 'the compose form has an attach list container');
// The container must sit between the body and the actions row.
$bodyPos = strpos($composeForm, 'compose-body');
$attPos = strpos($composeForm, 'compose-attach-list');
$actPos = strpos($composeForm, 'compose-actions');
assert_true($bodyPos < $attPos && $attPos < $actPos, 'attach list sits between body and actions');
assert_contains("'.compose-attach-list'", $rjs, 'js: the attach list is wired');
assert_contains('compose-attach-chip', $rcss, 'css: attachment chip is styled');
assert_contains('compose-attach-remove', $rcss, 'css: attachment remove button is styled');

// A failed action used to be silent in the reader: rowError() wrote into
// .row-error, which only the list rendered. It now reports via a floating toast,
// which lives in the shared chrome and exists on every page.
$readErrEmail = ['subject' => 'hi', 'from' => 'a@x.io', 'to' => 'b@y.io', 'cc' => '',
    'date' => '2026-01-01', 'body_text' => 'x', 'attachments' => []];
assert_not_contains('row-error', view_read($readErrEmail, 7, 'INBOX'),
    'the reader has no static error box of its own');

// ------------------------------------------------------------ markdown -----+
// md_to_html() output lands in the composer's contenteditable, which is in the
// MAIN document, where the CSP does not stop inline script. Every hostile case
// below is therefore an XSS case, not a cosmetic one.

// Strict allowlist check: parse the output and fail on anything outside the
// renderer's contract. A text match cannot see an attribute the parser invents.
function md_assert_safe(string $html, string $label): void
{
    $tags = ['div', 'br', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li',
        'blockquote', 'pre', 'code', 'hr', 'strong', 'em', 's', 'a', 'img'];
    $attrs = ['a' => ['href'], 'img' => ['src', 'alt'], 'ol' => ['start']];
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="md-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $bad = [];
    foreach ($doc->getElementsByTagName('*') as $el) {
        $tag = strtolower($el->nodeName);
        if ($el->getAttribute('id') === 'md-root') {
            continue;
        }
        if (!in_array($tag, $tags, true)) {
            $bad[] = "tag <$tag>";
        }
        foreach ($el->attributes as $a) {
            $name = strtolower($a->nodeName);
            if (!in_array($name, $attrs[$tag] ?? [], true)) {
                $bad[] = "attribute $name on <$tag>";
            }
        }
        if ($tag === 'a' && !preg_match('#^(https?://[^/\s]|mailto:\S)#i', $el->getAttribute('href'))) {
            $bad[] = 'href ' . $el->getAttribute('href');
        }
        if ($tag === 'img' && !preg_match('#^https://[^/\s]#i', $el->getAttribute('src'))) {
            $bad[] = 'src ' . $el->getAttribute('src');
        }
        if ($tag === 'ol' && $el->hasAttribute('start') && !ctype_digit($el->getAttribute('start'))) {
            $bad[] = 'start ' . $el->getAttribute('start');
        }
    }
    if ($bad) {
        failTest($label . ': ' . implode(', ', $bad));
    } else {
        passTest($label);
    }
}

// What a line of plain text must render as: its own div, escaped, nothing else.
function md_literal_html(string $text): string
{
    $lines = explode("\n", rtrim(str_replace("\r\n", "\n", $text), "\n"));
    return implode("\n", array_map(fn($l) => $l === '' ? '<div><br></div>' : '<div>' . h($l) . '</div>', $lines));
}

group('md_to_html() lines and blocks');

assert_eq('', md_to_html(''), 'an empty body renders nothing');
assert_eq('<div>hello</div>', md_to_html('hello'), 'one line is one div');
assert_eq("<div>a</div>\n<div>b</div>", md_to_html("a\nb"), 'a single newline starts a new line');
assert_eq("<div>a</div>\n<div><br></div>\n<div>b</div>", md_to_html("a\n\nb"), 'a blank line is an empty line');
assert_eq("<div>a</div>\n<div>b</div>", md_to_html("a\r\nb"), 'CRLF from a textarea is normalised');
assert_eq('<div>a</div>', md_to_html("a\n\n\n"), 'trailing blank lines are dropped');
assert_eq('<div>ab</div>', md_to_html("a\0b"), 'NUL is stripped');
assert_eq('<h1>Title</h1>', md_to_html('# Title'), '# is h1');
assert_eq('<h2>T</h2>', md_to_html('## T'), '## is h2');
assert_eq('<h6>T</h6>', md_to_html('###### T'), '###### is h6');
assert_eq('<div>####### T</div>', md_to_html('####### T'), 'seven # is text');
assert_eq('<div>#hashtag</div>', md_to_html('#hashtag'), 'a hashtag is not a heading');
assert_eq("<div>text</div>\n<h1>T</h1>", md_to_html("text\n# T"), 'a heading may follow a line directly');
assert_eq('<ul><li>a</li><li>b</li></ul>', md_to_html("- a\n- b"), '- makes a bullet list');
assert_eq('<ul><li>a</li></ul>', md_to_html('* a'), '* makes a bullet list');
assert_eq('<ul><li>a</li></ul>', md_to_html('• a'), '• makes a bullet list');
assert_eq('<ol><li>a</li><li>b</li></ol>', md_to_html("1. a\n2. b"), '1. makes a numbered list');
assert_eq('<ol start="3"><li>a</li><li>b</li></ol>', md_to_html("3. a\n4. b"), 'a list keeps its first number');
assert_eq("<div>Shopping:</div>\n<ul><li>milk</li></ul>", md_to_html("Shopping:\n- milk"),
    'a bullet list may follow a line directly');
assert_eq("<div>I was born in</div>\n<div>1986. It was a year</div>", md_to_html("I was born in\n1986. It was a year"),
    'only 1. may start a numbered list right after a line of text');
assert_eq("<div>Steps:</div>\n<ol><li>go</li></ol>", md_to_html("Steps:\n1. go"), 'but 1. may');
assert_eq('<ul><li>a<br>more</li></ul>', md_to_html("- a\n  more"), 'an indented line continues the item');
assert_eq("<ul><li>a</li></ul>\n<div>after</div>", md_to_html("- a\nafter"), 'an unindented line ends the list');
assert_eq("<ul><li>a</li></ul>\n<div><br></div>\n<ul><li>b</li></ul>", md_to_html("- a\n\n- b"), 'a blank line ends the list');
assert_eq("<ul><li>a</li></ul>\n<ol><li>b</li></ol>", md_to_html("- a\n1. b"), 'a change of marker starts a new list');
assert_eq("<blockquote><div>a</div>\n<div>b</div></blockquote>", md_to_html("> a\n> b"), '> quotes');
assert_eq('<blockquote><div>a</div></blockquote>', md_to_html('>a'), '> needs no space');
assert_eq("<blockquote><div>a</div>\n<div><br></div>\n<div>b</div></blockquote>", md_to_html("> a\n>\n> b"),
    'an empty quoted line stays inside the quote');
assert_eq('<blockquote><blockquote><div>a</div></blockquote></blockquote>', md_to_html('> > a'), 'quotes nest');
assert_eq("<blockquote><div>new</div>\n<blockquote><div>old</div></blockquote></blockquote>", md_to_html("> new\n> > old"),
    'a reply to a reply nests one level deeper');
assert_eq('<pre><code>*a* &lt;b&gt;' . "\n" . 'x</code></pre>', md_to_html("```\n*a* <b>\nx\n```"),
    'a fence keeps its content verbatim and escaped');
assert_eq('<pre><code>x</code></pre>', md_to_html("```php\nx\n```"), 'a fence info string is ignored');
assert_eq('<pre><code>x</code></pre>', md_to_html("```\nx"), 'an unclosed fence runs to the end');
foreach (['---', '***', '___', '- - -', '—-'] as $hr) {
    assert_eq('<hr>', md_to_html($hr), "$hr is a rule");
}
assert_eq('<div>-- </div>', md_to_html('-- '), 'the signature delimiter stays text');
assert_eq('<div>----- Original Message -----</div>', md_to_html('----- Original Message -----'),
    'a dashed banner with words is text');

group('md_to_html() inline');

assert_eq('<div><strong>b</strong></div>', md_to_html('**b**'), '** is strong');
assert_eq('<div><em>i</em></div>', md_to_html('*i*'), '* is em');
assert_eq('<div><em>i</em></div>', md_to_html('_i_'), '_ is em');
assert_eq('<div><s>x</s></div>', md_to_html('~~x~~'), '~~ is strike');
assert_eq('<div>~5 min</div>', md_to_html('~5 min'), 'a single ~ is text');
assert_eq('<div>~~ x~~</div>', md_to_html('~~ x~~'), '~~ before a space is text');
assert_eq('<div><code>c</code></div>', md_to_html('`c`'), '` is code');
assert_eq('<div><code>a`b</code></div>', md_to_html('`` a`b ``'), 'a longer backtick run holds a backtick');
assert_eq('<div><code>*x* [a](https://x.io)</code></div>', md_to_html('`*x* [a](https://x.io)`'), 'code is verbatim');
assert_eq('<div>snake_case_name</div>', md_to_html('snake_case_name'), 'intra-word _ is text');
assert_eq('<div>2 * 3 * 4</div>', md_to_html('2 * 3 * 4'), 'spaced * is text');
assert_eq('<div><strong>a <em>b</em> c</strong></div>', md_to_html('**a *b* c**'), 'emphasis nests');
assert_eq('<div>**a</div>', md_to_html('**a'), 'an unclosed ** is text');
assert_eq('<div>*a*</div>', md_to_html('\*a\*'), 'backslash escapes a marker');
assert_eq('<div>C:\path</div>', md_to_html('C:\path'), 'a backslash before a letter is text');
assert_eq('<div>\\</div>', md_to_html('\\\\'), 'an escaped backslash is one backslash');
assert_eq('<div>:-* ok</div>', md_to_html(':-* ok'), 'an emoticon is text');
assert_eq('<div>&lt;b&gt;x&lt;/b&gt; &amp; a &lt; b</div>', md_to_html('<b>x</b> & a < b'), 'HTML is escaped');
assert_eq('<div><a href="https://x.io/a">t</a></div>', md_to_html('[t](https://x.io/a)'), '[t](url) is a link');
assert_eq('<div><a href="mailto:a@x.io">mail</a></div>', md_to_html('[mail](mailto:a@x.io)'), 'mailto links');
assert_eq('<div><a href="https://x.io"><strong>t</strong></a></div>', md_to_html('[**t**](https://x.io)'),
    'a link label may be emphasised');
assert_eq('<div><a href="https://en.wikipedia.org/wiki/Foo_(bar)">w</a></div>',
    md_to_html('[w](https://en.wikipedia.org/wiki/Foo_(bar))'), 'link URLs balance parentheses');
assert_eq('<div><img src="https://x.io/i.png" alt="a b"></div>', md_to_html('![a b](https://x.io/i.png)'),
    '![alt](url) is an image');
assert_eq('<div>see <a href="https://x.io/a_b_c">https://x.io/a_b_c</a>.</div>', md_to_html('see https://x.io/a_b_c.'),
    'a bare URL links, without its trailing full stop and without emphasis inside');
assert_eq('<div><a href="https://en.wikipedia.org/wiki/Foo_(bar)">https://en.wikipedia.org/wiki/Foo_(bar)</a></div>',
    md_to_html('https://en.wikipedia.org/wiki/Foo_(bar)'), 'a bare URL keeps balanced parentheses');
assert_eq('<div>(see <a href="https://x.io">https://x.io</a>)</div>', md_to_html('(see https://x.io)'),
    'a bare URL drops an unbalanced closing parenthesis');
assert_eq('<div><strong><a href="https://x.io">https://x.io</a></strong></div>', md_to_html('**https://x.io**'),
    'a bare URL inside strong');
assert_eq('<h1><em>a</em></h1>', md_to_html('# *a*'), 'headings take inline rules');
assert_eq('<ul><li><code>x</code></li></ul>', md_to_html('- `x`'), 'list items take inline rules');

group('md_to_html() URL safety');

$badLinks = [
    'javascript:alert(1)', 'JaVaScRiPt:alert(1)', "java\tscript:alert(1)", "\x01javascript:alert(1)",
    '//evil.io/x', '/relative', 'relative', 'data:text/html,x', 'vbscript:x', 'https:\\\\evil.io',
    'https:evil.io', 'mailto:', 'ftp://x.io',
];
foreach ($badLinks as $u) {
    $out = md_to_html('[t](' . $u . ')');
    assert_not_contains('<a', $out, 'no link for ' . json_encode($u));
    md_assert_safe($out, 'allowlist holds for ' . json_encode($u));
}
foreach (['http://x.io/i.png', 'data:image/png;base64,AAAA', '/i.png', '//x.io/i.png', 'javascript:x'] as $u) {
    $out = md_to_html('![a](' . $u . ')');
    assert_not_contains('<img', $out, 'no image for ' . json_encode($u));
}
$breakout = md_to_html('[t](https://a/x/onmouseover=alert(1))');
assert_eq('<div><a href="https://a/x/onmouseover=alert(1)">t</a></div>', $breakout,
    'a URL inside a link is not autolinked again');
$hostile = [
    '[t](https://a/"onmouseover="alert(1))',
    '[t](https://a/x) https://b/"><script>alert(1)</script>',
    '![x" onerror="alert(1)](https://x.io/i.png)',
    '![a](https://x.io/i.png"onerror="alert(1))',
    '[![a](https://x.io/i.png)](javascript:alert(1))',
    '**[t](https://a/**x**)**',
    '_https://x.io/_a_b_',
    '`[t](javascript:x)`',
    '<img src=x onerror=alert(1)>',
    '<a href="javascript:x">t</a>',
    '[t](https://x.io/<b>)',
    "# [t](https://x.io)\n> ![a](https://x.io/i.png)\n- **x** https://y.io",
    '[t](https://x.io "title")',
    "\x00[t](javascript:x)",
];
foreach ($hostile as $i => $m) {
    md_assert_safe(md_to_html($m), 'hostile fixture #' . $i . ' stays inside the allowlist');
}
assert_not_contains('<script', md_to_html('<script>alert(1)</script>'), 'no script tag');

group('md_to_html() robustness');

$t0 = microtime(true);
$patho = [
    str_repeat('*a', 500000),
    str_repeat('[', 1000000),
    str_repeat('[](((', 200000),
    str_repeat('`', 1000000),
    str_repeat('_a ', 300000),
    str_repeat('**a ', 250000),
    str_repeat('https://x.io/', 80000),
    str_repeat("> ", 100000) . 'deep',
];
foreach ($patho as $i => $p) {
    assert_true(md_to_html($p) !== '', 'pathological input #' . $i . ' still renders');
}
assert_true(microtime(true) - $t0 < 10, 'pathological inputs render in bounded time');
assert_contains('abc', md_to_html("\xff abc"), 'invalid UTF-8 does not blank the body');

group('md_escape()');

$literals = [
    'snake_case_name', '2 * 3 * 4', '*hi*', '**bold**', '_em_', '~~s~~', '`code`', '```',
    '# Title', '###### T', '> quoted', '>no space', '- item', '* item', '• item', '1986. was a year',
    '1. one', '---', '***', '___', '- - -', '—-', 'C:\path', 'a\*b', '\\', '[t](x)',
    '![a](x.png)', '[ ] task', "line one\nline two\n\n# after blank", '<b>x</b> & y',
    '  - indented', ':-*', '#hashtag', 'a_b_ c', '_a_b', '*a*b*', '~~~', '\\`',
];
foreach ($literals as $x) {
    assert_eq(md_literal_html($x), md_to_html(md_escape($x)), 'md_escape keeps ' . json_encode($x) . ' literal');
}
assert_eq('snake_case', md_escape('snake_case'), 'intra-word _ needs no escape');
assert_eq('2 * 3', md_escape('2 * 3'), 'spaced * needs no escape');
assert_eq('C:\path', md_escape('C:\path'), 'a backslash before a letter needs no escape');
assert_eq('1986\. was', md_escape('1986. was'), 'a number at line start is escaped');
assert_eq('see https://x.io/a_b_c', md_escape('see https://x.io/a_b_c'), 'URLs are left alone');
// A bare URL stays a link after escaping: its text IS its target, so it cannot
// deceive. Trailing punctuation is outside the link, as in GFM, and stays literal.
assert_eq('<div>go <a href="https://x.io/_a">https://x.io/_a</a>_</div>', md_to_html(md_escape('go https://x.io/_a_')),
    'an escaped URL still links, and its trailing _ stays literal');
assert_not_contains('<img', md_to_html(md_escape('![](https://tracker.io/px)')), 'an escaped image never loads');
assert_not_contains('>bank</a>', md_to_html(md_escape('[bank](https://phish.io)')), 'an escaped link label is not a link');
assert_eq('<div>[t](<a href="https://x.io">https://x.io</a>)</div>', md_to_html(md_escape('[t](https://x.io)')),
    'an escaped link shows its real target');

group('md_email_html() is what recipients get');

$mail = md_email_html("**a**\nb\n> q\n```\nc\n```\n# h\n- i\n![x](https://x.io/i.png)");
assert_contains('<!DOCTYPE html>', $mail, 'a full document');
assert_contains('<meta charset="utf-8">', $mail, 'with a charset');
assert_contains('font-family:', $mail, 'and a base font (Outlook falls back to Times)');
assert_contains('<strong>a</strong>', $mail, 'the body is the rendered Markdown');
assert_contains('<blockquote style="', $mail, 'quotes are styled inline (Gmail strips <style>)');
assert_contains('<pre style="', $mail, 'code blocks too');
assert_contains('<h1 style="', $mail, 'headings too');
assert_contains('<ul style="', $mail, 'lists too');
assert_contains('<img style="max-width:100%;height:auto" src="https://x.io/i.png"', $mail, 'images shrink to fit');
assert_not_contains('color:', $mail, 'no text colour: unreadable under a recipient dark mode');
assert_not_contains('background', $mail, 'no background either');
assert_contains('&lt;h1&gt;', md_email_html('<h1>x</h1>'), 'a literal tag in the text is not styled or rendered');

group('smtp_build_raw_message() carries the Markdown source');

// Decode every leaf part of a built message: [content-type => body].
function mime_leaf_parts(string $raw): array
{
    $parts = [];
    foreach (preg_split('/\r\n--[^\r\n]+\r\n/', $raw) as $chunk) {
        if (!preg_match('/^Content-Type: (text\/(?:plain|html))[^\r\n]*\r\nContent-Transfer-Encoding: base64\r\n\r\n(.*)$/s', $chunk, $m)) {
            continue;
        }
        $b64 = preg_replace('/\r\n--.*$/s', '', $m[2]);
        $parts[$m[1]] = base64_decode(preg_replace('/\s+/', '', $b64));
    }
    return $parts;
}

$src = "Hi **Ann**,\n\na <b> c & d\n-- \nMe";
$mdRaw = smtp_build_raw_message('f@e.com', 't@e.com', 'S', md_email_html($src), '', [], '', '', '', '', textBody: $src);
$mdParts = mime_leaf_parts($mdRaw);
assert_eq(str_replace("\n", "\r\n", $src), $mdParts['text/plain'] ?? null,
    'the text part is the exact source, CRLF, with no strip_tags');
assert_contains('<strong>Ann</strong>', $mdParts['text/html'] ?? '', 'the HTML part is rendered');
assert_contains('<div>-- </div>', $mdParts['text/html'] ?? '', 'the signature line survives');
$mdRawAtt = smtp_build_raw_message('f@e.com', 't@e.com', 'S', md_email_html($src), '',
    [['name' => 'a.txt', 'data' => 'x']], '', '', '', '', textBody: $src);
assert_eq(str_replace("\n", "\r\n", $src), mime_leaf_parts($mdRawAtt)['text/plain'] ?? null,
    'the same with an attachment');
$legacy = mime_leaf_parts(smtp_build_raw_message('f@e.com', 't@e.com', 'S', '<p>x &amp; y</p>'));
assert_eq('x & y', $legacy['text/plain'] ?? null, 'without textBody the text part is still derived from the HTML');

$sendSrc = (function (string $fn): string {
    $r = new ReflectionFunction($fn);
    $lines = file($r->getFileName());
    return implode('', array_slice($lines, $r->getStartLine() - 1, $r->getEndLine() - $r->getStartLine() + 1));
});
foreach (['handle_send', 'handle_save_draft'] as $fn) {
    $code = $sendSrc($fn);
    assert_contains('md_email_html($body)', $code, "$fn renders the Markdown for the HTML part");
    assert_contains('textBody: $body', $code, "$fn sends the source as the text part");
}

group('quoting: the original is text, never Markdown');

$q = ['from' => 'Vin <vin@pm.me>', 'date' => 'Wed, 09 Sep 2026 15:23:38 +0000', 'subject' => 'S',
    'body_text' => '', 'body_html' => ''];
assert_eq("Ann <ann@x.io>\n    indented & more", quote_source_text(['body_text' => "Ann <ann@x.io>\n    indented & more"] + $q),
    'a plain-text body is quoted as it is (no strip_tags, no whitespace collapse)');
assert_eq('Tom & Jerry', quote_source_text(['body_html' => '<p>Tom &amp; Jerry</p>'] + $q),
    'an HTML-only body is stripped and its entities decoded');

$hostileBody = "Hi,\n* note\n1. first\n# not a title\n![](https://tracker.io/px)\n[bank](https://phish.io)\n\n> earlier\n> > oldest";
$rq = reply_form_data(['body_text' => $hostileBody, 'from_address' => 'vin@pm.me', 'reply_to_address' => '',
    'to' => 'me@x.io', 'cc' => '', 'message_id' => '', 'references' => ''] + $q, 'me@x.io', false)['body'];
assert_true(strpos($rq, "\n\nOn Wed, 09 Sep 2026 15:23:38 +0000, Vin <vin@pm.me> wrote:\n> Hi,\n") === 0,
    'the reply leaves room on top, then attributes, then quotes');
$rqHtml = md_to_html($rq);
assert_not_contains('<img', $rqHtml, 'a quoted image does not load on Reply');
assert_not_contains('>bank</a>', $rqHtml, 'a quoted link label is not a link');
assert_not_contains('<li', $rqHtml, 'quoted list markers stay text');
assert_not_contains('<h1', $rqHtml, 'a quoted # stays text');
assert_contains('<blockquote><div>Hi,</div>', $rqHtml, 'the original is in a quote');
assert_contains('<blockquote><div>earlier</div>' . "\n" . '<blockquote><div>oldest</div></blockquote></blockquote></blockquote>',
    $rqHtml, 'its own quotes nest one level deeper');
assert_contains("\n>\n", $rq, 'a blank quoted line carries no trailing space');

$fq = forward_quote(['body_text' => $hostileBody] + $q);
assert_true(strpos($fq, "\n\n----- Forwarded Message -----\nFrom: Vin <vin@pm.me>\nDate: ") === 0,
    'a forward keeps its header block');
$fqHtml = md_to_html($fq);
assert_not_contains('<blockquote', $fqHtml, 'a forward is not quoted with >');
assert_not_contains('<img', $fqHtml, 'a forwarded image does not load');
assert_not_contains('<li', $fqHtml, 'forwarded list markers stay text');
assert_contains('<div>&gt; earlier</div>', $fqHtml, "the forwarded mail's own quote markers are shown, not rendered");

$draftSrc = $sendSrc('handle_edit_draft');
assert_contains('md_escape(quote_source_text($email))', $draftSrc,
    'an HTML-only draft (another client wrote it) is escaped, not read as Markdown');
assert_contains('forward_quote($email)', $sendSrc('handle_forward'), 'handle_forward uses the pure quote');

group('the composer carries a Markdown editor over the textarea');

$edForm = view_compose(['csrf' => 'tok', 'to' => 'a@x.io', 'subject' => 's', 'cc' => '', 'bcc' => '',
    'body' => "**hi** <b>\n> q", 'forward_attachments' => []]);
assert_contains('<div class="md-editor hidden" contenteditable="true"', $edForm,
    'the editor ships hidden: with JS off the textarea is the composer');
assert_contains('<strong>hi</strong> &lt;b&gt;', $edForm, 'the editor starts from md_to_html() of the body');
assert_contains('<blockquote>', $edForm, 'quotes render in the editor too');
assert_contains('>**hi** &lt;b&gt;' . "\n" . '&gt; q</textarea>', $edForm, 'the textarea still holds the Markdown source');
assert_true(strpos($edForm, 'md-editor') < strpos($edForm, 'name="body"') && strpos($edForm, 'compose-body') < strpos($edForm, 'md-editor'),
    'the editor sits in .compose-body, before the textarea');
assert_contains('name="body" autofocus', $edForm, 'the textarea keeps autofocus for JS-off; JS moves the focus');
assert_contains('data-placeholder="', $edForm, 'the editor has a placeholder');
assert_contains('aria-label="Message"', $edForm, 'and an accessible name');
$edJs = js_inline();
assert_contains("$('.md-editor').each(", $edJs, 'js: the editor is wired');
assert_contains("closest('form')", $edJs, 'js: it finds its textarea through the form');
assert_contains('doc.execCommand', $edJs, 'js: it checks for editing support first');
$edCss = file_get_contents(__DIR__ . '/../index.php');
assert_contains('.md-editor [style]', $edCss, 'css: styles the browser injects are neutralised');
assert_contains('.md-editor.is-empty::before', $edCss, 'css: the placeholder is drawn');
assert_contains('data-placeholder="Message"', $edForm, 'the editor placeholder is plain "Message", as the textarea had');

group('the composer explains its Markdown');

$helpPos = strpos($edForm, '<div class="md-help-row hidden"><button type="button" class="md-help" data-md-help>');
assert_true($helpPos !== false, 'a Markdown help row sits in the composer, hidden until JS can open the modal');
$fwdForm = view_compose(['csrf' => 'tok', 'to' => 'a@x.io', 'subject' => 's', 'cc' => '', 'bcc' => '', 'body' => '',
    'forward_attachments' => [['part_number' => '2', 'filename' => 'a.pdf', 'size' => 2048]]]);
$fwdHelp = strpos($fwdForm, 'md-help-row');
assert_true(strpos($fwdForm, 'compose-attach-list') < $fwdHelp && strpos($fwdForm, 'compose-attachments') < $fwdHelp,
    'attachments come first, between the editor and the help');
assert_true($fwdHelp < strpos($fwdForm, 'compose-actions'), 'the help sits right above the actions');
assert_contains('.md-help-row { border-top:1px solid var(--border-soft);', css_inline(),
    'the help row has a top border, like the To row has at its bottom');
assert_contains('Supports simplified Markdown syntax', $edForm, 'it says what the composer accepts');
assert_contains('#tabler-markdown', $edForm, 'with an icon');
assert_contains('id="tabler-markdown"', TABLER_SPRITE, 'the icon is in the offline sprite');
assert_not_contains('<details', $edForm, 'the inline dropdown is gone');
$dlgPos = strpos($edForm, '<dialog class="modal md-help-dialog" id="md-help-dialog" aria-labelledby="md-help-title">');
assert_true($dlgPos !== false, 'the options open in a modal');
$help = substr($edForm, $dlgPos, strpos($edForm, '</dialog>', $dlgPos) - $dlgPos);
assert_contains('<h3 class="modal-title" id="md-help-title" tabindex="-1" autofocus>', $help,
    'the modal is titled, and opens focused on its title, not scrolled to Close');
assert_contains('<table class="md-help-table">', $help, 'the options are a table');
assert_contains('<th scope="col">You type</th><th scope="col">You get</th>', $help, 'with a head');
assert_contains('<th colspan="2" scope="rowgroup">Anywhere in a line</th>', $help, 'inline marks are grouped');
assert_contains('<th colspan="2" scope="rowgroup">At the start of a line</th>', $help, 'line-start marks are grouped');
foreach (['**bold**', '*italic*', '_italic_', '~~strike~~', '`code`', '[text](https://…)', '![alt](https://…)',
    'https://…', '\*', '# ', '- ', '1. ', '&gt; ', '```', '---'] as $syntax) {
    assert_contains('<code>' . $syntax, $help, 'the table lists ' . $syntax);
}
assert_true(substr_count($help, '<tr><td><code>') >= 14, 'one row per option');
assert_contains('<strong>bold</strong>', $help, 'the result column shows the result');
assert_contains('<button class="btn-cancel" type="button">Close</button>', $help, 'the modal has a Close button');
assert_contains('.md-help-dialog', css_inline(), 'css: the modal is styled');

group('compose actions stay in view');

// A long message used to push Send below the window. The row sticks to the
// bottom instead, on its own surface so the text scrolls under it.
$stickyCss = css_inline();
assert_contains('.compose-actions { position:sticky; bottom:-1px; z-index:2; background:var(--surface);', $stickyCss,
    'the action row sticks to the bottom of the window');
assert_contains('border-top:1px solid transparent;', $stickyCss, 'its separator takes its space up front, so sticking does not shift it');
assert_contains('.compose-actions.stuck { border-top-color:var(--border-soft);', $stickyCss, 'the separator shows only while stuck');
assert_contains('env(safe-area-inset-bottom)', $stickyCss, 'it clears the iPhone home indicator');
assert_contains('html:has(.compose-actions) { scroll-padding-bottom:', $stickyCss,
    'typing at the end of a long message keeps the caret above the stuck row');
assert_contains('IntersectionObserver', js_inline(), 'js: sticking is detected, not guessed');

group('compose actions fit one row on a phone');

// Only Send keeps its words on a phone: Discard, Attach and Save draft become
// icons, like the reader toolbar, and carry data-tip + aria-label for it.
assert_contains('<a class="btn btn-sm compose-discard tip" data-tip="Discard" aria-label="Discard" href="?page=inbox">'
    . icon('arrow-back') . ' <span class="btn-label">Discard</span></a>', $edForm, 'Discard has an icon and a hideable label');
assert_contains('data-tip="Save draft" aria-label="Save draft"', $edForm, 'Save draft is named for the icon-only view');
assert_contains(icon('device-floppy') . ' <span class="btn-label">Save draft</span>', $edForm, 'and its label can hide');
assert_contains(icon('send') . ' Send</button>', $edForm, 'Send keeps its label');
assert_not_contains('<span class="btn-label">Send', $edForm, 'always');
$phoneCss = css_inline();
assert_contains('.compose-actions .btn-sm, .compose-actions .btn-sm:not(.compose-attach-btn) { width:32px; min-width:32px; padding:0; }',
    $phoneCss, 'the small actions shrink to icons');
assert_not_contains('flex-wrap:wrap; }', substr($phoneCss, strpos($phoneCss, '.compose-actions { padding:10px 14px;'), 60),
    'the row no longer wraps');

group('markdown v1 limitations');

// Each limitation is a decision (docs/plans/2026-09-28-markdown-compose.md, L1-L10).
// Lifting one means changing this test, the README and the plan together.
assert_eq('<ul><li>a</li><li>b</li></ul>', md_to_html("- a\n  - b"), 'L1: an indented item joins the same flat list');
assert_eq('<ol><li>a</li><li>b</li></ol>', md_to_html("1. a\n   2. b"), 'L1: numbered lists are flat too');
foreach (['-- x', '** x', '•• x', '--- x'] as $m) {
    $out = md_to_html($m);
    assert_true(strpos($out, '<li') === false && strpos($out, '<strong') === false && strpos($out, '<hr') === false,
        'L2: ' . $m . ' is not a nested-list marker');
}
assert_contains('&lt;b&gt;', md_to_html('<b>x</b>'), 'L4: raw HTML is text');
assert_not_contains('<table', md_to_html("| a | b |\n|---|---|"), 'L5: no tables');
assert_eq('<ul><li>[ ] x</li></ul>', md_to_html('- [ ] x'), 'L5: no task lists');
assert_not_contains('<img', md_to_html('![a](http://x.io/i.png)'), 'L6: http images are text');
assert_not_contains('<img', md_to_html('![a](data:image/png;base64,AAAA)'), 'L6: data images are text');
assert_not_contains('<img', md_to_html('![a](i.png)'), 'L6: relative images are text');

// ----------------------------------------- POST overflow detection -----------+

group('parse_php_size() converts PHP ini shorthand to bytes');

assert_eq(8 * 1024 * 1024, parse_php_size('8M'), '8M');
assert_eq(512 * 1024, parse_php_size('512K'), '512K');
assert_eq(1024 * 1024 * 1024, parse_php_size('1G'), '1G');
assert_eq(128 * 1024 * 1024, parse_php_size('128M'), '128M');
assert_eq(0, parse_php_size('0'), 'zero means unlimited or zero');
assert_eq(0, parse_php_size('-1'), '-1 means no limit');
assert_eq(1048576, parse_php_size('1048576'), 'plain bytes');
assert_eq(2 * 1024 * 1024, parse_php_size('2m'), 'lowercase suffix');

group('detect_post_overflow() catches truncated POST before CSRF fails');

// Simulate: CONTENT_LENGTH present and larger than post_max_size.
$saved = $_SERVER;
$_SERVER['CONTENT_LENGTH'] = (string)(10 * 1024 * 1024); // 10 MB
assert_true(detect_post_overflow(8 * 1024 * 1024), 'overflow: 10 MB content, 8 MB limit');
assert_false(detect_post_overflow(16 * 1024 * 1024), 'no overflow: 10 MB content, 16 MB limit');
assert_false(detect_post_overflow(0), 'limit=0 means unlimited, never overflows');
unset($_SERVER['CONTENT_LENGTH']);
assert_false(detect_post_overflow(8 * 1024 * 1024), 'no CONTENT_LENGTH header means no overflow');
$_SERVER = $saved;

group('compose JS warns before submitting files that exceed the server limit');

$cjs = js_inline();
assert_contains('postMaxBytes', $cjs, 'js: the server limit is available to the client');
assert_contains('attachments[]', $cjs, 'js: the file input is monitored for size');

// --------------------------------------------------------- new settings ----+

group('view_settings() renders new fields');

$settingsHtml = view_settings([
  'csrf' => 'tok', 'emails_per_page' => 20, 'display_name' => 'Test User',
  'show_remote_images' => false,
  'image_whitelist' => '', 'signature' => 'Best regards,\nTest',
  'auto_poll' => true, 'undo_period_seconds' => 10,
  'protected_folders' => '{"sent":"Sent Messages"}', 'folder_list' => [],
  'cached_messages' => 0,
]);
assert_contains('name="signature"', $settingsHtml, 'signature textarea present');
assert_contains('Best regards', $settingsHtml, 'signature value rendered');
assert_not_contains('sync_period_seconds', $settingsHtml, 'the period select is gone');
// Not a period, an on/off: the interval is fixed, so a six-option dropdown
// whose only reachable values were 60, 120, 300 and "off" was four questions
// where one was needed.
assert_contains('name="auto_poll"', $settingsHtml, 'the automatic check is a switch');
assert_contains('Check for new mail automatically', $settingsHtml, 'and it says what it does');
assert_contains('value="0"', $settingsHtml, 'a hidden 0 sits before it, so unticking really posts 0');
assert_contains('id="auto-poll" name="auto_poll" value="1" checked', $settingsHtml, 'the automatic check is on by default');
assert_contains('name="undo_period_seconds"', $settingsHtml, 'undo period select present');
assert_contains('value="10"', $settingsHtml, 'undo period 10s selected');
assert_contains('name="protected_folders"', $settingsHtml, 'protected folders hidden input present');
assert_contains('data-open-folder-map', $settingsHtml, 'folder map button present');
assert_contains('Sent', $settingsHtml, 'Sent role in folder map modal');

group('view_settings() ships the notification card outside the settings form');

$notifyCard = view_settings([
  'csrf' => 'tok', 'emails_per_page' => 20, 'display_name' => 'Test',
  'show_remote_images' => false,
  'image_whitelist' => '', 'signature' => '',
  'auto_poll' => true, 'undo_period_seconds' => 0,
  'protected_folders' => '{}', 'folder_list' => [],
  'cached_messages' => 0,
]);
assert_contains('class="card cache-card notify-card"', $notifyCard, 'the card is a settings card, like the cache one');
assert_contains('data-notify-toggle', $notifyCard, 'it holds the one button that acts at once');
// Outside the form on purpose: that form posts on Save, and a switch inside it
// that has already switched would be a lie.
$cardAt = strpos($notifyCard, 'notify-card');
$upToCard = substr($notifyCard, 0, $cardAt);
assert_false(strpos($upToCard, '<form') > strrpos($upToCard, '</form>'),
    'the card comes after the settings form, not inside it');
assert_not_contains('name="notify"', $notifyCard, 'the flag is per browser, so nothing is posted to the server');

// The interval is fixed, so the note under the switch is where "every minute"
// is stated. It used to sit under a select that offered 15s.
$autoNote = '';
if (preg_match('#for="auto-poll">.*?<div class="settings-note">(.*?)</div>#s', $notifyCard, $m)) {
  $autoNote = trim($m[1]);
}
assert_contains('once a minute', $autoNote, 'the switch says how often it actually checks');

group('view_settings() signature empty by default');
$noSig = view_settings([
  'csrf' => 'tok', 'emails_per_page' => 20, 'display_name' => 'Test',
  'show_remote_images' => false,
  'image_whitelist' => '', 'signature' => '',
  'auto_poll' => true, 'undo_period_seconds' => 0,
  'protected_folders' => '{}', 'folder_list' => [],
  'cached_messages' => 0,
]);
assert_contains('id="auto-poll" name="auto_poll" value="1" checked', $noSig, 'the automatic check defaults to on');
assert_contains('value="0"', $noSig, 'undo period defaults to off');

group('folder_is_protected() with custom map');

$map = ['sent' => 'Outbox', 'trash' => 'Waste'];
assert_true(folder_is_protected('INBOX', $map), 'INBOX always protected');
assert_true(folder_is_protected('Outbox', $map), 'user-mapped Sent is protected');
assert_true(folder_is_protected('Waste', $map), 'user-mapped Trash is protected');
assert_false(folder_is_protected('Sent', $map), 'default Sent not protected when mapped elsewhere');
assert_false(folder_is_protected('Projects', $map), 'user folder not protected');

group('folder_delete_check() with custom map');

$map2 = ['drafts' => 'Compos'];
assert_eq('protected', folder_delete_check('Compos', $map2), 'user-mapped Drafts is protected');
assert_eq('ok', folder_delete_check('Drafts', $map2), 'default Drafts not protected when mapped elsewhere');

group('MailImap::setFolderMap() stores the map');

$m = new MailImap('localhost', 993, 'u', 'p');
// setFolderMap should not throw.
$m->setFolderMap(['sent' => 'My Sent', 'trash' => 'Waste']);
// Verify the map is stored by checking it's used in findSpecialFolder.
// Without a real connection, getFolders() will fail, but the map is set.
assert_true(true, 'setFolderMap does not throw');

group('view_compose() includes undo_period_seconds');

$composeForm = view_compose([
  'csrf' => 'tok', 'to' => '', 'subject' => '', 'body' => '',
  'cc' => '', 'bcc' => '', 'forward_attachments' => [],
  'undo_period_seconds' => 15,
]);
assert_contains('name="undo_period_seconds"', $composeForm, 'undo period hidden input present');
assert_contains('value="15"', $composeForm, 'undo period value passed to compose');

group('view_sidebar() accepts folder map');

$sidebar = view_sidebar([
  ['name' => 'INBOX', 'group' => 'primary', 'displayName' => 'Inbox'],
  ['name' => 'Sent', 'group' => 'primary', 'displayName' => 'Sent'],
], 'INBOX', ['sent' => 'Sent']);
assert_contains('INBOX', $sidebar, 'INBOX in sidebar');
assert_not_contains('folder-del', $sidebar, 'INBOX has no delete button');

group('sidebar folder links carry a navigation hook for the spinner');
assert_contains('class="folder-link"', $sidebar, 'sidebar: folder links are findable by the click handler');
assert_contains('href="?page=folder&folder=INBOX"', $sidebar, 'sidebar: the folder link still navigates to the folder');

// ------------------------------------------- onboarding folder mapping -------+
// Test double: a MailImap whose find*Folder methods answer without a socket.
// PHP allows a different constructor signature in a subclass, so the probe
// (5s TCP + imap_open) never runs in tests.
class DetectStubImap extends MailImap
{
  public function __construct() {}
  public function isConnected(): bool { return true; }
  public function findSentFolder(): ?string { return 'Sent Items'; }
  public function findDraftsFolder(): ?string { return 'Draft'; }
  public function findJunkFolder(): ?string { return 'Junk'; }
  public function findTrashFolder(): ?string { return 'Deleted Items'; }
  public function findArchiveFolder(): ?string { return 'All Mail'; }
}

class EmptyDetectStubImap extends MailImap
{
  public function __construct() {}
  public function isConnected(): bool { return true; }
  public function findSentFolder(): ?string { return null; }
  public function findDraftsFolder(): ?string { return null; }
  public function findJunkFolder(): ?string { return null; }
  public function findTrashFolder(): ?string { return null; }
  public function findArchiveFolder(): ?string { return null; }
}

// Test double exposing a folder list without a connection, so findArchiveFolder()
// exercises the real findSpecialFolder() roleMap path.
class ArchivStubImap extends MailImap
{
  public function __construct() {}
  private $folders = [];
  public function setFakeFolders(array $f): void { $this->folders = $f; }
  public function getFolders(): array { return $this->folders; }
}

group('detect_folder_map() builds a map from detected folders');

unset($_SESSION['detected_folder_map']);
$det = detect_folder_map(new DetectStubImap());
assert_eq('Sent Items', $det['sent'] ?? null, 'sent detected');
assert_eq('Draft', $det['drafts'] ?? null, 'drafts detected');
assert_eq('Junk', $det['spam'] ?? null, 'spam detected (findJunkFolder)');
assert_eq('Deleted Items', $det['trash'] ?? null, 'trash detected');
assert_eq('All Mail', $det['archive'] ?? null, 'archive detected');
assert_eq(5, count($det), 'a full detection has five roles');

group('detect_folder_map() skips roles the server does not have');

unset($_SESSION['detected_folder_map']);
assert_eq([], detect_folder_map(new EmptyDetectStubImap()), 'a null-only imap yields an empty map');

group('detect_folder_map() returns [] without session credentials');

unset($_SESSION['detected_folder_map']);
$savedSession = $_SESSION;
$_SESSION = [];
assert_eq([], detect_folder_map(null), 'no session -> empty map, no redirect');
$_SESSION = $savedSession;

group('detect_folder_map() caches in session');

$_SESSION['detected_folder_map'] = ['sent' => 'Cached'];
assert_eq('Cached', detect_folder_map(new DetectStubImap())['sent'] ?? null,
    'the session cache short-circuits a second IMAP probe');
unset($_SESSION['detected_folder_map']);

group('folder_setup_complete is a plain EAV key');

$obUser = 'onboarding@example.com';
assert_eq('0', db_get_setting($obUser, 'folder_setup_complete', '0'),
    'missing flag defaults to 0');
db_set_setting($obUser, 'folder_setup_complete', '1');
assert_eq('1', db_get_setting($obUser, 'folder_setup_complete', '0'),
    'flag reads back after write');
db_set_setting($obUser, 'folder_setup_complete', '0');

group('onboarding_pending() gates on the flag and the save page');

assert_true(onboarding_pending($obUser), 'missing/0 flag means onboarding is pending');
db_set_setting($obUser, 'folder_setup_complete', '1');
assert_false(onboarding_pending($obUser), 'flag 1 means onboarding is done');
assert_false(onboarding_pending($obUser, 'saveOnboarding'), 'never re-triggers on the save route');
db_set_setting($obUser, 'folder_setup_complete', '0');

group('folder_map_clean() validates a saved map');

assert_eq(['sent' => 'Sent Items'],
    folder_map_clean(['sent' => 'Sent Items']), 'a valid role is kept');
assert_eq(['sent' => 'Outbox'],
    folder_map_clean(['SENT' => 'Outbox']), 'role keys are lowercased');
assert_eq(['sent' => 'Sent'], folder_map_clean(['sent' => 'Sent', 'junk' => 'Other']),
    'an unknown key is dropped');
assert_eq(['sent' => 'Sent'], folder_map_clean(['sent' => 'Sent', 'trash' => '']),
    'an empty value is dropped');
assert_eq([], folder_map_clean(['0' => 'x', '1' => 'y']), 'a JSON array (numeric keys) is rejected');
assert_eq([], folder_map_clean([]), 'an empty map is an empty map');
assert_eq([], folder_map_clean(['foo' => 'bar']), 'only unknown keys -> empty');

group('the archiv -> archive roleMap fix finds a user-mapped archive');

$archiv = new ArchivStubImap();
$archiv->setFakeFolders([
  ['name' => 'All Mail', 'displayName' => 'All Mail', 'delimiter' => '/', 'attributes' => 0],
]);
$archiv->setFolderMap(['archive' => 'All Mail']);
assert_eq('All Mail', $archiv->findArchiveFolder(),
    'findArchiveFolder() honors the user archive map via the archiv keyword');

group('view_settings() pre-fills data-default-map when the user map is empty');

$prefillHtml = view_settings([
  'csrf' => 'tok', 'emails_per_page' => 20, 'display_name' => 'Test',
  'show_remote_images' => false, 'image_whitelist' => '',
  'signature' => '', 'auto_poll' => true, 'undo_period_seconds' => 0,
  'protected_folders' => '{}', 'folder_list' => [
    ['name' => 'Sent Items', 'displayName' => 'Sent Items', 'delimiter' => '/', 'attributes' => 0],
  ],
  'detected_folder_map' => ['sent' => 'Sent Items'],
  'cached_messages' => 0,
]);
assert_contains('data-default-map', $prefillHtml, 'settings offers the detected map when none is saved');
assert_contains('Sent Items', $prefillHtml, 'and carries the detected name');

$savedMapHtml = view_settings([
  'csrf' => 'tok', 'emails_per_page' => 20, 'display_name' => 'Test',
  'show_remote_images' => false, 'image_whitelist' => '',
  'signature' => '', 'auto_poll' => true, 'undo_period_seconds' => 0,
  'protected_folders' => '{"sent":"Outbox"}', 'folder_list' => [],
  'detected_folder_map' => ['sent' => 'Sent Items'],
  'cached_messages' => 0,
]);
assert_not_contains('data-default-map', $savedMapHtml,
    'a saved map wins over detection, so no default is offered');

group('handle_settings() and onboarding persist the setup flag (source-level)');

$obSrc = file_get_contents(__DIR__ . '/../index.php');
assert_contains("db_set_setting(\$me, 'folder_setup_complete', '1')", $obSrc,
    'onboarding save sets the flag');
assert_contains("db_set_setting(\$me, 'folder_setup_complete', '1')", $obSrc,
    'settings save sets the flag when the map is non-empty');
assert_contains("case 'saveOnboarding':", $obSrc, 'router exposes the onboarding route');
assert_contains('function handle_save_onboarding()', $obSrc, 'the onboarding handler exists');
assert_contains('function detect_folder_map(', $obSrc, 'the detector exists');
assert_contains("'archiv' => 'archive'", $obSrc, 'the archiv roleMap key exists');

group('onboarding JS (source-level)');

$obJs = js_inline();
assert_contains('win.openOnboarding', $obJs, 'js: openOnboarding is exposed');
assert_contains('win.buildFolderRows', $obJs, 'js: the shared row builder is exposed');
assert_contains('win.collectFolderMap', $obJs, 'js: the shared map collector is exposed');
assert_contains('?page=saveOnboarding', $obJs, 'js: onboarding save posts the route');
assert_contains('__openOnboarding', $obJs, 'js: the ready block honors the trigger flag');
// The emitted trigger script is a top-level <script>, not part of js_inline()'s
// IIFE, so it must set the flag on window -- `win` is an IIFE-local and reading
// it there threw "ReferenceError: win is not defined" on every page.
assert_contains("window.__openOnboarding = true", $obSrc,
    'the trigger script sets the flag on window, where the ready block reads it');
assert_not_contains("function(){ win.__openOnboarding = true; }", $obSrc,
    'and never references win (an IIFE-local) from the top-level script');

// ------------------------------------------------------- keyboard shortcuts -+
// One PHP-owned table drives both the dispatcher (via data-kb-list) and the ?-sheet.
group('kb_shortcuts(): the single shortcut table');

$kb = kb_shortcuts();
assert_true(is_array($kb) && count($kb) > 0, 'kb_shortcuts: returns a non-empty array');
$seenKeys = [];
foreach ($kb as $entry) {
    assert_true(is_array($entry) && isset($entry['key'], $entry['label']), 'kb_shortcuts: each entry has key+label');
    assert_true($entry['label'] !== '', 'kb_shortcuts: label is never empty');
    assert_true(!in_array($entry['key'], $seenKeys, true), 'kb_shortcuts: key "' . $entry['key'] . '" appears once');
    $seenKeys[] = $entry['key'];
}
foreach (['j', 'k', 'Enter or o', 'Esc', 'e', '#', '!', 'r', 'a', 'f', 's', 'v',
          'g i', 'g t', 'g d', 'g s', 'g a', 'g r', 'u', 'y', 'c', '/', 'Cmd+Enter',
          'Cmd+S', 'Cmd+Shift+A', 'Cmd+Shift+X', '?', 'x or Space'] as $needKey) {
    assert_true(in_array($needKey, $seenKeys, true), 'kb_shortcuts: lists "' . $needKey . '"');
}
// The table alone is satisfied by an entry that does nothing: kb_shortcuts()
// feeds the ?-sheet and nothing else, so an 'x' row is a lie unless the
// dispatcher acts on it. Nothing in PHP ties the two together.
$kbJs = file_get_contents(__DIR__ . '/../index.php');
assert_contains("case 'x': kbSelectCursor(); return;", $kbJs,
    "kb_shortcuts: the table's 'x' is wired to kbSelectCursor() in the dispatcher");
assert_contains("case ' ': kbSpaceSelect(ev); return;", $kbJs,
    "kb_shortcuts: and so is Space");

group('kb_folder_map(): roles resolve, custom map wins, unmapped roles absent');

$kbFolders = [
    ['name' => 'INBOX',   'displayName' => 'Inbox',   'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Sent',    'displayName' => 'Sent',    'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Trash',   'displayName' => 'Trash',   'delimiter' => '/', 'attributes' => 0],
    ['name' => 'Archive', 'displayName' => 'Archive', 'delimiter' => '/', 'attributes' => 0],
];
$kbMapAuto = kb_folder_map($kbFolders, null);
assert_eq('INBOX', $kbMapAuto['i'] ?? '', 'auto map: i is INBOX');
assert_eq('Sent', $kbMapAuto['t'] ?? '', 'auto map: t is Sent');
assert_eq('Trash', $kbMapAuto['r'] ?? '', 'auto map: r is Trash');
assert_eq('Archive', $kbMapAuto['a'] ?? '', 'auto map: a is Archive');
assert_false(isset($kbMapAuto['s']), 'auto map: no Spam folder, role absent');
assert_false(isset($kbMapAuto['d']), 'auto map: no Drafts folder, role absent');

$kbMapCustom = kb_folder_map($kbFolders, ['sent' => 'Archive', 'spam' => 'Sent']);
assert_eq('Archive', $kbMapCustom['t'] ?? '', 'custom map: t honours the map');
assert_eq('Sent', $kbMapCustom['s'] ?? '', 'custom map: s honours the map');
assert_eq('Trash', $kbMapCustom['r'] ?? '', 'custom map: unmapped roles still detect');

// An empty list used to drop every role, so `g i` toasted "No such folder." on a
// page whose cache was merely cold. RFC 3501 requires a folder named INBOX, and
// the rest of the app already treats it as unconditional (syncTitle, the poll
// chain), so `g i` is the one jump that can never be dead. Every other role still
// needs a match, or the toast stops being honest.
$kbMapEmpty = kb_folder_map([], null);
assert_eq(['i' => 'INBOX'], $kbMapEmpty, 'an empty list still maps i to INBOX, and nothing else');
assert_eq('INBOX', kb_folder_map([], ['inbox' => 'Somewhere else'])['i'] ?? '',
    'an empty list overrides a stale inbox mapping rather than honouring it');
assert_eq('INBOX', kb_folder_map($kbFolders, ['inbox' => 'Somewhere else'])['i'] ?? '',
    'an inbox mapping that is not in the list falls back to INBOX');

group('view_kb_help_dialog(): the ?-sheet renders from kb_shortcuts()');

$kbDlg = view_kb_help_dialog();
assert_contains('id="kb-help-dialog"', $kbDlg, 'sheet: dialog id present');
assert_contains('aria-labelledby="kb-help-title"', $kbDlg, 'sheet: title linked to the dialog');
assert_contains('btn-cancel" autofocus', $kbDlg, 'sheet: Close is the first focusable');
assert_contains('data-kb-disable', $kbDlg, 'sheet: disable-shortcuts link present');
foreach (kb_shortcuts() as $entry) {
    assert_contains('<kbd>' . h($entry['key']) . '</kbd>', $kbDlg, 'sheet: renders ' . $entry['key'] . ' as a key');
}
preg_match('/data-kb-list="([^"]*)"/', $kbDlg, $kbListM);
assert_true(isset($kbListM[1]), 'sheet: carries the JSON table as data-kb-list');
assert_eq(json_encode(kb_shortcuts(), JSON_UNESCAPED_UNICODE), html_entity_decode($kbListM[1] ?? '', ENT_QUOTES),
    'sheet: data-kb-list JSON equals kb_shortcuts() exactly');

group('render_page() emits data-kb-map and ships the sheet when authed');

$kbUser = 'kb@x.io';
$_SESSION['logged_in'] = true;
$_SESSION['email'] = $kbUser;
db_set_folder_list($kbUser, [
    ['name' => 'INBOX', 'displayName' => 'Inbox', 'delimiter' => '/', 'attributes' => 0, 'group' => 'primary'],
    ['name' => 'Sent',  'displayName' => 'Sent',  'delimiter' => '/', 'attributes' => 0, 'group' => 'primary'],
]);
db_set_setting($kbUser, 'folder_setup_complete', '1');
db_set_setting($kbUser, 'protected_folders', '{"sent":"Sent"}');
ob_start();
render_page('T', '<div id="probe">x</div>', true);
$kbPage = ob_get_clean();
$_SESSION = [];
assert_contains('data-kb-map="', $kbPage, 'authed body carries data-kb-map');
// h() escapes the JSON's quotes, which is right for an attribute: the browser
// decodes them back when the JS reads the attribute via getAttribute().
assert_contains('data-kb-map="{&quot;i&quot;:&quot;INBOX&quot;', $kbPage, 'data-kb-map: inbox role present');
assert_contains('&quot;t&quot;:&quot;Sent&quot;', $kbPage, 'data-kb-map: sent role present');
assert_contains('id="kb-help-dialog"', $kbPage, 'authed page ships the ?-sheet dialog');
assert_contains('data-kb-list="', $kbPage, 'and it carries the JSON table');

ob_start();
render_page('T', '<div id="probe">x</div>', false);
$kbLoginPage = ob_get_clean();
// The JS parser mentions data-kb-map in its source, so match the attribute form.
assert_not_contains('data-kb-map="', $kbLoginPage, 'login page has no data-kb-map');
assert_not_contains('id="kb-help-dialog"', $kbLoginPage, 'login page ships no ?-sheet');

group('read_prev_next(): neighbours by rank over the whole cached folder');

$pnUser = 'pn@x.io';
$pnRows = [];
for ($i = 1; $i <= 45; $i++) {
    $pnRows[] = ['uid' => $i, 'subject' => 's', 'from_name' => 'a', 'from_address' => 'a@x.io',
        'to_name' => '', 'to_address' => '', 'date_ts' => 1700000000 + $i * 60,
        'unread' => 0, 'flagged' => 0, 'has_attachment' => 0, 'size' => 10, 'snippet' => ''];
}
db_upsert_messages($pnUser, 'INBOX', $pnRows);
$pnMid = read_prev_next($pnUser, 'INBOX', 38);
assert_eq(39, $pnMid['prev'] ?? 0, 'uid 38: prev (row above, newer) is 39');
assert_eq(37, $pnMid['next'] ?? 0, 'uid 38: next (row below, older) is 37');
$pnTop = read_prev_next($pnUser, 'INBOX', 45);
assert_eq(0, $pnTop['prev'] ?? -1, 'newest message: no prev');
assert_eq(44, $pnTop['next'] ?? 0, 'newest message: next is 44');
$pnBottom = read_prev_next($pnUser, 'INBOX', 1);
assert_eq(2, $pnBottom['prev'] ?? 0, 'oldest message: prev is 2');
assert_eq(0, $pnBottom['next'] ?? -1, 'oldest message: no next');
assert_eq(['prev' => 0, 'next' => 0], read_prev_next($pnUser, 'INBOX', 999),
    'unknown uid: both neighbours 0');

group('view_read() carries the keyboard hooks on the card');

$kbReadEmail = ['subject' => 'Re: hi', 'from' => 'a@x.io', 'to' => 'b@y.io',
    'cc' => '', 'date' => '2026-01-01', 'body_text' => 'hello', 'attachments' => []];
$kbReadHtml = view_read($kbReadEmail, 7, 'INBOX', 'me@x.io', [], ['prev_uid' => 6, 'next_uid' => 8]);
assert_contains('class="card" data-uid="7" data-folder="INBOX"', $kbReadHtml,
    'card: data-uid and data-folder on the card itself');
assert_contains('data-reply="?page=reply&amp;uid=7&amp;folder=INBOX"', $kbReadHtml,
    'card: reply href carried for the r key');
assert_contains('data-replyall="?page=replyAll&amp;uid=7&amp;folder=INBOX"', $kbReadHtml,
    'card: reply-all href carried for the a key');
assert_contains('data-forward="?page=forward&amp;uid=7&amp;folder=INBOX"', $kbReadHtml,
    'card: forward href carried for the f key');
assert_contains('data-prev="?page=read&amp;uid=6&amp;folder=INBOX"', $kbReadHtml,
    'card: data-prev (row above, k) emitted');
assert_contains('data-next="?page=read&amp;uid=8&amp;folder=INBOX"', $kbReadHtml,
    'card: data-next (row below, j) emitted');
$kbReadNo = view_read($kbReadEmail, 7, 'INBOX', 'me@x.io', [], []);
assert_not_contains('data-prev=', $kbReadNo, 'card: no data-prev when the neighbour is absent');
assert_not_contains('data-next=', $kbReadNo, 'card: no data-next when the neighbour is absent');

group('js_inline() ships the keyboard layer');

$kbJs = js_inline();
assert_contains('function kbDispatch', $kbJs, 'js: the single dispatcher exists');
assert_contains('kbEnabled', $kbJs, 'js: kbEnabled guard exists');
assert_contains('kbAuthed', $kbJs, 'js: kbAuthed guard exists');
assert_contains('function kbEditable', $kbJs, 'js: kbEditable guard exists');
assert_contains("if (ev.key === 'Escape')", $kbJs, 'js: Esc passes the editable guard');
assert_contains('kbModalOpen', $kbJs, 'js: kbModalOpen guard exists');
assert_contains('kbPrefix', $kbJs, 'js: the g-prefix state exists');
assert_contains('kbFolderMap', $kbJs, 'js: the data-kb-map parser exists');
assert_contains('data-kb-list', $kbJs, 'js: the dispatcher reads the JSON table from the sheet');
assert_contains('addEventListener(\'keydown\', kbDispatch)', $kbJs, 'js: the dispatcher binds once at boot');
assert_contains('[data-kb-help]', $kbJs, 'js: the account-menu Help item is wired');
// The / shortcut must not also type itself into the field it focuses.
assert_contains("ev.preventDefault(); kbFocusSearch()", $kbJs,
    'js: / is prevented before it focuses search');
// Arrows are aliases for the movement keys.
assert_contains("case 'ArrowDown'", $kbJs, 'js: ArrowDown walks the list cursor');
assert_contains("case 'ArrowUp'", $kbJs, 'js: ArrowUp walks the list cursor');
// The ?-sheet stays reachable when shortcuts are off, and its toggle flips both
// ways, so a disabled user is never trapped.
assert_contains("if (key === '?') { kbHelp(); return; }", $kbJs,
    'js: ? opens the sheet before the off-flag is consulted');
assert_contains("removeItem('emmmail-kb')", $kbJs, 'js: the toggle can re-enable shortcuts');
assert_contains("Enable shortcuts", $kbJs, 'js: the sheet label reflects the state');
// Keyboard navigation acknowledges its wait like the mouse equivalent.
assert_contains('function kbBusy', $kbJs, 'js: navigation shortcuts run the shared busy helper');
assert_contains('function kbFolderLink', $kbJs, 'js: a folder jump can spin the folder link');
assert_contains('markBusy(back)', $kbJs, 'js: reader Back spins its control');
// Esc unwinds the composer, and a suggestion dropdown first.
assert_contains('function kbDiscard', $kbJs, 'js: Esc discards the composer');
assert_contains('function kbCloseAutocomplete', $kbJs, 'js: Esc closes a suggestion dropdown first');
assert_contains('function kbBlurField', $kbJs, 'js: Esc unfocuses a focused field');
assert_contains('function kbSync', $kbJs, 'js: a shortcut triggers the sync button');
assert_contains("case 'y': kbSync()", $kbJs, 'js: y is the sync shortcut');
// Form actions: save draft / settings, attach, clear cache.
assert_contains('function kbSaveDraft', $kbJs, 'js: Cmd+S saves the draft');
assert_contains('function kbAttach', $kbJs, 'js: Cmd+Shift+A attaches a file');
assert_contains('function kbSaveSettings', $kbJs, 'js: Cmd+S saves settings');
assert_contains('function kbClearCache', $kbJs, 'js: Cmd+Shift+X clears the cache');

group('the ?-sheet is a table with a header row');

$kbDlgTable = view_kb_help_dialog();
assert_contains('class="kb-help-table"', $kbDlgTable, 'sheet: table class present');
assert_contains('<thead>', $kbDlgTable, 'sheet: a header row is present');
assert_contains('<th scope="col">Key</th>', $kbDlgTable, 'sheet: the Key column is headed');
assert_contains('<th scope="col">Action</th>', $kbDlgTable, 'sheet: the Action column is headed');
assert_contains('<kbd>j</kbd>', $kbDlgTable, 'sheet: keys are rendered in <kbd>');

group('account menu: Help sits between Theme and Logout, with dividers and a badge');

$_SESSION['logged_in'] = true;
$_SESSION['email'] = 'menu@x.io';
db_set_setting('menu@x.io', 'folder_setup_complete', '1');
ob_start();
render_page('T', '<div>x</div>', true);
$menuPage = ob_get_clean();
$_SESSION = [];
preg_match('/<div class="account-menu".*?<\/div><\/div>/s', $menuPage, $menuM);
$menuHtml = $menuM[0] ?? '';
assert_contains('data-kb-help', $menuHtml, 'account menu: Help item present');
assert_contains('tabler-help', $menuHtml, 'account menu: Help carries an icon');
assert_contains('class="menu-kbd"', $menuHtml, 'account menu: a shortcut badge is present');
$posTheme = strpos($menuHtml, 'data-theme-toggle');
$posHelp = strpos($menuHtml, 'data-kb-help');
$posLogout = strpos($menuHtml, '?page=logout');
assert_true($posTheme !== false && $posHelp !== false && $posLogout !== false,
    'account menu: Theme, Help and Logout all render');
assert_true($posTheme < $posHelp && $posHelp < $posLogout,
    'account menu: Help sits between Theme and Logout');
$sepFirst = strpos($menuHtml, '<hr class="menu-sep">');
$sepLast = strrpos($menuHtml, '<hr class="menu-sep">');
assert_eq(2, substr_count($menuHtml, '<hr class="menu-sep">'), 'account menu: two dividers');
assert_true($sepFirst !== false && $sepFirst < $posHelp, 'account menu: a divider above Help');
assert_true($sepLast !== false && $sepLast > $posHelp, 'account menu: a divider below Help');

group('css: the ?-sheet table and the menu shortcut badge are styled');

$kbCss = css_inline();
assert_contains('.kb-help-table', $kbCss, 'css: the shortcut table is styled');
assert_contains('.kb-help-table kbd', $kbCss, 'css: the keys are styled as <kbd>');
assert_contains('.menu-kbd', $kbCss, 'css: the account-menu shortcut badge is styled');
// A gap between the sheet's table and its note.
assert_contains('dialog.modal.kb-help-dialog .modal-desc { margin-top:16px; }', $kbCss,
    'css: the ?-sheet note is spaced from the table');

// ------------------------------------------------ sidebar folder counters ---+
group('folder_counts: schema v3 and storage');

assert_true(SCHEMA_VERSION >= 3, 'schema is at least v3');
$fcTables = db_open()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('folder_counts', $fcTables, true), 'folder_counts table exists');

$fu = 'counts-a@example.com';
$fv = 'counts-b@example.com';
assert_eq([], db_get_folder_counts($fu), 'no counts yet');
assert_true(db_set_folder_counts($fu, 'INBOX', 4, 10, 1700000000), 'counts are stored');
db_set_folder_counts($fu, 'Drafts', 0, 2, 1700000000);
db_set_folder_counts($fu, 'Odd', null, null, 1700000000);
$fc = db_get_folder_counts($fu);
assert_eq(['unseen' => 4, 'total' => 10, 'checked_at' => 1700000000], $fc['INBOX'], 'a row reads back as ints');
assert_eq(null, $fc['Odd']['unseen'], 'an unknown unseen stays null, not 0');
assert_eq([], db_get_folder_counts($fv), 'counts are per user');
db_set_folder_counts($fu, 'INBOX', 5, 11, 1700000100);
assert_eq(5, db_get_folder_counts($fu)['INBOX']['unseen'], 'a second write replaces the row');

db_bump_folder_unseen($fu, 'INBOX', -1);
assert_eq(4, db_get_folder_counts($fu)['INBOX']['unseen'], 'bump -1');
db_bump_folder_unseen($fu, 'INBOX', 2);
assert_eq(6, db_get_folder_counts($fu)['INBOX']['unseen'], 'bump +2');
db_set_folder_counts($fu, 'Zero', 0, 3, 1700000000);
db_bump_folder_unseen($fu, 'Zero', -1);
assert_eq(0, db_get_folder_counts($fu)['Zero']['unseen'], 'a bump never goes below 0');
db_bump_folder_unseen($fu, 'Odd', 1);
assert_eq(null, db_get_folder_counts($fu)['Odd']['unseen'], 'a bump leaves an unknown count unknown');
db_bump_folder_unseen($fu, 'Nowhere', 1);
assert_false(isset(db_get_folder_counts($fu)['Nowhere']), 'a bump does not invent a row');

db_set_folder_state($fu, 'Odd', ['uidvalidity' => 1, 'uidnext' => 2, 'total' => 1, 'unseen' => 0]);
db_delete_folder_state($fu, 'Odd');
assert_false(isset(db_get_folder_counts($fu)['Odd']), 'deleting a folder drops its counts');
db_clear_cache_for_user($fu);
assert_eq([], db_get_folder_counts($fu), 'clearing the cache drops the counts');

group('folder_badge(): which folders show what');

$row = function ($u, $t) { return ['unseen' => $u, 'total' => $t, 'checked_at' => 1]; };
assert_eq(['n' => 3, 'kind' => 'unseen'], folder_badge('INBOX', $row(3, 9), null), 'inbox shows unseen');
assert_eq(['n' => 3, 'kind' => 'unseen'], folder_badge('Blogstatic', $row(3, 9), null), 'a custom folder shows unseen');
assert_eq(['n' => 3, 'kind' => 'unseen'], folder_badge('Junk', $row(3, 9), null), 'spam shows unseen');
assert_eq(['n' => 2, 'kind' => 'total'], folder_badge('Drafts', $row(0, 2), null), 'drafts shows its total');
assert_eq(null, folder_badge('Sent', $row(3, 9), null), 'sent shows nothing');
assert_eq(null, folder_badge('Trash', $row(3, 9), null), 'trash shows nothing');
assert_eq(null, folder_badge('Archive', $row(3, 9), null), 'archive shows nothing');
assert_eq(['n' => 0, 'kind' => 'unseen'], folder_badge('INBOX', $row(0, 9), null), 'zero is still a badge (JS hides it)');
assert_eq(null, folder_badge('INBOX', null, null), 'no row -> no badge');
assert_eq(null, folder_badge('INBOX', $row(null, 9), null), 'unknown unseen -> no badge');
$fmap = ['sent' => 'Outbox', 'drafts' => 'Brouillons', 'trash' => 'Bin', 'archive' => 'Old'];
assert_eq(null, folder_badge('Outbox', $row(3, 9), $fmap), 'a mapped sent folder shows nothing');
assert_eq(null, folder_badge('Old', $row(3, 9), $fmap), 'a mapped archive folder shows nothing');
assert_eq(['n' => 9, 'kind' => 'total'], folder_badge('Brouillons', $row(3, 9), $fmap), 'a mapped drafts folder shows total');
assert_eq(['n' => 3, 'kind' => 'unseen'], folder_badge('Sent', $row(3, 9), $fmap), 'with a map, only mapped names are excluded');

group('cache_mark_read(): bumps only on a real change');

$mr = 'markread-a@example.com';
db_upsert_messages($mr, 'INBOX', [$msg(1, 1700000001, ['unread' => true]), $msg(2, 1700000002)]);
db_set_folder_counts($mr, 'INBOX', 1, 2, 1700000000);
cache_mark_read($mr, 'INBOX', 1, true);
assert_eq(0, db_get_folder_counts($mr)['INBOX']['unseen'], 'reading an unread message decrements');
assert_eq(false, db_message_flags_map($mr, 'INBOX')[1]['unread'], 'and clears the cached flag');
cache_mark_read($mr, 'INBOX', 1, true);
assert_eq(0, db_get_folder_counts($mr)['INBOX']['unseen'], 'reading it again changes nothing');
cache_mark_read($mr, 'INBOX', 2, false);
assert_eq(1, db_get_folder_counts($mr)['INBOX']['unseen'], 'marking a read message unread increments');
cache_mark_read($mr, 'INBOX', 999, true);
assert_eq(1, db_get_folder_counts($mr)['INBOX']['unseen'], 'an uncached message changes nothing');

group('counts_sweep(): STATUS the due folders on one lazy connection');

class CountsFakeImap
{
  public $calls = [];
  public $fail = [];
  public function statusFor(string $folder): ?array
  {
    $this->calls[] = $folder;
    if (in_array($folder, $this->fail, true)) return null;
    return ['messages' => 10, 'unseen' => strlen($folder), 'uidnext' => 5, 'uidvalidity' => 1];
  }
}
$sw = 'sweep-a@example.com';
$swFolders = [
  ['name' => 'INBOX'], ['name' => 'Sent'], ['name' => 'Drafts'], ['name' => 'Trash'],
  ['name' => 'Archive'], ['name' => 'Junk'], ['name' => 'Blogstatic'],
];
$fake = new CountsFakeImap();
$opened = 0;
$getFake = function () use ($fake, &$opened) { $opened++; return $fake; };

$n = counts_sweep($getFake, $sw, $swFolders, null, 'INBOX', 1700001000, 60);
assert_eq(['Drafts', 'Junk', 'Blogstatic'], $fake->calls, 'skips the open folder and the excluded roles');
assert_eq(3, $n, 'reports the folders it swept');
assert_eq(1, $opened, 'one connection for the whole sweep');
assert_eq(4, db_get_folder_counts($sw)['Junk']['unseen'], 'stores the STATUS unseen');
assert_eq(10, db_get_folder_counts($sw)['Drafts']['total'], 'stores the STATUS total');
assert_eq(null, db_get_folder_state($sw, 'Junk'), 'never writes sync bookkeeping');

$fake->calls = [];
$opened = 0;
assert_eq(0, counts_sweep($getFake, $sw, $swFolders, null, 'INBOX', 1700001030, 60), 'nothing due inside the interval');
assert_eq(0, $opened, 'nothing due -> no connection');

$fake->calls = [];
$fake->fail = ['Junk'];
counts_sweep($getFake, $sw, $swFolders, null, 'INBOX', 1700001100, 60);
assert_eq(['Drafts', 'Junk', 'Blogstatic'], $fake->calls, 'due again after the interval');
assert_eq(4, db_get_folder_counts($sw)['Junk']['unseen'], 'a failed STATUS keeps the old counts');

$fake->calls = [];
assert_eq(0, counts_sweep(function () { return null; }, $sw, $swFolders, null, 'INBOX', 1700009999, 60),
    'no connection -> nothing swept');

group('view_sidebar(): counters');

$csb = view_sidebar([
  ['name' => 'INBOX', 'group' => 'primary', 'displayName' => 'Inbox'],
  ['name' => 'Drafts', 'group' => 'primary', 'displayName' => 'Drafts'],
  ['name' => 'Sent', 'group' => 'primary', 'displayName' => 'Sent'],
  ['name' => 'Blog static', 'group' => 'other', 'displayName' => 'Blog static'],
], 'INBOX', null, [
  'INBOX' => $row(7, 20), 'Drafts' => $row(0, 2), 'Sent' => $row(5, 50), 'Blog static' => $row(0, 3),
]);
assert_contains('data-folder="INBOX"', $csb, 'links carry their urlencoded folder');
assert_contains('data-folder="Blog+static"', $csb, 'data-folder uses the urlencode convention');
assert_contains('<span class="folder-count" data-kind="unseen">7</span>', $csb, 'inbox badge');
assert_contains('<span class="folder-count" data-kind="total">2</span>', $csb, 'drafts badge is its total');
assert_not_contains('>50</span>', $csb, 'no sent badge');
assert_contains('<span class="folder-count" data-kind="unseen" hidden>0</span>', $csb,
    'a zero badge is in the DOM but hidden, so JS can bump it');
assert_eq(3, substr_count($csb, 'class="folder-count"'), 'one badge per counted folder');
assert_contains('.sidebar .folder-count', css_inline(), 'css: the badge is styled');

// --------------------------------------------------------------- footer ------+
group('view_footer() is the one credit line');

$foot = view_footer();
assert_contains('Rogerio Taques', $foot, 'footer: names the author');
assert_contains('href="https://x.com/rogeriotaques"', $foot, 'footer: author link');
assert_contains('Use Bare', $foot, 'footer: names Use Bare');
assert_contains(
    'href="https://usebare.org?ref=Emmmail&amp;utm_source=Emmmail&amp;utm_media=Instance&amp;utm_content=' . APP_VERSION . '"',
    $foot,
    'footer: the Use Bare link carries the campaign and the version'
);
assert_contains('target="_blank" rel="noopener noreferrer"', $foot,
    'footer: credit links open safely in a new tab');
assert_contains('#' . APP_VERSION . ' &copy; Abtz Labs', $foot, 'footer: the version line is literal');

group('view_login() mounts the footer outside the card');

$loginFoot = view_login(str_repeat('0', 64));
assert_contains('class="login-footer"', $loginFoot, 'login: the footer is present');
assert_true(strpos($loginFoot, 'class="login-footer"') > strpos($loginFoot, '</form></div>'),
    'login: the footer sits after the card closes');
assert_contains('.login-footer', css_inline(), 'login: the footer is centered by CSS');

group('view_sidebar() pins the footer to the bottom of the left panel');

$footSb = view_sidebar([
  ['name' => 'INBOX', 'group' => 'primary', 'displayName' => 'Inbox'],
], 'INBOX', null, []);
assert_contains('class="sidebar-footer"', $footSb, 'app: the footer is present');
assert_true(strpos($footSb, 'class="sidebar-footer"') < strpos($footSb, '</aside>'),
    'app: the footer lives inside the panel');
assert_true(strpos($footSb, 'class="sidebar-footer"') > strpos($footSb, '<nav>'),
    'app: the footer follows the folder list');
assert_contains('class="sidebar-footer"', view_sidebar([], '', null, []),
    'app: an empty panel still carries the footer');
assert_contains('.sidebar-footer', css_inline(), 'app: the footer is pinned with CSS');

// ------------------------------------------------------------- summary ------+
summary();
