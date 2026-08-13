<?php

namespace App\Jobs;

use App\Domain\Notifications\NotificationService;
use App\Domain\Notifications\NotificationTemplate;
use App\Mail\TemplatedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Dispatch one notification.  [AC-NTF-02]
 *
 * Three attempts with exponential backoff, then `failed` with the provider's
 * error text retained. The retry exists because the provider being briefly
 * unreachable is ordinary — the host has one cron entry draining the queue each
 * minute, so a transient failure costs minutes, not a lost message.
 *
 * On the final failure the log row is updated by failed(), not by the last
 * attempt: a job that dies mid-send must still leave the row saying so.
 */
class SendNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** Exponential: 10s, then 60s. Long enough for a provider blip to clear. */
    public array $backoff = [10, 60];

    /** @param array<string, mixed> $data */
    public function __construct(
        public int $logId,
        public NotificationTemplate $template,
        public string $email,
        public string $subject,
        public array $data = [],
    ) {}

    public function handle(NotificationService $notifications): void
    {
        $mailable = new TemplatedMail($this->template, $this->subject, $this->data);

        Mail::to($this->email)->send($mailable);

        $notifications->markOutcome($this->logId, 'sent', [
            'sent_at' => now(),
            // Resend returns its id in this header. Kept because a bounce
            // webhook arrives hours later carrying only that id, and without it
            // the delivery event cannot be matched to the row.
            'provider_message_id' => $this->providerMessageId($mailable),
            'error' => null,
        ]);
    }

    public function failed(?Throwable $e): void
    {
        app(NotificationService::class)->markOutcome($this->logId, 'failed', [
            // Message only, never a trace: a trace can carry request data, and
            // nothing resembling bank detail may reach a log (I-5).
            'error' => $e?->getMessage(),
        ]);
    }

    private function providerMessageId(TemplatedMail $mailable): ?string
    {
        $id = $mailable->messageId ?? null;

        if ($id === null) {
            return null;
        }

        return substr(trim($id, '<>'), 0, 100);
    }
}
