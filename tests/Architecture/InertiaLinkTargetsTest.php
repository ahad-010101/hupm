<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Inertia links never point at the Blade site  [D-05]
|--------------------------------------------------------------------------
|
| The public site is Blade and its route group deliberately omits Inertia's
| middleware, which is what makes AC-PUB-01 structural. The consequence is easy
| to forget and produces a baffling symptom.
|
| Inertia's `<Link>` fetches by XHR and expects an Inertia JSON response. A
| Blade route answers with plain HTML, so Inertia decides the page errored and
| renders the whole response **inside a modal overlay** — the marketing site
| appearing in a box on top of the login screen, rather than the browser simply
| going there. Nothing throws, nothing is logged, and the words "Inertia" and
| "modal" appear nowhere near the code that caused it.
|
| Leaving the application is a real page load. This test says so, because the
| symptom does not.
|
*/

it('D-05 uses a plain anchor for every link into the Blade public site', function () {
    // Read the public routes rather than listing them: a page added later is
    // covered without anybody remembering to extend this.
    $publicUris = collect(Route::getRoutes())
        ->filter(fn ($route) => in_array('public', $route->gatherMiddleware(), true))
        ->filter(fn ($route) => in_array('GET', $route->methods(), true))
        ->map(fn ($route) => '/'.ltrim($route->uri(), '/'))
        // `/health` is JSON for a monitor; nothing links to it.
        ->reject(fn (string $uri) => $uri === '/health')
        ->values()
        ->all();

    expect($publicUris)->not->toBeEmpty('No public routes found; the middleware group has moved.');

    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.jsx')
            || str_ends_with($file->getFilename(), '.test.jsx')) {
            continue;
        }

        $source = file_get_contents($file->getPathname());

        // Every <Link ...> element, however its attributes are wrapped.
        preg_match_all('/<Link\b[^>]*?href=(?:"([^"]*)"|\{`([^`]*)`\})/s', $source, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $href = $match[1] !== '' ? $match[1] : ($match[2] ?? '');

            if (in_array($href, $publicUris, true)) {
                $offenders[] = $file->getFilename().' links to '.$href;
            }
        }
    }

    expect($offenders)->toBe([], implode(PHP_EOL, array_merge(
        ['An Inertia <Link> points at a Blade route. Use a plain <a href> instead:'],
        $offenders,
    )));
});
