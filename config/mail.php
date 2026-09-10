<?php

return [

    /*
    | Nothing sends mail today. The env override exists for the test suite
    | (array) and for pointing local development at Mailpit (smtp).
    */

    'default' => env('MAIL_MAILER', 'log'),

    'mailers' => [

        'smtp' => [
            'transport' => 'smtp',
            'host' => env('MAIL_HOST', '127.0.0.1'),
            'port' => env('MAIL_PORT', 1025),
            'username' => null,
            'password' => null,
            'timeout' => null,
        ],

        'log' => [
            'transport' => 'log',
        ],

        'array' => [
            'transport' => 'array',
        ],

    ],

    'from' => [
        'address' => 'abode@localhost',
        'name' => 'Abode',
    ],

];
