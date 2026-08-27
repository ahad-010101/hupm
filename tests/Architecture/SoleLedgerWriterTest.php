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

use App\Models\LedgerEntry;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\Route;

const LEDGER_TABLE = 'ledger_entries';

const ALLOCATION_TABLE = 'payment_allocations';

/** The one file allowed to write the ledger. */
const SOLE_WRITER = 'app/Domain/Ledger/LedgerService.php';

/** The one file allowed to write allocations. */
const SOLE_ALLOCATOR = 'app/Domain/Payments/AllocationService.php';

/**
 * Every scanned file, with comments removed.
 *
 * Stripping comments matters more than it sounds: the first version of this
 * test flagged BalanceCalculator for the sentence "a cached balance is a second
 * source of truth" — prose explaining the rule, read as a violation of it. An
 * architecture test that greps documentation punishes writing any down.
 *
 * @param  string  ...$except  the file the rule under test permits to write
 * @return array<string, string> relative path => code without comments
 */
function scannedSources(string ...$except): array
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

            if (in_array($relative, $except, true)) {
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
        ->and(file_exists(__DIR__.'/../../'.SOLE_WRITER))->toBeTrue()
        ->and(file_exists(__DIR__.'/../../'.SOLE_ALLOCATOR))->toBeTrue();
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

    foreach (scannedSources(SOLE_WRITER) as $path => $source) {
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

    foreach (scannedSources(SOLE_WRITER) as $path => $source) {
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
    $model = new LedgerEntry;

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

/*
 |--------------------------------------------------------------------------
 | Sole allocation writer  [WP-11, FR-LED-03]
 |--------------------------------------------------------------------------
 |
 | The same rule for the same reason. An allocation decides how much of a
 | charge is still owed, so a second writer is a second answer to "what does
 | this tenant owe" — and unlike the ledger, allocations have no reversing-entry
 | discipline to make a wrong one visible. They are stamped, not opposed.
 |
 */

it('lets no other class write payment_allocations through Eloquent', function () {
    $offenders = [];

    $forbidden = [
        '/PaymentAllocation::create\s*\(/' => 'PaymentAllocation::create()',
        '/PaymentAllocation::insert\s*\(/' => 'PaymentAllocation::insert()',
        '/PaymentAllocation::insertGetId\s*\(/' => 'PaymentAllocation::insertGetId()',
        '/PaymentAllocation::updateOrCreate\s*\(/' => 'PaymentAllocation::updateOrCreate()',
        '/PaymentAllocation::firstOrCreate\s*\(/' => 'PaymentAllocation::firstOrCreate()',
        '/PaymentAllocation::forceCreate\s*\(/' => 'PaymentAllocation::forceCreate()',
        '/PaymentAllocation::upsert\s*\(/' => 'PaymentAllocation::upsert()',
        '/new\s+PaymentAllocation\b/' => 'new PaymentAllocation',
    ];

    foreach (scannedSources(SOLE_ALLOCATOR) as $path => $source) {
        foreach ($forbidden as $pattern => $label) {
            if (preg_match($pattern, $source)) {
                $offenders[] = "{$path} uses {$label}";
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('lets no other class write payment_allocations through the query builder', function () {
    $offenders = [];

    foreach (scannedSources(SOLE_ALLOCATOR) as $path => $source) {
        foreach (['insert', 'insertGetId', 'update', 'delete', 'upsert', 'truncate'] as $write) {
            $pattern = '/table\(\s*[\'"]'.ALLOCATION_TABLE.'[\'"]\s*\)(?:(?!;).)*->'.$write.'\s*\(/s';

            if (preg_match($pattern, $source)) {
                $offenders[] = "{$path} calls ->{$write}() on ".ALLOCATION_TABLE;
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('D-02 un-allocates by stamping reversed_at, never by deleting', function () {
    $model = new PaymentAllocation;

    expect($model->getGuarded())->toBe(['*'])
        // The Immutable trait refuses a delete outright; `reversed_at` is the
        // only attribute that may change after the row exists.
        ->and((fn () => $this->mutableAttributes())->call($model))->toBe(['reversed_at']);
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
