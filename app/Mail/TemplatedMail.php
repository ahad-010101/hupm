<?php

namespace App\Mail;

use App\Domain\Notifications\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One Mailable for all twelve triggers.
 *
 * Twelve near-identical Mailable classes would differ only in which Blade file
 * they name — the variation that matters is in the templates and the subject
 * line, both of which the enum already owns.
 */
class TemplatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** @param array<string, mixed> $data */
    public function __construct(
        public NotificationTemplate $template,
        public string $subjectLine,
        public array $data = [],
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: $this->template->view(),
            with: $this->data,
        );
    }
}
