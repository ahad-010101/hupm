<?php

namespace App\Mail;

use App\Domain\Notifications\NotificationTemplate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message from the public contact form, to the office.  [FR-PUB-01]
 *
 * Its own Mailable rather than a thirteenth {@see NotificationTemplate}
 * case: the twelve templates are all things the system tells a resident, on a
 * schedule, from data it owns. This is a stranger writing in, and it travels in
 * the opposite direction.
 *
 * The visitor's address goes in `replyTo`, never in `from`. Sending as them
 * would fail SPF and DKIM alignment against our own domain (WP-03) and land the
 * message in a spam folder — which is the same as losing it, except nobody
 * knows.
 */
class ContactMessage extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $senderName,
        public string $senderEmail,
        public ?string $senderPhone,
        public string $subjectLine,
        public string $body,
        public string $submittedAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            // Prefixed so a rule in the office mailbox can file these without
            // reading them, and so it is obvious this did not come from a
            // resident's authenticated account.
            subject: 'Website enquiry: '.$this->subjectLine,
            replyTo: [new Address($this->senderEmail, $this->senderName)],
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contact_message');
    }
}
