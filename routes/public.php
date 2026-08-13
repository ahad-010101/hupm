<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes — Blade, no Inertia  [DEVIATION D-05, WP-18]
|--------------------------------------------------------------------------
|
| Registered against the 'public' middleware group in bootstrap/app.php, which
| omits HandleInertiaRequests. Nothing in this file may return Inertia::render().
|
| The routes exist now so the WP-05 shell has real navigation to render and
| test; WP-18 replaces the placeholder bodies with the content in UI §1 and
| adds the rate-limited contact form.
|
| BR-22 / AC-PUB-01: no response from this file may contain a tenant name,
| balance, lease detail or maintenance data.
|
*/

Route::view('/', 'public.home')->name('public.home');

Route::view('/about', 'public.placeholder')->name('public.about')
    ->defaults('heading', 'About Heads Up Enterprises');

Route::view('/properties', 'public.placeholder')->name('public.properties')
    ->defaults('heading', 'Available Properties');

Route::view('/resources', 'public.placeholder')->name('public.resources')
    ->defaults('heading', 'Resident Resources');

Route::view('/georgia-rental-info', 'public.placeholder')->name('public.georgia')
    ->defaults('heading', 'Georgia Rental Information & DCA');

// The reason the public site is Blade at all: this page must render with no
// JavaScript, on a poor connection, at the worst possible moment (D-05).
Route::view('/emergency-maintenance', 'public.placeholder')->name('public.emergency')
    ->defaults('heading', 'Emergency Maintenance Instructions');

Route::view('/contact', 'public.placeholder')->name('public.contact')
    ->defaults('heading', 'Contact Us');

Route::view('/privacy', 'public.placeholder')->name('public.privacy')
    ->defaults('heading', 'Privacy Policy');

Route::view('/terms', 'public.placeholder')->name('public.terms')
    ->defaults('heading', 'Terms of Use');
