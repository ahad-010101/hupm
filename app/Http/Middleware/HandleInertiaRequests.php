<?php

namespace App\Http\Middleware;

use App\Domain\Reporting\ExceptionFeed;
use App\Support\ReconciliationHealth;
use App\Support\Settings;
use Illuminate\Http\Request;
use Inertia\Middleware;

/**
 * Props shared with every Inertia page.
 *
 * This runs on the portal, admin and owner pipelines only. It is deliberately
 * absent from the public middleware group (D-05), which is what makes AC-PUB-01
 * structural: a public response has no shared prop bag, so there is nothing
 * here that could leak onto one.
 *
 * INVARIANT I-4: nothing added here may carry the Housing Authority portion,
 * because these props reach the tenant portal.
 */
class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                // An explicit whitelist, not the model. Serialising the whole
                // user would ship password hashes, tokens and tenant_id to the
                // browser on every page — $hidden protects some of that, but
                // naming the fields is the guarantee.
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                    'role' => $request->user()->role,
                ] : null,
            ],

            /*
             | Company details, for the pages a signed-out person sees.
             |
             | The login and password screens are the door between the public
             | site and the portal, and they should not look like a different
             | product. The emergency number belongs there too: somebody in
             | trouble may well try to sign in before they think to look for it.
             |
             | Cheap — Settings is a cached singleton, so this is an array
             | lookup after the first request.
            */
            'company' => fn () => [
                'name' => app(Settings::class)->string('company.name', config('app.name')),
                'phone' => app(Settings::class)->string('company.phone'),
                'emergency_phone' => app(Settings::class)->string('company.emergency_phone'),
            ],

            // One-shot messages after a redirect. Split by tone so a page never
            // has to guess whether a string is good news.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'error' => fn () => $request->session()->get('error'),
            ],

            // R-6 / UI §3.9. Admin-wide, not one screen: a reconciliation that
            // stopped is invisible by nature, so the warning has to follow the
            // admin around rather than wait on a page nobody opens. A closure,
            // so the query runs only for an admin and never on the portal.
            'reconciliation' => fn () => $request->user()?->role === 'admin'
                ? app(ReconciliationHealth::class)->status()
                : null,

            // The exceptions badge, for the same reason: the things it counts
            // are exactly what goes unnoticed until somebody complains, and a
            // badge that is only right on the dashboard is a badge that lies
            // on every other screen (UI §3.7, §2.3).
            'exceptionSummary' => fn () => $request->user()?->role === 'admin'
                ? app(ExceptionFeed::class)->summary()
                : null,
        ];
    }
}
