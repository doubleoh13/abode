<?php

return [

    /*
    | The env override exists for the test suite, which pins the array driver.
    */

    'driver' => env('SESSION_DRIVER', 'database'),

    'lifetime' => 120,

    'expire_on_close' => false,

    'encrypt' => false,

    'files' => storage_path('framework/sessions'),

    'connection' => null,

    'table' => 'sessions',

    'store' => null,

    'lottery' => [2, 100],

    'cookie' => 'abode-session',

    'path' => '/',

    'domain' => null,

    'secure' => env('SESSION_SECURE_COOKIE'),

    'http_only' => true,

    'same_site' => 'lax',

    'partitioned' => false,

    'serialization' => 'json',

];
