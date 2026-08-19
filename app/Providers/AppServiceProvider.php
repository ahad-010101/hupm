<?php

namespace App\Providers;

use App\Models\User;
use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\PermissionMatrix;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Illuminate\Validation\Rules\Password;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
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

            return Limit::perHour(5)
                ->by($tenantId ? 'tenant:'.$tenantId : 'ip:'.$request->ip())
                ->response(fn () => response()->json([
                    'message' => 'That is several payment attempts in a short time. '
                        .'Please wait a little while, or call the office if something is wrong.',
                ], 429));
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
