<?php

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
