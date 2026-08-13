<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
|--------------------------------------------------------------------------
| Authenticated application routes — React via Inertia
|--------------------------------------------------------------------------
|
| The public site is NOT here. It lives in routes/public.php and renders in
| Blade (D-05). Nothing in this file should be reachable without auth once
| WP-04 lands.
|
*/

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
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
| WP-05 acceptance criteria can be checked by looking: all four layouts at
| 375 / 768 / 1440px, keyboard traversal with a visible focus ring, and no
| critical axe violations.
|
| Registered only in local and testing. It is not behind auth, so it must not
| exist anywhere else — and a route that only exists locally cannot be reached
| in production even if a link to it survives.
|
*/
if (app()->environment(['local', 'testing'])) {
    Route::get('/dev/ui/{layout?}', function (string $layout = 'portal') {
        abort_unless(in_array($layout, ['portal', 'admin', 'owner'], true), 404);

        return Inertia::render('Dev/UiGallery', ['layout' => $layout]);
    })->name('dev.ui');
}

require __DIR__.'/auth.php';
