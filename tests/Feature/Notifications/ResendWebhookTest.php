<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| Resend delivery webhook  [WP-03, D-17]
|--------------------------------------------------------------------------
|
| A 200 from the send API means the provider accepted the message, not that it
| arrived. Without these events notification_logs would read `sent` for an
| address that does not exist.
|
| The endpoint is unauthenticated by necessity, so the signature is the only
| control on it.
|
*/

uses(RefreshDatabase::class);

const WEBHOOK_SECRET = 'whsec_'.'dGVzdHNlY3JldGZvcmh1cG13ZWJob29rcw==';

beforeEach(function () {
    config()->set('services.resend.webhook_secret', WEBHOOK_SECRET);

    $this->logId = DB::table('notification_logs')->insertGetId([
        'channel' => 'email',
        'template' => 'rent_due',
        'subject' => 'Rent due',
        'recipient' => 'tenant@example.test',
        'status' => 'sent',
        'provider_message_id' => 'msg_abc123',
        'created_at' => now(),
    ]);
});

/** Build a request signed the way Svix signs it. */
function signedPost(array $payload, ?string $secret = WEBHOOK_SECRET, ?int $timestamp = null): TestResponse
{
    $body = json_encode($payload);
    $id = 'msg_2abc';
    $timestamp ??= time();

    $key = base64_decode(substr($secret, 6), true);
    $signature = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

    return test()->call('POST', '/webhooks/resend', [], [], [], [
        'HTTP_SVIX_ID' => $id,
        'HTTP_SVIX_TIMESTAMP' => (string) $timestamp,
        'HTTP_SVIX_SIGNATURE' => "v1,{$signature}",
        'CONTENT_TYPE' => 'application/json',
    ], $body);
}

it('marks a message delivered', function () {
    signedPost(['type' => 'email.delivered', 'data' => ['email_id' => 'msg_abc123']])
        ->assertNoContent();

    expect(DB::table('notification_logs')->find($this->logId)->status)->toBe('delivered');
});

it('marks a bounce and keeps the reason', function () {
    signedPost([
        'type' => 'email.bounced',
        'data' => ['email_id' => 'msg_abc123', 'reason' => 'Mailbox does not exist'],
    ])->assertNoContent();

    $row = DB::table('notification_logs')->find($this->logId);

    expect($row->status)->toBe('bounced')
        ->and($row->error)->toBe('Mailbox does not exist');
});

it('does not treat a delay as a failure', function () {
    // The provider is still retrying. Marking it failed sends an admin chasing
    // a message that is about to arrive.
    signedPost(['type' => 'email.delivery_delayed', 'data' => ['email_id' => 'msg_abc123']])
        ->assertNoContent();

    $row = DB::table('notification_logs')->find($this->logId);

    expect($row->status)->toBe('sent')
        ->and($row->error)->toContain('still retrying');
});

it('keeps the status truthful for a spam complaint', function () {
    // The schema has no `complained` state, and the message *was* delivered.
    // Recording it as bounced would be a lie in a table admins rely on.
    signedPost(['type' => 'email.complained', 'data' => ['email_id' => 'msg_abc123']])
        ->assertNoContent();

    $row = DB::table('notification_logs')->find($this->logId);

    expect($row->status)->toBe('sent')
        ->and($row->error)->toContain('spam');
});

it('rejects an unsigned request', function () {
    $this->postJson('/webhooks/resend', ['type' => 'email.delivered', 'data' => ['email_id' => 'msg_abc123']])
        ->assertUnauthorized();

    expect(DB::table('notification_logs')->find($this->logId)->status)->toBe('sent');
});

it('rejects a request signed with the wrong secret', function () {
    signedPost(
        ['type' => 'email.bounced', 'data' => ['email_id' => 'msg_abc123']],
        'whsec_'.base64_encode('an-attackers-guess'),
    )->assertUnauthorized();

    expect(DB::table('notification_logs')->find($this->logId)->status)->toBe('sent');
});

it('rejects a replayed request', function () {
    // A valid signature captured yesterday must not still work.
    signedPost(
        ['type' => 'email.bounced', 'data' => ['email_id' => 'msg_abc123']],
        timestamp: time() - 3600,
    )->assertUnauthorized();
});

it('records an audit row when it rejects a request', function () {
    $this->postJson('/webhooks/resend', ['type' => 'email.delivered']);

    expect(DB::table('audit_logs')->where('action', 'notification.webhook.rejected')->count())->toBe(1);
});

it('fails closed when no secret is configured', function () {
    // An unconfigured secret must never mean "accept everything" — that is how
    // an open endpoint reaches production.
    config()->set('services.resend.webhook_secret', null);

    signedPost(['type' => 'email.delivered', 'data' => ['email_id' => 'msg_abc123']])
        ->assertStatus(503);
});

it('acknowledges an event for a message it does not know', function () {
    // A send from the provider dashboard, or a replay after a restore. 200 so
    // the provider stops retrying forever.
    signedPost(['type' => 'email.delivered', 'data' => ['email_id' => 'msg_unknown']])
        ->assertOk();
});

it('the packages own webhook route is unreachable on this application, with or without a secret', function () {
    // resend/resend-laravel registers POST /resend/webhook unconditionally and
    // verifies signatures only when resend.webhook.secret is truthy — a blank
    // secret disables the CHECK, not the route.
    //
    // Requiring the secret was the old mitigation, and it was one cleared
    // environment variable away from reopening an unauthenticated endpoint
    // that dispatches events from attacker-controlled input. It did reach
    // production that way: on 2 November 2026 the live site answered this
    // route with no secret set.
    //
    // The guard is now structural. config/resend.php binds the package's route
    // group to an RFC 2606 `.invalid` domain, so the route cannot match a
    // request to our own hostname at all — Laravel 404s before the controller
    // is constructed. Asserting BOTH secret states is the point of the test:
    // the mitigation must not depend on the secret.
    $payload = ['type' => 'email.delivered', 'data' => ['email_id' => 'msg_abc123']];

    config()->set('resend.webhook.secret', null);
    $this->postJson('/resend/webhook', $payload)->assertNotFound();

    config()->set('resend.webhook.secret', WEBHOOK_SECRET);
    $this->postJson('/resend/webhook', $payload)->assertNotFound();
});

it('our own handler is still routable', function () {
    // The guard above must close the dependency's route and nothing else.
    // Without this, a typo in the domain constraint could silently take out
    // bounce tracking and the suite would still be green.
    signedPost(['type' => 'email.delivered', 'data' => ['email_id' => 'msg_abc123']])
        ->assertNoContent();
});
