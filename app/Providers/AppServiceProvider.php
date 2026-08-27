<?php

namespace App\Providers;

use App\Domain\Content\PageContent;
use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\PermissionMatrix;
use App\Support\Settings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /*
     | The rate limits from TDD 6.2, as constants so the security review can
     | assert the number rather than the behaviour.  [WP-34]
     |
     | A test that submits six payments and expects a 429 passes whether the
     | limit is five or fifty-nine, because it only ever proves the limiter is
     | wired up. That is how `perHour(15)` sat under a comment saying five.
    */
    public const PAYMENT_ATTEMPTS_PER_HOUR = 5;

    public const CONTACT_MESSAGES_PER_HOUR = 3;

    public const PASSWORD_RESETS_PER_HOUR = 3;

    public const AUTHENTICATED_REQUESTS_PER_MINUTE = 120;

    public function register(): void
    {
        // Singletons: settings are read many times per request and the resolved
        // set should not be re-queried each time.
        $this->app->singleton(Settings::class, fn ($app) => new Settings($app['cache.store']));
        $this->app->singleton(BusinessCalendar::class, fn ($app) => new BusinessCalendar($app->make(Settings::class)));

        // Not a singleton: the request-scoped IP and user agent are part of what
        // an audit row records, so it resolves per request.
        $this->app->bind(AuditLogger::class, fn ($app) => new AuditLogger($app->bound('request') ? $app['request'] : null));
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Five payment submissions an hour, per TENANT (WP-13).
        //
        // Keyed on the tenant rather than the IP on purpose: a household behind
        // one address is one tenant, and a shared library computer is several.
        // An IP limit would punish the second case and miss the first. Falls
        // back to the IP only when there is no tenant to key on, which cannot
        // happen behind `role:tenant` but is cheap insurance.
        RateLimiter::for('payments', function ($request) {
            $tenantId = $request->user()?->tenant_id;

            // [WP-34] Five, not fifteen. TDD 6.2 says five per hour per
            // tenant and the comment above this block has always said five;
            // only the code said fifteen. Nothing had caught it, because a
            // limit three times too loose refuses nothing a test would try.
            return Limit::perHour(self::PAYMENT_ATTEMPTS_PER_HOUR)
                ->by($tenantId ? 'tenant:'.$tenantId : 'ip:'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'That is several payment attempts in a short time. '
                        .'Please wait a little while, or call the office if something is wrong.',
                ], 429));
        });

        /*
         | Public contact form (AC-PUB-02). Three an hour per address.
         |
         | Keyed on the IP because there is nobody signed in to key on, which
         | makes this the one limit in the system that can catch a real person:
         | an office block or a library shares an address. Three is chosen to
         | sit well above one honest enquiry and well below anything worth
         | automating, and the refusal says to telephone instead — a limit that
         | leaves somebody with no way to reach the office is not a limit, it is
         | an outage.
        */
        RateLimiter::for('contact', fn ($request) => Limit::perHour(self::CONTACT_MESSAGES_PER_HOUR)
            ->by('ip:'.$request->ip())
            ->response(fn () => redirect()->route('public.contact')
                ->withInput($request->except('message'))
                ->withErrors(['message' => 'That is several messages in a short time. '
                    .'Please telephone the office if this is urgent.'])));

        /*
         | Password reset requests (TDD 6.2). Three an hour per ADDRESS.
         |
         | Keyed on the email rather than the IP, because the abuse this stops
         | is mailbombing one person from anywhere -- an IP limit makes the
         | attacker change IP, which costs nothing. Keying on the address does
         | mean somebody can lock a stranger out of the reset flow for an hour
         | by requesting three; that is the lesser harm, since the account
         | itself is untouched and the office can send a fresh invitation.
         |
         | Lowercased and trimmed so three requests for A@b.test, a@b.test and
         | " a@b.test " are three, not one each.
        */
        RateLimiter::for('password-reset', fn ($request) => Limit::perHour(self::PASSWORD_RESETS_PER_HOUR)
            ->by('reset:'.Str::lower(trim((string) $request->input('email'))))
            ->response(fn () => back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'A reset link has already been sent recently. '
                    .'Please check your inbox, including junk mail, or telephone the office.'])));

        /*
         | Authenticated general (TDD 6.2). 120 a minute per USER.
         |
         | A backstop, not a policy: two a second is far above a person using
         | the console and far below a script walking every ledger row. It is
         | the limit that makes scraping expensive after an account is taken
         | over, which is the case the specific limits above do not cover.
         |
         | Falls back to the IP for the handful of authenticated-group routes
         | reachable before the session resolves.
        */
        RateLimiter::for('authenticated', fn ($request) => Limit::perMinute(self::AUTHENTICATED_REQUESTS_PER_MINUTE)
            ->by($request->user()?->id ? 'user:'.$request->user()->id : 'ip:'.$request->ip()));

        /*
         | Turn Vite's asset prefetching off for the public site.  [D-05]
         |
         | Vite::prefetch() below injects an inline <script> into *every* @vite
         | call and uses it to pull down the manifest's assets — which on a
         | public page means fetching the React bundle that page will never run.
         |
         | The whole reason the public site is Blade is that Emergency
         | Maintenance Instructions must render with no JavaScript, on a poor
         | connection, at the worst possible moment. A prefetch script is
         | JavaScript, and the bundle it pulls is the exact download that
         | connection cannot spare.
         |
         | Disabled here rather than globally: the portal and admin console are
         | app shells behind a login, where prefetching is worth having.
        */
        View::composer('public.*', fn () => Vite::usePrefetchStrategy(null));

        /*
         | The public header navigation, from the content table.  [WP-36, D-27]
         |
         | So a page appears in the nav by being published, not by somebody
         | editing the layout — which is how /services arrives.
         |
         | **Falls back to a literal list when the query returns nothing.** A
         | fresh database, a first request after a deploy that cleared the
         | cache before the seeder ran, a half-finished migration: any of them
         | would otherwise produce a site with no navigation at all, which is
         | a far worse failure than a slightly stale menu.
        */
        View::composer('public.*', function ($view): void {
            $links = $this->app->make(PageContent::class)->navigation();

            $view->with('navigation', $links ?: [
                ['route' => 'public.home', 'label' => 'Home'],
                ['route' => 'public.about', 'label' => 'About'],
                ['route' => 'public.services', 'label' => 'Services'],
                ['route' => 'public.properties', 'label' => 'Properties'],
                ['route' => 'public.resources', 'label' => 'Resources'],
                ['route' => 'public.contact', 'label' => 'Contact'],
            ]);
        });

        // Company details for the public Blade layout (UI §2.1). A composer
        // rather than View::share so the query runs only when a public page is
        // actually rendered — not on every console command, and not during
        // migrate:fresh when the settings table may not exist yet.
        View::composer(['public.*', 'errors.*', 'mail.*'], function ($view): void {
            $settings = $this->app->make(Settings::class);

            $view->with('company', [
                'name' => $settings->string('company.name', config('app.name')),
                'phone' => $settings->string('company.phone'),
                'address' => $settings->string('company.address'),
                // Blank until the client supplies them. The contact page offers
                // the telephone instead of a form that delivers nowhere, and
                // says nothing at all about hours rather than guessing them.
                'email' => $settings->string('company.email'),
                'office_hours' => $settings->string('company.office_hours'),
                // [GATE] Unset until the client supplies it. WP-35 blocks
                // go-live while empty — the layout says so rather than
                // rendering a blank space where a number should be.
                'emergency_phone' => $settings->string('company.emergency_phone'),
            ]);
        });

        // Fail loudly on a mass-assignment attempt against a guarded attribute
        // instead of silently discarding it. Financial models are fully guarded
        // (I-11) and every write goes through a domain service, so a silent drop
        // would look like the write simply did not happen.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());

        // Lazy loading in a loop over ledger entries is how a balance page turns
        // into 400 queries on shared hosting.
        Model::preventLazyLoading(! $this->app->isProduction());

        // The TDD §5.3 matrix defines the Gate, rather than being restated in
        // it. Every capability in the specification becomes an ability with the
        // same name, so a feature package cannot invent its own answer to a
        // question the matrix already settles.
        foreach (PermissionMatrix::capabilities() as $capability) {
            Gate::define($capability, fn (User $user) => PermissionMatrix::allows($capability, $user->role));
        }

        // One password policy for every path that sets one: invitation, reset
        // and change (TDD §4). Defined once so the three cannot drift apart.
        // `uncompromised()` checks Have I Been Pwned's k-anonymity API — a
        // 12-character password that already appears in a breach corpus is not
        // a strong password.
        Password::defaults(function () {
            $rule = Password::min(12);

            // The check is skipped in tests, which must not depend on an
            // outbound HTTP call to a third party to pass.
            return $this->app->runningUnitTests() ? $rule : $rule->uncompromised();
        });
    }
}
