<?php

use App\Domain\Documents\DocumentVault;
use App\Models\Document;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/*
|--------------------------------------------------------------------------
| Document vault  [WP-17, FR-DOC-01/02, TDD §6.2]
|--------------------------------------------------------------------------
|
| A tenant's paperwork is the most sensitive thing this system holds after
| their money: a lease carries their full name, their address and their
| signature, and a HAP contract says they are on housing assistance.
|
| So the tests here are mostly about the ways a file store leaks — a path
| built from a filename, a MIME header taken on trust, an image carrying the
| GPS coordinates of the home it photographs, a URL that keeps working.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->vault = app(DocumentVault::class);

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Uriel', 'last_name' => 'Pouros']);
    $this->user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'total_contract_rent' => '500.00', 'tenant_portion' => '500.00', 'ha_portion' => '0.00',
        'rent_due_day' => 1, 'grace_period_days' => 5, 'status' => 'active',
    ])->save();
});

function filePdf(string $name = 'lease.pdf'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n%stub\n")
        ->mimeType('application/pdf');
}

function fileDocument(array $attributes = [], ?UploadedFile $file = null, ?Tenant $tenant = null): Document
{
    return test()->vault->store(
        $tenant ?? test()->tenant,
        $file ?? filePdf(),
        array_merge(['category' => 'current_lease', 'title' => 'Lease'], $attributes),
        test()->admin,
    );
}

/*
 |--------------------------------------------------------------------------
 | Where files go
 |--------------------------------------------------------------------------
 */

it('stores under a UUID, never under the name the uploader chose', function () {
    $document = fileDocument([], filePdf('../../public/shell.php.pdf'));

    // A filename is user input. A stored path built from one is a remote shell.
    expect($document->stored_path)->toStartWith("documents/{$this->tenant->id}/")
        ->and($document->stored_path)->not->toContain('shell')
        ->and($document->stored_path)->not->toContain('..')
        ->and($document->stored_path)->toEndWith('.pdf')
        // Symfony's getClientOriginalName() already basenames it, so the
        // traversal is gone before we see it — belt as well as braces, since
        // the path is built from a UUID either way.
        ->and($document->original_filename)->toBe('shell.php.pdf');

    Storage::disk('local')->assertExists($document->stored_path);
});

it('AC-DOC-02 keeps the file outside anything the web server serves', function () {
    $document = fileDocument();

    $absolute = Storage::disk('local')->path($document->stored_path);
    $publicRoot = realpath(base_path('public'));

    // The check that matters is structural: the storage root is not under the
    // document root, so there is no URL that could reach it. Verified against
    // the live host with curl at WP-00H.
    expect(str_starts_with(str_replace('\\', '/', $absolute), str_replace('\\', '/', $publicRoot)))
        ->toBeFalse();
});

it('records a SHA-256 of what was actually written', function () {
    $document = fileDocument();

    expect($document->sha256)->toHaveLength(64)
        ->and($document->sha256)->toBe(
            hash_file('sha256', Storage::disk('local')->path($document->stored_path))
        );
});

/*
 |--------------------------------------------------------------------------
 | What gets refused
 |--------------------------------------------------------------------------
 */

it('refuses a file whose extension and contents disagree', function () {
    // A .pdf that is really a JPEG inside. Either check alone is trivially
    // defeated: the MIME is a header and the extension is a suffix.
    $jpeg = imagecreatetruecolor(10, 10);
    ob_start();
    imagejpeg($jpeg);
    $bytes = (string) ob_get_clean();
    imagedestroy($jpeg);

    fileDocument([], UploadedFile::fake()->createWithContent('invoice.pdf', $bytes)->mimeType('image/jpeg'));
})->throws(InvalidArgumentException::class, 'does not look like a PDF file inside');

it('refuses a type that is not on the list', function () {
    fileDocument([], UploadedFile::fake()->create('macros.xlsm', 10, 'application/vnd.ms-excel'));
})->throws(InvalidArgumentException::class, 'Only PDF, JPG, PNG and DOCX');

it('refuses a file over 25 MB, and says how far over', function () {
    fileDocument([], UploadedFile::fake()->create('scan.pdf', 26 * 1024, 'application/pdf'));
})->throws(InvalidArgumentException::class, 'Documents have to be under 25 MB');

it('refuses a category that is not one of the fifteen', function () {
    fileDocument(['category' => 'blackmail']);
})->throws(InvalidArgumentException::class, 'Choose what sort of document');

/*
 |--------------------------------------------------------------------------
 | Images
 |--------------------------------------------------------------------------
 */

it('strips EXIF, including where the photograph was taken', function () {
    // A JPEG carrying a GPS tag and a comment. Both are places a payload or a
    // home address can hide, and neither survives being decoded and re-encoded
    // from pixels.
    $source = imagecreatetruecolor(40, 40);
    ob_start();
    imagejpeg($source);
    $bytes = (string) ob_get_clean();
    imagedestroy($source);

    // APP1/EXIF segment with a recognisable marker, spliced in after SOI.
    $exif = "\xFF\xE1".pack('n', 40)."Exif\x00\x00GPSLatitude=33.7490,GPSLongitude=-84.3880";
    $withExif = substr($bytes, 0, 2).$exif.substr($bytes, 2);

    $document = fileDocument(
        ['category' => 'move_in_inspection'],
        UploadedFile::fake()->createWithContent('kitchen.jpg', $withExif)->mimeType('image/jpeg'),
    );

    $stored = Storage::disk('local')->get($document->stored_path);

    expect($withExif)->toContain('GPSLatitude')
        ->and($stored)->not->toContain('GPSLatitude')
        ->and($stored)->not->toContain('GPSLongitude')
        // Still a readable JPEG afterwards.
        ->and(substr($stored, 0, 2))->toBe("\xFF\xD8");
});

it('refuses an image it cannot decode rather than storing it', function () {
    // A file claiming image/jpeg that GD cannot read is not a JPEG, whatever
    // the header says.
    fileDocument([], UploadedFile::fake()->createWithContent('fake.jpg', 'not an image at all')
        ->mimeType('image/jpeg'));
})->throws(InvalidArgumentException::class, 'could not be read');

it('leaves a PDF byte-for-byte alone', function () {
    $document = fileDocument([], filePdf());

    // Only images are rebuilt. Re-encoding a PDF would change a hash that a
    // signature may already reference (BR-26).
    expect(Storage::disk('local')->get($document->stored_path))->toContain('%PDF-1.4');
});

/*
 |--------------------------------------------------------------------------
 | Versions
 |--------------------------------------------------------------------------
 */

it('AC-DOC-05 keeps the old version and shows the new one', function () {
    $original = fileDocument(['title' => 'Lease']);
    $replacement = $this->vault->replace($original, filePdf('lease-2027.pdf'), $this->admin);

    expect($replacement->version)->toBe(2)
        ->and($replacement->supersedes_document_id)->toBe($original->id)
        // Nothing is deleted, and the old bytes are still on disk.
        ->and(Document::count())->toBe(2)
        ->and($original->fresh()->visible_to_tenant)->toBeFalse();

    Storage::disk('local')->assertExists($original->stored_path);
    Storage::disk('local')->assertExists($replacement->stored_path);
});

it('shows the admin the whole chain, newest first', function () {
    $v1 = fileDocument();
    $v2 = $this->vault->replace($v1, filePdf(), $this->admin);
    $v3 = $this->vault->replace($v2, filePdf(), $this->admin);

    expect($this->vault->versionsOf($v3)->pluck('version')->all())->toBe([3, 2, 1]);
});

it('the tenant sees only the current version', function () {
    $v1 = fileDocument();
    $this->vault->replace($v1, filePdf(), $this->admin);

    $props = [];
    $this->actingAs($this->user)->get('/portal/documents')->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    expect($props['documents'])->toHaveCount(1);
});

it('the admin list shows one row per chain with its history nested', function () {
    $v1 = fileDocument();
    $this->vault->replace($v1, filePdf(), $this->admin);

    $props = [];
    $this->actingAs($this->admin)->get('/admin/documents')->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    // GAP-2 / D-12: nested, not a separate endpoint.
    expect($props['documents'])->toHaveCount(1)
        ->and($props['documents'][0]['version'])->toBe(2)
        ->and($props['documents'][0]['previous'])->toHaveCount(1)
        ->and($props['documents'][0]['previous'][0]['version'])->toBe(1);
});

it('survives a cycle in the version chain rather than hanging the page', function () {
    $a = fileDocument();
    $b = $this->vault->replace($a, filePdf(), $this->admin);

    // Exactly what a bad import would produce.
    DB::table('documents')->where('id', $a->id)->update(['supersedes_document_id' => $b->id]);

    expect($this->vault->versionsOf($b->fresh()))->toHaveCount(2);
});

/*
 |--------------------------------------------------------------------------
 | Who may read what
 |--------------------------------------------------------------------------
 */

it('AC-DOC-01 gives tenant A a 404 for tenant B’s document, and audits it', function () {
    $other = Tenant::factory()->create();
    $theirs = fileDocument([], null, $other);

    $url = URL::temporarySignedRoute('portal.documents.download', now()->addMinutes(5), ['document' => $theirs->id]);

    // 403 would confirm the document exists and belongs to somebody, which is
    // most of what they were trying to find out (I-9, BR-20).
    $this->actingAs($this->user)->get($url)->assertNotFound();

    expect(DB::table('audit_logs')->where('action', 'auth.ownership.denied')->exists())->toBeTrue();
});

it('AC-DOC-03 refuses an expired signed URL', function () {
    $document = fileDocument();

    $url = URL::temporarySignedRoute('portal.documents.download', now()->addMinutes(5), ['document' => $document->id]);

    $this->actingAs($this->user)->get($url)->assertOk();

    // Six minutes later the same URL is dead, which is what makes one pasted
    // into a group chat harmless.
    $this->travel(6)->minutes();
    $this->actingAs($this->user)->get($url)->assertForbidden();
});

it('refuses a download URL with no signature at all', function () {
    $document = fileDocument();

    $this->actingAs($this->user)
        ->get("/portal/documents/{$document->id}/download")
        ->assertForbidden();
});

it('AC-DOC-04 gives an owner 403 on every document route', function () {
    $document = fileDocument();
    $owner = User::factory()->create(['role' => 'owner']);

    foreach ([
        '/portal/documents',
        "/portal/documents/{$document->id}",
        '/admin/documents',
    ] as $path) {
        // BR-21: the owner role exists but has no business with resident
        // paperwork. Wrong role is 403 — the record's existence is not the
        // secret here, the role boundary is.
        $this->actingAs($owner)->get($path)->assertForbidden();
    }
});

it('will not hand a resident a document marked internal', function () {
    $internal = fileDocument(['visible_to_tenant' => false, 'title' => 'Internal note']);

    $url = URL::temporarySignedRoute('portal.documents.download', now()->addMinutes(5), ['document' => $internal->id]);

    $this->actingAs($this->user)->get($url)->assertNotFound();
});

it('serves the file through the controller with the original name attached', function () {
    $document = fileDocument([], filePdf('Lease 2026.pdf'));

    $url = URL::temporarySignedRoute('portal.documents.download', now()->addMinutes(5), ['document' => $document->id]);

    $this->actingAs($this->user)->get($url)
        ->assertOk()
        ->assertHeader('content-disposition', 'attachment; filename="Lease 2026.pdf"');

    expect(DB::table('audit_logs')->where('action', 'document.downloaded')->exists())->toBeTrue();
});

it('lets an admin fetch a superseded version the tenant can no longer see', function () {
    $v1 = fileDocument();
    $this->vault->replace($v1, filePdf(), $this->admin);

    $url = URL::temporarySignedRoute('admin.documents.download', now()->addMinutes(5), ['document' => $v1->id]);

    // AC-DOC-05: "prior versions remain retrievable by ADMIN" is the whole
    // point of not deleting them.
    $this->actingAs($this->admin)->get($url)->assertOk();
});

/*
 |--------------------------------------------------------------------------
 | Uploading, through the screen
 |--------------------------------------------------------------------------
 */

it('files a document an admin uploads and shows it to the resident', function () {
    $this->actingAs($this->admin)->post('/admin/documents', [
        'tenant_id' => $this->tenant->id,
        'category' => 'hap_contract',
        'title' => 'HAP contract 2026',
        'visible_to_tenant' => true,
        'file' => filePdf('hap.pdf'),
    ])->assertSessionHasNoErrors();

    $props = [];
    $this->actingAs($this->user)->get('/portal/documents')->assertInertia(function ($page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    expect($props['documents'][0]['title'])->toBe('HAP contract 2026')
        ->and($props['documents'][0]['category'])->toBe('HAP contract')
        // Never the stored path, and never the hash: neither is any use to a
        // browser and one of them is a filesystem layout.
        ->and(json_encode($props))->not->toContain('stored_path')
        ->not->toContain('documents/'.$this->tenant->id);
});

it('keeps a tenant from uploading anything', function () {
    $this->actingAs($this->user)->post('/admin/documents', [
        'tenant_id' => $this->tenant->id,
        'category' => 'correspondence',
        'file' => filePdf(),
    ])->assertForbidden();
});

it('refuses a signed document being replaced', function () {
    $document = fileDocument();
    $document->is_signed = true;
    $document->save();

    // FR-SIG-02: once a tenant has put their name to it, the bytes and the hash
    // are evidence. A new version is fine; changing this row is not.
    expect(fn () => $document->fresh()->update(['title' => 'Something else']))
        ->toThrow(Exception::class);
});
