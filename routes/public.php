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
| WP-18 fills in the remaining seven pages: About, Available Properties,
| Resident Resources, Georgia Rental Info & DCA, Emergency Maintenance
| Instructions, Contact Us. Login stays Blade and posts to the same auth
| controller; the Inertia portal takes over after redirect.
|
| BR-22 / AC-PUB-01: no response from this file may contain a tenant name,
| balance, lease detail or maintenance data.
|
*/

Route::get('/', fn () => view('public.home'))->name('public.home');
