<?php

namespace App\Providers;

use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
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

        // Company details for the public Blade layout (UI §2.1). A composer
        // rather than View::share so the query runs only when a public page is
        // actually rendered — not on every console command, and not during
        // migrate:fresh when the settings table may not exist yet.
        View::composer(['public.*', 'errors.*'], function ($view): void {
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
    }
}
