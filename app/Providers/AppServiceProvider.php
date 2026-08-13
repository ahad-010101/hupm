<?php

namespace App\Providers;

use App\Support\AuditLogger;
use App\Support\BusinessCalendar;
use App\Support\Settings;
use Illuminate\Database\Eloquent\Model;
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
