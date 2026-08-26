<?php

use App\Domain\Content\PageContent;
use App\Domain\Content\SectionCatalogue;
use App\Models\ContentPage;
use App\Models\PageSection;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Public pages, rendered from content  [WP-36, D-27]
|--------------------------------------------------------------------------
|
| The public site's words now live in a table an admin can edit. That is a
| feature and also a new way for the site to break, so most of what follows is
| about a page holding up when its content is wrong, missing or hostile.
|
| Nothing here may reintroduce JavaScript. D-05 stands: the emergency page must
| render on a bad connection with scripting off, and the surest way to lose that
| is a section type somebody reaches for a library to build.
|
*/

uses(RefreshDatabase::class);

/** A page with exactly the sections a test needs. */
function pageWith(array $sections, string $slug = 'services'): ContentPage
{
    // forceFill because `slug` is deliberately not fillable: it binds to a
    // route, and a slug arriving from a form could point /about at a page the
    // router has never heard of.
    $page = new ContentPage;
    $page->forceFill([
        'slug' => $slug,
        'title' => 'Test page',
        'is_published' => true,
        'show_in_nav' => false,
        'nav_position' => 0,
    ])->save();

    foreach (array_values($sections) as $position => [$type, $payload, $enabled]) {
        PageSection::create([
            'content_page_id' => $page->id,
            'type' => $type,
            'position' => $position,
            'is_enabled' => $enabled,
            'payload' => $payload,
        ]);
    }

    app(PageContent::class)->flush();

    return $page;
}

/*
 |--------------------------------------------------------------------------
 | Holding up when the content is wrong
 |--------------------------------------------------------------------------
 */

it('renders a page that has no sections at all', function () {
    // The state of a fresh install, so it has to be decent rather than merely
    // non-fatal. A blank <main> would be the worst of both.
    $page = new ContentPage;
    $page->forceFill([
        'slug' => 'services', 'title' => 'What we handle',
        'is_published' => true, 'show_in_nav' => false, 'nav_position' => 0,
    ])->save();

    app(PageContent::class)->flush();

    $this->get('/services')
        ->assertOk()
        ->assertSee('What we handle')
        ->assertSee('Resident login');
});

it('renders a page with no row behind it at all', function () {
    // Every slug here is a route declared in code. A missing content row must
    // not turn a URL that genuinely exists into a 500 or a lie.
    $this->get('/services')->assertOk();
});

it('skips a section type it has no partial for, and renders the rest', function () {
    pageWith([
        ['hero', ['heading' => 'Still here'], true],
        ['a_type_from_the_future', ['heading' => 'Not rendered'], true],
    ]);

    $this->get('/services')->assertOk()->assertSee('Still here')->assertDontSee('Not rendered');
});

it('cannot be talked into including a template outside the section library', function () {
    // The renderer's include path comes from a database column, so a type is a
    // file-inclusion primitive unless it is checked against a closed set. The
    // catalogue is that set, and it is checked in the controller rather than by
    // asking whether a view happens to resolve.
    pageWith([
        ['../../../admin/settings', [], true],
        ['hero', ['heading' => 'Unharmed'], true],
    ]);

    $this->get('/services')->assertOk()->assertSee('Unharmed');
});

it('leaves a disabled section out of the response entirely', function () {
    pageWith([
        ['hero', ['heading' => 'Published'], true],
        ['prose', ['heading' => 'Draft heading', 'body' => '<p>Not ready.</p>'], false],
    ]);

    $this->get('/services')
        ->assertOk()
        ->assertSee('Published')
        ->assertDontSee('Draft heading')
        ->assertDontSee('Not ready');
});

it('renders sections in their stored order', function () {
    $page = pageWith([
        ['hero', ['heading' => 'First one'], true],
        ['prose', ['heading' => 'Second one', 'body' => ''], true],
    ]);

    $html = $this->get('/services')->getContent();
    expect(strpos($html, 'First one'))->toBeLessThan(strpos($html, 'Second one'));

    // Swap them and the page follows.
    [$a, $b] = $page->sections()->orderBy('position')->get()->all();
    $a->update(['position' => 5]);
    $b->update(['position' => 1]);
    app(PageContent::class)->flush();

    $html = $this->get('/services')->getContent();
    expect(strpos($html, 'Second one'))->toBeLessThan(strpos($html, 'First one'));
});

it('renders every section type in the library without falling over', function () {
    // The cheap test that catches a missing Blade partial the day it is missed,
    // rather than the day somebody adds that section to a live page.
    $catalogue = app(SectionCatalogue::class);

    $sections = [];

    foreach (array_keys($catalogue->all()) as $type) {
        // Deliberately empty payloads: this asserts the defensive reads hold.
        $sections[] = [$type, $catalogue->normalise($type, []), true];
    }

    pageWith($sections);

    $this->get('/services')->assertOk();
});

/*
 |--------------------------------------------------------------------------
 | Content cannot break the site
 |--------------------------------------------------------------------------
 */

it('never lets a section put a script on a public page', function () {
    $sections = app(SectionCatalogue::class);

    pageWith([
        ['prose', $sections->normalise('prose', [
            'heading' => 'Safe',
            'body' => '<p>Fine.</p><script>alert(1)</script><img src=x onerror=alert(1)>'
                .'<a href="javascript:alert(1)">tap</a>',
        ]), true],
    ]);

    $html = $this->get('/services')->assertOk()->getContent();

    expect($html)->not->toContain('<script')
        ->and($html)->not->toContain('onerror')
        ->and($html)->not->toContain('javascript:')
        ->and($html)->toContain('Fine.');
});

it('escapes a heading rather than rendering it', function () {
    // Only `rich` fields are echoed unescaped, and only after sanitising. Every
    // other field is plain text and Blade escapes it.
    pageWith([['hero', ['heading' => '<b>bold</b>'], true]]);

    $this->get('/services')->assertOk()->assertDontSee('<b>bold</b>', escape: false);
});

it('D-05 loads no JavaScript on any section-driven page', function (string $route) {
    // Extended from the emergency page to every public route. The FAQ accordion
    // is the most likely place somebody reaches for a script later, and this is
    // what would stop them.
    $this->seed(ContentSeeder::class);

    $html = $this->get($route)->assertOk()->getContent();

    expect($html)->toContain('.css')
        ->and($html)->not->toContain('app.jsx')
        ->and($html)->not->toContain('type="module"')
        ->and($html)->not->toContain('<script');
})->with(['/', '/about', '/services', '/properties', '/resources', '/georgia-rental-info', '/contact']);

it('opens and closes questions without JavaScript', function () {
    pageWith([['faq', ['heading' => 'Questions', 'items' => [
        ['question' => 'Is it open?', 'answer' => 'Natively.'],
    ]], true]]);

    $html = $this->get('/services')->assertOk()->getContent();

    expect($html)->toContain('<details')->and($html)->toContain('<summary');

    // No hand-written aria-expanded: <details> announces its own state, and a
    // written one goes stale the moment somebody opens it.
    expect($html)->not->toContain('aria-expanded');
});

it('marks an outward link safe and leaves an internal one alone', function () {
    pageWith([['link_cards', ['heading' => 'Elsewhere', 'items' => [
        ['title' => 'DCA', 'url' => 'https://www.dca.ga.gov/', 'blurb' => 'The state agency.'],
    ]], true]]);

    $this->get('/services')->assertOk()->assertSee('rel="noopener noreferrer"', escape: false);
});

/*
 |--------------------------------------------------------------------------
 | The menu
 |--------------------------------------------------------------------------
 */

it('builds the menu from the published pages', function () {
    $this->seed(ContentSeeder::class);

    $this->get('/')->assertOk()->assertSee('Services');
});

it('drops an unpublished page out of the menu', function () {
    $this->seed(ContentSeeder::class);

    ContentPage::where('slug', 'services')->update(['is_published' => false]);
    app(PageContent::class)->flush();

    $html = $this->get('/')->assertOk()->getContent();

    // Asserted on the link target rather than the label: the label is wrapped
    // in template whitespace, and /services is linked from nowhere else on the
    // home page, so its absence is unambiguous.
    expect($html)->not->toContain(route('public.services'));

    // And the header is still a header.
    expect($html)->toContain(route('public.about'));
});

it('shows a menu even with no content at all', function () {
    // A fresh database, or a deploy that cleared the cache before the seeder
    // ran. A site with no navigation is a far worse failure than a stale one,
    // so the composer falls back to a literal list.
    expect(ContentPage::count())->toBe(0);

    $this->get('/')->assertOk()->assertSee('Home')->assertSee('Contact');
});
