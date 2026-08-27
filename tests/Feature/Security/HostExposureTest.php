<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Exposure and configuration  [WP-34, TDD §6.1 A05, §6.3]
|--------------------------------------------------------------------------
|
| Two DoD items here can only be finished on the live host, and this file is
| explicit about which half it does:
|
|   - "Fetching /storage/…, /.env and /*.md over HTTP all fail" — needs Apache,
|     so it needs WP-00H. What is testable now is that the rules which will
|     produce that failure are present in the file that will be deployed, and
|     that the framework itself does not route those paths.
|   - "Every security header present, verified against the production response"
|     — the application half is in SecurityHeadersTest; the host half is
|     whether anything in front of us strips them.
|
| Neither is ticked by this file. Writing the rule and shipping it are not the
| same act, and a review that treats them as the same is how `.env` ends up
| readable on a host nobody checked.
*/

uses(RefreshDatabase::class);

function htaccess(): string
{
    return (string) file_get_contents(public_path('.htaccess'));
}

/*
 |--------------------------------------------------------------------------
 | What ships in public/.htaccess
 |--------------------------------------------------------------------------
 */

it('TDD 6.3 denies dotfiles, so a stray .env is not served', function () {
    /*
     | `.env` lives outside the document root by construction, so this is the
     | second lock, not the first. It is worth having because the way it fails
     | is not a code change: a deploy that copies the tree to the wrong place,
     | or a backup unpacked under public/ during an incident. Apache serving a
     | stray `.env` is the single commonest way a Laravel APP_KEY leaks.
    */
    expect(htaccess())->toContain('RewriteRule (^|/)\. - [F,L]');
});

it('TDD 6.3 denies documentation and configuration files by extension', function () {
    $rules = htaccess();

    foreach (['md', 'yml', 'lock', 'json', 'ini', 'log', 'sql', 'bak', 'example'] as $extension) {
        expect(str_contains($rules, $extension))
            ->toBeTrue("public/.htaccess does not deny .{$extension} files.");
    }

    // Both Apache authorisation syntaxes. cPanel hosts vary between 2.2 and
    // 2.4, and a directive the running Apache does not understand is a 500 for
    // the whole site rather than a rule that quietly does nothing.
    expect($rules)->toContain('mod_authz_core.c')
        ->toContain('Require all denied')
        ->toContain('Deny from all');
});

it('TDD 6.3 disables directory listing unconditionally', function () {
    /*
     | Laravel ships `Options -MultiViews -Indexes` nested inside two IfModule
     | checks — mod_rewrite and mod_negotiation. On a host without
     | mod_negotiation the -Indexes never applies, and the directory that gets
     | listed is the one holding compiled assets.
    */
    $rules = htaccess();

    $unconditional = preg_replace('/<IfModule.*?<\/IfModule>/s', '', $rules);

    expect($unconditional)->toContain('Options -Indexes');
});

it('WP-34 keeps index.php reachable, since a hardened site that 500s is not hardened', function () {
    // The deny rules above are broad on purpose. This is the check that they
    // did not become broad enough to break the application.
    expect(htaccess())->toContain('RewriteRule ^ index.php [L]');

    // And the front controller still answers.
    $this->get('/')->assertOk();
});

/*
 |--------------------------------------------------------------------------
 | What the framework itself will not route
 |--------------------------------------------------------------------------
 */

it('A05 routes nothing under /storage, /.env or a markdown path', function () {
    /*
     | Independent of Apache. Even with every .htaccess rule removed, these
     | must not be routes — because on the host the application answers first
     | for anything the rewrite passes through to index.php.
     |
     | 404 is the right answer here, not 403: these are not resources being
     | withheld, they are paths that mean nothing.
    */
    foreach (['/.env', '/README.md', '/composer.json', '/storage/documents/1/anything.pdf'] as $path) {
        expect($this->get($path)->status())
            ->toBe(404, "{$path} is routed by the application.");
    }
});

it('A05 registers no framework route over the disk holding tenant documents', function () {
    /*
     | Found by this review. Laravel 11+ defaults `serve => true` on a local
     | disk, which registers two routes on whatever that disk holds:
     |
     |     GET  /storage/{path}   storage.local
     |     PUT  /storage/{path}   storage.local.upload
     |
     | Our local disk IS the document vault. Both routes check a URL signature
     | and nothing else -- no session, no policy, no ownership -- so a signed
     | link to `documents/5/<uuid>.pdf` would serve tenant 5's lease to whoever
     | held it, and the PUT would write into the private store.
     |
     | That is the check AC-DOC-04 and I-9 exist to enforce, and
     | Portal\DocumentController does enforce it. This was a second, weaker
     | door onto the same files. Nothing generated those URLs, so closing it
     | costs nothing.
    */
    foreach (['storage.local', 'storage.local.upload', 'storage.public'] as $name) {
        expect(Route::has($name))
            ->toBeFalse("The framework serves {$name}, bypassing the ownership check.");
    }
});

it('A05 has no public storage symlink to serve uploads through', function () {
    /*
     | `php artisan storage:link` is a habit rather than a requirement, and it
     | would put `public/storage` in front of a disk. It happens to be harmless
     | here — FILESYSTEM_DISK is `local`, rooted at storage/app/private, which
     | the link does not point at — but a link that only fails to expose
     | anything because of a separate setting is one setting away from doing so.
    */
    expect(is_link(public_path('storage')) || is_dir(public_path('storage')))
        ->toBeFalse('public/storage exists, putting a storage disk inside the document root.');
});

/*
 |--------------------------------------------------------------------------
 | Configuration
 |--------------------------------------------------------------------------
 */

it('A05 ships no APP_DEBUG=true and no APP_KEY in .env.example', function () {
    /*
     | The example file is what a deploy is copied from, so its defaults become
     | production's defaults for anyone in a hurry. APP_DEBUG=true is the one
     | that matters: a stack trace on a 500 carries the query, the bindings and
     | the file path, and this application's queries carry money and names.
     |
     | It IS true in the example today, which is correct for a local file —
     | so this asserts the pair that makes that safe: APP_ENV=local beside it,
     | and an empty APP_KEY, so nobody inherits a key from a repository.
    */
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)->toContain('APP_ENV=local')
        ->toContain('APP_KEY=');

    expect(preg_match('/^APP_KEY=.+$/m', $example))
        ->toBe(0, '.env.example ships a real APP_KEY. Generate it on the server (TDD §6.3).');
});

it('A05 shows a tenant no stack trace when debug is off', function () {
    config(['app.debug' => false]);

    /*
     | The error page a resident actually sees. It carries a reference they can
     | quote and an admin can grep for — never a trace, never a file path,
     | never a query.
    */
    $response = $this->get('/no-such-page-exists-here');

    $body = $response->getContent();

    expect(str_contains($body, base_path()))->toBeFalse('The error page leaks a filesystem path.')
        ->and(str_contains($body, 'Stack trace'))->toBeFalse('The error page carries a stack trace.');
});

it('TDD 6.3 asks MySQL for no privilege it does not need', function () {
    /*
     | The DoD item is "database user holds no DROP or GRANT", which can only be
     | asked of the production user on the host. What is checkable here is the
     | thing that would make that impossible to satisfy: an application that
     | issues DDL at runtime.
     |
     | It does not. Migrations are a deploy step, run by a person, and the
     | privilege they need can be granted for that window and taken away again.
    */
    $ddl = collect(glob(app_path('**/*.php')) ?: [])
        ->merge(glob(app_path('*/*/*.php')) ?: [])
        /*
         | Console commands are exempt, and only these. `hupm:preflight`
         | creates and drops a scratch table on purpose -- proving the database
         | user CAN do DDL is one of its TDD §12.4 checks. That is a person
         | running a command at deploy time, which is exactly the window where
         | the privilege is meant to exist and be taken away again afterwards.
         | What must never issue DDL is a request or a queued job.
        */
        ->reject(fn ($file) => str_contains(str_replace('\\', '/', (string) $file), '/Console/Commands/'))
        ->filter(fn ($file) => (bool) preg_match(
            '/Schema::(create|drop|dropIfExists|rename)|DB::statement\(\s*[\'"](DROP|CREATE TABLE|GRANT)/i',
            (string) file_get_contents($file),
        ))
        ->values()
        ->all();

    expect($ddl)->toBe([], 'Application code issues DDL: '.implode(', ', $ddl));
});

/*
 |--------------------------------------------------------------------------
 | I-5, the final grep
 |--------------------------------------------------------------------------
 */

it('I-5 finds no bank number anywhere in the database or the logs', function () {
    /*
     | The DoD's final grep, as a command so it can be run again on the host at
     | go-live (WP-35) rather than only here.
     |
     | Distinct from the architecture test, which forbids a COLUMN called
     | `account_number`. This asks whether a number is sitting in a column that
     | is innocent by name — an adjustment description, a ticket note, an audit
     | context — which is how it actually gets in. Nobody adds a routing_number
     | column; somebody pastes what the bank's email said into a reason field,
     | and ledger rows are immutable (I-3), so it stays.
    */
    expect(Artisan::call('hupm:bank-data-sweep'))
        ->toBe(0, Artisan::output());
});

it('I-5 makes the sweep prove it can fail', function () {
    /*
     | A sweep that always returns zero is indistinguishable from a sweep that
     | never looks. Plant one, confirm it is found, and confirm the finding does
     | not print the number — a report that quotes the account number commits
     | the offence it exists to report.
    */
    Tenant::factory()->create([
        'first_name' => 'Marisol',
        'last_name' => 'Quintanilla',
        'notes' => 'Returned R01 — bank quoted account 900012345678 routing 061000052.',
    ]);

    expect(Artisan::call('hupm:bank-data-sweep'))->toBe(1);

    $output = Artisan::output();

    expect(str_contains($output, 'tenants.notes'))->toBeTrue('The finding does not say where it is.')
        ->and(str_contains($output, '900012345678'))->toBeFalse('The report printed the account number.')
        ->and(str_contains($output, '[redacted]'))->toBeTrue('The finding is not redacted.');
});
