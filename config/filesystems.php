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

    /*
    |--------------------------------------------------------------------------
    | Message Attachment Disk
    |--------------------------------------------------------------------------
    |
    | Customers' photos are kept apart from the catalogue on purpose. Product
    | images and brand logos are PUBLIC by nature - the app fetches them by URL
    | with no auth - while a photo sent into a message thread is readable only
    | by the two people in it. R2 has no per-object permissions, so "public
    | catalogue, private conversation" cannot be one bucket; it has to be two.
    |
    | Unset (local development) this falls back to the default disk, so laragon
    | needs no bucket at all.
    |
    */

    'attachments' => env('ATTACHMENTS_DISK'),

    /*
     * NOTE on Laravel Cloud. Buckets attached there are injected as
     * LARAVEL_CLOUD_DISK_CONFIG - a JSON array of disks, each with its own
     * NAME - so there is nothing to define or credential in this file. The
     * private attachments bucket arrives as the disk `private`.
     *
     * ATTACHMENTS_DISK must name that disk EXPLICITLY and must never be left
     * to follow the default. The `AWS_*` variables belong to whichever bucket
     * is currently default, and when the public catalogue bucket is attached
     * for product images it becomes the default - at which point an
     * unpinned attachments disk would quietly start writing customers'
     * photographs into a PUBLIC bucket. Pinning it by name is the whole
     * safeguard.
     */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
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
