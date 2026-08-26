<?php

use App\Mail\ContactMessage;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/*
|--------------------------------------------------------------------------
| The public contact form  [WP-18, FR-PUB-01, AC-PUB-02, API-PUB-05/06]
|--------------------------------------------------------------------------
|
| Anyone on the internet may post to this, and it must work with JavaScript
| switched off (D-05) — which rules out every hosted captcha and leaves two
| server-side traps: a field a person never sees, and a clock.
|
| Neither asks a real visitor to prove anything. That matters more here than it
| sounds: the people most likely to be stopped by a puzzle captcha are the same
| people most likely to be chasing a repair from an old phone.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();

    app(Settings::class)->set('company.email', 'office@example.test');
    app(Settings::class)->set('company.phone', '(404) 555-0100');

    RateLimiter::clear('ip:127.0.0.1');
});

/** A message a person could plausibly have typed. */
function enquiry(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jordan Miller',
        'email' => 'jordan@example.test',
        'phone' => '(404) 555-0177',
        'subject' => 'Two-bedroom availability',
        'message' => 'Do you have anything with two bedrooms coming up in Decatur? I hold a voucher.',
        'website' => '',
        // Filled in long enough ago to have been read.
        'started_at' => (string) (time() - 30),
    ], $overrides);
}

/*
 |--------------------------------------------------------------------------
 | The happy path
 |--------------------------------------------------------------------------
 */

it('API-PUB-06 sends the message to the office', function () {
    $this->post('/contact', enquiry())
        ->assertRedirect()
        ->assertSessionHas('status');

    Mail::assertSent(ContactMessage::class, function (ContactMessage $mail) {
        return $mail->senderEmail === 'jordan@example.test'
            && $mail->subjectLine === 'Two-bedroom availability'
            && $mail->hasTo('office@example.test');
    });
});

it('replies to the sender without pretending to be them', function () {
    $this->post('/contact', enquiry());

    Mail::assertSent(ContactMessage::class, function (ContactMessage $mail) {
        // Sending as the visitor would fail SPF and DKIM against our own domain
        // and land in a spam folder, which is the same as losing it. The
        // mailable never sets a from address at all, so the configured one is
        // used and alignment holds.
        return $mail->hasReplyTo('jordan@example.test') && $mail->from === [];
    });
});

it('confirms in words the sender can act on', function () {
    $this->followingRedirects()
        ->post('/contact', enquiry())
        ->assertOk()
        ->assertSee('Message sent')
        ->assertSee('within one working day');
});

/*
 |--------------------------------------------------------------------------
 | AC-PUB-02 — abuse
 |--------------------------------------------------------------------------
 */

it('AC-PUB-02 rate limits to three an hour from one address', function () {
    foreach (range(1, 3) as $attempt) {
        $this->post('/contact', enquiry(['subject' => "Enquiry {$attempt}"]))
            ->assertSessionHasNoErrors();
    }

    $this->post('/contact', enquiry(['subject' => 'One too many']))
        ->assertSessionHasErrors('message');

    Mail::assertSentTimes(ContactMessage::class, 3);
});

it('AC-PUB-02 tells a throttled visitor to telephone instead', function () {
    foreach (range(1, 3) as $attempt) {
        $this->post('/contact', enquiry());
    }

    $this->followingRedirects()
        ->post('/contact', enquiry())
        ->assertSee('telephone the office');

    // A limit that leaves somebody with no way to reach us is not a limit, it
    // is an outage.
    $this->get('/contact')->assertSee('(404) 555-0100');
});

it('AC-PUB-02 drops anything that fills the hidden field', function () {
    $this->post('/contact', enquiry(['website' => 'https://example.test/spam']))
        ->assertSessionHasErrors('website');

    Mail::assertNothingSent();
});

it('AC-PUB-02 drops anything submitted faster than the page can be read', function () {
    $this->post('/contact', enquiry(['started_at' => (string) time()]))
        ->assertSessionHasErrors('message');

    Mail::assertNothingSent();
});

it('AC-PUB-02 treats a tampered timestamp as too fast rather than trusting it', function () {
    foreach (['0', '-1', 'yesterday', (string) (time() + 600)] as $value) {
        $this->post('/contact', enquiry(['started_at' => $value]))
            ->assertSessionHasErrors('message');
    }

    Mail::assertNothingSent();
});

it('asks a visitor to resend rather than losing a message from a stale tab', function () {
    $this->post('/contact', enquiry(['started_at' => (string) (time() - 90000)]))
        ->assertSessionHasErrors('message');

    expect(session('errors')->first('message'))->toContain('send it again');
});

/*
 |--------------------------------------------------------------------------
 | Validation
 |--------------------------------------------------------------------------
 */

it('requires the fields it cannot act without', function (string $field) {
    $this->post('/contact', enquiry([$field => '']))->assertSessionHasErrors($field);

    Mail::assertNothingSent();
})->with(['name', 'email', 'subject', 'message']);

it('needs an address it can actually reply to', function () {
    $this->post('/contact', enquiry(['email' => 'not-an-address']))
        ->assertSessionHasErrors('email');
});

it('keeps what was typed when it refuses', function () {
    $this->followingRedirects()
        ->post('/contact', enquiry(['email' => 'not-an-address']))
        ->assertSee('Jordan Miller')
        ->assertSee('Nothing you typed has been lost');
});

it('says what to do next in every refusal', function () {
    // UI §8: an error that does not tell you what to do is a dead end.
    $this->post('/contact', enquiry(['message' => 'help']));

    expect(session('errors')->first('message'))->toContain('a little more');
});

/*
 |--------------------------------------------------------------------------
 | When there is nowhere to deliver
 |--------------------------------------------------------------------------
 */

it('offers the telephone instead of a form that goes nowhere', function () {
    // [GATE] company.email unset. A form that accepts a message and drops it is
    // worse than no form: the sender believes they have been in touch.
    app(Settings::class)->set('company.email', '');

    $this->get('/contact')
        ->assertOk()
        ->assertSee('not available yet')
        ->assertSee('(404) 555-0100')
        ->assertDontSee('Send message');
});

it('refuses a posted message when there is nowhere to send it', function () {
    app(Settings::class)->set('company.email', '');

    $this->post('/contact', enquiry())->assertSessionHasErrors('message');

    Mail::assertNothingSent();
});

/*
 |--------------------------------------------------------------------------
 | Privacy
 |--------------------------------------------------------------------------
 */

it('warns against sending bank details, and never asks for them', function () {
    $response = $this->get('/contact')->assertOk();

    $response->assertSee('never ask for them');

    // I-5 in spirit: no field on a public page invites an account number.
    // Checked as field names rather than as loose words — the page says the
    // phrase "bank account or card details" on purpose, and searching for
    // "card" would flag its own warning.
    foreach (['account_number', 'routing', 'card_number', 'cvv', 'iban', 'sort_code'] as $forbidden) {
        $response->assertDontSee('name="'.$forbidden.'"', escape: false);
    }
});

it('sends an emergency to the telephone rather than into a queue', function () {
    // A message about a burst pipe sitting unread in an inbox overnight is the
    // failure this page exists to prevent.
    $this->get('/contact')
        ->assertSee('Is this an emergency?')
        ->assertSee('needs a telephone call, not this form');
});
