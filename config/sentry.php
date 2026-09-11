<?php

/*
| Sentry is inert without a DSN, so local and test runs never send anything.
| The DSN is the only server-provided value; everything else is fixed here.
*/

return [

    'dsn' => env('SENTRY_LARAVEL_DSN'),

    'release' => env('APP_BUILD_SHA'),

    'send_default_pii' => false,

    'ignore_transactions' => ['/up'],

    'breadcrumbs' => [
        'sql_bindings' => false,
    ],

];
