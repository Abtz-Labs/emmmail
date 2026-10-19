<?php
/**
 * tests/_harness.php — shared bootstrap and assertions for every test suite.
 *
 * Support file, not a suite: tests/run.php only requires the suites it lists.
 *
 * A suite starts with:
 *
 *     require_once __DIR__ . '/_harness.php';
 *     test_suite('unit');
 *
 * ...makes assertions, then calls summary() at the end. Suites work the same way
 * whether run directly (`php tests/unit.php`) or required by tests/run.php.
 *
 * test_suite() gives each suite its own throwaway SQLite database, so the real
 * emmmail.sqlite is never touched and suites cannot see each other's rows.
 *
 * Everything runs at global scope on purpose: index.php declares top-level
 * variables, so requiring it from inside a function would change their scope.
 */

// Included with require_once, which is what keeps this idempotent: a
// defined()-guard would not, because PHP early-binds unconditional top-level
// function declarations at compile time, before any guard could run.

// Load index.php as a library: the router returns early when this is defined.
define('EMMMAIL_TESTING', true);
require_once __DIR__ . '/../index.php';

// ------------------------------------------------------------- counters ----+
$GLOBALS['TEST_PASS'] = 0;   // current suite
$GLOBALS['TEST_FAIL'] = 0;
$GLOBALS['TEST_SKIP'] = 0;
$GLOBALS['TEST_SUITE'] = '';
$GLOBALS['TEST_ALL'] = ['pass' => 0, 'fail' => 0, 'skip' => 0, 'suites' => 0];
$GLOBALS['TEST_DBS'] = [];   // throwaway databases to remove on exit

// Colour only when attached to a terminal, so piped/CI output stays clean.
function test_tty(): bool
{
    static $tty = null;
    if ($tty === null) {
        $tty = function_exists('posix_isatty') ? @posix_isatty(STDOUT) : (getenv('TERM') !== false);
    }
    return $tty;
}
function colorGreen(string $s): string  { return test_tty() ? "\033[32m$s\033[0m" : $s; }
function colorRed(string $s): string    { return test_tty() ? "\033[31m$s\033[0m" : $s; }
function colorYellow(string $s): string { return test_tty() ? "\033[33m$s\033[0m" : $s; }
function colorBold(string $s): string   { return test_tty() ? "\033[1m$s\033[0m" : $s; }

function cleanup_test_db(): void
{
    foreach ($GLOBALS['TEST_DBS'] as $db) {
        foreach (glob($db . '*') ?: [] as $f) {
            @chmod($f, 0600);
            @unlink($f);
        }
    }
}
register_shutdown_function('cleanup_test_db');

// Begin a suite: give it a private database and reset the per-suite counters.
function test_suite(string $name): void
{
    $GLOBALS['TEST_SUITE'] = $name;
    $GLOBALS['TEST_PASS'] = 0;
    $GLOBALS['TEST_FAIL'] = 0;
    $GLOBALS['TEST_SKIP'] = 0;
    $GLOBALS['TEST_ALL']['suites']++;

    $db = __DIR__ . '/' . $name . '.sqlite';
    $GLOBALS['TEST_DBS'][$db] = $db;
    putenv('EMMMAIL_DB_PATH=' . $db);
    // Clear anything left by an interrupted run, WAL/SHM sidecars included.
    foreach (glob($db . '*') ?: [] as $f) { @unlink($f); }
    // Passing a path resets db_open()'s memoized handle onto the new file.
    db_open($db);

    echo "\n" . colorBold(str_repeat('=', 72)) . "\n";
    echo colorBold('SUITE: ' . $name) . "\n";
    echo colorBold(str_repeat('=', 72)) . "\n";
}

// ------------------------------------------------------------- reporting ---+
function group(string $name): void
{
    echo "\n" . colorBold("### {$name} ###") . "\n";
}

function passTest(string $message): void
{
    $GLOBALS['TEST_PASS']++;
    echo '  ' . colorGreen('PASS') . ' ' . $message . "\n";
}

function failTest(string $message): void
{
    $GLOBALS['TEST_FAIL']++;
    echo '  ' . colorRed('FAIL') . ' ' . $message . "\n";
}

function skip(string $message): void
{
    $GLOBALS['TEST_SKIP']++;
    echo '  ' . colorYellow('SKIP') . ' ' . $message . "\n";
}

// ------------------------------------------------------------ assertions ---+
function assert_eq($expected, $actual, $label): void
{
    if ($expected === $actual) {
        passTest($label);
        return;
    }
    failTest($label);
    echo '    ' . colorRed('expected: ' . var_export($expected, true)) . "\n";
    echo '    ' . colorRed('actual:   ' . var_export($actual, true)) . "\n";
}

function assert_true($val, $label): void
{
    if ($val === true) {
        passTest($label);
        return;
    }
    failTest($label);
    echo '    ' . colorRed('expected: true') . "\n";
    echo '    ' . colorRed('actual:   ' . var_export($val, true)) . "\n";
}

function assert_false($val, $label): void
{
    if ($val === false) {
        passTest($label);
        return;
    }
    failTest($label);
    echo '    ' . colorRed('expected: false') . "\n";
    echo '    ' . colorRed('actual:   ' . var_export($val, true)) . "\n";
}

function assert_contains($needle, $haystack, $label): void
{
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        passTest($label);
        return;
    }
    failTest($label);
    echo '    ' . colorRed('expected to contain: ' . var_export($needle, true)) . "\n";
}

function assert_not_contains($needle, $haystack, $label): void
{
    if (is_string($haystack) && strpos($haystack, $needle) !== false) {
        failTest($label);
        echo '    ' . colorRed('should NOT contain: ' . var_export($needle, true)) . "\n";
        return;
    }
    passTest($label);
}

// A bare-global guard. `$name` is the global the app must never reach for
// (`Notification`, `localStorage`, ...); a hit is anything that names it without
// going through `win.`, so an object property of the same name is fine. Comments
// are stripped first, because they talk about window.localStorage rather than
// reaching for it, and `$drop` blanks the few lines allowed to name the global as
// a deliberate handoff.
function assert_no_bare_global(string $name, string $js, string $label, array $drop = []): void
{
    $code = (string)preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $js);
    foreach ($drop as $line) {
        $code = str_replace($line, '', $code);
    }
    if (preg_match('/(?<![.\w$])' . preg_quote($name, '/') . '\b/', $code, $m) !== 1) {
        passTest($label);
        return;
    }
    failTest($label);
    echo '    ' . colorRed('bare global: ' . $m[0]) . "\n";
}

// ---------------------------------------------------------------- summary --+
// End of a suite. Prints its result and folds it into the running total. Exits
// only when the suite was run on its own; under tests/run.php it returns so the
// next suite can run and test_totals() can report the whole thing.
function summary(): void
{
    $pass = $GLOBALS['TEST_PASS'];
    $fail = $GLOBALS['TEST_FAIL'];
    $skip = $GLOBALS['TEST_SKIP'];

    $GLOBALS['TEST_ALL']['pass'] += $pass;
    $GLOBALS['TEST_ALL']['fail'] += $fail;
    $GLOBALS['TEST_ALL']['skip'] += $skip;

    echo "\n" . colorGreen("$pass passed.") . ' '
        . ($fail > 0 ? colorRed("$fail failed.") : "$fail failed.") . ' '
        . ($skip > 0 ? colorYellow("$skip skipped.") : "$skip skipped.") . "\n";

    if (!defined('EMMMAIL_TEST_RUNNER')) {
        cleanup_test_db();
        exit($fail === 0 ? 0 : 1);
    }
}

// Final report for tests/run.php.
function test_totals(): void
{
    $t = $GLOBALS['TEST_ALL'];
    $ran = $t['pass'] + $t['fail'];

    echo "\n" . colorBold(str_repeat('=', 72)) . "\n";
    echo colorBold(sprintf('%d suites, %d tests run: ', $t['suites'], $ran))
        . colorGreen($t['pass'] . ' passed') . ', '
        . ($t['fail'] > 0 ? colorRed($t['fail'] . ' failed') : '0 failed') . ', '
        . ($t['skip'] > 0 ? colorYellow($t['skip'] . ' skipped') : '0 skipped') . "\n";
    echo colorBold(str_repeat('=', 72)) . "\n";

    cleanup_test_db();
    exit($t['fail'] === 0 ? 0 : 1);
}
