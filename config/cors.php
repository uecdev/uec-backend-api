<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Native webviews (capacitor://localhost, https://localhost) + approved web origins; set UEC_CORS_ALLOWED_ORIGINS for extra web origins
    'allowed_origins' => array_values(array_filter(array_merge(
        ['capacitor://localhost', 'https://localhost', 'ionic://localhost'],
        array_map('trim', explode(',', (string) env('UEC_CORS_ALLOWED_ORIGINS', ''))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Accept-Language', 'Authorization', 'Content-Type', 'If-None-Match', 'X-App-Version', 'X-Platform', 'X-Requested-With'],

    'exposed_headers' => ['ETag', 'Retry-After'],

    'max_age' => 86400,

    'supports_credentials' => false,

];
