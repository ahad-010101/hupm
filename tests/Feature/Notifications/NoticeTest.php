<?php

use App\Domain\Notifications\NoticeService;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Jobs\SendNotification;
use App\Models\Lease;
use App\Models\Notice;
use App\Models\NoticeRecipient;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\HtmlSanitiser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Notices  [WP-20, FR-NTF-02, UI §3.10]
|--------------------------------------------------------------------------
|
| A notice is the record that a resident was told something, which is why it
| cannot be edited or deleted and why every recipient gets their own delivery
| status. "We sent it to everyone" is not an answer to "did Mrs Pouros get it".
|
| The body is the only admin-authored HTML in the product, so most of what is
| below is about the sanitiser holding.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    $this->service = app(NoticeService::class);
    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office']);

    $this->property = Property::factory()->create(['name' => 'Peachtree House']);

    $this->withLease = makeResident('Uriel', 'Pouros', $this->property);
    $this->neighbour = makeResident('Jordan', 'Miller', $this->property);
    $this->elsewhere = makeResident('Alyce', 'Kerluke', Property::factory()->create());
    $this->noEmail = Tenant::factory()->withoutEmail()->create(['first_name' => 'Mertie', 'last_name' => 'Huel']);

    $this->actingAs($this->admin);
});

function makeResident(string $first, string $last, Property $property): Tenant
{
    $tenant = Tenant::factory()->create(['first_name' => $first, 'last_name' => $last]);

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create(['property_id' => $property->id])->id,
        'tenant_id' => $tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    return $tenant;
}

/** @return array<string, mixed> */
function noticePayload(array $overrides = []): array
{
    return array_merge([
        'subject' => 'Water off on Tuesday',
        'body' => '<p>The water will be off between 9am and 1pm.</p>',
        'audience_type' => 'all',
        'audience_ref' => [],
    ], $overrides);
}

/*
 |--------------------------------------------------------------------------
 | The sanitiser — the only admin HTML in the product
 |--------------------------------------------------------------------------
 */

it('neutralises a script tag server-side, before it is ever stored', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
        'body' => '<p>Hello</p><script>alert(document.cookie)</script>',
    ]))->assertSessionHasNoErrors();

    // Stored safe, not rendered safe. A second place that renders this later
    // cannot forget to sanitise, because there is nothing left to sanitise.
    expect(Notice::sole()->body)->toBe('<p>Hello</p>')
        ->not->toContain('script');
});

it('strips every way a link or attribute can execute', function () {
    $sanitiser = app(HtmlSanitiser::class);

    $payloads = [
        '<img src=x onerror=alert(1)>',
        '<a href="javascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href="data:text/html,<script>alert(1)</script>">x</a>',
        '<div style="background:url(javascript:alert(1))">x</div>',
        '<svg><animate onbegin=alert(1) attributeName=x dur=1s>',
        // Mutation XSS: almost-valid markup the browser re-parses into
        // something else. The reason this is HTMLPurifier and not a regex.
        '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
        '<iframe src="https://evil.test"></iframe>',
        '<form action="https://evil.test"><input name="p"></form>',
    ];

    foreach ($payloads as $payload) {
        expect($sanitiser->clean($payload))
            ->not->toMatch('/<script|onerror|onbegin|javascript:|data:text|<iframe|<form|<style/i');
    }
});

it('keeps the markup a letter actually needs', function () {
    $clean = app(HtmlSanitiser::class)->clean(
        '<h2>Inspection</h2><p>On <strong>Tuesday</strong>.</p>'
        .'<ul><li>Be in</li></ul><a href="https://hupm.test/x">Details</a>'
    );

    expect($clean)->toContain('<h2>')
        ->toContain('<strong>')
        ->toContain('<li>')
        ->toContain('href="https://hupm.test/x"');
});

it('adds rel=noopener to outbound links it keeps', function () {
    // A target=_blank link without it hands the opener to the destination.
    expect(app(HtmlSanitiser::class)->clean('<a href="https://hupm.test">x</a>'))
        ->toContain('noopener');
});

/*
 |--------------------------------------------------------------------------
 | Audience and sending
 |--------------------------------------------------------------------------
 */

it('AC-NTF-04 writes one recipient row per resident, each with its own status', function () {
    $this->post('/admin/notices', noticePayload([
        'confirm_count' => Tenant::where('status', 'active')->count(),
    ]))->assertSessionHasNoErrors();

    $notice = Notice::sole();

    expect($notice->recipient_count)->toBe(4)
        ->and(NoticeRecipient::where('notice_id', $notice->id)->count())->toBe(4)
        // The resident with no address is a known state an admin must act on,
        // not a failure to retry (Q-4, AC-NTF-03).
        ->and(NoticeRecipient::where('tenant_id', $this->noEmail->id)->value('delivery_status'))
        ->toBe('not_deliverable')
        ->and(NoticeRecipient::where('tenant_id', $this->withLease->id)->value('delivery_status'))
        ->toBe('queued');
});

it('advances the recipient status once the message actually leaves', function () {
    // Queue::fake() in beforeEach means the send job never ran in the test
    // above — which is exactly how this went unnoticed. `queued` was asserted
    // and nobody asked what came next. The answer was: nothing, ever. The log
    // learned the message was sent and the recipient row never heard.
    Mail::fake();

    $this->post('/admin/notices', noticePayload([
        'confirm_count' => Tenant::where('status', 'active')->count(),
    ]))->assertSessionHasNoErrors();

    $recipient = NoticeRecipient::where('tenant_id', $this->withLease->id)->sole();

    expect($recipient->delivery_status)->toBe('queued')
        ->and($recipient->sent_at)->toBeNull()
        // The link that lets the outcome find its way back.
        ->and($recipient->notification_log_id)->not->toBeNull();

    // Run the job the faked queue is holding.
    (new SendNotification(
        $recipient->notification_log_id,
        NotificationTemplate::NoticeIssued,
        $recipient->email,
        'Water off Tuesday',
        ['name' => 'Resident', 'subject' => 'Water off Tuesday', 'body' => 'Hello', 'url' => 'http://localhost'],
    ))->handle(app(NotificationService::class));

    $recipient->refresh();

    expect($recipient->delivery_status)->toBe('sent')
        ->and($recipient->sent_at)->not->toBeNull();
});

it('shows a bounce on the notice, not only in the notification log', function () {
    $this->post('/admin/notices', noticePayload([
        'confirm_count' => Tenant::where('status', 'active')->count(),
    ]))->assertSessionHasNoErrors();

    $recipient = NoticeRecipient::where('tenant_id', $this->withLease->id)->sole();

    // A bounce arrives hours later carrying only the provider's id, and lands
    // on the log. If it stops there, the notice screen goes on saying the
    // message is on its way to an address that does not exist (AC-NTF-05).
    app(NotificationService::class)->markOutcome(
        $recipient->notification_log_id,
        'bounced',
        ['error' => 'Mailbox does not exist.'],
    );

    $recipient->refresh();

    expect($recipient->delivery_status)->toBe('bounced')
        ->and($recipient->error)->toContain('does not exist');
});

it('resolves a property audience to everyone living there', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'property',
        'audience_ref' => [$this->property->id],
    ]))->assertSessionHasNoErrors();

    $recipients = NoticeRecipient::pluck('tenant_id')->all();

    // A notice about the water being off is addressed to the building.
    expect($recipients)->toHaveCount(2)
        ->toContain($this->withLease->id, $this->neighbour->id)
        ->not->toContain($this->elsewhere->id);
});

it('resolves a selected audience to exactly those chosen', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'selected',
        'audience_ref' => [$this->withLease->id, $this->elsewhere->id],
    ]))->assertSessionHasNoErrors();

    expect(NoticeRecipient::pluck('tenant_id')->all())
        ->toHaveCount(2)
        ->toContain($this->withLease->id, $this->elsewhere->id);
});

it('refuses an audience with nobody in it', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'selected',
        'audience_ref' => [],
    ]))->assertSessionHasErrors('body');

    expect(Notice::count())->toBe(0);
});

it('reports the live count from the same method the send uses', function () {
    $this->postJson('/admin/notices/audience', [
        'audience_type' => 'property',
        'audience_ref' => [$this->property->id],
    ])->assertOk()->assertJson(['count' => 2, 'without_email' => 0]);

    // The number an admin is shown before sending includes who it will miss.
    $this->postJson('/admin/notices/audience', ['audience_type' => 'all'])
        ->assertOk()->assertJson(['count' => 4, 'without_email' => 1]);
});

/*
 |--------------------------------------------------------------------------
 | Sending to everyone
 |--------------------------------------------------------------------------
 */

it('UI §3.10 refuses an all-residents send without the typed count', function () {
    $this->post('/admin/notices', noticePayload(['confirm_count' => null]))
        ->assertSessionHasErrors('confirm_count');

    expect(Notice::count())->toBe(0);
});

it('refuses a typed count that does not match', function () {
    // A mis-clicked audience selector is otherwise indistinguishable from a
    // deliberate all-residents send until the replies start.
    $this->post('/admin/notices', noticePayload(['confirm_count' => 2]))
        ->assertSessionHasErrors('confirm_count');

    expect(Notice::count())->toBe(0);
});

it('sends to one resident without asking for a typed count', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
    ]))->assertSessionHasNoErrors();

    expect(Notice::sole()->recipient_count)->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | Permanence
 |--------------------------------------------------------------------------
 */

it('AC-NTF-06 refuses to delete a sent notice', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
    ]));

    // Not a policy and not a soft delete. The model refuses, because a record
    // that can be tidied away is not evidence of anything.
    expect(fn () => Notice::sole()->delete())->toThrow(RuntimeException::class);
    expect(Notice::count())->toBe(1);
});

it('exposes no route that edits or deletes a notice', function () {
    $offending = collect(Route::getRoutes())
        ->filter(fn ($route) => str_contains($route->uri(), 'notices')
            && array_intersect($route->methods(), ['PUT', 'PATCH', 'DELETE']) !== []);

    expect($offending->pluck('uri')->all())->toBe([]);
});

/*
 |--------------------------------------------------------------------------
 | Afterwards
 |--------------------------------------------------------------------------
 */

it('AC-NTF-05 keeps date, audience, recipients, statuses and attachments visible', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'property',
        'audience_ref' => [$this->property->id],
        'attachments' => [
            UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 stub')
                ->mimeType('application/pdf'),
        ],
    ]))->assertSessionHasNoErrors();

    $props = [];
    $this->get('/admin/notices/'.Notice::sole()->id)->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect($props['notice']['sent_on'])->not->toBeNull()
        ->and($props['notice']['sent_by'])->toBe('Office')
        ->and($props['notice']['audience_type'])->toBe('property')
        ->and($props['recipients'])->toHaveCount(2)
        ->and($props['recipients'][0]['status'])->not->toBeNull()
        ->and($props['attachments'])->toHaveCount(1)
        ->and($props['attachments'][0]['filename'])->toBe('notice.pdf');
});

it('stores an attachment once rather than once per recipient', function () {
    $this->post('/admin/notices', noticePayload([
        'confirm_count' => 4,
        'attachments' => [
            UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 stub')
                ->mimeType('application/pdf'),
        ],
    ]))->assertSessionHasNoErrors();

    // D-23. Four recipients, one file: three 10 MB attachments to twenty-six
    // residents would otherwise be 780 MB of the same bytes.
    expect(DB::table('notice_attachments')->count())->toBe(1)
        ->and(NoticeRecipient::count())->toBe(4);
});

it('refuses an attachment whose extension and contents disagree', function () {
    $jpeg = imagecreatetruecolor(8, 8);
    ob_start();
    imagejpeg($jpeg);
    $bytes = (string) ob_get_clean();
    imagedestroy($jpeg);

    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
        'attachments' => [
            UploadedFile::fake()->createWithContent('report.pdf', $bytes)->mimeType('image/jpeg'),
        ],
    ]))->assertSessionHasErrors();

    expect(Notice::count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | What a resident sees
 |--------------------------------------------------------------------------
 */

it('shows a resident only the notices addressed to them', function () {
    $this->post('/admin/notices', noticePayload([
        'subject' => 'For Uriel only',
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
    ]));

    $reader = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->neighbour->id]);

    $props = [];
    $this->actingAs($reader)->get('/portal/notices')->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect($props['notices'])->toBeEmpty();
});

it('I-9 gives 404 on an attachment for a notice a resident was not sent', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
        'attachments' => [
            UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 stub')
                ->mimeType('application/pdf'),
        ],
    ]));

    $notice = Notice::sole();
    $attachment = DB::table('notice_attachments')->where('notice_id', $notice->id)->first();
    $outsider = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->neighbour->id]);

    // Membership of the recipient list is the whole authorisation, and a 403
    // would confirm the notice exists.
    $this->actingAs($outsider)
        ->get("/portal/notices/{$notice->id}/attachments/{$attachment->id}")
        ->assertNotFound();

    expect(DB::table('audit_logs')->where('action', 'auth.ownership.denied')->exists())->toBeTrue();
});

it('lets a recipient download the attachment', function () {
    $this->post('/admin/notices', noticePayload([
        'audience_type' => 'tenant',
        'audience_ref' => [$this->withLease->id],
        'attachments' => [
            UploadedFile::fake()->createWithContent('notice.pdf', '%PDF-1.4 stub')
                ->mimeType('application/pdf'),
        ],
    ]));

    $notice = Notice::sole();
    $attachment = DB::table('notice_attachments')->where('notice_id', $notice->id)->first();
    $reader = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->withLease->id]);

    $this->actingAs($reader)
        ->get("/portal/notices/{$notice->id}/attachments/{$attachment->id}")
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="notice.pdf"');
});

it('keeps an owner and a tenant out of the compose screen', function () {
    foreach (['owner', 'tenant'] as $role) {
        $user = User::factory()->create(['role' => $role, 'tenant_id' => $role === 'tenant' ? $this->withLease->id : null]);

        $this->actingAs($user)->get('/admin/notices/new')->assertForbidden();
        $this->actingAs($user)->post('/admin/notices', noticePayload())->assertForbidden();
    }
});
