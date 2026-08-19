<?php

use App\Http\Controllers\Webhook\AuthorizeNetWebhookController;
use App\Http\Controllers\Webhook\ResendWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Webhook routes
|--------------------------------------------------------------------------
|
| No session, no CSRF token, no Inertia. A provider posting a delivery event
| has no cookie and cannot supply a token; authenticity comes from the
| signature on the request, verified inside each controller.
|
| Keeping these out of the `web` group is deliberate — adding CSRF exemptions
| to `web` would weaken every other route to accommodate these two.
|
*/

Route::post('/webhooks/resend', ResendWebhookController::class)->name('webhooks.resend');

/*
 | API-HOOK-01. Supplementary only: this endpoint records the event and marks
 | the payment for priority reconciliation. It never moves a balance — the
 | daily settlement job is authoritative (R-6, TDD 9.1).
 */
Route::post('/webhooks/authorize-net', AuthorizeNetWebhookController::class)
    ->name('webhooks.authorize-net');
