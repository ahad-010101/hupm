<?php

use App\Domain\Content\PageContent;
use App\Models\ContentPage;
use App\Support\Settings;
use Database\Seeders\ContentSeeder;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The public pages  [WP-18, FR-PUB-01, AC-PUB-01, UI §1]
|--------------------------------------------------------------------------
|
| Eight pages that anyone on the internet can read, sitting in front of a
| database holding twenty-six people's rent, addresses and repair histories. The
| guarantee that none of it escapes is structural rather than careful: the
| public route file has no Inertia middleware, and none of its controllers reads
| the tenant tables at all.
|
| The crawl below is the belt to that braces. It seeds the full demo portfolio
| and then checks every public route against every resident's real details —
| because "no controller reads it" is a claim about today's code, and this is a
| test about tomorrow's.
|
*/

uses(RefreshDatabase::class);

/*
 | The public pages render from the database now (WP-36, D-27), and
 | RefreshDatabase starts every test with an empty one. Seeded explicitly
 | here rather than through a global hook: the copy these tests assert is
 | the copy the seeder ships, and a fixture that appeared by magic would
 | make the next failure unexplainable.
 */
beforeEach(function () {
    $this->seed(ContentSeeder::class);
});

/** Every public route, so a new one cannot be added without appearing here. */
function publicRoutes(): array
{
    return [
        '/', '/about', '/services', '/properties', '/resources', '/georgia-rental-info',
        '/emergency-maintenance', '/contact', '/privacy', '/terms',
    ];
}

/*
 |--------------------------------------------------------------------------
 | AC-PUB-01 — nothing of a resident's reaches a public page
 |--------------------------------------------------------------------------
 */

it('AC-PUB-01 leaks no resident detail on any public route, with real data loaded', function () {
    $this->seed(DemoDataSeeder::class);

    $tenants = DB::table('tenants')->get(['first_name', 'last_name', 'email', 'phone']);
    $leases = DB::table('leases')->get(['tenant_portion', 'ha_portion', 'total_contract_rent']);

    expect($tenants)->not->toBeEmpty('The seeder produced no tenants, so this test proves nothing.');

    foreach (publicRoutes() as $route) {
        $html = $this->get($route)->assertOk()->getContent();

        foreach ($tenants as $tenant) {
            // A surname is the cheapest thing to leak and the hardest to
            // notice: it arrives inside an address, a testimonial, a photo
            // caption. Checked against every resident, not a sample.
            expect($html)->not->toContain($tenant->last_name, "{$tenant->last_name} appeared on {$route}");

            if ($tenant->email) {
                expect($html)->not->toContain($tenant->email, "An email address appeared on {$route}");
            }

            if ($tenant->phone) {
                expect($html)->not->toContain($tenant->phone, "A telephone number appeared on {$route}");
            }
        }

        foreach (['tenant_portion', 'ha_portion', 'delinquency_state', 'ticket_number'] as $field) {
            expect($html)->not->toContain($field, "The field {$field} appeared on {$route}");
        }

        // I-4 reaches even here: the authority's share is not a public figure
        // any more than it is a resident-facing one.
        foreach ($leases as $lease) {
            expect($html)->not->toContain('"'.$lease->ha_portion.'"');
        }
    }
});

it('AC-PUB-01 is structural — no public controller touches the tenant tables', function () {
    // The crawl above proves today's output. This proves the reason for it, so
    // a page added later starts from the same footing.
    $sources = glob(app_path('Http/Controllers/Public/*.php'));

    expect($sources)->not->toBeEmpty();

    foreach ($sources as $file) {
        $code = file_get_contents($file);

        foreach (['Tenant::', 'Lease::', 'Payment::', 'LedgerEntry::', 'MaintenanceRequest::'] as $model) {
            expect($code)->not->toContain($model, basename($file).' reads a resident model');
        }
    }
});

/*
 |--------------------------------------------------------------------------
 | The pages themselves
 |--------------------------------------------------------------------------
 */

it('serves every public page with real content, not a placeholder', function (string $route) {
    $this->get($route)
        ->assertOk()
        ->assertDontSee('scheduled for WP-18')
        ->assertDontSee('Placeholder');
})->with(publicRoutes());

it('gives the home page both things a resident actually came for', function () {
    $this->get('/')
        ->assertSee('Resident login')
        ->assertSee('Emergency maintenance');
});

it('tells a prospective resident that vacancies are not listed live', function () {
    // BR-22: availability is static copy, never a query over empty units — a
    // public list of vacancies is a public statement about who is not at home.
    $this->seed(DemoDataSeeder::class);

    $this->get('/properties')
        ->assertOk()
        ->assertSee('nothing advertised at the moment')
        ->assertSee('Housing Choice Vouchers');
});

it('shows the listings the office has written, one per line', function () {
    // Rewritten for WP-36. This drove the feature through the
    // `public.available_properties` setting, which has been retired — listings
    // are a section on the properties page now. The behaviour under test is
    // unchanged, including that a blank line is not a listing.
    $page = ContentPage::where('slug', 'properties')->sole();

    $page->sections()->where('type', 'listings')->sole()->update([
        'payload' => [
            'heading' => 'Currently advertised',
            'intro' => '',
            'entries' => '2 bed, Decatur, $1,150 pcm

3 bed, East Point, $1,400 pcm
',
            'empty_text' => 'We have nothing advertised at the moment.',
        ],
    ]);

    app(PageContent::class)->flush();

    $response = $this->get('/properties')->assertOk();

    $response->assertSee('2 bed, Decatur', escape: false);
    $response->assertSee('3 bed, East Point', escape: false);
    expect(substr_count($response->getContent(), '<li class="rounded-xl'))->toBe(2);
});

it('points at the state’s own material rather than our reading of it', function () {
    $this->get('/georgia-rental-info')
        ->assertOk()
        ->assertSee('dca.ga.gov', escape: false)
        ->assertSee('Nothing on this page is legal advice');
});

it('admits the legal pages are unwritten instead of inventing them', function (string $route) {
    // A made-up privacy policy is a promise nobody has checked we keep.
    $this->get($route)->assertOk()->assertSee('not published yet');
})->with(['/privacy', '/terms']);

/*
 |--------------------------------------------------------------------------
 | Emergency instructions — the reason the public site is Blade at all
 |--------------------------------------------------------------------------
 */

it('D-05 renders the emergency page with no JavaScript whatsoever', function () {
    $html = $this->get('/emergency-maintenance')->assertOk()->getContent();

    expect($html)->toContain('.css')
        ->and($html)->not->toContain('app.jsx')
        ->and($html)->not->toContain('type="module"')
        ->and($html)->not->toContain('<script');
});

it('BR-23 puts 911 above our own number, and makes both dialable', function () {
    $html = $this->get('/emergency-maintenance')->assertOk()->getContent();

    expect($html)->toContain('tel:911');

    // 911 first in the document, literally. We are not the right call for a
    // gas leak, and page order is the only ordering somebody in a hurry reads.
    app(Settings::class)->set('company.emergency_phone', '(404) 555-0142');

    $html = $this->get('/emergency-maintenance')->getContent();

    expect(strpos($html, 'tel:911'))->toBeLessThan(strpos($html, 'tel:4045550142'));
});

it('says the out-of-hours number is unpublished rather than showing a gap', function () {
    // [GATE] company.emergency_phone. A blank space where a phone number
    // belongs is the worst possible answer on this particular page.
    $this->get('/emergency-maintenance')
        ->assertOk()
        ->assertSee('has not been published yet');
});

it('leads with what to do, not with what counts as an emergency', function () {
    $html = $this->get('/emergency-maintenance')->getContent();

    // Somebody already standing in water does not need the definition first.
    expect(strpos($html, 'What to do first'))->toBeLessThan(strpos($html, 'What counts as an emergency'));
});
