<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Documents\DocumentVault;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Tenant;
use App\Support\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The vault, from the office side.  [FR-DOC-01/02, API-ADM-26/27]
 *
 * Version history is nested in this list rather than behind an endpoint of its
 * own (GAP-2, D-12): a superseded lease is only interesting next to the lease
 * that replaced it.
 */
class DocumentController extends Controller
{
    public function __construct(
        private readonly DocumentVault $vault,
        private readonly AuditLogger $audit,
    ) {}

    /** API-ADM-26. */
    public function index(Request $request): Response
    {
        $tenantId = $request->integer('tenant_id') ?: null;

        // Only the head of each chain. The older versions hang off it.
        $current = Document::query()
            ->with('tenant:id,first_name,last_name')
            ->whereNotIn('id', Document::query()
                ->whereNotNull('supersedes_document_id')
                ->pluck('supersedes_document_id'))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($request->string('category')->value(), fn ($q, $c) => $q->where('category', $c))
            ->orderByDesc('created_at')
            ->get();

        return Inertia::render('Admin/Documents/Index', [
            'documents' => $current->map(fn (Document $document) => [
                ...$this->summary($document),
                'tenant' => $document->tenant?->fullName(),
                'tenant_id' => $document->tenant_id,
                'previous' => $this->vault->versionsOf($document)
                    ->reject(fn (Document $version) => $version->id === $document->id)
                    ->map(fn (Document $version) => $this->summary($version))
                    ->values()
                    ->all(),
            ])->all(),
            'categories' => DocumentVault::CATEGORIES,
            'tenants' => Tenant::orderBy('last_name')->get(['id', 'first_name', 'last_name'])
                ->map(fn (Tenant $t) => ['id' => $t->id, 'name' => $t->fullName()]),
            'filters' => ['tenant_id' => $tenantId, 'category' => $request->string('category')->value()],
            'maxMegabytes' => intdiv(DocumentVault::MAX_BYTES, 1048576),
        ]);
    }

    /** API-ADM-26 (POST). */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'tenant_id' => ['required', 'exists:tenants,id'],
            'category' => ['required', Rule::in(array_keys(DocumentVault::CATEGORIES))],
            'title' => ['nullable', 'string', 'max:200'],
            'visible_to_tenant' => ['boolean'],
            // Belt and braces: the vault checks MIME against extension too, and
            // is the check that actually decides.
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:'.intdiv(DocumentVault::MAX_BYTES, 1024)],
        ]);

        try {
            $this->vault->store(
                Tenant::findOrFail($validated['tenant_id']),
                $request->file('file'),
                [
                    'category' => $validated['category'],
                    'title' => $validated['title'] ?? null,
                    'visible_to_tenant' => $request->boolean('visible_to_tenant', true),
                ],
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('status', 'Document filed.');
    }

    /** FR-DOC-02. A replacement is a new version, never an overwrite. */
    public function replace(Request $request, Document $document): RedirectResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,docx', 'max:'.intdiv(DocumentVault::MAX_BYTES, 1024)],
        ]);

        try {
            $replacement = $this->vault->replace($document, $request->file('file'), $request->user());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('status', "Replaced. This is now version {$replacement->version}; the previous one is still on file.");
    }

    /**
     * API-ADM-27. Streamed through here, never from the web server.
     *
     * The route carries a signature with a five-minute life (AC-DOC-03) *and*
     * requires an admin session. The signature is what makes a copied URL
     * useless; the session is what stops it being useful in the first place.
     */
    public function download(Document $document): BinaryFileResponse
    {
        $this->audit->record('document.downloaded', $document, [
            'version' => $document->version,
            'by' => 'admin',
        ]);

        return response()->file($this->vault->pathFor($document), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($document->original_filename).'"',
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(Document $document): array
    {
        return [
            'id' => $document->id,
            'title' => $document->title,
            'category' => DocumentVault::CATEGORIES[$document->category] ?? $document->category,
            'category_key' => $document->category,
            'filename' => $document->original_filename,
            'version' => $document->version,
            'size_kb' => (int) ceil($document->size_bytes / 1024),
            // First twelve characters is enough to compare two by eye; the whole
            // hash is in the audit row for anyone who needs it.
            'sha256_short' => substr($document->sha256, 0, 12),
            'is_signed' => $document->is_signed,
            'visible_to_tenant' => $document->visible_to_tenant,
            'added_on' => $document->created_at?->format('j M Y'),
            'download_url' => $this->vault->signedUrlFor($document, 'admin.documents.download'),
        ];
    }
}
