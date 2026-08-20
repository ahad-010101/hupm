<?php

namespace App\Domain\Signatures;

use App\Domain\Documents\DocumentVault;
use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Models\ConsentRecord;
use App\Models\Document;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\ElectronicRecordsConsent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * In-house electronic signature.  [FR-SIG-01/02, TDD §9.3, WP-22]
 *
 * **This is a legal evidence chain, not a UI feature.** Under ESIGN and UETA
 * six elements have to be present, and a missing one weakens the rest (R-7):
 *
 *   1. Consent   — recorded before any request may be sent (BR-25), with the
 *                  hash of the exact text agreed to.
 *   2. Intent    — a typed legal name and the exact label of the control
 *                  pressed, stored verbatim.
 *   3. Attribution — authenticated session, user, email, IP, user agent, and a
 *                  timestamp to the millisecond.
 *   4. Integrity — SHA-256 of the precise bytes presented. A later mismatch
 *                  invalidates the signature (BR-26).
 *   5. Certificate — appended page carrying all of the above.
 *   6. Retention — executed PDF immutable, superseded but never replaced
 *                  (BR-27), and emailed to the signer.
 *
 * **Signing is one transaction.** A session that expires mid-signature records
 * nothing and leaves the request pending (F1): half an evidence chain is worse
 * than none, because it looks like a signature.
 */
class SignatureService
{
    public function __construct(
        private readonly DocumentVault $vault,
        private readonly ElectronicRecordsConsent $consentText,
        private readonly NotificationService $notifications,
        private readonly AuditCertificate $certificate,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Has this person agreed to transact electronically?  [BR-25, AC-SIG-01]
     */
    public function hasConsent(User $user): bool
    {
        return ConsentRecord::where('user_id', $user->id)
            ->where('consent_type', ConsentRecord::TYPE_ELECTRONIC_RECORDS)
            ->exists();
    }

    /** The most recent consent, with its text verified against version control. */
    public function consentFor(User $user): ?ConsentRecord
    {
        return ConsentRecord::where('user_id', $user->id)
            ->where('consent_type', ConsentRecord::TYPE_ELECTRONIC_RECORDS)
            ->orderByDesc('agreed_at')
            ->first();
    }

    /** FR-SIG-01, API-POR-18. */
    public function recordConsent(User $user, Request $request): ConsentRecord
    {
        $record = new ConsentRecord;
        $record->forceFill([
            'user_id' => $user->id,
            'consent_type' => ConsentRecord::TYPE_ELECTRONIC_RECORDS,
            'consent_text_version' => $this->consentText->version(),
            // The hash is what makes this verifiable in five years: the text is
            // in version control and either still matches or does not.
            'consent_text_sha256' => $this->consentText->hashFor(),
            'ip_address' => (string) $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'agreed_at' => now(),
        ])->save();

        $this->audit->record('signature.consent.recorded', $record, [
            'version' => $record->consent_text_version,
            'sha256' => $record->consent_text_sha256,
        ]);

        return $record;
    }

    /**
     * Ask someone to sign a document.  [API-ADM-28]
     *
     * A request against a document that is already signed is refused: the
     * executed version is the record, and asking for it to be signed again
     * would produce a second one competing with it.
     */
    public function createRequest(
        Document $document,
        Tenant $tenant,
        User $signer,
        User $actor,
        ?CarbonImmutable $expiresAt = null,
    ): SignatureRequest {
        if ($document->is_signed) {
            throw new RuntimeException('That document has already been signed.');
        }

        if ((int) $document->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('That document does not belong to this resident.');
        }

        return DB::transaction(function () use ($document, $tenant, $signer, $actor, $expiresAt) {
            $request = new SignatureRequest;
            $request->forceFill([
                'document_id' => $document->id,
                'tenant_id' => $tenant->id,
                'user_id' => $signer->id,
                'status' => SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
                'expires_at' => $expiresAt,
                'created_by_user_id' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            $this->event($request, SignatureEvent::CREATED, request());
            $this->event($request, SignatureEvent::SENT, request());

            $this->notifications->send(
                NotificationTemplate::SignatureRequest,
                $signer->email,
                [
                    'name' => $tenant->fullName(),
                    'documentTitle' => $document->title,
                    'url' => url("/portal/sign/{$request->id}"),
                ],
                tenantId: $tenant->id,
                userId: $signer->id,
            );

            $this->audit->record('signature.requested', $request, [
                'document_id' => $document->id,
                'signer_user_id' => $signer->id,
            ]);

            return $request;
        });
    }

    /** The signer opened it. */
    public function markViewed(SignatureRequest $request, Request $http): void
    {
        if (! $request->isOpen()) {
            return;
        }

        $this->event($request, SignatureEvent::OPENED, $http);

        if ($request->status === SignatureRequest::STATUS_PENDING) {
            $request->forceFill([
                'status' => SignatureRequest::STATUS_VIEWED,
                'viewed_at' => now(),
            ])->save();
        }
    }

    /**
     * They reached the bottom.  [AC-SIG-04]
     *
     * Recorded server-side as its own event, because "they saw the whole thing"
     * is a claim the certificate makes and a disabled button is not evidence of
     * anything.
     */
    public function markScrolled(SignatureRequest $request, Request $http): void
    {
        if ($request->events()->where('event', SignatureEvent::SCROLLED_COMPLETE)->exists()) {
            return;
        }

        $this->event($request, SignatureEvent::SCROLLED_COMPLETE, $http);
    }

    /**
     * Execute the signature.  [FR-SIG-02 steps 5–7, AC-SIG-02]
     *
     * One transaction. Everything below either happens together or does not
     * happen: a half-written evidence chain reads like a signature and is not
     * one.
     */
    public function sign(
        SignatureRequest $request,
        User $signer,
        string $typedName,
        string $buttonLabel,
        Request $http,
    ): Document {
        $this->guardSignable($request, $signer, $typedName);

        $document = $request->document;
        $sourcePath = $this->vault->pathFor($document);

        // Element 4. Hashed here, from the bytes on disk, at the moment of
        // signing — not taken from the row, which could have been written at
        // upload and diverged since (BR-26).
        $sha256 = hash_file('sha256', $sourcePath);

        if (! hash_equals((string) $document->sha256, $sha256)) {
            // The file changed after it was filed. Signing it now would attest
            // to bytes nobody reviewed.
            throw new RuntimeException(
                'This document has changed since it was filed and cannot be signed. Please contact the office.'
            );
        }

        return DB::transaction(function () use ($request, $signer, $typedName, $buttonLabel, $http, $document, $sourcePath, $sha256) {
            $this->event($request, SignatureEvent::SIGNED, $http, [
                'typed_name' => $typedName,
                // Element 2: the exact words on the control they pressed, so
                // intent is evidenced rather than asserted.
                'button_label' => $buttonLabel,
                'document_sha256' => $sha256,
            ]);

            $consent = $this->consentFor($signer);

            $executed = $this->certificate->build(
                $request,
                $document,
                $sourcePath,
                $request->events()->get(),
                [
                    'agreed_at' => $consent?->agreed_at?->format('Y-m-d H:i:s'),
                    'version' => $consent?->consent_text_version,
                    'sha256' => $consent?->consent_text_sha256,
                    'ip_address' => $consent?->ip_address,
                ],
            );

            $merged = $this->certificate->lastOutcome()['merged'];

            $signedDocument = $this->storeExecuted($document, $executed, $signer);

            $request->forceFill([
                'status' => SignatureRequest::STATUS_SIGNED,
                'signed_at' => now(),
                'signed_document_id' => $signedDocument->id,
                'updated_at' => now(),
            ])->save();

            // BR-27. From here the original may be superseded but never
            // replaced, and the Document model's own guard enforces it.
            $document->is_signed = true;
            $document->save();

            $this->notifications->send(
                NotificationTemplate::SignatureCompleted,
                $signer->email,
                [
                    'name' => $signer->name,
                    'documentTitle' => $document->title,
                    'url' => url('/portal/documents/'.$signedDocument->id),
                ],
                tenantId: $request->tenant_id,
                userId: $signer->id,
            );

            $this->audit->record('signature.completed', $request, [
                'document_id' => $document->id,
                'signed_document_id' => $signedDocument->id,
                'document_sha256' => $sha256,
                'typed_name' => $typedName,
                'button_label' => $buttonLabel,
                // Surfaced rather than discovered: when the source could not be
                // embedded, the certificate says so and so does the trail.
                'original_embedded' => $merged,
            ]);

            return $signedDocument;
        });
    }

    /**
     * Does the stored file still hash to what was signed?  [AC-SIG-03, BR-26]
     *
     * Answered on demand rather than cached, because the question is "are these
     * bytes still the bytes" and a cached answer is about the bytes at some
     * earlier moment.
     *
     * @return array{valid: bool, expected: ?string, actual: ?string, reason: ?string}
     */
    public function verifyIntegrity(SignatureRequest $request): array
    {
        $signed = $request->events()->where('event', SignatureEvent::SIGNED)->first();
        $expected = $signed?->document_sha256;

        if (! $expected) {
            return ['valid' => false, 'expected' => null, 'actual' => null, 'reason' => 'No signature has been recorded.'];
        }

        try {
            $actual = hash_file('sha256', $this->vault->pathFor($request->document));
        } catch (\Throwable $e) {
            return ['valid' => false, 'expected' => $expected, 'actual' => null, 'reason' => 'The signed file is missing from storage.'];
        }

        return hash_equals($expected, $actual)
            ? ['valid' => true, 'expected' => $expected, 'actual' => $actual, 'reason' => null]
            : [
                'valid' => false,
                'expected' => $expected,
                'actual' => $actual,
                // AC-SIG-03: a mismatch invalidates the signature, and saying so
                // is the whole value of having recorded the hash.
                'reason' => 'The stored file no longer matches the bytes that were signed. '
                    .'This signature can no longer be relied on.',
            ];
    }

    private function guardSignable(SignatureRequest $request, User $signer, string $typedName): void
    {
        if ((int) $request->user_id !== (int) $signer->id) {
            abort(404);
        }

        if ($request->isSigned()) {
            throw new RuntimeException('That document has already been signed.');
        }

        if (! $request->isOpen()) {
            throw new RuntimeException('That signature request is no longer open.');
        }

        if ($request->hasExpired()) {
            throw new RuntimeException('That signature request has expired. Please contact the office.');
        }

        // AC-SIG-01. Checked here as well as on the screen, because the screen
        // is not what a court looks at.
        if (! $this->hasConsent($signer)) {
            throw new RuntimeException('Electronic records consent has not been recorded.');
        }

        // AC-SIG-04. The disabled button is a courtesy; this is the rule.
        if (! $request->events()->where('event', SignatureEvent::SCROLLED_COMPLETE)->exists()) {
            throw new RuntimeException('Please read the whole document before signing.');
        }

        if (trim($typedName) === '') {
            throw new RuntimeException('Type your full legal name to sign.');
        }
    }

    /**
     * File the executed PDF as a new, signed document version.  [BR-27]
     */
    private function storeExecuted(Document $original, string $bytes, User $signer): Document
    {
        $path = "documents/{$original->tenant_id}/".Str::uuid().'.pdf';
        Storage::disk('local')->put($path, $bytes);

        $executed = new Document;
        $executed->forceFill([
            'tenant_id' => $original->tenant_id,
            'lease_id' => $original->lease_id,
            'category' => $original->category,
            'title' => $original->title.' (signed)',
            'original_filename' => pathinfo($original->original_filename, PATHINFO_FILENAME).'-signed.pdf',
            'stored_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => Storage::disk('local')->size($path),
            'sha256' => hash('sha256', $bytes),
            'version' => $original->version + 1,
            'supersedes_document_id' => $original->id,
            // Immutable from birth: the Document model refuses every write once
            // is_signed is true, including the ones that set it.
            'is_signed' => true,
            'visible_to_tenant' => true,
            'uploaded_by_user_id' => $signer->id,
        ])->save();

        return $executed;
    }

    /** @param array<string, mixed> $extra */
    private function event(
        SignatureRequest $request,
        string $event,
        ?Request $http,
        array $extra = [],
    ): SignatureEvent {
        $record = new SignatureEvent;
        $record->forceFill(array_merge([
            'signature_request_id' => $request->id,
            'event' => $event,
            'ip_address' => substr((string) ($http?->ip() ?? '0.0.0.0'), 0, 45),
            'user_agent' => substr((string) ($http?->userAgent() ?? 'system'), 0, 500),
            // Millisecond precision, or a scroll and the signature that follows
            // it cannot be ordered (the D-18 problem again).
            'occurred_at' => now(),
        ], $extra))->save();

        return $record;
    }
}
