<?php

namespace App\Domain\Notifications;

use App\Models\Notice;
use App\Models\NoticeAttachment;
use App\Models\NoticeRecipient;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\HtmlSanitiser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Composing and sending a notice.  [FR-NTF-02, UI §3.10, WP-20]
 *
 * Three things this class is careful about, in order of how badly they go
 * wrong:
 *
 *   1. **The body is sanitised before it is stored**, never at render. What is
 *      in the column is already safe, so a second place that renders it later
 *      cannot forget (TDD §6.2).
 *   2. **One recipient row per resident** (AC-NTF-04), each with its own
 *      delivery status. "We sent it to everyone" is not an answer to "did Mrs
 *      Pouros get it".
 *   3. **A resident with no email is `not_deliverable`, not a failure** — a
 *      known state that surfaces to an admin who then has to post it (Q-4,
 *      AC-NTF-03). Silence there is how someone is evicted over a notice they
 *      were never sent.
 */
class NoticeService
{
    public const MAX_ATTACHMENTS = 3;

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    /** Deliberately narrower than the vault's: a notice carries a letter. */
    public const ACCEPTED = [
        'pdf' => ['application/pdf'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
    ];

    private const DISK = 'local';

    public function __construct(
        private readonly HtmlSanitiser $sanitiser,
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Who a given audience resolves to, right now.
     *
     * Drives the live count on the compose screen and the send itself, so the
     * number an admin types to confirm is the number that gets written.
     *
     * @param  array<int|string>  $reference
     * @return Collection<int, Tenant>
     */
    public function audienceFor(string $type, array $reference = []): Collection
    {
        $active = Tenant::query()->where('status', 'active');

        return match ($type) {
            Notice::AUDIENCE_ALL => $active->orderBy('last_name')->get(),

            Notice::AUDIENCE_TENANT => $active
                ->whereIn('id', array_slice(array_map('intval', $reference), 0, 1))
                ->get(),

            Notice::AUDIENCE_SELECTED => $active
                ->whereIn('id', array_map('intval', $reference))
                ->orderBy('last_name')
                ->get(),

            // Everyone with an active lease on a unit at these properties. A
            // notice about the water being off is addressed to the building,
            // not to a list someone maintains by hand.
            Notice::AUDIENCE_PROPERTY => $active
                ->whereHas('leases', fn ($q) => $q
                    ->where('status', 'active')
                    ->whereHas('unit', fn ($u) => $u->whereIn('property_id', array_map('intval', $reference))))
                ->orderBy('last_name')
                ->get(),

            default => throw new InvalidArgumentException("'{$type}' is not an audience."),
        };
    }

    /**
     * Write the notice, file its attachments, and send it.
     *
     * @param  array{subject: string, body: string, audience_type: string, audience_ref?: array<int|string>}  $input
     * @param  list<UploadedFile>  $attachments
     */
    public function send(array $input, array $attachments, User $actor): Notice
    {
        $audience = $this->audienceFor($input['audience_type'], $input['audience_ref'] ?? []);

        if ($audience->isEmpty()) {
            throw new InvalidArgumentException(
                'That audience has nobody in it. Check the selection and try again.'
            );
        }

        foreach ($attachments as $file) {
            $this->assertAcceptable($file);
        }

        if (count($attachments) > self::MAX_ATTACHMENTS) {
            throw new InvalidArgumentException(
                'A notice can carry up to '.self::MAX_ATTACHMENTS.' attachments.'
            );
        }

        // Sanitised here, once, on the way in.
        $body = $this->sanitiser->clean($input['body']);

        $notice = DB::transaction(function () use ($input, $body, $audience, $attachments, $actor) {
            $notice = new Notice;
            $notice->forceFill([
                'subject' => $input['subject'],
                'body' => $body,
                'audience_type' => $input['audience_type'],
                'audience_ref' => $input['audience_ref'] ?? null,
                'recipient_count' => $audience->count(),
                'sent_by_user_id' => $actor->id,
                'sent_at' => now(),
                'created_at' => now(),
            ])->save();

            foreach ($attachments as $file) {
                $this->fileAttachment($notice, $file);
            }

            foreach ($audience as $tenant) {
                $recipient = new NoticeRecipient;
                $recipient->forceFill([
                    'notice_id' => $notice->id,
                    'tenant_id' => $tenant->id,
                    'email' => $tenant->email,
                    'delivery_status' => 'queued',
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->save();
            }

            return $notice;
        });

        // Dispatch outside the transaction: a queued job that starts before the
        // commit would look for rows that are not there yet.
        $this->deliver($notice, $audience);

        $this->audit->record('notice.sent', $notice, [
            'subject' => $notice->subject,
            'audience_type' => $notice->audience_type,
            'recipients' => $notice->recipient_count,
            'attachments' => count($attachments),
        ]);

        return $notice;
    }

    /**
     * @param  Collection<int, Tenant>  $audience
     */
    private function deliver(Notice $notice, Collection $audience): void
    {
        foreach ($audience as $tenant) {
            $logId = $this->notifications->send(
                NotificationTemplate::NoticeIssued,
                $tenant->email,
                [
                    'name' => $tenant->fullName(),
                    'subject' => $notice->subject,
                    // Already sanitised. The email template renders it raw and
                    // says so, because sanitising again at render would let the
                    // stored record and the sent message differ.
                    'body' => $notice->body,
                    'url' => url('/portal/notices'),
                ],
                tenantId: $tenant->id,
            );

            $status = DB::table('notification_logs')->where('id', $logId)->value('status');

            NoticeRecipient::where('notice_id', $notice->id)
                ->where('tenant_id', $tenant->id)
                ->update([
                    // `not_deliverable` is a resident with no address on file,
                    // which an admin has to act on rather than a failure to
                    // retry (Q-4, AC-NTF-03).
                    'delivery_status' => $status === 'not_deliverable' ? 'not_deliverable' : 'queued',
                    'sent_at' => $status === 'not_deliverable' ? null : now(),
                    'updated_at' => now(),
                ]);
        }
    }

    private function fileAttachment(Notice $notice, UploadedFile $file): NoticeAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $path = "notices/{$notice->id}/".Str::uuid().'.'.$extension;

        Storage::disk(self::DISK)->putFileAs(dirname($path), $file, basename($path));

        $attachment = new NoticeAttachment;
        $attachment->forceFill([
            'notice_id' => $notice->id,
            // Metadata only. The path is a UUID; a filename never touches it.
            'original_filename' => substr($file->getClientOriginalName(), 0, 255),
            'stored_path' => $path,
            'mime_type' => (string) $file->getMimeType(),
            'size_bytes' => Storage::disk(self::DISK)->size($path),
            'sha256' => hash_file('sha256', Storage::disk(self::DISK)->path($path)),
            'created_at' => now(),
        ])->save();

        return $attachment;
    }

    private function assertAcceptable(UploadedFile $file): void
    {
        if ($file->getSize() > self::MAX_ATTACHMENT_BYTES) {
            throw new InvalidArgumentException(sprintf(
                '%s is over the %d MB limit for a notice attachment.',
                $file->getClientOriginalName(),
                intdiv(self::MAX_ATTACHMENT_BYTES, 1048576),
            ));
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        // Extension and contents both, and they have to agree — the same rule
        // the vault applies, for the same reason (TDD §6.2).
        if (! array_key_exists($extension, self::ACCEPTED)
            || ! in_array($mime, self::ACCEPTED[$extension], true)) {
            throw new InvalidArgumentException(sprintf(
                '%s is not a PDF, JPG or PNG.',
                $file->getClientOriginalName(),
            ));
        }
    }

    /** Absolute path, for streaming through a controller. */
    public function pathFor(NoticeAttachment $attachment): string
    {
        $path = Storage::disk(self::DISK)->path($attachment->stored_path);

        if (! is_file($path)) {
            throw new InvalidArgumentException('That attachment is missing from storage.');
        }

        return $path;
    }

    public function isRecipient(Notice $notice, int $tenantId): bool
    {
        return NoticeRecipient::where('notice_id', $notice->id)
            ->where('tenant_id', $tenantId)
            ->exists();
    }
}
