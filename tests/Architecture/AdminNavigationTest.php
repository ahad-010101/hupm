<?php

use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\UrlGenerationException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
|--------------------------------------------------------------------------
| The admin sidebar  [WP-55]
|--------------------------------------------------------------------------
|
| Two failures, opposite directions, and neither announces itself.
|
| A link to nowhere is a 404 or — worse, as the dashboard proved — a 405 that
| reads as a server fault. A screen with no link is invisible: the housing
| authority console had a page, an invite flow and a portal behind it, and the
| only way in was to type the URL. Nobody reports a missing menu item; they
| conclude the feature was never built.
|
| Read from the source rather than a list kept here, so a group added later is
| covered without anybody remembering to extend this.
|
*/

/** Every href in the sidebar definition. */
function adminNavHrefs(): array
{
    $source = file_get_contents(resource_path('js/Layouts/AdminLayout.jsx'));

    // Only the uncommented entries: this file documents the screens that were
    // descoped by leaving their line inside a block comment, and a hidden
    // Import link must not be read as a promise to route one.
    $source = preg_replace('#/\*.*?\*/#s', '', $source) ?? '';

    preg_match_all("/href:\s*'([^']+)'/", $source, $matches);

    return array_values(array_unique($matches[1] ?? []));
}

it('WP-55 offers no sidebar link that does not resolve', function () {
    $hrefs = adminNavHrefs();

    expect($hrefs)->not->toBeEmpty('No sidebar links found; AdminLayout has been restructured.');

    $broken = [];

    foreach ($hrefs as $href) {
        try {
            Route::getRoutes()->match(Request::create($href, 'GET'));
        } catch (NotFoundHttpException|MethodNotAllowedHttpException|UrlGenerationException $e) {
            $broken[] = $href.' — '.class_basename($e);
        }
    }

    expect($broken)->toBe([], implode(PHP_EOL, array_merge(
        ['A sidebar link does not resolve to a GET route:'],
        $broken,
    )));
});

it('WP-55 puts every outside party with a portal in the sidebar', function () {
    $hrefs = adminNavHrefs();

    // Housing authorities and contractors both have a console screen, an
    // invitation and a portal of their own. One of them was missing for a
    // month because nothing checked.
    expect($hrefs)->toContain('/admin/housing-authorities')
        ->and($hrefs)->toContain('/admin/vendors');
});
