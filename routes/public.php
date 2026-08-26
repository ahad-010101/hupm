<?php

use App\Http\Controllers\Public\ContactController;
use App\Http\Controllers\Public\HealthController;
use App\Http\Controllers\Public\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes — Blade, no Inertia  [DEVIATION D-05, WP-18]
|--------------------------------------------------------------------------
|
| Registered against the 'public' middleware group in bootstrap/app.php, which
| omits HandleInertiaRequests. Nothing in this file may return Inertia::render().
|
| BR-22 / AC-PUB-01: no response from this file may contain a tenant name,
| balance, lease detail or maintenance data. Note what that rules out — none of
| these routes reads the tenant tables at all, which is what makes the guarantee
| structural rather than a filter somebody has to remember.
|
*/

Route::get('/', PageController::class)->defaults('slug', 'home')->name('public.home');

Route::get('/about', PageController::class)->defaults('slug', 'about')->name('public.about');

// New with WP-36. Resident-facing: what we handle for the people living in
// our homes, not a pitch to landlords.
Route::get('/services', PageController::class)->defaults('slug', 'services')->name('public.services');

/*
 | Available properties (BR-22). A `listings` section the office maintains —
 | never a query over vacant units, which would publish occupancy.
 */
Route::get('/properties', PageController::class)->defaults('slug', 'properties')->name('public.properties');

Route::get('/resources', PageController::class)->defaults('slug', 'resources')->name('public.resources');

Route::get('/georgia-rental-info', PageController::class)->defaults('slug', 'georgia')->name('public.georgia');

/*
 | The reason the public site is Blade at all: this page must render with no
 | JavaScript, on a poor connection, at the worst possible moment (D-05).
 */
Route::view('/emergency-maintenance', 'public.emergency')->name('public.emergency');

Route::get('/contact', [ContactController::class, 'show'])->name('public.contact');
Route::post('/contact', [ContactController::class, 'store'])
    // AC-PUB-02. Three an hour per address, and the refusal points at the
    // telephone rather than leaving somebody with no way to reach the office.
    ->middleware('throttle:contact')
    ->name('public.contact.send');

Route::get('/privacy', PageController::class)->defaults('slug', 'privacy')->name('public.privacy');

Route::get('/terms', PageController::class)->defaults('slug', 'terms')->name('public.terms');

/*
 | Health (API-PUB-07, D-06). Not a page — JSON, for an uptime monitor.
 |
 | Throttled because it is unauthenticated and touches the database on every
 | call; sixty a minute is far more than any monitor needs and far less than a
 | cheap way to make the database do work.
 */
Route::get('/health', HealthController::class)
    ->middleware('throttle:60,1')
    ->name('public.health');
