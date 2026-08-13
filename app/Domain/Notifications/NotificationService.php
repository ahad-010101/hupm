<?php

namespace App\Domain\Notifications;

use App\Jobs\SendNotification;
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
     * @return int  The notification_logs row id.
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
    }

    /**
     * Rows an admin needs to act on: nothing was delivered and nothing will be
     * unless a person intervenes (AC-NTF-03).
     *
     * @return \Illuminate\Support\Collection<int, object>
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
