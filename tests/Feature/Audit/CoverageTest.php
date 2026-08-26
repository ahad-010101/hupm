<?php

use App\Domain\Delinquency\DelinquencyService;
use App\Domain\Documents\DocumentVault;
use App\Domain\Ledger\LedgerService;
use App\Domain\Notifications\NoticeService;
use App\Domain\Payments\PaymentRecordingService;
use App\Domain\Signatures\SignatureService;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Property;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\TestAddressSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Audit coverage  [FR-AUD-01, AC-AUD-01, WP-29]
|--------------------------------------------------------------------------
|
| FR-AUD-01 names seven things that must leave a trail:
|
|   ledger mutation · payment event · delinquency transition · notice issuance
|   · document access · signature event · administrative action
|
| This file walks all seven by performing the real action through the real
| service or the real route, then asserting a row exists carrying actor, action,
| subject and timestamp. The DoD asks for coverage proven by walking each event
| type rather than by inspection, and the difference matters: reading the code
| tells you an `audit->record(...)` line is present, not that it survives the
| transaction it sits inside, or that the actor is still resolvable by the time
| it runs.
|
| Recording that *nobody* acted is part of the requirement, not a gap. A NULL
| actor means a rule ran, and that is the entire answer when a resident asks why
| a late fee appeared at three in the morning.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();
    Storage::fake('local');

    // The property form validates state against country and city against state
    // (D-19), so creating one needs the reference data.
    $this->seed(TestAddressSeeder::class);

    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office']);
    $this->resident = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);

    $this->signer = User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $this->resident->id,
    ]);

    $this->lease = new Lease;
    $this->lease->forceFill([
        'unit_id' => Unit::factory()->create([
            'property_id' => Property::factory()->create(['name' => 'Peachtree House'])->id,
        ])->id,
        'tenant_id' => $this->resident->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00', 'tenant_portion' => '500.00', 'ha_portion' => '700.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
        'delinquency_state' => 'current',
    ])->save();

    $this->actingAs($this->admin);
});

/**
 * The newest row for an action, or null.
 *
 * Read through the query builder rather than the model, so the assertion sees
 * exactly what was written rather than what the casts make of it.
 */
function auditedAs(string $action): ?object
{
    return DB::table('audit_logs')->where('action', $action)->orderByDesc('id')->first();
}

/** AC-AUD-01: actor, action, subject, timestamp — all four, or it does not count. */
function expectAudited(string $action, string $subjectType, int $subjectId, ?int $actorId): void
{
    $row = auditedAs($action);

    expect($row)->not->toBeNull("No audit row was written for {$action}.");
    expect($row->subject_type)->toBe($subjectType);
    expect((int) $row->subject_id)->toBe($subjectId);
    expect($row->user_id === null ? null : (int) $row->user_id)->toBe($actorId);
    expect($row->created_at)->not->toBeNull();
}

/** A stored document belonging to the resident. */
function auditedDocument(): Document
{
    return app(DocumentVault::class)->store(
        test()->resident,
        UploadedFile::fake()->createWithContent('lease.pdf', "%PDF-1.4\n%stub\n")
            ->mimeType('application/pdf'),
        ['category' => 'current_lease', 'title' => 'Lease'],
        test()->admin,
    );
}

/*
 |--------------------------------------------------------------------------
 | The seven categories
 |--------------------------------------------------------------------------
 */

it('AC-AUD-01 records a ledger mutation', function () {
    $entry = app(LedgerService::class)->postAdjustment(
        lease: $this->lease,
        payer: 'tenant',
        amount: Money::fromString('-25.00'),
        reason: 'Goodwill credit',
        description: 'Goodwill credit',
    );

    expectAudited('ledger.adjustment.posted', $entry::class, $entry->id, $this->admin->id);
});

it('AC-AUD-01 records a payment event', function () {
    $payment = app(PaymentRecordingService::class)->record(
        lease: $this->lease,
        payer: 'tenant',
        amount: Money::fromString('100.00'),
        receivedOn: CarbonImmutable::parse(businessToday()),
        method: 'cheque',
        idempotencyKey: (string) Str::uuid(),
        reference: '10412',
    );

    expectAudited('payment.recorded', $payment::class, $payment->id, $this->admin->id);
});

it('AC-AUD-01 records a delinquency transition', function () {
    app(DelinquencyService::class)->enterReview($this->lease, 'Day 5 past due');

    expectAudited('delinquency.review.entered', $this->lease::class, $this->lease->id, $this->admin->id);

    // Releasing is always a person's decision, and always carries a reason
    // (BR-14, AC-DEL-05). There is no route that releases without one.
    app(DelinquencyService::class)->release($this->lease->fresh(), 'Paid in full at the office', $this->admin);

    expectAudited('delinquency.review.released', $this->lease::class, $this->lease->id, $this->admin->id);
});

it('AC-AUD-01 attributes an overnight rule to the system, not to a signed-in person', function () {
    // The 02:30 job has no authenticated user, and AuditLogger reads the actor
    // from the session rather than from the caller's arguments — so this is the
    // only way to exercise the NULL-actor path honestly.
    //
    // It is worth exercising. "Nobody chose to charge you, a rule did" is the
    // whole answer when a resident asks why a fee appeared at three in the
    // morning, and it is only true if the row genuinely says so.
    Auth::logout();

    app(DelinquencyService::class)->enterReview($this->lease, 'Day 5 past due');

    expectAudited('delinquency.review.entered', $this->lease::class, $this->lease->id, null);
});

it('AC-AUD-01 records a notice issuance', function () {
    $notice = app(NoticeService::class)->send([
        'subject' => 'Water off on Tuesday',
        'body' => '<p>The water will be off between 9am and 1pm.</p>',
        'audience_type' => 'tenant',
        'audience_ref' => [$this->resident->id],
    ], [], $this->admin);

    expectAudited('notice.sent', $notice::class, $notice->id, $this->admin->id);
});

it('AC-AUD-01 records document access, not merely document upload', function () {
    $document = auditedDocument();

    // Reading a document is the event FR-AUD-01 names — an upload leaves a file
    // behind as its own evidence, whereas a download leaves nothing at all
    // unless this row is written.
    $this->get(app(DocumentVault::class)->signedUrlFor($document, 'admin.documents.download'))
        ->assertOk();

    expectAudited('document.downloaded', $document::class, $document->id, $this->admin->id);
});

it('AC-AUD-01 records a signature event', function () {
    $request = app(SignatureService::class)->createRequest(
        document: auditedDocument(),
        tenant: $this->resident,
        signer: $this->signer,
        actor: $this->admin,
    );

    expectAudited('signature.requested', $request::class, $request->id, $this->admin->id);
});

it('AC-AUD-01 records an administrative action, with the actor from the request', function () {
    $this->post('/admin/properties', [
        'name' => 'Peachtree House II',
        'country_code' => 'US',
        'state' => 'Georgia',
        'city' => 'Atlanta',
        'street_address' => '145 Peachtree Street',
        'postal_code' => '30303',
    ])->assertSessionHasNoErrors();

    $property = Property::where('name', 'Peachtree House II')->sole();

    expectAudited('property.created', $property::class, $property->id, $this->admin->id);
});

/*
 |--------------------------------------------------------------------------
 | Shape
 |--------------------------------------------------------------------------
 */

it('AC-AUD-01 gives every row an action and a timestamp, whatever wrote it', function () {
    app(LedgerService::class)->postAdjustment(
        lease: $this->lease, payer: 'tenant', amount: Money::fromString('-5.00'),
        reason: 'Rounding', description: 'Rounding',
    );

    $this->post('/admin/properties', [
        'name' => 'Ponce House', 'country_code' => 'US', 'state' => 'Georgia',
        'city' => 'Atlanta', 'street_address' => '9 Ponce', 'postal_code' => '30308',
    ])->assertSessionHasNoErrors();

    $rows = DB::table('audit_logs')->get();

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $row) {
        expect(trim((string) $row->action))->not->toBe('');
        expect($row->created_at)->not->toBeNull();
        // Namespaced, so the browser can group by prefix without a dictionary
        // that would be out of date the moment a work package adds an action.
        expect($row->action)->toMatch('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/');
    }
});

it('I-5 keeps bank detail out of the trail even when the action is about money', function () {
    app(PaymentRecordingService::class)->record(
        lease: $this->lease,
        payer: 'tenant',
        amount: Money::fromString('100.00'),
        receivedOn: CarbonImmutable::parse(businessToday()),
        method: 'cheque',
        idempotencyKey: (string) Str::uuid(),
        reference: '10412',
    );

    $trail = DB::table('audit_logs')->pluck('changes')->implode(' ');

    foreach (['routing', 'account_number', 'accountNumber', 'cvv', 'card_number'] as $forbidden) {
        expect($trail)->not->toContain($forbidden);
    }
});
