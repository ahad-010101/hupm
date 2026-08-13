<?php

/*
|--------------------------------------------------------------------------
| SECURITY NOTE — read before changing anything here  [WP-03, D-17]
|--------------------------------------------------------------------------
|
| resend/resend-laravel registers its own webhook route unconditionally:
|
|     POST /resend/webhook  →  Resend\Laravel\Http\Controllers\WebhookController
|
| That controller applies its signature-verification middleware only when
| `resend.webhook.secret` is truthy:
|
|     if (config('resend.webhook.secret')) {
|         $this->middleware(VerifyWebhookSignature::class);
|     }
|
| So a blank secret does not disable the endpoint — it disables the CHECK, and
| leaves an unauthenticated POST route that dispatches application events from
| attacker-controlled input. It fails open, which is the wrong default.
|
| The service provider offers no way to switch the route off, so the mitigation
| is to make the secret mandatory instead of optional:
|
|   - `php artisan hupm:preflight` fails when the resend mailer is configured
|     and RESEND_WEBHOOK_SECRET is empty.
|   - A test asserts the package endpoint rejects unsigned requests once the
|     secret is set.
|
| HUPM's own handler is POST /webhooks/resend
| (App\Http\Controllers\Webhook\ResendWebhookController). That is the URL to
| configure in the Resend dashboard. It fails CLOSED — no secret means 503, not
| an open door.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Resend API Key
    |--------------------------------------------------------------------------
    |
    | The Resend API key give you access to Resend's API. The "api_key" is
    | typically used to make a email request to the API.
    |
    */

    'api_key' => env('RESEND_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Resend Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where the package routes will be accessible from.
    | If the setting is null, Resend will reside under the same domain as your
    | application. Otherwise, this value will be used as the subdomain.
    |
    */

    'domain' => env('RESEND_DOMAIN', null),

    /*
    |--------------------------------------------------------------------------
    | Resend Path
    |--------------------------------------------------------------------------
    |
    | This is the base URI path where the package routes, such as the webhook
    | handler, will be available from. You are free to tweak the path to your
    | preference and application design.
    |
    */

    'path' => env('RESEND_PATH', 'resend'),

    /*
    |--------------------------------------------------------------------------
    | Resend Webhooks
    |--------------------------------------------------------------------------
    |
    | Your Resend webhook secret is used to prevent unauthorized requestes to
    | your Resend webhook handling controllers. The tolerance setting will
    | check the drift between the current time and the signed request's.
    |
    */

    'webhook' => [
        'secret' => env('RESEND_WEBHOOK_SECRET'),
        'tolerance' => env('RESEND_WEBHOOK_TOLERANCE', 300),
    ],

];
