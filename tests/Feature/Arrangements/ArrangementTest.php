<?php

use App\Domain\Arrangements\ArrangementService;
use App\Domain\Ledger\LedgerService;
use App\Domain\Payments\PartialPaymentPolicy;
use App\Models\Document;
use App\Models\Lease;
use App\Models\PaymentArrangement;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Payment arrangements  [WP-25, FR-ARR-01/02, BR-19]
|--------------------------------------------------------------------------
|
| BR-19 is the substance: every approved partial payment produces a written
| agreement carrying nine named elements. AC-ARR-04 says to prove that by
| parsing the document, not by looking at it, so that is what happens below —
| an element that quietly stops rendering fails a test rather than turning up
| missing in a hearing.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->ledger = app(LedgerService::class);
    $this->arrangements = app(ArrangementService::class);
    $this->policy = app(PartialPaymentPolicy::class);

    $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Office Manager']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->signer = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $this->lease = arrangementLease();
    oweBalance('1200.00');
});

function arrangementLease(array $overrides = []): Lease
{
    $lease = new Lease;
    $lease->forceFill(array_merge([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => test()->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '1200.00',
        'tenant_portion' => '600.00',
        'ha_portion' => '600.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'partial_payment_policy' => 'partial_allowed',
        'status' => 'active',
    ], $overrides))->save();

    return $lease;
}

function oweBalance(string $amount, string $period = '2026-02'): void
{
    test()->ledger->postCharge(
        test()->lease, 'rent', 'tenant', Money::fromString($amount),
        'Rent — February 2026', 'arr:'.test()->lease->id.":{$period}",
        CarbonImmutable::parse($period.'-01'), $period,
    );
}

/** @param list<array{due_on: string, amount: string}> $schedule */
function draftArrangement(string $accepted = '400.00', array $schedule = [], bool $lateFees = true): PaymentArrangement
{
    return test()->arrangements->draft(
        test()->lease,
        Money::fromString($accepted),
        $schedule,
        $lateFees,
        'If any payment is missed the whole balance becomes due immediately.',
        test()->admin,
    );
}

/**
 * Pull readable text out of a dompdf-produced PDF.
 *
 * dompdf embeds DejaVu Sans and writes its literals as UTF-16BE, so the naive
 * extractor used for the FPDF-generated signature certificate returns bytes
 * with a null between every character. Decoding is the difference between
 * asserting on the document and asserting on nothing.
 */
function pdfText(string $bytes): string
{
    $text = '';

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches)) {
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated === false ? $stream : $inflated;
        }
    }

    preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $text, $literals);

    return implode(' ', array_map(function (string $literal) {
        $decoded = stripcslashes($literal);

        return str_contains($decoded, "\0")
            ? (string) mb_convert_encoding($decoded, 'UTF-8', 'UTF-16BE')
            : $decoded;
    }, $literals[1] ?? []));
}

/*
 |--------------------------------------------------------------------------
 | BR-19 — the nine elements
 |--------------------------------------------------------------------------
 */

it('AC-ARR-04 puts all nine required elements in the agreement and files it', function () {
    $arrangement = draftArrangement('400.00', [
        ['due_on' => '2026-03-01', 'amount' => '400.00'],
        ['due_on' => '2026-04-01', 'amount' => '400.00'],
    ]);

    $this->arrangements->approve($arrangement, $this->admin);

    $document = Document::where('category', 'payment_agreement')->sole();
    $text = pdfText(Storage::disk('local')->get($document->stored_path));

    // Each of the nine, individually. Asserting on the document rather than on
    // the model is the point of AC-ARR-04 — a heading that stops rendering has
    // to fail here rather than turn up missing in a hearing.
    expect($text)
        ->toContain('Total amount owed')->toContain('1,200.00')          // 1
        ->toContain('Amount accepted today')->toContain('400.00')        // 2
        ->toContain('Remaining balance')->toContain('800.00')            // 3
        ->toContain('Payment dates')->toContain('1 March 2026')          // 4
        ->toContain('Late fees')                                          // 5
        ->toContain('If a payment is missed')                             // 6
        ->toContain('Consequences of breach')                             // 7
        ->toContain('Resident')                                           // 8
        ->toContain('Landlord');                                          // 9

    // And it is in the vault, not on disk somewhere of its own.
    expect($document->tenant_id)->toBe($this->tenant->id)
        ->and($document->visible_to_tenant)->toBeTrue()
        ->and($document->sha256)->toHaveLength(64);
});

it('states that late fees continue when they do, and that they stop when they do not', function () {
    $continues = draftArrangement('400.00', [], true);
    $this->arrangements->approve($continues, $this->admin);

    $text = pdfText(Storage::disk('local')->get(
        Document::latest('id')->first()->stored_path
    ));

    expect($text)->toContain('continue to apply');

    // A fresh resident, so the second agreement is drafted against a balance
    // of its own rather than what the first one already accounted for.
    $this->tenant = Tenant::factory()->create();
    $this->lease = arrangementLease(['unit_id' => Unit::factory()->create()->id]);
    oweBalance('1000.00', '2026-03');

    $paused = draftArrangement('300.00', [], false);
    $this->arrangements->approve($paused, $this->admin);

    expect(pdfText(Storage::disk('local')->get(Document::latest('id')->first()->stored_path)))
        ->toContain('not to charge further late fees');
});

it('says the balance is payable immediately when there is no schedule', function () {
    $arrangement = draftArrangement('400.00', []);
    $this->arrangements->approve($arrangement, $this->admin);

    expect(pdfText(Storage::disk('local')->get(Document::latest('id')->first()->stored_path)))
        ->toContain('payable in full immediately');
});

/*
 |--------------------------------------------------------------------------
 | Drafting
 |--------------------------------------------------------------------------
 */

it('snapshots what was owed rather than tracking the ledger afterwards', function () {
    $arrangement = draftArrangement('400.00');

    // More rent posts after the agreement is written.
    oweBalance('600.00', '2026-03');

    // A figure that drifted with the ledger would quietly rewrite a signed
    // document — and store a balance, which I-1 forbids.
    expect($arrangement->fresh()->total_owed->toDecimalString())->toBe('1200.00')
        ->and($arrangement->fresh()->remaining_balance->toDecimalString())->toBe('800.00');
});

it('refuses instalments that do not add up to the remaining balance', function () {
    // $1200 owed, $400 accepted, $800 left — but the instalments come to $700.
    expect(fn () => draftArrangement('400.00', [
        ['due_on' => '2026-03-01', 'amount' => '400.00'],
        ['due_on' => '2026-04-01', 'amount' => '300.00'],
    ]))->toThrow(InvalidArgumentException::class, 'come to $700.00');
});

it('refuses to accept more than is owed', function () {
    expect(fn () => draftArrangement('1500.00'))
        ->toThrow(InvalidArgumentException::class, 'more than the $1,200.00');
});

it('refuses an arrangement against an account that owes nothing', function () {
    // A different RESIDENT, not just a different lease: the balance is per
    // tenant, so a second lease for the same person still owes the same $1,200.
    $this->tenant = Tenant::factory()->create();
    $this->lease = arrangementLease(['unit_id' => Unit::factory()->create()->id]);

    expect(fn () => draftArrangement('100.00'))
        ->toThrow(InvalidArgumentException::class, 'nothing outstanding');
});

it('refuses an agreement that does not say what happens on a breach', function () {
    expect(fn () => $this->arrangements->draft(
        $this->lease, Money::fromString('400.00'), [], true, '   ', $this->admin,
    ))->toThrow(InvalidArgumentException::class, 'what happens on a breach');
});

it('sorts instalments into date order however they were entered', function () {
    $arrangement = draftArrangement('400.00', [
        ['due_on' => '2026-04-01', 'amount' => '400.00'],
        ['due_on' => '2026-03-01', 'amount' => '400.00'],
    ]);

    expect(array_column($arrangement->schedule_json, 'due_on'))
        ->toBe(['2026-03-01', '2026-04-01']);
});

/*
 |--------------------------------------------------------------------------
 | Approval and signature
 |--------------------------------------------------------------------------
 */

it('AC-ARR-05 sends the agreement for signature and shows it to the resident', function () {
    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement, $this->admin);

    $request = SignatureRequest::sole();

    expect($arrangement->fresh()->status)->toBe('pending_signature')
        ->and($request->user_id)->toBe($this->signer->id)
        ->and($request->document_id)->toBe($arrangement->fresh()->document_id);

    // And it is in their documents.
    $props = [];
    $this->actingAs($this->signer)->get('/portal/documents')
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    expect(collect($props['documents'])->pluck('title')->first())
        ->toContain('Payment arrangement');
});

it('still files the agreement for a resident with no portal login', function () {
    $this->signer->delete();

    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement->fresh(), $this->admin);

    // Q-4: a resident with no login gets a paper copy. The agreement still
    // exists and is still filed.
    expect(Document::where('category', 'payment_agreement')->count())->toBe(1)
        ->and(SignatureRequest::count())->toBe(0)
        ->and($arrangement->fresh()->status)->toBe('pending_signature');
});

it('refuses to approve the same arrangement twice', function () {
    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement, $this->admin);

    expect(fn () => $this->arrangements->approve($arrangement->fresh(), $this->admin))
        ->toThrow(InvalidArgumentException::class, 'already been approved');

    expect(Document::where('category', 'payment_agreement')->count())->toBe(1);
});

it('only becomes live once it is signed', function () {
    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement, $this->admin);

    // An unsigned arrangement is a proposal.
    expect($arrangement->fresh()->isLive())->toBeFalse();

    $this->arrangements->activate($arrangement->fresh());

    expect($arrangement->fresh()->status)->toBe('active');
});

it('records a default with a reason, never by inference', function () {
    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement, $this->admin);
    $this->arrangements->activate($arrangement->fresh());

    expect(fn () => $this->arrangements->markDefaulted($arrangement->fresh(), '  ', $this->admin))
        ->toThrow(InvalidArgumentException::class, 'requires a reason');

    $this->arrangements->markDefaulted($arrangement->fresh(), 'March instalment missed.', $this->admin);

    expect($arrangement->fresh()->status)->toBe('defaulted')
        ->and(DB::table('audit_logs')->where('action', 'arrangement.defaulted')->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | FR-ARR-01 — the per-lease partial payment settings
 |--------------------------------------------------------------------------
 */

it('AC-ARR-01 fixes the amount on a full-only lease', function () {
    $this->lease->forceFill(['partial_payment_policy' => 'full_only'])->save();

    $verdict = $this->policy->check($this->lease->fresh(), Money::fromString('400.00'));

    expect($verdict['allowed'])->toBeFalse()
        ->and($verdict['reason'])->toContain('full balance of $1,200.00');

    // Paying the whole balance is always permitted.
    expect($this->policy->check($this->lease->fresh(), Money::fromString('1200.00'))['allowed'])->toBeTrue();
});

it('AC-ARR-02 refuses a part payment past the due date on a before-due lease', function () {
    $this->lease->forceFill(['partial_payment_policy' => 'before_due_only'])->save();

    CarbonImmutable::setTestNow('2026-02-20 12:00:00');
    Carbon::setTestNow('2026-02-20 12:00:00');

    $verdict = $this->policy->check($this->lease->fresh(), Money::fromString('400.00'));

    CarbonImmutable::setTestNow();
    Carbon::setTestNow();

    expect($verdict['allowed'])->toBeFalse()
        // Every rejection says what to do next (UI §8).
        ->and($verdict['reason'])->toContain('only accepted before')
        ->and($verdict['reason'])->toContain('contact the office');
});

it('AC-ARR-03 states the minimum when the amount is under it', function () {
    $this->lease->forceFill(['partial_minimum_amount' => '100.00'])->save();

    $verdict = $this->policy->check($this->lease->fresh(), Money::fromString('50.00'));

    expect($verdict['allowed'])->toBeFalse()
        ->and($verdict['reason'])->toContain('$100.00');
});

it('accepts a part payment only under a live arrangement when set that way', function () {
    $this->lease->forceFill(['partial_payment_policy' => 'under_arrangement_only'])->save();

    expect($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeFalse();

    $arrangement = draftArrangement('400.00');
    $this->arrangements->approve($arrangement, $this->admin);

    // Approved but unsigned is still a proposal.
    expect($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeFalse();

    $this->arrangements->activate($arrangement->fresh());

    expect($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | GATE C1/Q-1 — "full ledger required"
 |--------------------------------------------------------------------------
 */

it('GATE C1 blocks a part payment until the ledger is reviewed for this period', function () {
    $this->lease->forceFill(['ledger_review_required' => true])->save();

    $verdict = $this->policy->check($this->lease->fresh(), Money::fromString('400.00'));

    // The shipped reading of an undefined requirement. Confirm before go-live.
    expect($verdict['allowed'])->toBeFalse()
        ->and($verdict['reason'])->toContain('review it first')
        ->and($verdict['reason'])->toContain('contact us');
});

it('accepts the part payment once an admin marks the ledger reviewed', function () {
    $this->lease->forceFill(['ledger_review_required' => true])->save();

    $this->actingAs($this->admin)
        ->post("/admin/arrangements/leases/{$this->lease->id}/review-ledger")
        ->assertSessionHasNoErrors();

    expect($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeTrue()
        ->and($this->lease->fresh()->ledger_reviewed_by_user_id)->toBe($this->admin->id)
        ->and(DB::table('audit_logs')->where('action', 'arrangement.ledger.reviewed')->exists())->toBeTrue();
});

it('D-24 does not let last month’s review license this month’s part payment', function () {
    $this->lease->forceFill([
        'ledger_review_required' => true,
        // Reviewed, but for a period that has passed.
        'ledger_reviewed_period' => '2020-01',
    ])->save();

    expect($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeFalse();
});

it('leaves accounts alone that do not require a review', function () {
    // The flag is off by default, so nothing changes for everyone else.
    expect($this->lease->ledger_review_required)->toBeFalsy()
        ->and($this->policy->check($this->lease->fresh(), Money::fromString('400.00'))['allowed'])->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | The screen
 |--------------------------------------------------------------------------
 */

it('surfaces the ledgers waiting on a review', function () {
    $this->lease->forceFill(['ledger_review_required' => true])->save();

    $props = [];
    $this->actingAs($this->admin)->get('/admin/arrangements')->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    $waiting = collect($props['leases'])->firstWhere('id', $this->lease->id);

    expect($waiting['ledger_review_required'])->toBeTrue()
        ->and($waiting['ledger_reviewed'])->toBeFalse()
        ->and($props['currentPeriod'])->toBe(now()->format('Y-m'));
});

it('drafts and approves through the screen', function () {
    $this->actingAs($this->admin)->post('/admin/arrangements', [
        'lease_id' => $this->lease->id,
        'amount_accepted' => '400.00',
        'late_fees_continue' => true,
        'default_terms' => 'The whole balance becomes due if a payment is missed.',
        'schedule' => [['due_on' => '2026-03-01', 'amount' => '800.00']],
    ])->assertSessionHasNoErrors();

    $arrangement = PaymentArrangement::sole();
    expect($arrangement->status)->toBe('draft');

    $this->actingAs($this->admin)
        ->post("/admin/arrangements/{$arrangement->id}/approve")
        ->assertSessionHasNoErrors();

    expect($arrangement->fresh()->status)->toBe('pending_signature')
        ->and($arrangement->fresh()->document_id)->not->toBeNull();
});

it('keeps a tenant out of arrangements entirely', function () {
    $this->actingAs($this->signer)->get('/admin/arrangements')->assertForbidden();
    $this->actingAs($this->signer)->post('/admin/arrangements', [
        'lease_id' => $this->lease->id,
        'amount_accepted' => '1.00',
        'default_terms' => 'Nothing at all happens ever.',
    ])->assertForbidden();
    $this->actingAs($this->signer)
        ->post("/admin/arrangements/leases/{$this->lease->id}/review-ledger")
        ->assertForbidden();
});
