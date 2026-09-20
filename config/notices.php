<?php

return [

    /*
    |--------------------------------------------------------------------------
    | When to remind somebody they are travelling
    |--------------------------------------------------------------------------
    |
    | Days before departure. Two weeks by default: long enough to fix a
    | passport, short enough to be believed.
    |
    */

    'remind_days_before' => (int) env('NOTICE_REMIND_DAYS_BEFORE', 14),

];
