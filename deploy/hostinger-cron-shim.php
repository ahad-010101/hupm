<?php

/*
 * Cron shim for Hostinger hPanel.  [WP-00H, 2 November 2026]
 *
 * hPanel's "Command to run" field is not a shell command line. Whatever you
 * type into it, hPanel runs:
 *
 *     /usr/bin/php /home/u468256881/<what you typed>
 *
 * Pasting the full command therefore produced
 *
 *     /usr/bin/php /home/u468256881//opt/alt/php83/usr/bin/php /home/.../artisan
 *
 * and the error "Could not open input file" - PHP handed the PHP binary as a
 * script to execute.
 *
 * Typing "hupm/artisan schedule:run" does work, but /usr/bin/php is 8.2.35
 * while the web server runs 8.3.36. The project declares ^8.2, so nothing
 * breaks today; it would mean every scheduled job runs on a different PHP
 * from every web request, which is how you get a bug that only ever appears
 * in cron and never in a browser.
 *
 * So hPanel points at this file, and this file hands off to 8.3. The path is
 * absolute on purpose: bare `php` in cron is R-5, the single most common
 * silent failure on this class of hosting.
 *
 * WHY proc_open AND NOT passthru. This account disables system, exec,
 * shell_exec, passthru and popen. proc_open is the only one left, which is
 * also why Laravel's own scheduler can run background jobs here at all.
 * Do not "simplify" this to passthru - it fatals.
 *
 * hPanel "Command to run":  hupm-cron.php
 * Schedule:                 every minute (* * * * *)
 *
 * Lives outside the release directory so a deploy never replaces or removes
 * it. Documented in deploy/hostinger-migration.md.
 */

const PHP83 = '/opt/alt/php83/usr/bin/php';
const ARTISAN = '/home/u468256881/hupm/artisan';

if (! is_executable(PHP83)) {
    // Loud, because the alternative is charges silently never posting. This
    // surfaces in hPanel's "View Output".
    fwrite(STDERR, 'hupm-cron: '.PHP83." is missing or not executable\n");
    exit(1);
}

// Inherit this process's stdout/stderr so anything the scheduler says reaches
// hPanel's output viewer rather than disappearing.
$process = proc_open(
    [PHP83, ARTISAN, 'schedule:run'],
    [1 => STDOUT, 2 => STDERR],
    $pipes,
);

if (! is_resource($process)) {
    fwrite(STDERR, "hupm-cron: could not start the scheduler\n");
    exit(1);
}

exit(proc_close($process));
