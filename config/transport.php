<?php

/*
|--------------------------------------------------------------------------
| Transport — docs/TRANSPORT_PLAN.md
|--------------------------------------------------------------------------
*/

return [

    // How long a boat booking holds its seats while it waits to be
    // confirmed or paid, in minutes. The scheduled sweep lapses it after.
    'hold_minutes' => (int) env('TRANSPORT_HOLD_MINUTES', 15),

    // A booking made online holds longer: the booker has names typed in
    // but still has a bank transfer to make, and staff are told at once.
    'online_hold_minutes' => (int) env('TRANSPORT_ONLINE_HOLD_MINUTES', 60),

];
