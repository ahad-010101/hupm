<?php

namespace App\Http\Requests\Public;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Validator;

/**
 * The public contact form.  [FR-PUB-01, AC-PUB-02, UI §1]
 *
 * Anyone may submit this, so everything below assumes the sender is hostile
 * until the message looks like a person wrote it.
 *
 * Bot protection has to work with **no JavaScript** (D-05), which rules out
 * every hosted captcha. Two server-side traps do the work instead: a field a
 * person cannot see and a bot will fill, and a timestamp that catches a form
 * posted faster than anybody could read it. Neither asks a real visitor to
 * prove anything, which matters — a resident chasing a repair should not have
 * to identify traffic lights.
 */
class ContactRequest extends BaseFormRequest
{
    /** A person needs longer than this to read the form and type a message. */
    private const MINIMUM_SECONDS = 3;

    /** Older than this and it is a stale tab, not a submission. */
    private const MAXIMUM_SECONDS = 7200;

    /**
     * Where a rejected submission goes.
     *
     * Named rather than the default back(), which follows the Referer header —
     * a browser or privacy extension that strips it would send somebody to the
     * home page with their errors in a session they never see.
     */
    protected $redirectRoute = 'public.contact';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'subject' => ['required', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:4000'],
            // The trap. Never rendered visibly, so anything in it is a bot.
            'website' => ['prohibited'],
            'started_at' => ['required', 'string'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('started_at')) {
                return;
            }

            $started = (int) $this->input('started_at');
            $elapsed = time() - $started;

            // A tampered or missing timestamp is treated as "too fast" rather
            // than as a separate case: both mean the form was not filled in by
            // somebody reading it.
            if ($started <= 0 || $elapsed < self::MINIMUM_SECONDS) {
                $validator->errors()->add('message', 'That was sent faster than it can be read. Please try again.');

                return;
            }

            if ($elapsed > self::MAXIMUM_SECONDS) {
                $validator->errors()->add(
                    'message',
                    'This page has been open a long while. Please send it again so nothing is lost.',
                );
            }
        });
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            // Every message says what to do next (UI §8). A visitor who trips
            // the honeypot is far likelier to be a confused browser autofill
            // than an attacker, so it must not read like an accusation.
            'website.prohibited' => 'Something went wrong sending that. Please try again, or telephone the office.',
            'message.min' => 'Please say a little more, so we know how to help.',
            'email.email' => 'Enter an email address we can reply to.',
        ];
    }
}
