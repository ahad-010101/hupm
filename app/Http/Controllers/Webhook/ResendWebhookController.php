<?php

namespace App\Http\Controllers\Webhook;

use App\Support\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Delivery events from Resend.  [WP-03, D-17]
 *
 * A send returning 200 means the provider accepted the message, not that it
 * arrived. Bounces land here minutes or hours later, and without them
 * `notification_logs` would show `sent` for an address that does not exist —
 * which is exactly the silent failure FR-NTF-01 exists to prevent.
 *
 * The endpoint is unauthenticated by necessity, so the signature is the only
 * thing standing between this and anyone who can guess the URL. Resend signs
 * with Svix headers; verification is HMAC-SHA256 over
 * "{svix-id}.{svix-timestamp}.{body}".
 */
class ResendWebhookController
{
    /** Reject anything older than this, so a captured request cannot be replayed. */
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, AuditLogger $audit): Response
    {
        $secret = config('services.resend.webhook_secret');

        if (! $secret) {
            // Failing closed. An unconfigured secret must not mean "accept
            // everything" — that is how an open endpoint reaches production.
            return response('Webhook secret not configured', 503);
        }

        if (! $this->signatureIsValid($request, $secret)) {
            $audit->record('notification.webhook.rejected', null, [
                'reason' => 'invalid signature',
                'svix_id' => $request->header('svix-id'),
            ]);

            return response('Invalid signature', 401);
        }

        $type = (string) $request->input('type');
        $providerId = (string) $request->input('data.email_id');

        if ($providerId === '') {
            return response('No email id', 422);
        }

        $row = DB::table('notification_logs')->where('provider_message_id', $providerId)->first();

        if (! $row) {
            // Not an error: the provider may deliver an event for a message we
            // never logged (a manual send from their dashboard, or a replay
            // after a database restore). 200 stops them retrying forever.
            return response('Unknown message', 200);
        }

        $this->applyEvent($row->id, $type, $request);

        return response('', 204);
    }

    private function applyEvent(int $logId, string $type, Request $request): void
    {
        $update = match ($type) {
            'email.sent' => ['status' => 'sent', 'sent_at' => now()],
            'email.delivered' => ['status' => 'delivered'],
            'email.bounced' => [
                'status' => 'bounced',
                'error' => substr((string) $request->input('data.reason', 'Bounced'), 0, 1000),
            ],
            // A delay is not a failure yet — the provider is still retrying.
            // Recording it as failed would send an admin chasing a message that
            // is about to arrive.
            'email.delivery_delayed' => ['error' => 'Delivery delayed; the provider is still retrying.'],
            // The schema has no `complained` state, and neither `bounced` nor
            // `failed` is true — the message was delivered and the recipient
            // objected. Status stays accurate; the complaint is recorded so
            // deliverability monitoring (WP-31) can act on it.
            'email.complained' => ['error' => 'Recipient marked this message as spam.'],
            default => null,
        };

        if ($update !== null) {
            DB::table('notification_logs')->where('id', $logId)->update($update);
        }
    }

    private function signatureIsValid(Request $request, string $secret): bool
    {
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');

        if ($id === '' || $timestamp === '' || $signatures === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        // The secret is base64 after a "whsec_" prefix.
        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);

        if ($key === false) {
            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true)
        );

        // The header may carry several space-separated "v1,<sig>" values during
        // a secret rotation; any one matching is valid.
        foreach (explode(' ', $signatures) as $candidate) {
            $parts = explode(',', $candidate, 2);

            if (count($parts) === 2 && hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }
}
