<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SimpleFIN Bridge
    |--------------------------------------------------------------------------
    |
    | The claimed access URL, credentials included. Claim the setup token once
    | by hand and keep the result in the server environment only.
    |
    */

    'simplefin' => [
        'access_url' => env('SIMPLEFIN_ACCESS_URL'),
    ],

];
