<?php

/*
|--------------------------------------------------------------------------
| Case sensitivity  [WP-00L, DERIVED]
|--------------------------------------------------------------------------
|
| Development runs on Windows (case-insensitive); HostGator runs Linux
| (case-sensitive). A class referenced as `Userservice` but filed as
| `UserService.php` works locally and 500s in production. There is no CI and
| no validated staging on this project, so this test is the guard.
|
| file_exists() is itself case-insensitive on Windows, so every check below
| compares against a real directory listing rather than trusting the OS.
|
*/

const APP_ROOT = __DIR__.'/../..';

/**
 * Resolve whether a path exists with EXACT case, by walking each segment and
 * comparing against scandir output. Windows would otherwise answer "yes" to
 * any casing.
 */
function existsWithExactCase(string $absolutePath): bool
{
    $absolutePath = str_replace('\\', '/', $absolutePath);
    $root = str_replace('\\', '/', realpath(APP_ROOT) ?: APP_ROOT);

    if (! str_starts_with($absolutePath, $root)) {
        return false;
    }

    $relative = trim(substr($absolutePath, strlen($root)), '/');
    $current = $root;

    foreach (explode('/', $relative) as $segment) {
        $entries = @scandir($current);

        if ($entries === false || ! in_array($segment, $entries, true)) {
            return false;
        }

        $current .= '/'.$segment;
    }

    return true;
}

/** @return list<string> every .php file under app/ */
function phpClassFiles(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(APP_ROOT.'/app', FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'php') {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }

    sort($files);

    return $files;
}

it('names every class file exactly after the type it declares', function () {
    $mismatches = [];

    foreach (phpClassFiles() as $path) {
        $source = file_get_contents($path);

        if (! preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $m)) {
            continue; // no type declared — helper file, fine
        }

        $declared = $m[1];
        $basename = basename($path, '.php');

        if ($declared !== $basename) {
            $mismatches[] = basename($path)." declares {$declared}";
        }
    }

    expect($mismatches)->toBe([], 'Class name must match filename exactly — Linux will not find these:'.PHP_EOL.implode(PHP_EOL, $mismatches));
});

it('matches every namespace to its directory path with exact case', function () {
    $mismatches = [];

    foreach (phpClassFiles() as $path) {
        $source = file_get_contents($path);

        if (! preg_match('/^namespace\s+([^;]+);/m', $source, $m)) {
            continue;
        }

        // App\Domain\Ledger  ->  app/Domain/Ledger
        $expected = 'app/'.str_replace('\\', '/', substr(trim($m[1]), strlen('App\\')));
        $expected = rtrim($expected, '/');
        $actual = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $actualRelative = substr($actual, strpos($actual, '/app') + 1);

        if ($expected !== $actualRelative) {
            $mismatches[] = basename($path).": namespace expects {$expected}, file is in {$actualRelative}";
        }
    }

    expect($mismatches)->toBe([], 'PSR-4 namespace/directory case mismatch:'.PHP_EOL.implode(PHP_EOL, $mismatches));
});

it('resolves every Blade view reference with exact case', function () {
    $unresolved = [];
    $viewRoot = APP_ROOT.'/resources/views';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($viewRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (! str_ends_with($file->getFilename(), '.blade.php')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        preg_match_all("/@(?:extends|include|includeIf)\(\s*'([^']+)'/", $source, $matches);

        foreach ($matches[1] as $view) {
            /*
             | A trailing dot means the name is built at runtime —
             | `@include('public.sections.'.$section['type'])`, the WP-36 section
             | renderer. There is no file to check the case of, because the file
             | is chosen per row.
             |
             | Skipped here rather than exempted quietly: what the dynamic
             | include needs is proof that every type in the library has a
             | partial, and that is asserted directly in
             | tests/Feature/Admin/WebsiteEditorTest.php — "has a Blade partial
             | for every section type it offers". This test cannot do that job,
             | and pretending otherwise would be worse than saying so.
            */
            if (str_ends_with($view, '.')) {
                continue;
            }

            $target = $viewRoot.'/'.str_replace('.', '/', $view).'.blade.php';

            if (! existsWithExactCase($target)) {
                $unresolved[] = $file->getFilename()." references '{$view}'";
            }
        }
    }

    expect($unresolved)->toBe([], 'Blade view reference does not match a file with exact case:'.PHP_EOL.implode(PHP_EOL, $unresolved));
});
