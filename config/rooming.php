<?php

return [

    /*
    |--------------------------------------------------------------------------
    | What counts as a child
    |--------------------------------------------------------------------------
    |
    | Used for "a child sharing a room with no adult in it". Configuration
    | rather than a constant because it is a safeguarding judgement, not a
    | fact, and the operator may well have a different line than the one
    | guessed here.
    |
    | Measured at the departure date, like every other age rule in this
    | application: a fourteen-year-old who turns fifteen in the air is a
    | different booking from one who does not.
    |
    */

    'child_under' => (int) env('ROOMING_CHILD_UNDER', 16),

    /*
    |--------------------------------------------------------------------------
    | Which conflicts are reported
    |--------------------------------------------------------------------------
    |
    | Every one of these is **reported, never fixed**. §8.2 asks for
    | "intelligent grouping by family/gender/age", and an allocator that
    | shuffles real pilgrims on rules nobody has written down is how a
    | mother ends up separated from her children on the strength of a
    | heuristic. Making the mistakes visible is the whole job.
    |
    | `unaccompanied_child` can be switched off by an operator who rooms
    | families differently; the rest are arithmetic and stay on.
    |
    */

    'checks' => [
        'unaccompanied_child' => (bool) env('ROOMING_CHECK_CHILDREN', true),
        'mixed_gender' => (bool) env('ROOMING_CHECK_GENDER', true),
    ],

];
