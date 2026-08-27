<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * The five headers from TDD §6.3, on every response.  [WP-34, A05]
 *
 * Global rather than attached to a middleware group. There are four ways out of
 * this application — the `web` group, the `public` group (D-05), the two webhook
 * routes with no group at all, and the error responses rendered from
 * `bootstrap/app.php` when something has already failed. A header applied per
 * group is absent from at least two of those, and the one that matters most is
 * the error page: it is served by the framework at the moment least attention is
 * being paid to it.
 *
 * These headers cannot be tested against the live host until WP-00H, so the
 * suite tests them here instead. That is not a lesser check — a header set by
 * middleware is a property of the application, and Apache cannot remove it.
 */
class SecurityHeaders
{
    /**
     * Where the browser is allowed to POST.
     *
     * Both Authorize.Net hosts, not just the configured one: `form-action` is
     * enforced by the browser at submit time, and a CSP that only admits the
     * environment currently in `.env` turns the sandbox/production switch into
     * a silently blocked payment. The sandbox host is not a risk — it accepts
     * only sandbox credentials.
     */
    private const GATEWAY_FORM_TARGETS = [
        'https://accept.authorize.net',
        'https://test.authorize.net',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        // Must happen before the view renders: Vite stamps the nonce onto the
        // <script> and <link> tags it generates, including the inline prefetch
        // script from Vite::prefetch() in AppServiceProvider. Without this the
        // strict script-src below would block our own bundle.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            // Nosniff matters more here than usual: documents and maintenance
            // media are served through a controller with a Content-Type we set,
            // and content sniffing is what would let a file we called
            // application/octet-stream execute as something else.
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Content-Security-Policy' => $this->policy($nonce),
        ];

        // HSTS only over TLS. Sent on a plain-HTTP response it is ignored by
        // browsers per RFC 6797 §7.2, and locally it would be worse than
        // ignored — a browser that did honour it would pin http://localhost to
        // HTTPS for a year across every project on the machine.
        if ($request->secure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            // Never overwrite: a controller that has deliberately set its own
            // Content-Type-adjacent header for a download knows more about that
            // response than this middleware does.
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function policy(string $nonce): string
    {
        $script = ["'self'", "'nonce-{$nonce}'"];
        $connect = ["'self'"];

        /*
         | The Vite dev server, local only.
         |
         | In development the bundle is served from localhost:5173 over HTTP and
         | HMR runs over a websocket, neither of which 'self' covers. The React
         | refresh preamble is also injected inline without a nonce, so
         | 'unsafe-inline' is needed too — and a script-src containing both a
         | nonce and 'unsafe-inline' means the browser IGNORES the nonce, which
         | is precisely why this branch must never be reachable in production.
         |
         | Keyed on the dev server actually running rather than on the
         | environment name, so a local `npm run build` is served under the same
         | strict policy the host will use. Getting a CSP violation on your own
         | machine is the entire point of having one.
        */
        if ($this->viteDevServerIsRunning()) {
            $origin = 'http://[::1]:5173 http://localhost:5173 http://127.0.0.1:5173';
            $script[] = "'unsafe-inline'";
            $script[] = $origin;
            $connect[] = $origin;
            $connect[] = 'ws://localhost:5173 ws://127.0.0.1:5173 ws://[::1]:5173';
        }

        $directives = [
            "default-src 'self'",
            'script-src '.implode(' ', $script),
            // 'unsafe-inline' for styles, and this is a considered exception
            // rather than an oversight: the Blade pages carry ~107 style
            // attributes, and a style attribute is covered by style-src. The
            // XSS that style injection buys — data exfiltration through
            // attribute selectors — needs an injection point, and script-src
            // above is what denies those.
            "style-src 'self' 'unsafe-inline'",
            // data: for the inline SVG and icon data URIs the bundle emits.
            // No remote host: there are no third-party images anywhere.
            "img-src 'self' data:",
            "font-src 'self'",
            'connect-src '.implode(' ', $connect),
            // Nothing is embedded and nothing may embed us. frame-ancestors is
            // the modern half of X-Frame-Options, which older browsers read.
            "frame-src 'none'",
            "frame-ancestors 'none'",
            // The payment handoff is a real cross-origin POST (WP-13): the
            // portal builds a form and submits it to the hosted page.
            'form-action '.implode(' ', ["'self'", ...self::GATEWAY_FORM_TARGETS]),
            "base-uri 'self'",
            "object-src 'none'",
        ];

        return implode('; ', $directives);
    }

    private function viteDevServerIsRunning(): bool
    {
        $configured = config('hupm.csp.allow_vite_dev_server');

        // An explicit answer wins, so the security review can assert the
        // policy the host will serve without depending on whether somebody
        // happens to have `npm run dev` open.
        if ($configured !== null) {
            return (bool) $configured;
        }

        // Otherwise decide by looking. This is the file Vite writes while the
        // dev server is up, and Laravel uses the same marker to choose between
        // the dev server and the manifest -- so the policy cannot disagree with
        // what was actually rendered into the page.
        return is_file(public_path('hot'));
    }
}
