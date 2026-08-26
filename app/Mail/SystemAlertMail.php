<?php

namespace App\Mail;

use App\Domain\Notifications\SystemAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One operational alert, to an administrator.  [TDD §10, WP-31]
 *
 * Its own Mailable rather than a thirteenth NotificationTemplate case: that
 * enum is the twelve resident-facing triggers named in FR-NTF-01, and these
 * travel the other way to a different audience.
 *
 * @param  array<string, string|int>  $detail
 */
class SystemAlertMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, string|int> $detail */
    public function __construct(
        public SystemAlert $alert,
        public array $detail = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->alert->subject());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.system_alert');
    }
}
