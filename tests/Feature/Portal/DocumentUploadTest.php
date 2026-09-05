<?php

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| Residents sending documents in  [WP-42, FR-DOC-01]
|--------------------------------------------------------------------------
|
| The vault already refuses the dangerous things (WP-17, hardened at WP-34).
| What is new is a route a resident can reach, which is the classic way in —
| so the ownership rule and the vault's refusals are tested here on the portal
| route specifically, not assumed from the admin one.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    RateLimiter::clear('tenant:1');

    $this->tenant = Tenant::factory()->create();
    $this->resident = User::factory()->create([
        'role' => 'tenant',
        'tenant_id' => $this->tenant->id,
    ]);

    $this->other = Tenant::factory()->create();

    $this->actingAs($this->resident);
});

it('AC-DOC-06 accepts a document from a resident and files it against them', function () {
    $this->post('/portal/documents', [
        'title' => 'Proof of income — August',
        'file' => UploadedFile::fake()->create('payslip.pdf', 120, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    $document = Document::sole();

    expect($document->tenant_id)->toBe($this->tenant->id)
        ->and($document->title)->toBe('Proof of income — August')
        // Residents file everything as correspondence; the title carries the
        // meaning. The landlord's fourteen categories are not theirs to pick.
        ->and($document->category)->toBe('correspondence')
        // It is theirs, so of course they can see it.
        ->and($document->visible_to_tenant)->toBeTrue()
        // The column that tells the two directions apart.
        ->and($document->uploaded_by_user_id)->toBe($this->resident->id);
});

it('AC-DOC-06 shows the resident their own upload straight away', function () {
    $this->post('/portal/documents', [
        'title' => 'Renters insurance',
        'file' => UploadedFile::fake()->create('policy.pdf', 40, 'application/pdf'),
    ]);

    $this->get('/portal/documents')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Portal/Documents')
            ->where('documents.0.title', 'Renters insurance')
            ->where('documents.0.sent_by_resident', true));
});

it('AC-DOC-07 files against the signed-in resident, whatever the request claims', function () {
    // The whole vulnerability this route could have had. There is no tenant_id
    // parameter to trust, and sending one changes nothing (I-9, BR-20).
    $this->post('/portal/documents', [
        'title' => 'Not yours',
        'tenant_id' => $this->other->id,
        'file' => UploadedFile::fake()->create('note.pdf', 10, 'application/pdf'),
    ])->assertSessionHasNoErrors();

    expect(Document::sole()->tenant_id)->toBe($this->tenant->id)
        ->and(Document::where('tenant_id', $this->other->id)->count())->toBe(0);
});

it('AC-DOC-07 never shows one resident another resident\'s documents', function () {
    $this->post('/portal/documents', [
        'title' => 'Mine',
        'file' => UploadedFile::fake()->create('mine.pdf', 10, 'application/pdf'),
    ]);

    $intruder = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->other->id]);

    $this->actingAs($intruder)
        ->get('/portal/documents')
        ->assertInertia(fn ($page) => $page->has('documents', 0));
});

it('AC-DOC-08 refuses a file larger than the vault allows', function () {
    // 30MB against a 25MB cap.
    $this->post('/portal/documents', [
        'title' => 'Everything',
        'file' => UploadedFile::fake()->create('huge.pdf', 30 * 1024, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('AC-DOC-08 refuses a file type the vault does not accept', function () {
    $this->post('/portal/documents', [
        'title' => 'Script',
        'file' => UploadedFile::fake()->create('run.sh', 2, 'application/x-sh'),
    ])->assertSessionHasErrors('file');

    expect(Document::count())->toBe(0);
});

it('AC-DOC-08 requires a title, so the office knows what arrived', function () {
    $this->post('/portal/documents', [
        'title' => '',
        'file' => UploadedFile::fake()->create('unnamed.pdf', 10, 'application/pdf'),
    ])->assertSessionHasErrors('title');

    expect(Document::count())->toBe(0);
});

it('keeps a signed-in admin out of the resident upload route', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)
        ->post('/portal/documents', [
            'title' => 'Admin file',
            'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf'),
        ])
        ->assertForbidden();

    expect(Document::count())->toBe(0);
});

it('rate limits a resident uploading repeatedly', function () {
    for ($i = 0; $i < 10; $i++) {
        $this->post('/portal/documents', [
            'title' => "File {$i}",
            'file' => UploadedFile::fake()->create("f{$i}.pdf", 5, 'application/pdf'),
        ]);
    }

    $this->post('/portal/documents', [
        'title' => 'One too many',
        'file' => UploadedFile::fake()->create('over.pdf', 5, 'application/pdf'),
    ])->assertSessionHasErrors('file');

    expect(Document::count())->toBe(10);
});
