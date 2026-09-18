<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    // Laravel no longer ships this on the base controller. Without it,
    // $this->authorize() and authorizeResource() are undefined methods — a
    // fatal error at the exact moment a permission check should happen.
    use AuthorizesRequests;
}
