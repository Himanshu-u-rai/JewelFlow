<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Mobile API / client configuration
    |--------------------------------------------------------------------------
    |
    | Values surfaced by GET /api/mobile/bootstrap to the Expo mobile client
    | so it can gate UI, force updates, and align with the server contract.
    |
    | TODO(bootstrap): move `min_mobile_version` to a managed source (DB-backed
    | settings or a released-versions table) once the release process exists.
    | Bump this string whenever a breaking change ships in the mobile app.
    */

    'min_mobile_version' => env('MOBILE_MIN_VERSION', '1.0.0'),

    'api_version' => 'mobile-v1',
];
