<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Signatures\SignatureService;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\SignatureRequest;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Signature requests, from the office side.  [API-ADM-28/29]
 *
 * The integrity check is on this screen rather than buried in a console
 * command: "is this signature still good" is a question somebody asks under
 * pressure, usually the day before a hearing.
 */
class SignatureController extends Controller
{
    public function __construct(private readonly SignatureService $signatures) {}

    /** API-ADM-29. */
    public function index(): Response
    {
        $requests = SignatureRequest::query()
            ->with(['document:id,title,category', 'tenant:id,first_name,last_name', 'signer:id,name,email'])
            ->orderByRaw("FIELD(status, 'pending', 'viewed', 'signed', 'expired', 'declined')")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return Inertia::render('Admin/Signatures/Index', [
            'requests' => $requests->map(fn (SignatureRequest $r) => [
                'id' => $r->id,
                'document' => $r->document?->title,
                'document_id' => $r->document_id,
                'tenant' => $r->tenant?->fullName(),
                'tenant_id' => $r->tenant_id,
                'signer' => $r->signer?->name,
                'status' => $r->status,
                'sent_on' => $r->sent_at?->format('j M Y'),
                'signed_on' => $r->signed_at?->format('j M Y, H:i'),
                'expired' => $r->hasExpired(),
                'signed_document_id' => $r->signed_document_id,
                // Verified on read. A cached answer would be about the bytes at
                // some earlier moment, which is not the question (AC-SIG-03).
                'integrity' => $r->isSigned() ? $this->signatures->verifyIntegrity($r) : null,
            ])->all(),
            'documents' => Document::query()
                ->where('is_signed', false)
                ->whereNull('supersedes_document_id')
                ->with('tenant:id,first_name,last_name')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(['id', 'title', 'tenant_id'])
                ->map(fn (Document $d) => [
                    'id' => $d->id,
                    'title' => $d->title,
                    'tenant_id' => $d->tenant_id,
                    'tenant' => $d->tenant?->fullName(),
                ]),
            'signers' => User::where('role', 'tenant')->whereNotNull('tenant_id')
                ->get(['id', 'name', 'email', 'tenant_id'])
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'name' => $u->name,
                    'tenant_id' => $u->tenant_id,
                ]),
        ]);
    }

    /** API-ADM-28. */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'document_id' => ['required', 'exists:documents,id'],
            'user_id' => ['required', 'exists:users,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ], [
            'expires_at.after' => 'A request that has already expired cannot be signed.',
        ]);

        $document = Document::findOrFail($validated['document_id']);
        $signer = User::findOrFail($validated['user_id']);

        try {
            $this->signatures->createRequest(
                $document,
                Tenant::findOrFail($document->tenant_id),
                $signer,
                $request->user(),
                isset($validated['expires_at']) ? CarbonImmutable::parse($validated['expires_at']) : null,
            );
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['document_id' => $e->getMessage()]);
        }

        return back()->with('status', 'Signature request sent.');
    }
}
