<?php

/*
|--------------------------------------------------------------------------
| No float on money paths  [WP-02 DoD, INVARIANT I-10]
|--------------------------------------------------------------------------
|
| Money is never a PHP float. The failure mode is quiet: 0.1 + 0.2 is
| 0.30000000000000004, a balance ends a cent out, and nobody notices until a
| tenant disputes a figure that the system cannot justify.
|
| "Money path" means any file that touches App\Support\Money, plus the domain
| layer and the money cast. Files that legitimately use floats for non-money
| values (unit bathrooms is DECIMAL(3,1)) are unaffected, because they do not
| import Money.
|
*/

/** @return list<string> absolute paths of every PHP file under app/ */
function appPhpFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(__DIR__.'/../../app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

/** @return list<string> files that handle money */
function moneyPathFiles(): array
{
    return array_values(array_filter(appPhpFiles(), function (string $path) {
        if (str_contains($path, '/app/Domain/') || str_contains($path, '/app/Casts/')) {
            return true;
        }

        $source = file_get_contents($path);

        return str_contains($source, 'App\\Support\\Money')
            || str_contains($source, 'MoneyCast');
    }));
}

it('has money-handling files to check', function () {
    // Guards against the filter silently matching nothing, which would make
    // every assertion below vacuously true.
    expect(moneyPathFiles())->not->toBeEmpty();
});

it('I-10 uses no float cast on any money path', function () {
    $offenders = [];

    foreach (moneyPathFiles() as $path) {
        $source = file_get_contents($path);

        foreach ([
            '/\(\s*float\s*\)/i' => '(float) cast',
            '/\(\s*double\s*\)/i' => '(double) cast',
            '/\bfloatval\s*\(/i' => 'floatval()',
            '/\bdoubleval\s*\(/i' => 'doubleval()',
            // number_format()'s first parameter is a float, so calling it on a
            // money value converts through one — Money::format() builds the
            // string by concatenation instead.
            '/\bnumber_format\s*\(/i' => 'number_format()',
            // round/floor/ceil return floats and are how "just fix the cent"
            // becomes a permanent rounding bug.
            '/\bround\s*\(/i' => 'round()',
            '/\bfloor\s*\(/i' => 'floor()',
            '/\bceil\s*\(/i' => 'ceil()',
        ] as $pattern => $label) {
            if (preg_match($pattern, $source)) {
                $offenders[] = basename($path).' uses '.$label;
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, $offenders));
});

it('I-10 declares no float type on a money path', function () {
    $offenders = [];

    foreach (moneyPathFiles() as $path) {
        $source = file_get_contents($path);

        // ": float" as a return type, or "float $x" as a parameter.
        if (preg_match('/:\s*\??float\b/', $source) || preg_match('/\bfloat\s+\$/', $source)) {
            // MoneyCast deliberately names float to reject it; that is the one
            // legitimate mention.
            if (! str_contains($source, 'Refusing to store a float')) {
                $offenders[] = basename($path);
            }
        }
    }

    expect($offenders)->toBe([], 'float type declared on: '.implode(', ', $offenders));
});

it('keeps Money serialising as a string so no float reaches the frontend', function () {
    $source = file_get_contents(__DIR__.'/../../app/Support/Money.php');

    // jsonSerialize must be typed to string. If it ever returns a number,
    // JavaScript receives an IEEE-754 double and the guarantee ends at the
    // network boundary.
    expect($source)->toContain('public function jsonSerialize(): string');
});
