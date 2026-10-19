<?php
/**
 * tests/run.php — run every test suite and report one total.
 *
 *   php tests/run.php
 *
 * Suites are plain PHP files that can also be run on their own
 * (`php tests/unit.php`). Requiring them here just runs them in order and lets
 * their output through untouched; summary() then defers exiting to
 * test_totals().
 *
 * Note: this shares one process, so a fatal error in a suite stops the run.
 * That is deliberate — a broken suite should be fixed before the rest matter.
 */

define('EMMMAIL_TEST_RUNNER', true);
require_once __DIR__ . '/_harness.php';

// Fast, hermetic suites first; the live-server smoke test last.
foreach (['unit', 'js', 'imap', 'smoke'] as $suite) {
    require __DIR__ . '/' . $suite . '.php';
}

test_totals();
