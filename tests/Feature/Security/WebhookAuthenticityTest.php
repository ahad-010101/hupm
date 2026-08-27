<?php

use App\Domain\Notifications\AlertDetector;
use App\Domain\Notifications\SystemAlert;
use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| Webhook authenticity  [WP-34, TDD §6.2, A08]
|--------------------------------------------------------------------------
|
| Two endpoints take a POST from the public internet with no session and no
| CSRF token, because a provider has neither. The signature is the whole of
| their authentication.
|
| The DoD asks for "401 and alerts". The 401 was already there; the alert was
| not. Both endpoints wrote an audit row and a log line, which is a record
| rather than a nudge — and the failure this guards against is silent by
| nature. If the signature key is rotated in the Authorize.Net dashboard and
| not here, every settlement notice is refused and nothing looks broken: the
| daily reconciliation is authoritative (R-6), so the money still arrives, just
| later and with nobody aware the notices stopped.
|
| So a sixth system alert was added to WP-31's five.
*/

uses(RefreshDatabase::class);

/*
 |--------------------------------------------------------------------------
 | Rejection
 |--------------------------------------------------------------------------
 */

it('WP-34 rejects an Authorize.Net webhook with an invalid signature', function () {
    $response = $this->call(
        'POST',
        '/webhooks/authorize-net',
        server: ['HTTP_X_ANET_SIGNATURE' => 'sha512=deadbeef'],
        content: json_encode(['eventType' => 'net.authorize.payment.authcapture.created']),
    );

    expect($response->status())->toBe(401);
});

it('WP-34 rejects an Authorize.Net webhook with no signature at all', function () {
    // The scanner case. An endpoint that treats "absent" differently from
    // "wrong" is an endpoint with an unauthenticated path through it.
    $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created'])
        ->assertStatus(401);
});

it('WP-34 rejects a Resend webhook with an invalid signature', function () {
    config(['services.resend.webhook_secret' => 'whsec_'.base64_encode(str_repeat('k', 24))]);

    $this->call(
        'POST',
        '/webhooks/resend',
        server: [
            'HTTP_SVIX_ID' => 'msg_1',
            'HTTP_SVIX_TIMESTAMP' => (string) now()->timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,not-the-signature',
        ],
        content: json_encode(['type' => 'email.delivered']),
    )->assertStatus(401);
});

it('WP-34 records a rejection in the audit log, which is the half that survives', function () {
    $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);

    $row = AuditLog::query()->where('action', 'payment.webhook.rejected')->sole();

    // The audit trail is immutable and append-only, while the log rotates on a
    // fortnight — so this is the record that can still answer the question in
    // three months.
    expect($row->changes['reason'] ?? null)->toBe('invalid signature');
});

it('I-5 keeps the rejected payload out of the audit row', function () {
    /*
     | A forged webhook body is attacker-controlled text, and recording it
     | wholesale would mean an unauthenticated stranger can write arbitrary
     | content into the audit trail — which is immutable, so it cannot be
     | taken out again.
    */
    $this->call(
        'POST',
        '/webhooks/authorize-net',
        server: ['HTTP_X_ANET_SIGNATURE' => 'sha512=deadbeef'],
        content: json_encode([
            'eventType' => 'net.authorize.payment.authcapture.created',
            'payload' => ['accountNumber' => '900012345678', 'routingNumber' => '061000052'],
        ]),
    );

    $encoded = json_encode(AuditLog::query()->where('action', 'payment.webhook.rejected')->sole()->changes);

    expect(str_contains((string) $encoded, '900012345678'))
        ->toBeFalse('A bank number from a forged webhook reached the audit log.');
});

/*
 |--------------------------------------------------------------------------
 | And alerts
 |--------------------------------------------------------------------------
 */

it('WP-34 says nothing about a single rejected webhook', function () {
    /*
     | Deliberately quiet. A public endpoint is scanned continuously and one
     | 401 is the system working exactly as designed. Alerting on it teaches
     | the recipient to filter the sender, which is the failure mode WP-31's
     | whole cooldown design exists to avoid — and a filtered alert is worse
     | than no alert, because everyone believes it is working.
    */
    $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);

    expect(alertsNow())->not->toContain(SystemAlert::WebhookRejected);
});

it('WP-34 alerts once rejections cross the threshold in an hour', function () {
    foreach (range(1, AlertDetector::REJECTED_WEBHOOK_THRESHOLD) as $ignored) {
        $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);
    }

    expect(alertsNow())->toContain(SystemAlert::WebhookRejected);
});

it('WP-34 counts both endpoints together', function () {
    /*
     | One key rotation usually breaks both — they are rotated on the same
     | afternoon by the same person — and somebody probing one has found the
     | other. Counting them separately means two half-signals, neither of which
     | crosses its own threshold.
    */
    config(['services.resend.webhook_secret' => 'whsec_'.base64_encode(str_repeat('k', 24))]);

    $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);

    foreach (range(1, 2) as $ignored) {
        $this->call('POST', '/webhooks/resend', server: [
            'HTTP_SVIX_ID' => 'msg_'.$ignored,
            'HTTP_SVIX_TIMESTAMP' => (string) now()->timestamp,
            'HTTP_SVIX_SIGNATURE' => 'v1,not-the-signature',
        ], content: json_encode(['type' => 'email.delivered']));
    }

    expect(alertsNow())->toContain(SystemAlert::WebhookRejected);
});

it('WP-34 forgets rejections older than an hour', function () {
    foreach (range(1, AlertDetector::REJECTED_WEBHOOK_THRESHOLD + 2) as $ignored) {
        $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);
    }

    // A burst last week is history, not a live condition. An alert that never
    // clears is an alert nobody can act on.
    AuditLog::query()->update(['created_at' => now()->subDay()]);

    expect(alertsNow())->not->toContain(SystemAlert::WebhookRejected);
});

it('WP-34 gives the alert a shorter cooldown than the money alerts', function () {
    /*
     | Six hours rather than twenty-four. A mismatched key drops every
     | settlement notice until somebody fixes it, and unlike a stale
     | reconciliation this condition can start and stop several times in a day
     | while somebody is working on the dashboard.
    */
    expect(SystemAlert::WebhookRejected->cooldownHours())->toBe(6)
        ->and(SystemAlert::ReconciliationStale->cooldownHours())->toBe(24);
});

it('WP-34 does not call a configuration slip an attack', function () {
    // By far the likeliest cause is a rotated key. A subject line that cries
    // breach for a configuration slip is a subject line that stops being read.
    $subject = SystemAlert::WebhookRejected->subject();

    expect(str_contains(strtolower($subject), 'attack'))->toBeFalse()
        ->and(str_contains(strtolower(SystemAlert::WebhookRejected->action()), 'signature key'))
        ->toBeTrue('The alert does not name the first thing to check.');
});

/** The alerts currently true, as enum cases. */
function alertsNow(): array
{
    return array_map(
        fn (array $detected) => $detected['alert'],
        app(AlertDetector::class)->detect(),
    );
}
