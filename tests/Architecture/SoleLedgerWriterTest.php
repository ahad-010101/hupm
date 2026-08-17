<?php

/*
|--------------------------------------------------------------------------
| Sole ledger writer  [WP-09 DoD, INVARIANT I-2]
|--------------------------------------------------------------------------
|
| "No class other than LedgerService references LedgerEntry::create, ::insert,
| ::update or mass assignment. This test is the package."
|
| The rule is not tidiness. Every additional writer is another place where the
| sign convention, the BR-05 status rules, the charge_key idempotency and the
| audit row can drift apart — and a ledger that disagrees with itself cannot be
| used to answer a tenant's question about their own money.
|
| Seeders and console commands are scanned too. A seeder that inserts ledger
| rows directly is the most likely way this rule dies quietly, because it never
| looks like production code.
|
*/

use Illuminate\Support\Facades\Route;

const LEDGER_TABLE = 'ledger_entries';

/** The one file allowed to write. */
const SOLE_WRITER = 'app/Domain/Ledger/LedgerService.php';

/**
 * Every scanned file, with comments removed.
 *
 * Stripping comments matters more than it sounds: the first version of this
 * test flagged BalanceCalculator for the sentence "a cached balance is a second
 * source of truth" — prose explaining the rule, read as a violation of it. An
 * architecture test that greps documentation punishes writing any down.
 *
 * @return array<string, string> relative path => code without comments
 */
function scannedSources(): array
{
    $root = realpath(__DIR__.'/../..');
    $sources = [];

    foreach (['app', 'database', 'routes'] as $directory) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator("{$root}/{$directory}", FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($relative === SOLE_WRITER) {
                continue;
            }

            $sources[$relative] = stripComments(file_get_contents($file->getPathname()));
        }
    }

    return $sources;
}

function stripComments(string $source): string
{
    $code = '';

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $code .= in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1];

            continue;
        }

        $code .= $token;
    }

    return $code;
}

it('has sources to scan', function () {
    // A filter that silently matches nothing would make every assertion below
    // vacuously true.
    expect(scannedSources())->not->toBeEmpty()
        ->and(file_exists(__DIR__.'/../../'.SOLE_WRITER))->toBeTrue();
});

it('I-2 lets no other class write ledger_entries through Eloquent', function () {
    $offenders = [];

    $forbidden = [
        '/LedgerEntry::create\s*\(/' => 'LedgerEntry::create()',
        '/LedgerEntry::insert\s*\(/' => 'LedgerEntry::insert()',
        '/LedgerEntry::insertGetId\s*\(/' => 'LedgerEntry::insertGetId()',
        '/LedgerEntry::updateOrCreate\s*\(/' => 'LedgerEntry::updateOrCreate()',
        '/LedgerEntry::firstOrCreate\s*\(/' => 'LedgerEntry::firstOrCreate()',
        '/LedgerEntry::forceCreate\s*\(/' => 'LedgerEntry::forceCreate()',
        '/LedgerEntry::upsert\s*\(/' => 'LedgerEntry::upsert()',
        '/new\s+LedgerEntry\b/' => 'new LedgerEntry',
    ];

    foreach (scannedSources() as $path => $source) {
        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $source)) {
                $offenders[] = "{$path} uses {$label}";
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('I-2 lets no other class write ledger_entries through the query builder', function () {
    $offenders = [];

    foreach (scannedSources() as $path => $source) {
        // Reads are fine — BalanceCalculator sums through the query builder on
        // purpose. Only writes are forbidden.
        foreach (['insert', 'insertGetId', 'update', 'delete', 'upsert', 'truncate'] as $write) {
            $pattern = '/table\(\s*[\'"]'.LEDGER_TABLE.'[\'"]\s*\)(?:(?!;).)*->'.$write.'\s*\(/s';

            if (preg_match($pattern, $source)) {
                $offenders[] = "{$path} calls ->{$write}() on ".LEDGER_TABLE;
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('I-2 keeps LedgerEntry unfillable, so nothing can mass-assign its way past the service', function () {
    $model = new App\Models\LedgerEntry;

    expect($model->getFillable())->toBe([])
        ->and($model->getGuarded())->toBe(['*']);
});

it('AC-LED-01 exposes no route that updates or deletes a ledger entry', function () {
    $offending = collect(Route::getRoutes())->filter(function ($route) {
        $uri = $route->uri();
        $methods = $route->methods();

        return str_contains($uri, 'ledger')
            && array_intersect($methods, ['PUT', 'PATCH', 'DELETE']) !== [];
    });

    // Corrections are reversing entries. There is no edit route to secure,
    // because there is no edit route.
    expect($offending->pluck('uri')->all())->toBe([]);
});

it('BR-03 / I-1 stores no balance anywhere, so nothing can drift', function () {
    $offenders = [];

    foreach (scannedSources() as $path => $source) {
        // A cached balance is a second source of truth. TDD §7 forbids it, and
        // the failure mode is a figure nobody can explain. Matches real calls —
        // cache()->remember('...balance...'), Cache::put('balance', …) — not
        // the word appearing near the word.
        if (preg_match('/(?:cache\(\)|Cache::)\s*(?:->)?\w*\s*\(\s*[\'"][^\'"]*balance/i', $source)) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([], 'balance appears to be cached in: '.implode(', ', $offenders));
});
