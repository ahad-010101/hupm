<?php

namespace App\Domain\Documents;

use App\Models\Document;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The document vault.  [FR-DOC-01/02, TDD §6.2]
 *
 * Four rules, and each of them exists because of a specific way file uploads
 * go wrong:
 *
 *   1. **Outside the web root, under a UUID.** The tenant's filename is
 *      metadata and never touches the path — `../../public/shell.php` is a
 *      filename, and a stored path built from one is a remote shell.
 *   2. **MIME *and* extension.** Either alone is trivially defeated: the
 *      browser-supplied MIME is a header, and the extension is a suffix.
 *   3. **Images are re-encoded, not copied.** A JPEG can carry PHP in an EXIF
 *      comment and the GPS coordinates of someone's home in its header.
 *      Decoding and re-encoding discards both, because the output is built
 *      from pixels rather than from the original bytes.
 *   4. **SHA-256 on every file.** `signature_events.document_sha256` records
 *      what was on screen at the moment of signing (BR-26); that reference is
 *      worthless without a hash on the document itself.
 */
class DocumentVault
{
    public const MAX_BYTES = 25 * 1024 * 1024;

    /** FR-DOC-01. Extension => acceptable MIME types. Both are checked. */
    public const ACCEPTED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'docx' => [
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip',
        ],
    ];

    /** The fifteen from FR-DOC-01, in the source document's order. */
    public const CATEGORIES = [
        'current_lease' => 'Current lease',
        'renewal' => 'Renewal',
        'hap_contract' => 'HAP contract',
        'tenancy_addendum' => 'Tenancy addendum',
        'move_in_inspection' => 'Move-in inspection',
        'move_out_inspection' => 'Move-out inspection',
        'lead_paint_disclosure' => 'Lead paint disclosure',
        'house_rules' => 'House rules',
        'payment_agreement' => 'Payment agreement',
        'late_notice' => 'Late notice',
        'maintenance_notice' => 'Maintenance notice',
        'rent_increase_notice' => 'Rent increase notice',
        'insurance' => 'Insurance',
        'security_deposit_record' => 'Security deposit record',
        'correspondence' => 'Correspondence',
    ];

    /**
     * Long enough to click, short enough that a URL in a shared inbox is dead
     * by the time anyone else opens it.
     */
    public const SIGNED_URL_MINUTES = 5;

    private const DISK = 'local';

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * File a new document.
     *
     * @param  array{category: string, title?: string, lease_id?: int|null, visible_to_tenant?: bool, version?: int, supersedes_document_id?: int|null}  $attributes
     */
    public function store(
        Tenant $tenant,
        UploadedFile $file,
        array $attributes,
        ?User $actor = null,
    ): Document {
        $this->assertAcceptable($file);
        $this->assertCategory($attributes['category']);

        return DB::transaction(function () use ($tenant, $file, $attributes, $actor) {
            [$path, $bytes, $mime] = $this->put($tenant, $file);

            $document = new Document;
            $document->forceFill([
                'tenant_id' => $tenant->id,
                'lease_id' => $attributes['lease_id'] ?? $tenant->activeLease()?->id,
                'category' => $attributes['category'],
                'title' => $attributes['title'] ?? pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
                // Kept for display only. It is user input and never a path.
                'original_filename' => substr($file->getClientOriginalName(), 0, 255),
                'stored_path' => $path,
                'mime_type' => $mime,
                'size_bytes' => $bytes,
                'sha256' => hash_file('sha256', Storage::disk(self::DISK)->path($path)),
                // Set at insert, never as a follow-up write: the model is
                // immutable except for a named handful, and the version chain
                // is not among them. It is also the honest shape — a version is
                // a fact about the row from the moment it exists.
                'version' => $attributes['version'] ?? 1,
                'supersedes_document_id' => $attributes['supersedes_document_id'] ?? null,
                'visible_to_tenant' => $attributes['visible_to_tenant'] ?? true,
                'uploaded_by_user_id' => $actor?->id,
            ])->save();

            $this->audit->record('document.uploaded', $document, [
                'category' => $document->category,
                'sha256' => $document->sha256,
                'visible_to_tenant' => $document->visible_to_tenant,
            ]);

            return $document;
        });
    }

    /**
     * Replace a document with a newer version.  [FR-DOC-02, AC-DOC-05]
     *
     * The old row and the old bytes both stay. A lease that was superseded is
     * still the lease that was in force last March, and "we replaced it" is not
     * an answer to "what did it say at the time".
     */
    public function replace(Document $current, UploadedFile $file, ?User $actor = null): Document
    {
        $tenant = Tenant::findOrFail($current->tenant_id);

        $replacement = $this->store($tenant, $file, [
            'category' => $current->category,
            'title' => $current->title,
            'lease_id' => $current->lease_id,
            'visible_to_tenant' => $current->visible_to_tenant,
            'version' => $this->latestVersion($current) + 1,
            'supersedes_document_id' => $current->id,
        ], $actor);

        // The old version leaves the tenant's list but stays retrievable by an
        // admin (AC-DOC-05). Hiding it from the resident is not the same as
        // deleting it, and nothing here deletes anything.
        if (! $current->is_signed) {
            $current->visible_to_tenant = false;
            $current->save();
        }

        $this->audit->record('document.replaced', $replacement, [
            'supersedes_document_id' => $current->id,
            'version' => $replacement->version,
        ]);

        return $replacement;
    }

    /**
     * Every version of a document, newest first.
     *
     * Rendered nested inside the vault list rather than behind an endpoint of
     * its own (GAP-2, D-12).
     *
     * @return Collection<int, Document>
     */
    public function versionsOf(Document $document): Collection
    {
        $chain = collect([$document]);
        $cursor = $document;

        // Walk backwards down the supersedes chain. Bounded, because a cycle
        // would otherwise hang a page render — and a cycle is exactly what a
        // bad import would produce.
        for ($i = 0; $i < 50; $i++) {
            $previous = $cursor->supersedes_document_id
                ? Document::find($cursor->supersedes_document_id)
                : null;

            if (! $previous || $chain->contains(fn (Document $d) => $d->id === $previous->id)) {
                break;
            }

            $chain->push($previous);
            $cursor = $previous;
        }

        return $chain->sortByDesc('version')->values();
    }

    /** The current version of whatever chain this document belongs to. */
    public function currentVersionOf(Document $document): Document
    {
        $cursor = $document;

        for ($i = 0; $i < 50; $i++) {
            $next = Document::where('supersedes_document_id', $cursor->id)->first();

            if (! $next) {
                break;
            }

            $cursor = $next;
        }

        return $cursor;
    }

    /**
     * A URL that stops working.  [AC-DOC-03]
     *
     * Defence in depth, not the defence itself: the route still requires the
     * session and still checks ownership. The signature is what makes a URL
     * pasted into a group chat useless five minutes later.
     */
    public function signedUrlFor(Document $document, string $routeName): string
    {
        return URL::temporarySignedRoute(
            $routeName,
            now()->addMinutes(self::SIGNED_URL_MINUTES),
            ['document' => $document->id],
        );
    }

    /** Absolute path on disk, for streaming through a controller. */
    public function pathFor(Document $document): string
    {
        $path = Storage::disk(self::DISK)->path($document->stored_path);

        if (! is_file($path)) {
            throw new InvalidArgumentException('That file is missing from storage.');
        }

        return $path;
    }

    /**
     * Write the file, re-encoding images on the way.
     *
     * @return array{0: string, 1: int, 2: string} path, bytes, mime
     */
    private function put(Tenant $tenant, UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        // A UUID, never the tenant's filename. Directory per tenant so a
        // misfiled document is at least visibly misfiled.
        $path = "documents/{$tenant->id}/".Str::uuid().'.'.$extension;

        $rebuilt = $this->reencodeImage($file, $mime);

        if ($rebuilt !== null) {
            Storage::disk(self::DISK)->put($path, $rebuilt);
        } else {
            Storage::disk(self::DISK)->putFileAs(
                dirname($path),
                $file,
                basename($path),
            );
        }

        return [$path, Storage::disk(self::DISK)->size($path), $mime];
    }

    /**
     * Rebuild a JPEG or PNG from its pixels.
     *
     * The output has no EXIF, so no GPS coordinates of the home someone
     * photographed, and no comment field to hide a payload in. Anything that
     * is not an image GD can decode is rejected here rather than stored — a
     * file claiming to be a JPEG that GD cannot read is not a JPEG.
     */
    private function reencodeImage(UploadedFile $file, string $mime): ?string
    {
        if (! in_array($mime, ['image/jpeg', 'image/png'], true)) {
            return null;
        }

        $image = @imagecreatefromstring((string) file_get_contents($file->getRealPath()));

        if ($image === false) {
            throw new InvalidArgumentException(
                'That image could not be read. Please re-save it and try again.'
            );
        }

        // PNG keeps its alpha channel; a scanned signature on transparency
        // would otherwise come back with a black background.
        if ($mime === 'image/png') {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        ob_start();
        $mime === 'image/png' ? imagepng($image, null, 6) : imagejpeg($image, null, 90);
        $bytes = (string) ob_get_clean();

        imagedestroy($image);

        return $bytes;
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_BYTES) {
            $tenths = intdiv((int) $file->getSize() * 10, 1048576);

            throw new InvalidArgumentException(sprintf(
                '%s is %d.%d MB. Documents have to be under %d MB.',
                $file->getClientOriginalName(),
                intdiv($tenths, 10),
                $tenths % 10,
                intdiv(self::MAX_BYTES, 1048576),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        // Both, and they have to agree. A .pdf that reports image/jpeg is
        // either a mistake or an attempt, and neither gets stored.
        if (! array_key_exists($extension, self::ACCEPTED)) {
            throw new InvalidArgumentException(
                'Only PDF, JPG, PNG and DOCX files can be filed here.'
            );
        }

        if (! in_array($mime, self::ACCEPTED[$extension], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s does not look like a %s file inside. Please check it and try again.',
                $file->getClientOriginalName(),
                strtoupper($extension),
            ));
        }
    }

    private function assertCategory(string $category): void
    {
        if (! array_key_exists($category, self::CATEGORIES)) {
            throw new InvalidArgumentException('Choose what sort of document this is.');
        }
    }

    private function latestVersion(Document $document): int
    {
        return (int) $this->versionsOf($document)->max('version');
    }
}
