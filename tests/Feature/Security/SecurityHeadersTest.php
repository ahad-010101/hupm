<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| TDD §6.3 security headers  [WP-34, A05]
|--------------------------------------------------------------------------
|
| The application shipped with none of these. Not a weakened one, not a stale
| one — no middleware set a security header on any response.
|
| The DoD asks for them to be "verified against the production response",
| which needs WP-00H and the live host. This file verifies the half that is
| ours: they are set by application middleware, on every route out of the
| application, and Apache cannot remove what PHP has already sent. What the
| host check adds is whether anything in front of us strips them.
|
| Every route family is exercised separately, because the reason to make this
| middleware global was that a per-group header is absent from at least two
| ways out: the public site has its own group (D-05), the webhooks have no
| group at all, and the error pages are rendered outside both.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    /*
     | Assert the policy the HOST will serve, not the one this machine happens
     | to be serving. Without this, every CSP test below passes or fails on
     | whether somebody has `npm run dev` open in another window -- and the
     | development branch deliberately adds 'unsafe-inline', which is the exact
     | thing these tests exist to forbid.
    */
    config(['hupm.csp.allow_vite_dev_server' => false]);
});

/** The four headers that are unconditional. HSTS is tested separately. */
const REQUIRED_HEADERS = [
    'X-Content-Type-Options' => 'nosniff',
    'X-Frame-Options' => 'DENY',
    'Referrer-Policy' => 'strict-origin-when-cross-origin',
];

dataset('every kind of response', [
    'a public page (no Inertia, D-05)' => ['/', 200],
    'the emergency page' => ['/emergency-maintenance', 200],
    'the login screen' => ['/login', 200],
    // 503, and that is the correct answer here: a freshly migrated database has
    // no scheduler heartbeat, so /health honestly reports degraded (D-06). The
    // status matters to this test only as proof the intended handler ran --
    // headers on a framework error page would prove nothing about the route.
    'the health endpoint (degraded on a fresh database)' => ['/health', 503],
    'a route that does not exist' => ['/no-such-page-exists-here', 404],
]);

it('TDD 6.3 sets every required header', function (string $path, int $status) {
    $response = $this->get($path);

    expect($response->status())->toBe($status);

    foreach (REQUIRED_HEADERS as $header => $value) {
        expect($response->headers->get($header))
            ->toBe($value, "{$path} is missing or misstating {$header}.");
    }

    expect($response->headers->get('Content-Security-Policy'))
        ->not->toBeNull("{$path} carries no Content-Security-Policy.");
})->with('every kind of response');

it('TDD 6.3 sets the headers on an authenticated response too', function () {
    $user = User::factory()->create(['role' => 'admin']);

    $response = $this->actingAs($user)->get('/admin');

    foreach (REQUIRED_HEADERS as $header => $value) {
        expect($response->headers->get($header))->toBe($value);
    }
});

it('TDD 6.3 sets the headers on a webhook response, which belongs to no middleware group', function () {
    // Deliberately unsigned: the 401 path is a response like any other, and it
    // is the one a scanner sees.
    $response = $this->postJson('/webhooks/authorize-net', ['eventType' => 'net.authorize.payment.authcapture.created']);

    expect($response->status())->toBe(401)
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('X-Frame-Options'))->toBe('DENY');
});

/*
 |--------------------------------------------------------------------------
 | HSTS
 |--------------------------------------------------------------------------
 */

it('TDD 6.3 sends HSTS over TLS and never over plain HTTP', function () {
    /*
     | Absent locally by design. RFC 6797 §7.2 says a browser must ignore HSTS
     | on a plain-HTTP response, so sending it there is not a weaker header —
     | it is a header with no meaning. Worse, a browser that did honour it
     | would pin http://localhost to HTTPS for a year, across every project on
     | the machine, and the person who hit that would have no idea why.
    */
    expect($this->get('/')->headers->get('Strict-Transport-Security'))->toBeNull();

    $secure = $this->get('https://localhost/');

    expect($secure->headers->get('Strict-Transport-Security'))
        ->toBe('max-age=31536000; includeSubDomains');
});

/*
 |--------------------------------------------------------------------------
 | The content security policy
 |--------------------------------------------------------------------------
 */

it('TDD 6.3 allows only self plus the payment gateway', function () {
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)
        ->toContain("default-src 'self'")
        ->toContain("base-uri 'self'")
        ->toContain("object-src 'none'")
        ->toContain("frame-ancestors 'none'");

    // The one real cross-origin destination in the system: the portal builds a
    // form and POSTs it to the hosted payment page (WP-13). Both hosts are
    // allowed so the sandbox/production switch cannot silently block a payment.
    expect($policy)->toContain('form-action')
        ->toContain('https://accept.authorize.net')
        ->toContain('https://test.authorize.net');
});

it('TDD 6.3 admits no third-party origin for scripts, styles, images or fonts', function () {
    /*
     | The point of a CSP here is not that a specific vendor is untrusted; it
     | is that this application loads nothing from anywhere. No CDN, no
     | analytics, no webfont, no remote image. So any host in the policy is
     | either a mistake or a change nobody wrote down, and this fails on both.
    */
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    $hosts = [];

    foreach (explode(';', $policy) as $directive) {
        $parts = preg_split('/\s+/', trim($directive));
        $name = array_shift($parts);

        if ($name === 'form-action') {
            continue; // Checked above, and the gateway is meant to be there.
        }

        foreach ($parts as $source) {
            if (str_contains($source, '://') || str_contains($source, '.')) {
                $hosts[] = "{$name} {$source}";
            }
        }
    }

    expect($hosts)->toBe([], 'The policy admits a remote origin: '.implode(', ', $hosts));
});

it('TDD 6.3 keeps a nonce rather than opening script-src to inline code', function () {
    /*
     | Vite::prefetch() in AppServiceProvider emits an inline <script>, and our
     | own bundle would be blocked by a strict script-src without help. The
     | help is a nonce, not 'unsafe-inline' — the difference being that a nonce
     | admits the script we generated and nothing an injection produces.
     |
     | The dev-server branch in the middleware adds 'unsafe-inline', and a
     | script-src carrying both a nonce and 'unsafe-inline' makes the browser
     | ignore the nonce completely -- while the page keeps working, so nothing
     | tells you. beforeEach pins the strict branch for exactly that reason.
    */
    $policy = $this->get('/login')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("script-src 'self' 'nonce-")
        ->and(str_contains($policy, "script-src 'self' 'unsafe-inline'"))
        ->toBeFalse('script-src admits inline code, so the nonce means nothing.');
});

it('TDD 6.3 accepts style attributes, which is a considered exception', function () {
    /*
     | Documented rather than silently permitted. The Blade pages carry around
     | a hundred style attributes and a style attribute is covered by
     | style-src, so 'unsafe-inline' here is load-bearing.
     |
     | What it costs: CSS-based exfiltration, which needs an injection point to
     | begin with. What denies that is script-src above, which is strict.
    */
    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    expect($policy)->toContain("style-src 'self' 'unsafe-inline'");
});

it('D-05 lets the dev server serve the public stylesheet', function () {
    /*
     | Regression. The first CSP added the dev-server origin to script-src and
     | connect-src and stopped there, which broke the PUBLIC site and nothing
     | else -- so it looked fine to anyone testing the portal.
     |
     | `resources/css/app.css` is its own Vite entry (D-05): the eight Blade
     | pages load the stylesheet ALONE, with no JS bundle, so Emergency
     | Maintenance renders with JavaScript disabled. In dev that is a real
     | <link rel="stylesheet"> pointing at the dev server, and style-src 'self'
     | blocks it. The Inertia side hid the problem, because app.jsx imports
     | app.css and Vite injects it through a script that 'unsafe-inline'
     | already allowed.
     |
     | Every directive that can carry an asset is checked, not just style-src.
     | A webfont or an image added to the public pages later would fail the
     | same way, silently, and only there.
    */
    config(['hupm.csp.allow_vite_dev_server' => true]);

    $policy = $this->get('/')->headers->get('Content-Security-Policy');

    foreach (['script-src', 'style-src', 'font-src', 'img-src', 'connect-src'] as $directive) {
        $line = collect(explode('; ', $policy))
            ->first(fn ($d) => str_starts_with($d, $directive.' '));

        expect(str_contains((string) $line, 'http://localhost:5173'))
            ->toBeTrue("{$directive} does not admit the Vite dev server: {$line}");
    }
});

it('D-05 leaves the public pages with no script to allow', function () {
    // The CSP is the belt; this is the braces. The emergency page must render
    // with no JavaScript at all, which is the whole reason D-05 exists.
    $body = $this->get('/emergency-maintenance')->getContent();

    expect(str_contains($body, '<script'))
        ->toBeFalse('The emergency page carries a script tag.');
});
