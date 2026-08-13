<?php

namespace App\Http\Controllers\Portal;

use App\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\Document;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant document access.  [AC-AUTH-09, AC-AUTH-10, AC-DOC-01]
 *
 * WP-17 builds the vault properly — storage, signed download URLs, versioning.
 * What exists here is the access control, because AC-AUTH-09 and AC-AUTH-10 are
 * WP-04 acceptance criteria and need a real tenant-owned route to be tested
 * against rather than a hypothetical one.
 */
class DocumentController extends Controller
{
    use AuthorizesOwnership;

    public function index(Request $request): Response
    {
        // Scoped from the authenticated user's tenant_id, never from input
        // (FR-AUTH-05). There is no route parameter here to get wrong.
        $documents = Document::query()
            ->where('tenant_id', $request->user()->tenant_id)
            ->where('visible_to_tenant', true)
            ->orderByDesc('created_at')
            ->get(['id', 'title', 'category', 'created_at']);

        return Inertia::render('Portal/Documents', ['documents' => $documents]);
    }

    public function show(Request $request, Document $document): Response
    {
        // 404 on someone else's document, not 403 (I-9), and the attempt is
        // audited.
        $this->authorizeOwnership('view', $document);

        return Inertia::render('Portal/DocumentShow', [
            'document' => $document->only(['id', 'title', 'category', 'created_at']),
        ]);
    }
}
