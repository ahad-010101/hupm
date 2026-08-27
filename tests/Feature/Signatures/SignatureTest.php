<?php

use App\Domain\Documents\DocumentVault;
use App\Domain\Notifications\NotificationTemplate;
use App\Domain\Signatures\SignatureService;
use App\Http\Controllers\Portal\SignatureController;
use App\Models\ConsentRecord;
use App\Models\Document;
use App\Models\Lease;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Support\ElectronicRecordsConsent;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| Electronic signature  [WP-22, FR-SIG-01/02, TDD §9.3]
|--------------------------------------------------------------------------
|
| A legal evidence chain, not a UI feature. Under ESIGN/UETA six elements have
| to be present and a missing one weakens the rest (R-7), so most of what is
| below is about what gets recorded rather than what gets rendered.
|
| The one that matters most is integrity: the hash is of the exact bytes
| presented, and a later mismatch invalidates the signature (BR-26).
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Queue::fake();

    $this->signatures = app(SignatureService::class);
    $this->vault = app(DocumentVault::class);
    $this->consentText = app(ElectronicRecordsConsent::class);

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->signer = User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $this->tenant->id,
        'name' => 'Uriel Pouros',
    ]);

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();

    $this->document = fileLease();
});

/** A real PDF, produced the way this system produces them. */
function realPdf(string $title = 'Lease'): UploadedFile
{
    $bytes = Pdf::loadHTML("<h1>{$title}</h1><p>".str_repeat('Terms and conditions. ', 200).'</p>')->output();
    $path = tempnam(sys_get_temp_dir(), 'sig').'.pdf';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'lease.pdf', 'application/pdf', null, true);
}

function fileLease(): Document
{
    return test()->vault->store(
        test()->tenant,
        realPdf(),
        ['category' => 'current_lease', 'title' => 'Lease 2026'],
        test()->admin,
    );
}

function consentGiven(): ConsentRecord
{
    return test()->signatures->recordConsent(test()->signer, request());
}

function openRequest(): SignatureRequest
{
    return test()->signatures->createRequest(
        test()->document, test()->tenant, test()->signer, test()->admin,
    );
}

/** Everything up to the moment of signing. */
function readyToSign(): SignatureRequest
{
    consentGiven();
    $request = openRequest();
    test()->signatures->markViewed($request, request());
    test()->signatures->markScrolled($request, request());

    return $request->refresh();
}

/*
 |--------------------------------------------------------------------------
 | Element 1 — consent
 |--------------------------------------------------------------------------
 */

it('AC-SIG-01 refuses to sign without a consent record', function () {
    $request = openRequest();
    $this->signatures->markScrolled($request, request());

    // BR-25. Checked in the service, not only on the screen — the screen is not
    // what a court looks at.
    expect(fn () => $this->signatures->sign(
        $request->refresh(), $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'consent has not been recorded');

    expect(SignatureEvent::where('event', SignatureEvent::SIGNED)->exists())->toBeFalse();
});

it('records the hash of the exact consent wording, not just its version', function () {
    $record = consentGiven();

    expect($record->consent_text_version)->toBe('1.0')
        ->and($record->consent_text_sha256)->toBe(hash('sha256', $this->consentText->text('1.0')))
        ->and($record->ip_address)->not->toBeEmpty();

    // A record that stored only "agreed, version 1" would be worth nothing if
    // version 1 were a row somebody could edit.
    expect($this->consentText->verify('1.0', $record->consent_text_sha256))->toBeTrue();
});

it('stops verifying if the consent wording is ever changed in place', function () {
    $record = consentGiven();

    // Simulating what an edit-in-place would look like from the record's side.
    expect($this->consentText->verify('1.0', hash('sha256', 'different wording')))->toBeFalse()
        ->and($this->consentText->verify('9.9', $record->consent_text_sha256))->toBeFalse();
});

it('a consent record cannot be altered afterwards', function () {
    $record = consentGiven();

    expect(fn () => $record->update(['ip_address' => '10.0.0.1']))->toThrow(Exception::class);
});

it('refuses consent echoed back with a stale wording version', function () {
    $request = openRequest();

    // A tab left open across a wording change would otherwise record agreement
    // to text nobody was showing.
    $this->actingAs($this->signer)
        ->postJson("/portal/sign/{$request->id}/consent", ['agreed' => true, 'version' => '0.9'])
        ->assertStatus(409);

    expect(ConsentRecord::count())->toBe(0);
});

/*
 |--------------------------------------------------------------------------
 | Elements 2 and 3 — intent and attribution
 |--------------------------------------------------------------------------
 */

it('stores the typed name and the exact label of the control pressed', function () {
    $request = readyToSign();

    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    $signed = SignatureEvent::where('event', SignatureEvent::SIGNED)->sole();

    expect($signed->typed_name)->toBe('Uriel Pouros')
        // Element 2. Intent is evidenced by what the control said, not asserted.
        ->and($signed->button_label)->toBe('Sign and submit')
        ->and($signed->ip_address)->not->toBeEmpty()
        ->and($signed->user_agent)->not->toBeEmpty();
});

it('keeps the stored button label and the rendered one from drifting apart', function () {
    $request = readyToSign();

    $props = [];
    $this->actingAs($this->signer)->get("/portal/sign/{$request->id}")
        ->assertOk()
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // One constant, sent to the browser and written to the evidence.
    expect($props['buttonLabel'])->toBe(SignatureController::BUTTON_LABEL);
});

it('orders events to the millisecond', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    $events = SignatureEvent::orderBy('id')->pluck('event')->all();

    // Scroll and signature routinely land in the same second. An evidence trail
    // that cannot order its own events is not evidence (the D-18 problem).
    expect($events)->toBe(['created', 'sent', 'opened', 'scrolled_complete', 'signed'])
        ->and(DB::table('signature_events')->orderBy('id')->pluck('occurred_at')->first())
        ->toContain('.');
});

it('a signature event cannot be altered afterwards', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    $event = SignatureEvent::where('event', SignatureEvent::SIGNED)->sole();

    // Fully immutable, unlike the ledger: there is no legitimate reason to edit
    // one, and every reason to make it impossible.
    expect(fn () => $event->update(['typed_name' => 'Someone Else']))->toThrow(Exception::class);
});

/*
 |--------------------------------------------------------------------------
 | Element 4 — integrity
 |--------------------------------------------------------------------------
 */

it('hashes the bytes on disk at the moment of signing, not the stored column', function () {
    $request = readyToSign();

    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    $signed = SignatureEvent::where('event', SignatureEvent::SIGNED)->sole();

    expect($signed->document_sha256)
        ->toBe(hash_file('sha256', $this->vault->pathFor($this->document)));
});

it('refuses to sign a file that changed after it was filed', function () {
    $request = readyToSign();

    // Somebody replaced the bytes on disk without going through the vault.
    file_put_contents($this->vault->pathFor($this->document), '%PDF-1.4 tampered');

    expect(fn () => $this->signatures->sign(
        $request, $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'changed since it was filed');

    expect(SignatureEvent::where('event', SignatureEvent::SIGNED)->exists())->toBeFalse();
});

it('AC-SIG-03 fails verification and says so when the bytes are altered later', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    expect($this->signatures->verifyIntegrity($request->refresh())['valid'])->toBeTrue();

    file_put_contents($this->vault->pathFor($this->document), '%PDF-1.4 altered after signing');

    $verdict = $this->signatures->verifyIntegrity($request->refresh());

    // BR-26: a mismatch invalidates the signature, and saying so plainly is the
    // whole value of having recorded the hash.
    expect($verdict['valid'])->toBeFalse()
        ->and($verdict['reason'])->toContain('no longer matches')
        ->and($verdict['expected'])->not->toBe($verdict['actual']);
});

it('reports a missing file rather than quietly passing', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    unlink($this->vault->pathFor($this->document));

    expect($this->signatures->verifyIntegrity($request->refresh())['valid'])->toBeFalse();
});

it('surfaces a broken signature on the admin screen', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());
    file_put_contents($this->vault->pathFor($this->document), '%PDF-1.4 altered');

    $props = [];
    $this->actingAs($this->admin)->get('/admin/signatures')
        ->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

    // Asked under pressure, usually the day before a hearing.
    expect($props['requests'][0]['integrity']['valid'])->toBeFalse();
});

/*
 |--------------------------------------------------------------------------
 | Element 5 — the certificate
 |--------------------------------------------------------------------------
 */

it('AC-SIG-02 produces an executed PDF carrying signer, time, IP and hash', function () {
    $request = readyToSign();

    $executed = $this->signatures->sign(
        $request, $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    );

    $bytes = Storage::disk('local')->get($executed->stored_path);
    $text = extractPdfText($bytes);

    expect(substr($bytes, 0, 4))->toBe('%PDF')
        ->and($text)->toContain('Certificate of electronic signature')
        ->and($text)->toContain('Uriel Pouros')
        ->and($text)->toContain('Sign and submit')
        // The hash of the exact bytes signed, on the face of the certificate.
        ->and($text)->toContain(substr(hash_file('sha256', $this->vault->pathFor($this->document)), 0, 20));
});

it('embeds the original pages rather than re-rendering them', function () {
    $request = readyToSign();

    $executed = $this->signatures->sign(
        $request, $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    );

    $text = extractPdfText(Storage::disk('local')->get($executed->stored_path));

    // Re-rendering would change the bytes and break the hash the certificate
    // asserts. The original pages are placed verbatim.
    expect($text)->toContain('Terms and conditions')
        ->and($text)->toContain('The signed document appears on the preceding pages');
});

it('says so on the certificate when the original cannot be embedded', function () {
    // A PDF FPDI cannot parse. Storing it takes the vault's word for the MIME
    // type, which is the realistic route for a file from a third party.
    $broken = $this->vault->store(
        $this->tenant,
        UploadedFile::fake()->createWithContent('scan.pdf', '%PDF-1.7 not really a parseable pdf')
            ->mimeType('application/pdf'),
        ['category' => 'renewal', 'title' => 'Scanned renewal'],
        $this->admin,
    );

    consentGiven();
    $request = $this->signatures->createRequest($broken, $this->tenant, $this->signer, $this->admin);
    $this->signatures->markScrolled($request, request());

    $executed = $this->signatures->sign(
        $request->refresh(), $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    );

    $text = extractPdfText(Storage::disk('local')->get($executed->stored_path));

    // A cryptographic binding is not a worse binding than a physical one. A
    // silent one would be.
    expect($text)->toContain('could not be embedded')
        ->and(DB::table('audit_logs')->where('action', 'signature.completed')->exists())->toBeTrue();
});

/*
 |--------------------------------------------------------------------------
 | Element 6 — retention
 |--------------------------------------------------------------------------
 */

it('AC-SIG-05 refuses to alter or delete a signed document', function () {
    $request = readyToSign();
    $executed = $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    // BR-27: superseded, never replaced.
    expect(fn () => $executed->update(['title' => 'Something else']))->toThrow(Exception::class)
        ->and(fn () => $executed->delete())->toThrow(Exception::class)
        ->and(fn () => $this->document->fresh()->update(['title' => 'Edited']))->toThrow(Exception::class);
});

it('files the executed copy as a new version that supersedes the original', function () {
    $request = readyToSign();
    $executed = $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    expect($executed->supersedes_document_id)->toBe($this->document->id)
        ->and($executed->version)->toBe($this->document->version + 1)
        ->and($executed->is_signed)->toBeTrue()
        ->and($this->document->fresh()->is_signed)->toBeTrue()
        // Nothing is deleted: the unsigned original is still on disk.
        ->and(Storage::disk('local')->exists($this->document->stored_path))->toBeTrue();
});

it('emails the signer a copy', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    expect(DB::table('notification_logs')
        ->where('template', NotificationTemplate::SignatureCompleted->value)->count())->toBe(1);
});

/*
 |--------------------------------------------------------------------------
 | The gates, and who may pass them
 |--------------------------------------------------------------------------
 */

it('AC-SIG-04 refuses to sign a document that was never scrolled', function () {
    consentGiven();
    $request = openRequest();

    // The disabled button is a courtesy; this is the rule.
    expect(fn () => $this->signatures->sign(
        $request, $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'read the whole document');
});

it('records reaching the end as its own event, once', function () {
    $request = readyToSign();

    $this->signatures->markScrolled($request, request());
    $this->signatures->markScrolled($request, request());

    expect(SignatureEvent::where('event', SignatureEvent::SCROLLED_COMPLETE)->count())->toBe(1);
});

it('refuses an empty typed name', function () {
    $request = readyToSign();

    expect(fn () => $this->signatures->sign(
        $request, $this->signer, '   ', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'full legal name');
});

it('refuses to sign twice', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    expect(fn () => $this->signatures->sign(
        $request->refresh(), $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'already been signed');
});

it('refuses an expired request', function () {
    consentGiven();
    $request = $this->signatures->createRequest(
        $this->document, $this->tenant, $this->signer, $this->admin,
        CarbonImmutable::now()->addHour(),
    );
    $this->signatures->markScrolled($request, request());

    $this->travel(2)->hours();

    expect(fn () => $this->signatures->sign(
        $request->refresh(), $this->signer, 'Uriel Pouros', 'Sign and submit', request(),
    ))->toThrow(RuntimeException::class, 'expired');
});

it('F1 records nothing when the session ends mid-signature', function () {
    $request = readyToSign();

    // Not authenticated: exactly what an expired session looks like to the
    // route. Half an evidence chain reads like a signature and is not one.
    $this->post("/portal/sign/{$request->id}", [
        'typed_name' => 'Uriel Pouros',
        'scrolled_complete' => true,
        'consent_acknowledged' => true,
    ])->assertRedirect('/login');

    expect(SignatureEvent::where('event', SignatureEvent::SIGNED)->exists())->toBeFalse()
        ->and($request->refresh()->status)->not->toBe('signed')
        ->and(Document::where('is_signed', true)->exists())->toBeFalse();
});

it('I-9 gives 404 on someone else’s signature request', function () {
    $request = readyToSign();

    $other = Tenant::factory()->create();
    $intruder = User::factory()->create(['role' => 'tenant', 'tenant_id' => $other->id]);

    // 403 would confirm the request exists and belongs to somebody.
    $this->actingAs($intruder)->get("/portal/sign/{$request->id}")->assertNotFound();
    $this->actingAs($intruder)->post("/portal/sign/{$request->id}", [
        'typed_name' => 'Not Me', 'scrolled_complete' => true, 'consent_acknowledged' => true,
    ])->assertNotFound();
});

it('refuses a request against a document that is already signed', function () {
    $request = readyToSign();
    $this->signatures->sign($request, $this->signer, 'Uriel Pouros', 'Sign and submit', request());

    expect(fn () => $this->signatures->createRequest(
        $this->document->fresh(), $this->tenant, $this->signer, $this->admin,
    ))->toThrow(RuntimeException::class, 'already been signed');
});

it('refuses a request for a document belonging to a different resident', function () {
    $other = Tenant::factory()->create();
    $otherUser = User::factory()->create(['role' => 'tenant', 'tenant_id' => $other->id]);

    expect(fn () => $this->signatures->createRequest(
        $this->document, $other, $otherUser, $this->admin,
    ))->toThrow(RuntimeException::class, 'does not belong to this resident');
});

it('keeps a tenant out of the admin signatures screen', function () {
    $this->actingAs($this->signer)->get('/admin/signatures')->assertForbidden();
});

/** Crude but sufficient: pull readable strings out of a PDF's content streams. */
function extractPdfText(string $bytes): string
{
    $text = '';

    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $bytes, $matches)) {
        foreach ($matches[1] as $stream) {
            $inflated = @gzuncompress($stream);
            $text .= $inflated === false ? $stream : $inflated;
        }
    }

    // Text is written as (literal) Tj / TJ in the content stream.
    preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)/', $text.$bytes, $literals);

    return implode(' ', array_map(
        fn ($s) => stripcslashes($s),
        $literals[1] ?? [],
    ));
}
