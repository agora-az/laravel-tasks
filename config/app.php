<?php

use Illuminate\Support\Facades\Facade;

return [
    'name' => env('APP_NAME', 'Opus Reconciliation'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => env('APP_TIMEZONE', 'UTC'),
    'locale' => env('APP_LOCALE', 'en'),
    'fallback_locale' => env('APP_FALLBACK_LOCALE', 'en'),
    'faker_locale' => env('APP_FAKER_LOCALE', 'en_US'),
    'cipher' => 'AES-256-CBC',
    'key' => env('APP_KEY'),
    'previous_keys' => [
        ...array_filter(
            explode(',', env('APP_PREVIOUS_KEYS', ''))
        ),
    ],
    'maintenance' => [
        'driver' => env('APP_MAINTENANCE_DRIVER', 'file'),
        'store' => env('APP_MAINTENANCE_STORE', 'database'),
    ],

    // Comma-separated list of nav item keys to hide.
    // Available keys: transaction_data, reconciliation, reports
    // Example: NAV_HIDE=transaction_data,reconciliation,reports
    'nav_hide' => array_filter(array_map('trim', explode(',', env('NAV_HIDE', '')))),
];
