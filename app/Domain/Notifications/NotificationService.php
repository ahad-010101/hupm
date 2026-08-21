<?php

namespace App\Domain\Notifications;

use App\Jobs\SendNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The single entry point for outbound notification.  [FR-NTF-01, WP-03]
 *
 * Nothing else in the application calls Mail:: directly. Every send is logged
 * before it is attempted and again after it resolves, so a message that
 * vanishes still leaves a row saying it was tried — silence is the failure
 * mode this table exists to eliminate (AC-NTF-01).
 *
 * Provider-agnostic on purpose. Resend is the transport (D-17) but nothing here
 * names it: the driver is a config line. NG-4 puts SMS out of scope only
 * because A2P 10DLC registration is outside project control, so the layer is
 * shaped to accept a second channel as configuration rather than a rewrite.
 */
class NotificationService
{
    /**
     * Queue a notification for a recipient.
     *
     * @param  array<string, mixed>  $data  Template variables. Never put bank
     *                                      detail here (I-5), and never the
     *                                      housing authority portion (I-4).
     * @return int The notification_logs row id.
     */
    public function send(
        NotificationTemplate $template,
        ?string $email,
        array $data = [],
        ?int $tenantId = null,
        ?int $userId = null,
    ): int {
        $subject = $template->subject($data);

        // AC-NTF-03: a tenant with no email address is a known, expected state
        // (Q-4 is unanswered and some of the 26 have none). It resolves to a
        // logged `not_deliverable` row that admin can see — never an exception,
        // never a silent no-op. Admin still has to act; the system's job is to
        // make sure someone knows.
        if ($email === null || trim($email) === '') {
            return $this->log($template, null, $subject, 'not_deliverable', $tenantId, $userId, [
                'error' => 'No email address on file for this recipient.',
            ]);
        }

        $logId = $this->log($template, $email, $subject, 'queued', $tenantId, $userId, [
            'queued_at' => now(),
        ]);

        SendNotification::dispatch($logId, $template, $email, $subject, $data);

        return $logId;
    }

    /**
     * Record the outcome of a dispatch attempt.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function markOutcome(int $logId, string $status, array $attributes = []): void
    {
        DB::table('notification_logs')->where('id', $logId)->update(array_merge([
            'status' => $status,
        ], $attributes));

        $this->mirrorToNoticeRecipient($logId, $status, $attributes);
    }

    /**
     * Carry the outcome across to the notice recipient row, if there is one.
     *
     * Every path that learns something about a message — the send job, the
     * bounce webhook, a failure after three attempts — comes through here, so
     * this is the one place the two tables can be kept in step. Doing it at the
     * call sites instead is how they drifted apart in the first place: the
     * recipient list sat on `queued` for every resident while the log knew
     * perfectly well what had happened (AC-NTF-04/05).
     *
     * `delivered_at` is only ever set by a provider event. We know when we
     * handed a message over; only the provider knows when it arrived.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function mirrorToNoticeRecipient(int $logId, string $status, array $attributes): void
    {
        $update = ['delivery_status' => $status, 'updated_at' => now()];

        foreach (['sent_at', 'provider_message_id', 'error'] as $column) {
            if (array_key_exists($column, $attributes)) {
                $update[$column] = $attributes[$column];
            }
        }

        if ($status === 'delivered') {
            $update['delivered_at'] = now();
        }

        DB::table('notice_recipients')
            ->where('notification_log_id', $logId)
            ->update($update);
    }

    /**
     * Rows an admin needs to act on: nothing was delivered and nothing will be
     * unless a person intervenes (AC-NTF-03).
     *
     * @return Collection<int, object>
     */
    public function needingAttention()
    {
        return DB::table('notification_logs')
            ->whereIn('status', ['not_deliverable', 'failed', 'bounced'])
            ->orderByDesc('created_at')
            ->get();
    }

    /** @param array<string, mixed> $extra */
    private function log(
        NotificationTemplate $template,
        ?string $recipient,
        string $subject,
        string $status,
        ?int $tenantId,
        ?int $userId,
        array $extra = [],
    ): int {
        return DB::table('notification_logs')->insertGetId(array_merge([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'channel' => 'email',
            'template' => $template->value,
            'subject' => $subject,
            'recipient' => $recipient,
            'status' => $status,
            'created_at' => now(),
        ], $extra));
    }
}
