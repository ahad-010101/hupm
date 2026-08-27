<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        /*
         | The document vault and maintenance media (WP-17, WP-19). Rooted
         | outside the document root, served only through a controller.
         |
         | `serve` is FALSE, and that is a change from Laravel's default rather
         | than an omission.  [WP-34]
         |
         | With it true the framework registers two routes on this disk:
         |
         |     GET  /storage/{path}   storage.local
         |     PUT  /storage/{path}   storage.local.upload
         |
         | Both check a URL signature and nothing else. No policy, no session,
         | no ownership -- so a signed link to `documents/5/<uuid>.pdf` serves
         | tenant 5's lease to whoever holds it, and the PUT writes arbitrary
         | content into the private document store. That is exactly the check
         | AC-DOC-04 and invariant I-9 exist to enforce, and
         | Portal\DocumentController does enforce it: signature AND session AND
         | ownership, because the signature only makes a copied URL expire.
         |
         | Nothing in this application generates those URLs, so turning the
         | routes off costs nothing and removes a second, weaker way in.
        */
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => false,
            'throw' => false,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
