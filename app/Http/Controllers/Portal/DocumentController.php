<?php

namespace App\Http\Controllers\Portal;

use App\Concerns\AuthorizesOwnership;
use App\Domain\Documents\DocumentVault;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A resident's own documents.  [FR-DOC-01, API-POR-15/16, AC-DOC-01…04]
 *
 * **Ownership failure is 404, never 403** (I-9, BR-20), and every attempt is
 * audited. A 403 would tell tenant A that document 41 exists and belongs to
 * somebody — which is most of what they were trying to find out.
 *
 * Files are never served by the web server. They live outside the document
 * root and come through this controller, behind a session, an ownership check
 * and a five-minute signature.
 */
class DocumentController extends Controller
{
    use AuthorizesOwnership;

    public function __construct(
        private readonly DocumentVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /** API-POR-15. */
    public function index(Request $request): Response
    {
        // Scoped from the authenticated user's tenant_id, never from input
        // (FR-AUTH-05, BR-20). There is no route parameter here to get wrong.
        $documents = Document::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('visible_to_tenant', true)
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Portal/Documents', [
            'documents' => $documents->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => DocumentVault::CATEGORIES[$document->category] ?? $document->category,
                // Long form in the portal, always (UI §8).
                'added_on' => $document->created_at?->format('j F Y'),
                'size_kb' => (int) ceil($document->size_bytes / 1024),
                'is_signed' => $document->is_signed,
                'download_url' => $this->vault->signedUrlFor($document, 'portal.documents.download'),
            ])->all(),
        ]);
    }

    /**
     * A single document.
     *
     * Kept alongside the list because WP-22 signs from here, and because
     * "is this the version I signed?" is a question with a place to be asked.
     */
    public function show(Request $request, Document $document): Response
    {
        $this->authorizeOwnership('view', $document);

        return Inertia::render('Portal/DocumentShow', [
            'document' => [
                'id' => $document->id,
                'title' => $document->title,
                'category' => DocumentVault::CATEGORIES[$document->category] ?? $document->category,
                'added_on' => $document->created_at?->format('j F Y'),
                'size_kb' => (int) ceil($document->size_bytes / 1024),
                'is_signed' => $document->is_signed,
                'download_url' => $this->vault->signedUrlFor($document, 'portal.documents.download'),
            ],
        ]);
    }

    /** API-POR-16. */
    public function download(Request $request, Document $document): BinaryFileResponse
    {
        $this->authorizeOwnership('view', $document);

        $this->audit->record('document.downloaded', $document, [
            'version' => $document->version,
            'by' => 'tenant',
        ]);

        return response()->file($this->vault->pathFor($document), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->original_filename).'"',
        ]);
    }
}
