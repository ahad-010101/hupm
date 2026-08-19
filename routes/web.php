<?php

use App\Http\Controllers\Admin\AddressLookupController;
use App\Http\Controllers\Admin\HousingAuthorityController;
use App\Http\Controllers\Admin\LeaseController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\LedgerController as PortalLedgerController;
use App\Http\Controllers\Portal\MaintenanceController as PortalMaintenanceController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Authenticated application routes — React via Inertia
|--------------------------------------------------------------------------
|
| The public site is NOT here. It lives in routes/public.php and renders in
| Blade (D-05).
|
| Role is enforced by the `role` middleware (403 on the wrong role, TDD §5.1).
| Ownership is enforced by policy inside each controller and returns 404, never
| 403 (I-9) — the two are deliberately different mechanisms.
|
*/

Route::middleware('auth')->group(function () {
    /*
     | Tenant portal
     */
    Route::middleware('role:tenant')->prefix('portal')->name('portal.')->group(function () {
        // I-4 / AC-POR-01: every figure on these screens is the TENANT portion.
        // The Housing Authority amount is not filtered out downstream — the
        // queries behind them never select it.
        Route::get('/', DashboardController::class)->name('dashboard');

        // Own ledger and statement (API-POR-02/03).
        Route::get('/ledger', [PortalLedgerController::class, 'index'])->name('ledger.index');
        Route::get('/ledger/export', [PortalLedgerController::class, 'export'])->name('ledger.export');
        /*
         | Pay (API-POR-05/06). The throttle is per tenant, not per IP: a
         | household behind one address is one tenant, and five attempts an
         | hour is the spec's ceiling on a tenant, not on a router.
         |
         | There is no route here that a lease in Management Review can use —
         | the service refuses as well, because AC-DEL-04 is about someone
         | constructing the request once the button has gone.
         */
        Route::get('/pay', [PortalPaymentController::class, 'show'])->name('pay.show');
        Route::post('/pay', [PortalPaymentController::class, 'store'])
            ->middleware('throttle:payments')
            ->name('pay.store');
        Route::get('/pay/confirm', [PortalPaymentController::class, 'confirm'])->name('pay.confirm');

        /*
         | Maintenance (API-POR-10…14). `new` before `{maintenance}` so the
         | literal segment cannot be swallowed by the model binding.
         */
        Route::get('/maintenance', [PortalMaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('/maintenance/new', [PortalMaintenanceController::class, 'create'])->name('maintenance.create');
        Route::post('/maintenance', [PortalMaintenanceController::class, 'store'])->name('maintenance.store');
        Route::get('/maintenance/{maintenance}', [PortalMaintenanceController::class, 'show'])
            ->whereNumber('maintenance')->name('maintenance.show');
        Route::post('/maintenance/{maintenance}/confirm', [PortalMaintenanceController::class, 'confirm'])
            ->whereNumber('maintenance')->name('maintenance.confirm');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    });

    /*
     | Admin console
     */
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::get('/', fn () => Inertia::render('Admin/Dashboard'))->name('dashboard');

        // Cascading address dropdowns for the property form (D-19).
        Route::get('address/states', [AddressLookupController::class, 'states'])->name('address.states');
        Route::get('address/cities', [AddressLookupController::class, 'cities'])->name('address.cities');

        // Properties and units (API-ADM-02…04). Units are nested because a unit
        // number is only unique within its property (AC-REG-01), so the parent
        // is part of the identity rather than a convenience.
        Route::resource('properties', PropertyController::class);
        Route::get('properties/{property}/units/create', [UnitController::class, 'create'])->name('properties.units.create');
        Route::post('properties/{property}/units', [UnitController::class, 'store'])->name('properties.units.store');
        Route::get('properties/{property}/units/{unit}/edit', [UnitController::class, 'edit'])->name('properties.units.edit');
        Route::patch('properties/{property}/units/{unit}', [UnitController::class, 'update'])->name('properties.units.update');
        Route::delete('properties/{property}/units/{unit}', [UnitController::class, 'destroy'])->name('properties.units.destroy');

        Route::resource('housing-authorities', HousingAuthorityController::class)->except(['show']);

        // Tenants (API-ADM-05…07). The invite action is separate from update
        // because issuing portal access is a different decision from correcting
        // a phone number, and it sends email.
        Route::resource('tenants', TenantController::class);
        Route::post('tenants/{tenant}/invite', [TenantController::class, 'invite'])->name('tenants.invite');

        // Ledger (WP-09 engine, WP-12 view pulled forward by D-16). There is
        // deliberately no PUT/PATCH/DELETE here: corrections are reversing
        // entries, never edits (BR-04, AC-LED-01).
        Route::get('ledger', [LedgerController::class, 'index'])->name('ledger.index');
        Route::get('ledger/{tenant}', [LedgerController::class, 'show'])->name('ledger.show');
        Route::post('ledger/{tenant}/adjustments', [LedgerController::class, 'adjust'])->name('ledger.adjust');
        Route::post('ledger/{tenant}/entries/{entry}/reverse', [LedgerController::class, 'reverse'])->name('ledger.reverse');

        // Payments (API-ADM-14/15). `record` and `remittance` are declared as
        // literal segments and there is no `payments/{payment}` route, so
        // nothing can shadow them.
        //
        // No middleware excludes an account in Management Review: admin-recorded
        // payments work at all times (BR-12, I-12, AC-PAY-15).
        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::get('payments/record', [PaymentController::class, 'create'])->name('payments.create');
        Route::post('payments/record', [PaymentController::class, 'store'])->name('payments.store');

        // API-ADM-16. Runs the same service as the nightly job, never a second
        // implementation of it.
        Route::post('payments/reconcile', [PaymentController::class, 'reconcile'])->name('payments.reconcile');

        // [GATE Q-2, R-9] One authority cheque covering many tenants.
        Route::get('payments/remittance', [PaymentController::class, 'remittance'])->name('payments.remittance');
        Route::post('payments/remittance', [PaymentController::class, 'storeRemittance'])->name('payments.remittance.store');

        // Maintenance (API-ADM-21…25). The queue is worked from a phone.
        Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
        Route::get('maintenance/{maintenance}', [MaintenanceController::class, 'show'])
            ->whereNumber('maintenance')->name('maintenance.show');
        Route::patch('maintenance/{maintenance}/status', [MaintenanceController::class, 'transition'])
            ->whereNumber('maintenance')->name('maintenance.transition');
        Route::post('maintenance/{maintenance}/assign', [MaintenanceController::class, 'assign'])
            ->whereNumber('maintenance')->name('maintenance.assign');
        Route::post('maintenance/{maintenance}/attachments', [MaintenanceController::class, 'attach'])
            ->whereNumber('maintenance')->name('maintenance.attach');

        // Leases (API-ADM-08…10). Termination is its own action, not a status
        // dropdown: FR-REG-03 needs an effective date and a reason, and it must
        // be a deliberate act rather than a field someone edits in passing.
        Route::resource('leases', LeaseController::class)->except(['show']);
        Route::post('leases/{lease}/terminate', [LeaseController::class, 'terminate'])->name('leases.terminate');
    });

    /*
     | Owner. [GATE Q-11] Whether this role ships at all is unanswered; it is
     | behind roles.owner_enabled, default false.
     */
    Route::middleware('role:owner')->prefix('owner')->name('owner.')->group(function () {
        Route::get('/', fn () => Inertia::render('Owner/Summary'))->name('summary');
    });

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

/*
|--------------------------------------------------------------------------
| Component gallery — local only  [WP-05]
|--------------------------------------------------------------------------
|
| Renders every shared component in every state inside each layout, so the
| WP-05 acceptance criteria can be checked by looking. Registered only in local
| and testing, so it cannot be reached in production even if a link survives.
|
*/
if (app()->environment(['local', 'testing'])) {
    Route::get('/dev/ui/{layout?}', function (string $layout = 'portal') {
        abort_unless(in_array($layout, ['portal', 'admin', 'owner'], true), 404);

        return Inertia::render('Dev/UiGallery', ['layout' => $layout]);
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
