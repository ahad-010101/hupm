<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    /*
    | Authorize.Net.  [TDD §9.1, WP-13]
    |
    | eCheck/ACH only — cards are out of scope for v1 (Q-7). `environment`
    | picks the endpoints; nothing else in the codebase knows a hostname.
    |
    | The Signature Key is NOT the Transaction Key. It is a separate 128-hex
    | value generated in the merchant interface, and it is the only thing
    | standing between the webhook endpoint and anyone who can guess the URL.
    */
    'authorize_net' => [
        'login_id' => env('AUTHORIZE_NET_LOGIN_ID'),
        'transaction_key' => env('AUTHORIZE_NET_TRANSACTION_KEY'),
        'signature_key' => env('AUTHORIZE_NET_SIGNATURE_KEY'),
        'environment' => env('AUTHORIZE_NET_ENVIRONMENT', 'sandbox'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
        // Svix signing secret for delivery webhooks. Absent means the webhook
        // endpoint refuses every request rather than accepting unsigned ones.
        'webhook_secret' => env('RESEND_WEBHOOK_SECRET'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
