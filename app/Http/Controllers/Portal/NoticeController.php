<?php

namespace App\Http\Controllers\Portal;

use App\Domain\Notifications\NoticeService;
use App\Http\Controllers\Controller;
use App\Models\Notice;
use App\Models\NoticeAttachment;
use App\Models\NoticeRecipient;
use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Notices a resident has received.  [FR-NTF-02, API-POR-20]
 *
 * Scoped from `notice_recipients`, never from a notice id in the URL: a notice
 * exists for the people it was addressed to and nobody else. Reading someone
 * else's returns 404 (I-9), not 403 — the existence of a notice to another
 * resident is not something to confirm.
 */
class NoticeController extends Controller
{
    public function __construct(
        private readonly NoticeService $notices,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): Response
    {
        $tenantId = $request->user()->tenant_id;

        $notices = Notice::query()
            ->whereHas('recipients', fn ($q) => $q->where('tenant_id', $tenantId))
            ->with('attachments')
            ->orderByDesc('sent_at')
            ->get();

        return Inertia::render('Portal/Notices', [
            'notices' => $notices->map(fn (Notice $notice) => [
                'id' => $notice->id,
                'subject' => $notice->subject,
                // Sanitised server-side at composition (TDD §6.2). This is the
                // only place the product renders admin HTML.
                'body' => $notice->body,
                // Long form in the portal, always (UI §8).
                'sent_on' => $notice->sent_at?->format('j F Y'),
                'attachments' => $notice->attachments->map(fn (NoticeAttachment $a) => [
                    'id' => $a->id,
                    'filename' => $a->original_filename,
                    'size_kb' => (int) ceil($a->size_bytes / 1024),
                    'url' => route('portal.notices.attachment', [
                        'notice' => $notice->id,
                        'attachment' => $a->id,
                    ]),
                ])->all(),
            ])->all(),
        ]);
    }

    public function attachment(Request $request, Notice $notice, NoticeAttachment $attachment): BinaryFileResponse
    {
        $tenantId = $request->user()->tenant_id;

        // Membership of the recipient list is the whole authorisation. 404 on
        // failure, and audited, exactly as for a document (I-9, BR-20).
        if ($attachment->notice_id !== $notice->id || ! $this->notices->isRecipient($notice, (int) $tenantId)) {
            $this->audit->record('auth.ownership.denied', $notice, [
                'ability' => 'view-notice-attachment',
                'actor_tenant_id' => $tenantId,
                'path' => $request->path(),
            ]);

            abort(404);
        }

        return response()->file($this->notices->pathFor($attachment), [
            'Content-Disposition' => 'attachment; filename="'.addslashes($attachment->original_filename).'"',
        ]);
    }
}
