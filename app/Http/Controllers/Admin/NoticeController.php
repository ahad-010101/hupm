<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Notifications\NoticeService;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeAttachment;
use App\Models\Property;
use App\Models\Tenant;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Notices.  [FR-NTF-02, UI §3.10, API-ADM-30/31]
 *
 * There is no update and no delete, and that is not an omission: a notice is
 * the record that a resident was told something, and a record that can be
 * edited afterwards is not evidence of anything (AC-NTF-06).
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeService $notices,
        private readonly AuditLogger $audit,
    ) {}

    /** API-ADM-30. */
    public function index(): Response
    {
        return Inertia::render('Admin/Notices/Index', [
            'notices' => Notice::query()
                ->with('sentBy:id,name')
                ->withCount([
                    'recipients',
                    'recipients as delivered_count' => fn ($q) => $q->whereIn('delivery_status', ['sent', 'delivered']),
                    'recipients as problem_count' => fn ($q) => $q->whereIn('delivery_status', ['bounced', 'failed', 'not_deliverable']),
                ])
                ->orderByDesc('sent_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (Notice $notice) => [
                    'id' => $notice->id,
                    'subject' => $notice->subject,
                    'audience_type' => $notice->audience_type,
                    'recipients' => $notice->recipients_count,
                    'delivered' => $notice->delivered_count,
                    'problems' => $notice->problem_count,
                    'sent_on' => $notice->sent_at?->format('j M Y, H:i'),
                    'sent_by' => $notice->sentBy?->name,
                ])
                ->all(),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Admin/Notices/Compose', [
            'tenants' => Tenant::where('status', 'active')->orderBy('last_name')
                ->get(['id', 'first_name', 'last_name', 'email'])
                ->map(fn (Tenant $t) => [
                    'id' => $t->id,
                    'name' => $t->fullName(),
                    // Shown so an admin can see who will need a posted copy
                    // before they send, not afterwards (Q-4).
                    'has_email' => filled($t->email),
                ]),
            'properties' => Property::orderBy('name')->get(['id', 'name']),
            'maxAttachments' => NoticeService::MAX_ATTACHMENTS,
            'maxMegabytes' => intdiv(NoticeService::MAX_ATTACHMENT_BYTES, 1048576),
        ]);
    }

    /**
     * The live recipient count behind the compose screen.
     *
     * Resolved by the same method that resolves the send, so the number an
     * admin types to confirm is the number that will actually be written.
     */
    public function audience(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience_type' => ['required', Rule::in(['tenant', 'property', 'selected', 'all'])],
            'audience_ref' => ['array'],
            'audience_ref.*' => ['integer'],
        ]);

        $audience = $this->notices->audienceFor(
            $validated['audience_type'],
            $validated['audience_ref'] ?? [],
        );

        return response()->json([
            'count' => $audience->count(),
            'without_email' => $audience->filter(fn (Tenant $t) => blank($t->email))->count(),
        ]);
    }

    /** API-ADM-30 (POST). */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:50000'],
            'audience_type' => ['required', Rule::in(['tenant', 'property', 'selected', 'all'])],
            'audience_ref' => ['array'],
            'audience_ref.*' => ['integer'],
            'attachments' => ['array', 'max:'.NoticeService::MAX_ATTACHMENTS],
            'attachments.*' => ['file', 'mimes:pdf,jpg,jpeg,png', 'max:'.intdiv(NoticeService::MAX_ATTACHMENT_BYTES, 1024)],
            // UI §3.10: sending to everyone requires typing the count. A
            // mis-clicked audience selector is otherwise indistinguishable from
            // a deliberate all-residents send until the replies start.
            'confirm_count' => ['nullable', 'integer'],
        ]);

        $audience = $this->notices->audienceFor(
            $validated['audience_type'],
            $validated['audience_ref'] ?? [],
        );

        if ($validated['audience_type'] === Notice::AUDIENCE_ALL
            && (int) ($validated['confirm_count'] ?? 0) !== $audience->count()) {
            throw ValidationException::withMessages([
                'confirm_count' => "Type {$audience->count()} to confirm you are writing to every resident.",
            ]);
        }

        try {
            $notice = $this->notices->send(
                $validated,
                array_values($request->file('attachments', [])),
                $request->user(),
            );
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['body' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.notices.show', $notice)
            ->with('status', "Sent to {$notice->recipient_count} ".
                ($notice->recipient_count === 1 ? 'resident.' : 'residents.'));
    }

    /** API-ADM-31. Everything AC-NTF-05 asks to still be visible afterwards. */
    public function show(Notice $notice): Response
    {
        return Inertia::render('Admin/Notices/Show', [
            'notice' => [
                'id' => $notice->id,
                'subject' => $notice->subject,
                // Sanitised at composition; safe to render (TDD §6.2).
                'body' => $notice->body,
                'audience_type' => $notice->audience_type,
                'sent_on' => $notice->sent_at?->format('j F Y, H:i'),
                'sent_by' => $notice->sentBy?->name,
                'recipient_count' => $notice->recipient_count,
            ],
            'recipients' => $notice->recipients()
                ->with('tenant:id,first_name,last_name')
                ->get()
                ->map(fn ($recipient) => [
                    'id' => $recipient->id,
                    'tenant' => $recipient->tenant?->fullName(),
                    'tenant_id' => $recipient->tenant_id,
                    'email' => $recipient->email,
                    'status' => $recipient->delivery_status,
                    'sent_on' => $recipient->sent_at?->format('j M Y, H:i'),
                    'error' => $recipient->error,
                ])
                ->all(),
            'attachments' => $notice->attachments->map(fn (NoticeAttachment $a) => [
                'id' => $a->id,
                'filename' => $a->original_filename,
                'size_kb' => (int) ceil($a->size_bytes / 1024),
                'url' => route('admin.notices.attachment', ['notice' => $notice->id, 'attachment' => $a->id]),
            ])->all(),
        ]);
    }

    public function attachment(Notice $notice, NoticeAttachment $attachment): BinaryFileResponse
    {
        abort_unless($attachment->notice_id === $notice->id, 404);

        return response()->file($this->notices->pathFor($attachment), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->original_filename).'"',
        ]);
    }
}
