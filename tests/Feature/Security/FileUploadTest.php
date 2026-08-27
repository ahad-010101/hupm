<?php

use App\Domain\Documents\DocumentVault;
use App\Domain\Maintenance\MaintenanceService;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/*
|--------------------------------------------------------------------------
| File uploads  [WP-34, TDD §6.2]
|--------------------------------------------------------------------------
|
| The DoD asks one question: is a `.php` renamed to `.jpg` rejected, and if it
| were stored, would it be executable.
|
| **`UploadedFile::fake()` cannot ask that question**, and this is the trap
| worth knowing about. `createWithContent('shell.jpg', '<?php ...')` reports
| its MIME as `image/jpeg` — the fake guesses from the *extension*, so the two
| halves of the check can never disagree and a test written with it passes
| whatever the validator does. Every upload here is therefore a real file on
| disk wrapped in a real UploadedFile, which sniffs `text/x-php` from the
| content while the client header still claims `image/jpeg`. That disagreement
| IS the attack, and it is the thing a fake cannot express.
|
| Both halves of the DoD matter and they fail differently. Rejection is
| validation, defeated by whichever half of the check you trust — the
| browser-supplied MIME is a header the client wrote, the extension is a suffix
| the client chose. Executability is architecture and survives a validation
| bug: a file under `storage/app/private` has no URL at all.
|
| This review found the two were applied inconsistently. The document vault
| matched MIME against extension. The maintenance uploader checked the sniffed
| MIME, then named the stored file from the client's extension — so the file on
| disk was named by the uploader even though its contents had been vouched for.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');

    $this->admin = User::factory()->create(['role' => 'admin']);
    $this->tenant = Tenant::factory()->create(['first_name' => 'Odalys', 'last_name' => 'Bąkowski']);
    $this->user = User::factory()->create(['role' => 'tenant', 'tenant_id' => $this->tenant->id]);

    $lease = new Lease;
    $lease->forceFill([
        'unit_id' => Unit::factory()->create()->id,
        'tenant_id' => $this->tenant->id,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'total_contract_rent' => '750.00',
        'tenant_portion' => '250.00',
        'ha_portion' => '500.00',
        'rent_due_day' => 1,
        'grace_period_days' => 5,
        'status' => 'active',
    ])->save();

    $this->lease = $lease;
});

afterEach(function () {
    foreach (secScratch() as $path) {
        @unlink($path);
    }
});

/**
 * Temporary files to clean up, tracked in a static rather than on the test.
 *
 * `test()->scratch[] = $path` looks right and silently does nothing: Pest
 * returns a HigherOrderTapProxy, and appending to an overloaded property is an
 * error rather than an append.
 *
 * Called with no argument it drains the list, so afterEach both reads and
 * resets it.
 *
 * @return list<string>
 */
function secScratch(?string $add = null): array
{
    static $paths = [];

    if ($add !== null) {
        $paths[] = $add;

        return $paths;
    }

    $all = $paths;
    $paths = [];

    return $all;
}

/**
 * A real upload whose CONTENT and whose NAME disagree.
 *
 * A real file on disk, not `UploadedFile::fake()` — see the header. The path
 * separators are normalised because finfo on Windows silently returns null for
 * a mixed `C:\dir/file.jpg`, and a null MIME would be rejected for the wrong
 * reason, which is how a security test comes to pass while proving nothing.
 */
function secUpload(string $name, string $claimedMime, string $content): UploadedFile
{
    $dir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/');
    $path = $dir.'/hupm-sec-'.uniqid().'-'.$name;

    file_put_contents($path, $content);
    secScratch($path);

    // test: true — skip the is_uploaded_file check, exactly as Laravel's own
    // fake does. The fourth argument is the error code, and null is "no error".
    return new UploadedFile($path, $name, $claimedMime, null, true);
}

function secShell(string $name, string $claimedMime = 'image/jpeg'): UploadedFile
{
    return secUpload($name, $claimedMime, '<?php system($_GET["c"]); ?>');
}

function secJpeg(string $name): UploadedFile
{
    // A one-pixel JPEG. Genuine bytes, so it sniffs as image/jpeg.
    return secUpload($name, 'image/jpeg', base64_decode(
        '/9j/4AAQSkZJRgABAQEAYABgAAD/2wBDAAgGBgcGBQgHBwcJCQgKDBQNDAsLDBkSEw8UHRofHh0a'
        .'HBwgJC4nICIsIxwcKDcpLDAxNDQ0Hyc5PTgyPC4zNDL/wAALCAABAAEBAREA/8QAFAABAAAAAAAA'
        .'AAAAAAAAAAAACf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAIAQEAAD8AKp//2Q=='
    ));
}

/*
 |--------------------------------------------------------------------------
 | Rejected
 |--------------------------------------------------------------------------
 */

it('WP-34 rejects a PHP file renamed to .jpg in the document vault', function () {
    /*
     | The literal DoD case. The name says `.jpg`, the browser header says
     | `image/jpeg`, and only the bytes say otherwise — which is why the check
     | has to read the bytes. Confirmed sniffing as `text/x-php` before this
     | test was written, so a pass here means the validator disagreed with the
     | uploader rather than that nothing was ever compared.
    */
    $shell = secShell('holiday.jpg');

    expect($shell->getMimeType())->toBe('text/x-php', 'The upload is not sniffing as PHP, so this proves nothing.')
        ->and($shell->getClientMimeType())->toBe('image/jpeg');

    expect(fn () => app(DocumentVault::class)->store(
        $this->tenant,
        $shell,
        ['category' => 'correspondence', 'title' => 'Holiday'],
        $this->admin,
    ))->toThrow(InvalidArgumentException::class);
});

it('WP-34 rejects a PHP file renamed to .jpg on a maintenance ticket', function () {
    expect(fn () => app(MaintenanceService::class)->attach(
        secTicket(),
        secShell('leak.jpg'),
    ))->toThrow(InvalidArgumentException::class);
});

it('WP-34 rejects a PHP file renamed to .pdf, the commoner direction', function () {
    // An honest-looking extension over content that is not what it claims.
    expect(fn () => app(DocumentVault::class)->store(
        $this->tenant,
        secShell('lease.pdf', 'application/pdf'),
        ['category' => 'current_lease', 'title' => 'Lease'],
        $this->admin,
    ))->toThrow(InvalidArgumentException::class);
});

it('WP-34 rejects a genuine JPEG carrying a .php extension', function () {
    /*
     | The polyglot case, and the one the maintenance uploader was exposed to:
     | content that passes a MIME check honestly, wearing an extension that
     | would matter if the file were ever served by a web server.
     |
     | The vault refuses it outright, because `php` is not a key in its
     | accepted map at all.
    */
    expect(fn () => app(DocumentVault::class)->store(
        $this->tenant,
        secJpeg('picture.php'),
        ['category' => 'correspondence', 'title' => 'Picture'],
        $this->admin,
    ))->toThrow(InvalidArgumentException::class);
});

function secTicket()
{
    return app(MaintenanceService::class)->submit(test()->lease, [
        'category' => 'plumbing',
        'description' => 'The bathroom tap has been dripping since the weekend.',
        'permission_to_enter' => true,
        'preferred_contact' => 'email',
    ], [], test()->user);
}

/*
 |--------------------------------------------------------------------------
 | And if one ever did get through
 |--------------------------------------------------------------------------
 */

it('WP-34 stores a maintenance file under the extension of its sniffed type, never the supplied one', function () {
    /*
     | The gap this review closed. The MIME check reads the file's own bytes and
     | is sound; the stored NAME was taken from `getClientOriginalExtension()`,
     | which is a substring of a string the uploader chose. A genuine image
     | named `shell.php` passed the content check and was written to disk as
     | `<uuid>.php`.
     |
     | It was never reachable — the disk is outside the document root, tested
     | below — so this is the second lock rather than the first. The point is
     | that the guarantee now rests on what the file IS.
    */
    $attachment = app(MaintenanceService::class)->attach(
        secTicket(),
        secJpeg('shell.php'),
    );

    expect($attachment->stored_path)->toEndWith('.jpg')
        ->and($attachment->stored_path)->not->toContain('shell')
        ->and($attachment->stored_path)->not->toContain('.php');

    // The name the uploader chose survives as metadata, which is where it is
    // harmless and where it is useful to whoever reads the ticket.
    expect($attachment->original_filename)->toBe('shell.php');
});

it('WP-34 stores a document under a UUID, so no uploaded name reaches the filesystem', function () {
    $document = app(DocumentVault::class)->store(
        $this->tenant,
        secUpload('notice.pdf', 'application/pdf', "%PDF-1.4\n%stub\n"),
        ['category' => 'correspondence', 'title' => 'Notice'],
        $this->admin,
    );

    // Traversal matters as much as execution here: `../../public` inside a
    // stored path is how a file nobody meant to serve reaches the document root.
    expect($document->stored_path)->not->toContain('..')
        ->and($document->stored_path)->not->toContain('notice')
        ->and($document->stored_path)->toEndWith('.pdf');
});

it('WP-34 keeps every upload on a disk that is not under the document root', function () {
    /*
     | The architectural half of the DoD, and what makes a validation bug
     | survivable. `storage/app/private` is not inside `public/`, so nothing
     | written there has a URL — an uploaded file is reachable only through a
     | controller that checks who is asking (AC-DOC-03/04).
     |
     | Asserted against the configured root rather than a path string, because
     | the way this breaks is somebody switching FILESYSTEM_DISK to `public`
     | for an afternoon to debug something and not switching it back.
    */
    $root = str_replace('\\', '/', (string) config('filesystems.disks.local.root'));
    $documentRoot = str_replace('\\', '/', public_path());

    expect(str_starts_with($root, $documentRoot))
        ->toBeFalse("The local disk is rooted inside the document root: {$root}");

    expect($root)->toEndWith('storage/app/private');
});

it('WP-34 serves a document only through the authenticated controller', function () {
    $document = app(DocumentVault::class)->store(
        $this->tenant,
        secUpload('lease.pdf', 'application/pdf', "%PDF-1.4\n%stub\n"),
        ['category' => 'current_lease', 'title' => 'Lease'],
        $this->admin,
    );

    // Signed out: a stored path is not a URL, and the route that does serve it
    // begins by asking who is asking.
    $this->get("/portal/documents/{$document->id}/download")->assertRedirect('/login');
});
