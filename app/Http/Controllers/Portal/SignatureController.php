<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Documents\DocumentVault;
use App\Domain\Signatures\SignatureService;
use App\Http\Controllers\Controller;
use App\Models\SignatureRequest;
use App\Support\ElectronicRecordsConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signing, from the resident's side.  [FR-SIG-02, UI §3.6, API-POR-17…19]
 *
 * Ownership failure is 404 (I-9): a signature request belongs to one person,
 * and confirming that request 41 exists tells somebody about a document that
 * is not theirs.
 */
class SignatureController extends Controller
{
    /** Element 2 of the evidence chain. Stored verbatim, so it lives in one place. */
    public const BUTTON_LABEL = 'Sign and submit';

    public function __construct(
        private readonly SignatureService $signatures,
        private readonly ElectronicRecordsConsent $consentText,
        private readonly DocumentVault $vault,
    ) {}

    /** API-POR-17. */
    public function show(Request $request, SignatureRequest $signature): Response
    {
        $this->authorizeSigner($request, $signature);

        $this->signatures->markViewed($signature, $request);

        $document = $signature->document;

        return Inertia::render('Portal/Sign', [
            'request' => [
                'id' => $signature->id,
                'status' => $signature->status,
                'signed' => $signature->isSigned(),
                'expired' => $signature->hasExpired(),
                'signed_document_id' => $signature->signed_document_id,
            ],
            'document' => [
                'title' => $document->title,
                'category' => DocumentVault::CATEGORIES[$document->category] ?? $document->category,
                'download_url' => $this->vault->signedUrlFor($document, 'portal.documents.download'),
            ],
            'consent' => [
                'recorded' => $this->signatures->hasConsent($request->user()),
                'version' => $this->consentText->version(),
                'text' => $this->consentText->text(),
            ],
            // Sent to the browser so the control's label and the evidence can
            // never drift apart.
            'buttonLabel' => self::BUTTON_LABEL,
            'signerName' => $request->user()->name,
        ]);
    }

    /** API-POR-18. */
    public function consent(Request $request, SignatureRequest $signature): JsonResponse
    {
        $this->authorizeSigner($request, $signature);

        $request->validate([
            'agreed' => ['required', 'accepted'],
            'version' => ['required', 'string'],
        ], [
            'agreed.accepted' => 'You have to agree before you can sign electronically.',
        ]);

        // The version the browser echoes back must be the one we are serving,
        // or a stale tab would record consent to text nobody is showing.
        if ($request->string('version')->value() !== $this->consentText->version()) {
            return response()->json([
                'message' => 'The consent wording has been updated. Please reload the page and read it again.',
            ], 409);
        }

        $record = $this->signatures->recordConsent($request->user(), $request);

        return response()->json([
            'recorded_at' => $record->agreed_at?->toIso8601String(),
        ]);
    }

    /** Server-side record that the whole document was read (AC-SIG-04). */
    public function scrolled(Request $request, SignatureRequest $signature): JsonResponse
    {
        $this->authorizeSigner($request, $signature);

        $this->signatures->markScrolled($signature, $request);

        return response()->json(['recorded' => true]);
    }

    /** API-POR-19. */
    public function sign(Request $request, SignatureRequest $signature): RedirectResponse
    {
        $this->authorizeSigner($request, $signature);

        $validated = $request->validate([
            'typed_name' => ['required', 'string', 'max:150'],
            'scrolled_complete' => ['required', 'accepted'],
            'consent_acknowledged' => ['required', 'accepted'],
        ], [
            'typed_name.required' => 'Type your full legal name to sign.',
            'scrolled_complete.accepted' => 'Please read the whole document before signing.',
        ]);

        try {
            $signed = $this->signatures->sign(
                $signature,
                $request->user(),
                $validated['typed_name'],
                self::BUTTON_LABEL,
                $request,
            );
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['typed_name' => $e->getMessage()]);
        }

        return redirect()
            ->route('portal.documents.show', $signed->id)
            ->with('status', 'Signed. A copy with its certificate has been emailed to you.');
    }

    private function authorizeSigner(Request $request, SignatureRequest $signature): void
    {
        // 404, never 403. The existence of someone else's signature request is
        // not a fact to confirm (I-9, BR-20).
        abort_unless(
            (int) $signature->user_id === (int) $request->user()->id
                && (int) $signature->tenant_id === (int) $request->user()->tenant_id,
            404,
        );
    }
}
