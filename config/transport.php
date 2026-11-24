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

];
