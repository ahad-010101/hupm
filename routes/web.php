<?php

use App\Http\Controllers\Admin\AddressLookupController;
use App\Http\Controllers\Admin\ArrangementController;
use App\Http\Controllers\Admin\AuditController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\DelinquencyController;
use App\Http\Controllers\Admin\DocumentController as AdminDocumentController;
use App\Http\Controllers\Admin\ExceptionController;
use App\Http\Controllers\Admin\HousingAuthorityController;
use App\Http\Controllers\Admin\LeaseController;
use App\Http\Controllers\Admin\LedgerController;
use App\Http\Controllers\Admin\MaintenanceController;
use App\Http\Controllers\Admin\NoticeController as AdminNoticeController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PropertyController;
use App\Http\Controllers\Admin\ReportController;
use App\Http\Controllers\Admin\SearchController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SignatureController as AdminSignatureController;
use App\Http\Controllers\Admin\TenantController;
use App\Http\Controllers\Admin\UnitController;
use App\Http\Controllers\Admin\VendorController;
use App\Http\Controllers\Admin\WeatherAlertController;
use App\Http\Controllers\Admin\WebsiteController;
use App\Http\Controllers\Portal\DashboardController;
use App\Http\Controllers\Portal\DocumentController;
use App\Http\Controllers\Portal\LedgerController as PortalLedgerController;
use App\Http\Controllers\Portal\MaintenanceController as PortalMaintenanceController;
use App\Http\Controllers\Portal\NoticeController as PortalNoticeController;
use App\Http\Controllers\Portal\PaymentController as PortalPaymentController;
use App\Http\Controllers\Portal\SignatureController as PortalSignatureController;
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

        /*
         | Signing (API-POR-17…19). Ownership failure is 404, never 403: the
         | existence of someone else's signature request is not a fact to
         | confirm (I-9).
         */
        Route::get('/sign/{signature}', [PortalSignatureController::class, 'show'])
            ->whereNumber('signature')->name('sign.show');
        Route::post('/sign/{signature}/consent', [PortalSignatureController::class, 'consent'])
            ->whereNumber('signature')->name('sign.consent');
        Route::post('/sign/{signature}/scrolled', [PortalSignatureController::class, 'scrolled'])
            ->whereNumber('signature')->name('sign.scrolled');
        Route::post('/sign/{signature}', [PortalSignatureController::class, 'sign'])
            ->whereNumber('signature')->name('sign.store');

        // Notices received (API-POR-20). Scoped from notice_recipients, never
        // from an id in the URL.
        Route::get('/notices', [PortalNoticeController::class, 'index'])->name('notices.index');
        Route::get('/notices/{notice}/attachments/{attachment}', [PortalNoticeController::class, 'attachment'])
            ->whereNumber(['notice', 'attachment'])->name('notices.attachment');

        Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
        Route::get('/documents/{document}', [DocumentController::class, 'show'])
            ->whereNumber('document')->name('documents.show');
        // API-POR-16. Signed with a five-minute life (AC-DOC-03) *and* behind
        // the session and the ownership check — the signature only makes a
        // copied URL useless, it is not what keeps the file private.
        Route::get('/documents/{document}/download', [DocumentController::class, 'download'])
            ->whereNumber('document')->middleware('signed')->name('documents.download');
    });

    /*
     | Admin console
     */
    Route::middleware('role:admin')->prefix('admin')->name('admin.')->group(function () {
        // API-ADM-01. Exceptions, KPIs and panels — filter state lives in the
        // query string so a dashboard is a URL somebody can send (AC-ADM-01).
        Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

        // The exceptions badge in the top bar leads here from every screen.
        // Acknowledgement is a POST because it records a decision (AC-ADM-02);
        // there is no route that un-acknowledges, because "I looked at this"
        // is not a thing that stops being true.
        Route::get('exceptions', [ExceptionController::class, 'index'])->name('exceptions.index');
        Route::post('exceptions/acknowledge', [ExceptionController::class, 'acknowledge'])
            ->name('exceptions.acknowledge');

        // Global search across tenant, unit and ticket number. A page, not an
        // endpoint: results get a URL and a back button.
        Route::get('search', SearchController::class)->name('search');

        // Reports (API-ADM-32…35). The export route is declared first so
        // `reports/{report}` cannot swallow `reports/rent-roll/export`.
        Route::get('reports/{report}/export', [ReportController::class, 'export'])->name('reports.export');
        Route::get('reports/{report?}', [ReportController::class, 'index'])->name('reports.index');

        /*
         | Contractors (API-ADM-38). The list the maintenance screen assigns
         | from — without it that dropdown is permanently empty.
         |
         | No `show` and no `create`: the whole thing is one screen with an
         | inline form, because a contractor is six fields and a page of its
         | own for six fields is a page nobody wants to visit.
         */
        Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
        Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
        Route::patch('vendors/{vendor}', [VendorController::class, 'update'])
            ->whereNumber('vendor')->name('vendors.update');
        Route::delete('vendors/{vendor}', [VendorController::class, 'destroy'])
            ->whereNumber('vendor')->name('vendors.destroy');

        /*
         | The public site (WP-36, D-27).
         |
         | Pages are edited, never created or deleted: `routes/public.php` is
         | the complete list of public URLs and stays that way. So there is no
         | POST to `website` and no DELETE on a page — only on a section.
         */
        Route::get('website', [WebsiteController::class, 'index'])->name('website.index');
        Route::get('website/{page}', [WebsiteController::class, 'edit'])->name('website.edit');
        Route::patch('website/{page}', [WebsiteController::class, 'update'])->name('website.update');

        Route::post('website/{page}/sections', [WebsiteController::class, 'storeSection'])
            ->name('website.sections.store');
        Route::patch('website/{page}/sections/{section}', [WebsiteController::class, 'updateSection'])
            ->whereNumber('section')->name('website.sections.update');
        Route::post('website/{page}/sections/{section}/move', [WebsiteController::class, 'moveSection'])
            ->whereNumber('section')->name('website.sections.move');
        Route::delete('website/{page}/sections/{section}', [WebsiteController::class, 'destroySection'])
            ->whereNumber('section')->name('website.sections.destroy');

        // Audit trail (API-ADM-41). One route, GET only. There is deliberately
        // no PUT, PATCH or DELETE anywhere for audit rows: the model refuses
        // both (AC-AUD-02), and a route that does not exist cannot be reached
        // by a bug in a policy either.
        Route::get('audit', [AuditController::class, 'index'])->name('audit.index');

        // Settings (API-ADM-40). Changing a value and confirming a gated
        // decision are separate routes because they are separate acts —
        // confirming the shipped default is an answer, and go-live blocks on
        // the confirmations rather than on the values.
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::patch('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/confirm', [SettingController::class, 'confirm'])->name('settings.confirm');

        // Cascading address dropdowns for the property form (D-19).
        Route::get('address/states', [AddressLookupController::class, 'states'])->name('address.states');
        Route::get('address/cities', [AddressLookupController::class, 'cities'])->name('address.cities');
        Route::get('address/counties', [AddressLookupController::class, 'counties'])->name('address.counties');

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

        // Payment arrangements (API-ADM-19/20). Drafting and approving are
        // separate acts: approval generates the BR-19 agreement and sends it
        // for signature, which is not something to do by accident.
        Route::get('arrangements', [ArrangementController::class, 'index'])->name('arrangements.index');
        Route::post('arrangements', [ArrangementController::class, 'store'])->name('arrangements.store');
        Route::post('arrangements/{arrangement}/approve', [ArrangementController::class, 'approve'])
            ->whereNumber('arrangement')->name('arrangements.approve');
        Route::post('arrangements/{arrangement}/default', [ArrangementController::class, 'markDefaulted'])
            ->whereNumber('arrangement')->name('arrangements.default');
        // [GATE C1/Q-1] Marking a ledger reviewed for the current period.
        Route::post('arrangements/leases/{lease}/review-ledger', [ArrangementController::class, 'reviewLedger'])
            ->whereNumber('lease')->name('arrangements.review-ledger');

        // Management Review (API-ADM-17/18). Release is the only write, it is
        // manual, and its reason is mandatory (BR-14, AC-DEL-05). There is no
        // route that puts an account IN — the nightly job does that.
        Route::get('delinquency', [DelinquencyController::class, 'index'])->name('delinquency.index');
        // Run the 02:30 rule now — for the morning after a cron that did not
        // fire. It only ever puts accounts IN; there is still no route that
        // takes one out without a reason.
        Route::post('delinquency/evaluate', [DelinquencyController::class, 'evaluate'])
            ->name('delinquency.evaluate');
        Route::post('delinquency/{lease}/release', [DelinquencyController::class, 'release'])
            ->whereNumber('lease')->name('delinquency.release');

        // Signature requests (API-ADM-28/29). No delete: a signed document is
        // superseded, never removed (BR-27, AC-SIG-05).
        Route::get('signatures', [AdminSignatureController::class, 'index'])->name('signatures.index');
        Route::post('signatures', [AdminSignatureController::class, 'store'])->name('signatures.store');

        // Weather and emergency alerts (FR-NTF-03). No update or delete: an
        // alert that was issued was issued.
        Route::get('alerts', [WeatherAlertController::class, 'index'])->name('alerts.index');
        Route::post('alerts', [WeatherAlertController::class, 'store'])->name('alerts.store');
        Route::post('alerts/poll', [WeatherAlertController::class, 'poll'])->name('alerts.poll');

        // Notices (API-ADM-30/31). There is deliberately no update and no
        // delete route: a sent notice is the record that a resident was told
        // something (AC-NTF-06).
        Route::get('notices', [AdminNoticeController::class, 'index'])->name('notices.index');
        Route::get('notices/new', [AdminNoticeController::class, 'create'])->name('notices.create');
        Route::post('notices/audience', [AdminNoticeController::class, 'audience'])->name('notices.audience');
        Route::post('notices', [AdminNoticeController::class, 'store'])->name('notices.store');
        Route::get('notices/{notice}', [AdminNoticeController::class, 'show'])
            ->whereNumber('notice')->name('notices.show');
        Route::get('notices/{notice}/attachments/{attachment}', [AdminNoticeController::class, 'attachment'])
            ->whereNumber(['notice', 'attachment'])->name('notices.attachment');

        // Document vault (API-ADM-26/27). Version history is nested in the
        // list rather than behind its own endpoint (GAP-2, D-12).
        Route::get('documents', [AdminDocumentController::class, 'index'])->name('documents.index');
        Route::post('documents', [AdminDocumentController::class, 'store'])->name('documents.store');
        Route::post('documents/{document}/replace', [AdminDocumentController::class, 'replace'])
            ->whereNumber('document')->name('documents.replace');
        Route::get('documents/{document}/download', [AdminDocumentController::class, 'download'])
            ->whereNumber('document')->middleware('signed')->name('documents.download');

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
