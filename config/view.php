<?php

/*
|--------------------------------------------------------------------------
| Views
|--------------------------------------------------------------------------
|
| This file is NOT in the Laravel 12 skeleton, and it was never removed from
| this repository — the whole history has no commit that adds or deletes it.
| Laravel 11+ ships a slim `config/`, and anything absent falls back to the
| framework's own copy in `vendor/laravel/framework/config/`. So the defaults
| were being loaded correctly all along.
|
| It exists now because of ONE character in that default:
|
|     'compiled' => env('VIEW_COMPILED_PATH', realpath(storage_path('framework/views'))),
|                                             ^^^^^^^^
|
| `realpath()` returns **false** for a path that does not exist. So on a host
| where `storage/framework/views` is missing, `config('view.compiled')` is not
| a wrong path — it is boolean false. `ViewClearCommand` then does:
|
|     if (! $path) { throw new RuntimeException('View path not found.'); }
|
| ...which is the error that stopped the HostGator deploy dead, after the
| upload and the migrations had both succeeded. The message points at the
| view *path*, so it reads like the views are missing; they were not. It is
| the compiled-output directory, and it is a directory Laravel is perfectly
| capable of creating for itself — `Compiler::ensureCompiledDirectoryExists()`
| does exactly that on first compile. `realpath()` just denies it the chance,
| because by the time Blade runs there is no longer a path to create.
|
| Dropping `realpath()` is the entire change. The value stays a real string,
| a missing directory heals itself on first compile, and `view:clear` has
| something to clear.
|
| Both paths below are resolved through Laravel's own helpers rather than
| written out, which matters here: production runs from a release symlink
| (`~/hupm -> ~/releases/<id>`) and `storage` is symlinked out to shared
| state that outlives releases. Nothing may pin a release directory.
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | View Storage Paths
    |--------------------------------------------------------------------------
    |
    | Where Blade looks for templates. Both of this project's frontends are
    | served from here: the React pages Inertia renders through the root
    | `app` view, and the eight public Blade pages that carry no JavaScript
    | at all (D-05).
    |
    */

    'paths' => [
        resource_path('views'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled View Path
    |--------------------------------------------------------------------------
    |
    | Where Blade writes its compiled templates. Deliberately NOT wrapped in
    | realpath() — see the note at the top of this file. That wrapper turns a
    | missing directory into `false`, and `false` is what raises "View path
    | not found." three commands into a deploy.
    |
    | On the host this resolves through the release's `storage` symlink into
    | shared state, so compiled views survive a release swap. They are also
    | rebuilt by `view:cache` on every deploy, so nothing stale is ever
    | served from a previous release.
    |
    */

    'compiled' => env(
        'VIEW_COMPILED_PATH',
        storage_path('framework/views')
    ),

];
