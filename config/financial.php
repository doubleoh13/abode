<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Recurring Transaction Lead Window
    |--------------------------------------------------------------------------
    |
    | Schedules post any occurrence due within this many days of today. Zero
    | posts only on the due date itself.
    |
    */

    'recurring_lead_days' => 14,

    /*
    |--------------------------------------------------------------------------
    | SimpleFIN Sync
    |--------------------------------------------------------------------------
    |
    | Bank timestamps become calendar dates in this timezone. The first sync
    | of an account reaches back initial_days; later syncs re-read
    | overlap_days before the account's newest row so pending rows are seen
    | again once they post. A bank row proposes a posting as its match when
    | the amounts agree and the dates fall within match_window_days.
    |
    | Rows whose description matches an ignored pattern are never staged.
    | Fidelity reports each cash movement in its cash management account a
    | second time as a sweep into or out of the SPAXX core position, which
    | the ledger does not model.
    |
    */

    'simplefin' => [
        'timezone' => 'America/Indiana/Indianapolis',
        'initial_days' => 45,
        'overlap_days' => 45,
        'match_window_days' => 3,
        'ignored_description_patterns' => [
            '/CORE ACCOUNT FIDELITY GOVERNMENT MONEY MARKET/i',
            '/^REINVESTMENT FIDELITY GOVERNMENT MONEY MARKET/i',
        ],
    ],

];
