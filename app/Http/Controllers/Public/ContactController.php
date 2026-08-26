<?php

namespace App\Http\Controllers\Public;

use App\Domain\Content\PageContent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactRequest;
use App\Mail\ContactMessage;
use App\Support\BusinessCalendar;
use App\Support\Settings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Contact Us.  [FR-PUB-01, AC-PUB-02, API-PUB-05/06, UI §1]
 *
 * Blade and a server-side post, deliberately (D-05). No JSON endpoint, no
 * client-side validation, nothing to break with JavaScript off — the form
 * re-renders with its errors and whatever was typed still in it.
 *
 * **Nothing here reads the tenant tables.** A stranger asking about a vacancy
 * and a resident chasing a repair send the same message, and the response says
 * the same thing to both: confirming that an email address belongs to a
 * resident would be a tenancy disclosed to whoever guessed it (AC-PUB-01).
 */
class ContactController extends Controller
{
    public function __construct(
        private readonly Settings $settings,
        private readonly BusinessCalendar $calendar,
        private readonly PageContent $content,
    ) {}

    /** API-PUB-05. */
    public function show(): View
    {
        return view('public.contact', [
            // The trap's opening timestamp. Rendered into the form so the
            // request can tell a person from something that posted instantly.
            'startedAt' => time(),
            'canSend' => $this->officeEmail() !== '',
            // Editable copy above the form (WP-36). The form itself stays in
            // code: the honeypot, the timing trap, the CSRF token and the
            // no-office-email fallback were all reviewed as a piece, and none
            // of them is content.
            'sections' => $this->content->forSlug('contact')['sections'],
        ]);
    }

    /** API-PUB-06. Rate limited to three an hour per address. */
    public function store(ContactRequest $request): RedirectResponse
    {
        $office = $this->officeEmail();

        if ($office === '') {
            // No configured address means nowhere to deliver. Saying so is the
            // only honest answer — a thank-you page over a message that went
            // nowhere is worse than no form at all.
            return redirect()->route('public.contact')
                ->withInput($request->except('message'))
                ->withErrors(['message' => 'The contact form is not available at the moment. '
                    .'Please telephone the office.']);
        }

        $data = $request->validated();

        Mail::to($office)->send(new ContactMessage(
            senderName: $data['name'],
            senderEmail: $data['email'],
            senderPhone: $data['phone'] ?? null,
            subjectLine: $data['subject'],
            body: $data['message'],
            submittedAt: $this->calendar->now()->format('j F Y, g:ia'),
        ));

        // The message itself is never logged: it is a stranger's words, and it
        // may carry an address or a phone number that has no business sitting
        // in a log file for fourteen days.
        Log::info('Public contact form submitted.', ['subject' => $data['subject']]);

        // Named route rather than back(): back() follows the Referer header,
        // and a browser or privacy extension that strips it would land the
        // sender on the home page having seen no confirmation at all.
        return redirect()->route('public.contact')
            ->with('status', 'Thank you — your message has been sent. '
                .'We aim to reply within one working day.');
    }

    private function officeEmail(): string
    {
        return trim($this->settings->string('company.email'));
    }
}
