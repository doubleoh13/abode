<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Development User Email
    |--------------------------------------------------------------------------
    |
    | Identifies the user recreated after database refreshes and logged in
    | by the local-only login bypass.
    |
    */

    'user_email' => 'jake@jakerichhart.com',

    /*
    |--------------------------------------------------------------------------
    | Development User Password
    |--------------------------------------------------------------------------
    |
    | The password assigned to the development user after every database
    | refresh so the UI login keeps working across migrate:fresh runs.
    |
    */

    'user_password' => env('DEVELOPMENT_USER_PASSWORD'),

];
