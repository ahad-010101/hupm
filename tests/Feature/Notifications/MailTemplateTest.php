<?php

use App\Domain\Notifications\NotificationTemplate;
use App\Mail\TemplatedMail;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Mail templates  [WP-03, FR-NTF-01, UI §8]
|--------------------------------------------------------------------------
|
| The twelve triggers, plus the tone rules that are easy to breach and costly
| when breached.
|
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    // Templates read company details through the view composer, so the
    // configured values have to be present for these to reflect reality.
    $this->seed(Database\Seeders\SettingsSeeder::class);
    app(App\Support\Settings::class)->flush();
});

/** Every variable any template might read, so rendering never fails on a missing key. */
function templateData(): array
{
    return [
        'name' => 'Denise Carter',
        'url' => 'https://hupm.test/portal',
        'expiresOn' => '20 September 2026',
        'amount' => '$300.00',
        'balance' => '$125.00',
        'dueDate' => '1 September 2026',
        'date' => '3 September 2026',
        'processing' => true,
        'reason' => 'Insufficient funds',
        'fee' => '$35.00',
        'graceEnd' => '6 September 2026',
        'phone' => '(404) 555-0100',
        'ticketNumber' => 'MR-000123',
        'category' => 'Plumbing',
        'status' => 'Scheduled',
        'note' => 'A contractor will attend.',
        'scheduledFor' => '9 September 2026',
        'body' => '<p>Building works begin Monday.</p>',
        'documentTitle' => 'Lease Renewal 2026',
        'signedAt' => '4 September 2026',
        'eventType' => 'Winter Storm Warning',
        'headline' => 'Snow and ice expected.',
        'expiresAt' => '5 September 2026',
        'subject' => 'Scheduled maintenance',
    ];
}

it('renders all twelve templates', function (NotificationTemplate $template) {
    $mail = new TemplatedMail($template, $template->subject(templateData()), templateData());

    $rendered = $mail->render();

    expect($rendered)->toContain('Heads Up Enterprises')
        ->and(trim($rendered))->not->toBe('');
})->with(NotificationTemplate::cases());

it('covers every trigger named in FR-NTF-01', function () {
    // Twelve triggers: welcome/set password, password reset, rent due, payment
    // receipt, payment returned, late fee posted, Management Review,
    // maintenance status change, notice issued, signature request, signature
    // completed, weather alert.
    expect(NotificationTemplate::cases())->toHaveCount(12);
});

it('UI §8 never tells a tenant a payment failed', function (NotificationTemplate $template) {
    $rendered = (new TemplatedMail($template, $template->subject(templateData()), templateData()))->render();

    // ACH takes 2–5 business days. A tenant told their payment "failed" pays
    // twice. A bank return is described as returned, never failed.
    expect(strtolower($rendered))->not->toContain('payment failed')
        ->and(strtolower($rendered))->not->toContain('failed payment');
})->with(NotificationTemplate::cases());

it('UI §8 keeps delinquency wording neutral and actionable', function () {
    $rendered = (new TemplatedMail(
        NotificationTemplate::ManagementReview,
        NotificationTemplate::ManagementReview->subject(),
        templateData(),
    ))->render();

    expect($rendered)->toContain('Please contact management to arrange payment.');

    foreach (['delinquent', 'overdue', 'violation', 'demand', 'eviction', 'immediately'] as $accusatory) {
        expect(strtolower($rendered))->not->toContain($accusatory);
    }
});

it('I-4 never names or implies the housing authority in tenant email', function (NotificationTemplate $template) {
    $rendered = strtolower(
        (new TemplatedMail($template, $template->subject(templateData()), templateData()))->render()
    );

    foreach (['housing authority', 'section 8', 'voucher', 'hap ', 'subsidy', 'subsidised', 'subsidized'] as $forbidden) {
        expect($rendered)->not->toContain($forbidden);
    }
})->with(NotificationTemplate::cases());

it('describes an ACH payment as processing, not pending', function () {
    $rendered = (new TemplatedMail(
        NotificationTemplate::PaymentReceipt,
        NotificationTemplate::PaymentReceipt->subject(),
        templateData(),
    ))->render();

    expect($rendered)->toContain('processing')
        ->and($rendered)->toContain('2 to 5 business days');
});

it('loads no external stylesheet, webfont or image', function (NotificationTemplate $template) {
    $rendered = (new TemplatedMail($template, $template->subject(templateData()), templateData()))->render();

    // Email clients strip most CSS and many tenants read with images off.
    expect($rendered)->not->toContain('<link')
        ->and($rendered)->not->toContain('<img')
        ->and($rendered)->not->toContain('fonts.');
})->with(NotificationTemplate::cases());

it('carries the emergency number in every message', function () {
    // [GATE] company.emergency_phone is unset, so the block is omitted rather
    // than rendering an empty label. Once set, it appears on every email.
    app(App\Support\Settings::class)->set('company.emergency_phone', '(404) 555-0199');
    app(App\Support\Settings::class)->flush();

    $rendered = (new TemplatedMail(
        NotificationTemplate::RentDue,
        NotificationTemplate::RentDue->subject(),
        templateData(),
    ))->render();

    expect($rendered)->toContain('(404) 555-0199')
        ->and($rendered)->toContain('call 911 first');
});
