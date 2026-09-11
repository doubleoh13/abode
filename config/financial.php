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
    */

    'simplefin' => [
        'timezone' => 'America/Indiana/Indianapolis',
        'initial_days' => 30,
        'overlap_days' => 7,
        'match_window_days' => 3,
    ],

];
