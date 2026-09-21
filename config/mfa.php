<?php

use App\Support\Access;

return [

    /*
    |--------------------------------------------------------------------------
    | Who must have a second factor
    |--------------------------------------------------------------------------
    |
    | §10.4 asks for "MFA for staff". Requiring it of everybody on day one
    | would mean a tour leader standing at a hotel desk in Makkah, on a
    | borrowed telephone, unable to take a head count — so it is required of
    | the roles that can read personal data or move money, and offered to
    | everybody else.
    |
    | Enforcement means "you must enrol", not "you are locked out": a person
    | in a required role who has not set one up is sent to the enrolment
    | page, which they can always complete. Nobody can be shut out of their
    | own account by this setting.
    |
    */

    'required_roles' => [
        Access::SUPER_ADMIN,
        Access::OPERATIONS_MANAGER,
        Access::FINANCE,
    ],

    /*
    |--------------------------------------------------------------------------
    | The switch, off by default
    |--------------------------------------------------------------------------
    |
    | **Off until somebody turns it on**, and that is deliberate rather than
    | timid. Shipping this enforcing would mean that on the next deploy the
    | owner signs in, is sent to enrolment, and needs an authenticator app on
    | the telephone in their hand before they can reach a booking — at
    | whatever moment the deploy happened to land. Changing how somebody
    | signs in to a live system is their decision to make, on a morning they
    | chose, not a side effect of a release.
    |
    | Off does not mean absent: anybody can enrol from /two-factor today, and
    | everybody who has is still asked for their code. Setting
    | MFA_ENFORCE=true then compels the roles below.
    |
    | It is also the emergency stop, for the morning somebody's telephone is
    | at the bottom of the lagoon and the recovery codes are in the office:
    | turning it off stops compelling anybody new without weakening the
    | accounts that already have a factor.
    |
    */

    'enforce' => (bool) env('MFA_ENFORCE', false),

    /*
    |--------------------------------------------------------------------------
    | How many recovery codes, and how long a verified session lasts
    |--------------------------------------------------------------------------
    |
    | The factor is re-asked for after this many minutes of a session, not
    | on every request: asking on every page is how people write the codes
    | on a sticky note.
    |
    */

    'recovery_codes' => 8,

    'remember_minutes' => (int) env('MFA_REMEMBER_MINUTES', 720),

];
