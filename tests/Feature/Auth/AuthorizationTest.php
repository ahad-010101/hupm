<?php

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Authorization enforcement  [WP-04, FR-AUTH-05, TC-SEC-01]
|--------------------------------------------------------------------------
|
| Two different failures with two different answers:
|
|   wrong ROLE      → 403. The route exists and you may not use it. Saying so
|                     discloses nothing.
|   wrong OWNER     → 404. Saying "forbidden" would confirm the record exists,
|                     which tells tenant A that tenant B is a customer here.
|
| Conflating them is the easy mistake, so both are asserted here.
|
*/

uses(RefreshDatabase::class);

function makeDocument(Tenant $tenant, bool $visible = true): Document
{
    $id = DB::table('documents')->insertGetId([
        'tenant_id' => $tenant->id,
        'category' => 'current_lease',
        'title' => 'Lease',
        'original_filename' => 'lease.pdf',
        'stored_path' => 'vault/'.Str::uuid().'.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('a', 64),
        'version' => 1,
        'is_signed' => false,
        'visible_to_tenant' => $visible,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Document::findOrFail($id);
}

it('TC-SEC-01 returns 404 when tenant A requests tenant B document, and audits it', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();

    $userA = User::factory()->tenant($tenantA)->create();
    $documentB = makeDocument($tenantB);

    // AC-AUTH-09. 404, never 403 — indistinguishable from a record that is
    // simply not there.
    $this->actingAs($userA)
        ->get("/portal/documents/{$documentB->id}")
        ->assertNotFound();

    // Someone walking IDs is exactly what this trail is for.
    $audit = DB::table('audit_logs')->where('action', 'auth.ownership.denied')->first();

    expect($audit)->not->toBeNull()
        ->and($audit->user_id)->toBe($userA->id)
        ->and(json_decode($audit->changes, true)['subject_tenant_id'])->toBe($tenantB->id);
});

it('TC-SEC-01 gives the same 404 for a document that does not exist', function () {
    $userA = User::factory()->tenant()->create();

    // The two responses must be indistinguishable, or the difference itself
    // becomes the oracle.
    $notOwned = $this->actingAs($userA)->get('/portal/documents/'.makeDocument(Tenant::factory()->create())->id);
    $missing = $this->actingAs($userA)->get('/portal/documents/999999');

    expect($notOwned->status())->toBe($missing->status())->toBe(404);
});

it('lets a tenant see their own document', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->tenant($tenant)->create();
    $document = makeDocument($tenant);

    $this->actingAs($user)->get("/portal/documents/{$document->id}")->assertOk();
});

it('hides a document the tenant is not meant to see, even though it is theirs', function () {
    $tenant = Tenant::factory()->create();
    $user = User::factory()->tenant($tenant)->create();
    $document = makeDocument($tenant, visible: false);

    $this->actingAs($user)->get("/portal/documents/{$document->id}")->assertNotFound();
});

it('AC-AUTH-10 gives an owner 403 on document routes', function () {
    $owner = User::factory()->owner()->create();
    $document = makeDocument(Tenant::factory()->create());

    // 403, not 404: the owner role legitimately exists and simply has no
    // business with resident paperwork. Nothing about a specific tenant is
    // disclosed by saying so.
    $this->actingAs($owner)->get('/portal/documents')->assertForbidden();
    $this->actingAs($owner)->get("/portal/documents/{$document->id}")->assertForbidden();
});

it('scopes the document list from the session, never from a parameter', function () {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $userA = User::factory()->tenant($tenantA)->create();

    makeDocument($tenantA);
    makeDocument($tenantB);

    // FR-AUTH-05: a tenant id in a URL is a client assertion, not a fact.
    $this->actingAs($userA)
        ->get('/portal/documents?tenant_id='.$tenantB->id)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('documents', 1));
});

it('keeps each role out of the others areas', function (string $role, array $forbidden) {
    $user = User::factory()->{$role}()->create();

    foreach ($forbidden as $path) {
        $this->actingAs($user)->get($path)->assertForbidden();
    }
})->with([
    'tenant' => ['tenant', ['/admin', '/owner']],
    'admin' => ['admin', ['/portal', '/owner']],
    'owner' => ['owner', ['/admin', '/portal']],
]);

it('redirects a guest to login rather than answering', function () {
    foreach (['/portal', '/admin', '/owner', '/portal/documents'] as $path) {
        $this->get($path)->assertRedirect('/login');
    }
});
