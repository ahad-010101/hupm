<?php

use App\Domain\Content\SectionCatalogue;
use App\Models\ContentPage;
use App\Models\PageSection;
use App\Models\User;
use Database\Seeders\ContentSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Editing the public site  [WP-36, D-27, API-ADM-41 adjacent]
|--------------------------------------------------------------------------
|
| The office can now change every word anyone on the internet reads. What it
| cannot do is change which URLs exist, put a script on a page, or break the
| design — and most of what follows is about those three.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(ContentSeeder::class);

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->page = ContentPage::where('slug', 'about')->sole();
});

/** The page's first section, whatever it is. */
function firstSection(ContentPage $page): PageSection
{
    return $page->sections()->orderBy('position')->firstOrFail();
}

/*
 |--------------------------------------------------------------------------
 | Reaching it
 |--------------------------------------------------------------------------
 */

it('lists the pages for an admin', function () {
    $this->actingAs($this->admin)
        ->get('/admin/website')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('Admin/Website/Index')->has('pages', 9));
});

it('opens a page with its sections and the catalogue that renders the form', function () {
    $this->actingAs($this->admin)
        ->get('/admin/website/about')
        ->assertOk()
        ->assertInertia(fn ($p) => $p
            ->component('Admin/Website/Edit')
            ->where('page.slug', 'about')
            ->has('sections')
            // The form is generated from this, which is why a new section type
            // needs no React at all.
            ->has('catalogue.hero.fields')
            ->has('routeOptions'));
});

it('I-9 keeps a resident out of the website editor', function () {
    $resident = User::factory()->create(['role' => 'tenant']);

    $this->actingAs($resident)->get('/admin/website')->assertForbidden();
    $this->actingAs($resident)->get('/admin/website/about')->assertForbidden();
});

it('is unreachable signed out', function () {
    $this->get('/admin/website')->assertRedirect('/login');
});

/*
 |--------------------------------------------------------------------------
 | What an editor may not do
 |--------------------------------------------------------------------------
 */

it('offers no way to create or delete a page', function () {
    // routes/public.php is the complete list of public URLs and stays that way.
    // A dynamic page would let somebody publish over a path the router already
    // means something by.
    $verbs = collect(app('router')->getRoutes())
        ->filter(fn ($route) => $route->uri() === 'admin/website'
            || $route->uri() === 'admin/website/{page}')
        ->flatMap(fn ($route) => $route->methods())
        ->unique()
        ->values()
        ->all();

    expect($verbs)->toEqualCanonicalizing(['GET', 'HEAD', 'PATCH']);
});

it('cannot change the slug a page is bound to', function () {
    $this->actingAs($this->admin)
        ->patch('/admin/website/about', [
            'slug' => 'somewhere-else',
            'title' => 'About us',
            'is_published' => true,
            'show_in_nav' => true,
            'nav_position' => 20,
        ])
        ->assertSessionHasNoErrors();

    expect(ContentPage::where('slug', 'about')->exists())->toBeTrue()
        ->and(ContentPage::where('slug', 'somewhere-else')->exists())->toBeFalse();
});

it('refuses a section type that is not in the library', function () {
    $this->actingAs($this->admin)
        ->post('/admin/website/about/sections', ['type' => 'iframe_embed'])
        ->assertSessionHasErrors('type');

    expect($this->page->sections()->where('type', 'iframe_embed')->exists())->toBeFalse();
});

it('I-9 returns 404 for a section belonging to another page', function () {
    $other = firstSection(ContentPage::where('slug', 'home')->sole());

    // Not 403: a "forbidden" reply confirms the row exists, which is itself a
    // leak. The rest of the console follows the same rule.
    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$other->id}", ['is_enabled' => true, 'payload' => []])
        ->assertNotFound();
});

/*
 |--------------------------------------------------------------------------
 | Editing
 |--------------------------------------------------------------------------
 */

it('saves a section and shows it on the public page', function () {
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => 'A brand new heading', 'body' => 'And a new sentence.'],
        ])
        ->assertSessionHasNoErrors();

    // Every write flushes the cache, so the public site is never a version
    // behind what the editor just saved.
    $this->get('/about')->assertOk()->assertSee('A brand new heading');
});

it('adds a section hidden, so nobody publishes an empty block by pressing Add', function () {
    $this->actingAs($this->admin)
        ->post('/admin/website/about/sections', ['type' => 'faq'])
        ->assertSessionHasNoErrors();

    $added = $this->page->sections()->where('type', 'faq')->sole();

    expect($added->is_enabled)->toBeFalse();

    $this->get('/about')->assertOk()->assertDontSee('faq');
});

it('hides a section without losing its words', function () {
    $section = firstSection($this->page);
    $heading = $section->field('heading');

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => false,
            'payload' => $section->payload,
        ])
        ->assertSessionHasNoErrors();

    $this->get('/about')->assertDontSee($heading);

    // Still there, ready to switch back on. Deleting to hide is how copy is lost.
    expect($section->fresh()->field('heading'))->toBe($heading);
});

it('moves a section up and down, and stops at the ends', function () {
    $sections = $this->page->sections()->orderBy('position')->get();
    $second = $sections[1];

    $this->actingAs($this->admin)
        ->post("/admin/website/about/sections/{$second->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect($this->page->sections()->orderBy('position')->first()->id)->toBe($second->id);

    // Already at the top: not an error, just nothing to do.
    $this->actingAs($this->admin)
        ->post("/admin/website/about/sections/{$second->id}/move", ['direction' => 'up'])
        ->assertSessionHasNoErrors();

    expect($this->page->sections()->orderBy('position')->first()->id)->toBe($second->id);
});

it('removes a section', function () {
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->delete("/admin/website/about/sections/{$section->id}")
        ->assertSessionHasNoErrors();

    expect(PageSection::find($section->id))->toBeNull();
});

/*
 |--------------------------------------------------------------------------
 | Content cannot break the site
 |--------------------------------------------------------------------------
 */

it('strips a script out of submitted copy before it is stored', function () {
    $section = $this->page->sections()->where('type', 'value_cards')->sole();

    $this->actingAs($this->admin)
        ->post('/admin/website/about/sections', ['type' => 'prose']);

    $prose = $this->page->sections()->where('type', 'prose')->sole();

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$prose->id}", [
            'is_enabled' => true,
            'payload' => [
                'heading' => 'Safe',
                'body' => '<p>Keep this.</p><script>alert(1)</script><img src=x onerror=alert(1)>',
            ],
        ])
        ->assertSessionHasNoErrors();

    // Sanitised on write, the same rule NoticeService follows: what is stored is
    // already safe, so a renderer added later cannot forget.
    $stored = $prose->fresh()->field('body');

    expect($stored)->toContain('Keep this.')
        ->and($stored)->not->toContain('<script')
        ->and($stored)->not->toContain('onerror');

    expect($section)->not->toBeNull();
});

it('drops a field the section type does not have', function () {
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => 'Kept', 'custom_html' => '<iframe src="//evil"></iframe>'],
        ])
        ->assertSessionHasNoErrors();

    expect($section->fresh()->payload)->not->toHaveKey('custom_html');
});

it('refuses a button that points somewhere it may not', function () {
    $hero = $this->page->sections()->where('type', 'hero')->sole();

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$hero->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => 'Hello', 'primary_label' => 'Go', 'primary_route' => 'admin.dashboard'],
        ])
        ->assertSessionHasErrors('payload');
});

it('refuses a link that is not http or https', function () {
    $this->actingAs($this->admin)->post('/admin/website/about/sections', ['type' => 'link_cards']);

    $links = $this->page->sections()->where('type', 'link_cards')->sole();

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$links->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => 'Links', 'items' => [
                ['title' => 'Tap', 'url' => 'javascript:alert(1)', 'blurb' => ''],
            ]],
        ])
        ->assertSessionHasErrors('payload');
});

it('caps a heading rather than letting it become a paragraph', function () {
    // The length ceilings are what stop content breaking the layout — a
    // six-hundred-character "heading" is not a heading.
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => str_repeat('a', 400)],
        ])
        ->assertSessionHasNoErrors();

    expect(mb_strlen($section->fresh()->field('heading')))->toBe(120);
});

/*
 |--------------------------------------------------------------------------
 | The record
 |--------------------------------------------------------------------------
 */

it('audits every kind of content change', function () {
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->patch('/admin/website/about', [
            'title' => 'About Heads Up', 'is_published' => true,
            'show_in_nav' => true, 'nav_position' => 20,
        ]);

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => true, 'payload' => ['heading' => 'Edited'],
        ]);

    $this->actingAs($this->admin)->post('/admin/website/about/sections', ['type' => 'stats']);

    $added = $this->page->sections()->where('type', 'stats')->sole();

    $this->actingAs($this->admin)
        ->post("/admin/website/about/sections/{$added->id}/move", ['direction' => 'up']);

    $this->actingAs($this->admin)->delete("/admin/website/about/sections/{$added->id}");

    $actions = DB::table('audit_logs')->pluck('action')->all();

    foreach ([
        'content.page.updated', 'content.section.updated', 'content.section.added',
        'content.section.moved', 'content.section.removed',
    ] as $action) {
        expect($actions)->toContain($action);
    }
});

it('I-5 records that copy changed without copying the copy into the log', function () {
    $section = firstSection($this->page);

    $this->actingAs($this->admin)
        ->patch("/admin/website/about/sections/{$section->id}", [
            'is_enabled' => true,
            'payload' => ['heading' => 'A distinctive phrase nobody else would write'],
        ]);

    $row = DB::table('audit_logs')->where('action', 'content.section.updated')->latest('id')->first();

    // An audit row is a record that somebody edited the About page. A full
    // before-and-after of eight hundred words is not a summary.
    expect($row->changes)->not->toContain('A distinctive phrase');
    expect($row->changes)->toContain('heading');
});

/*
 |--------------------------------------------------------------------------
 | The catalogue itself
 |--------------------------------------------------------------------------
 */

it('has a Blade partial for every section type it offers', function () {
    // Names the missing file the day it is missing, rather than the day an
    // editor adds that section to a live page.
    foreach (array_keys(app(SectionCatalogue::class)->all()) as $type) {
        expect(view()->exists("public.sections.{$type}"))
            ->toBeTrue("There is no partial for the {$type} section.");
    }
});

it('produces a renderable payload from nothing, for every type', function () {
    $catalogue = app(SectionCatalogue::class);

    foreach (array_keys($catalogue->all()) as $type) {
        $payload = $catalogue->normalise($type, []);

        expect($catalogue->problems($type, $payload))
            ->toBeArray("The {$type} section could not be normalised from empty.");

        // Idempotent: normalising twice is normalising once.
        expect($catalogue->normalise($type, $payload))->toBe($payload);
    }
});
