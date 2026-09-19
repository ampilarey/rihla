<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Raised when something tries to delete the only remaining Super Admin.
 *
 * Losing it means nobody can open either admin panel, and the way back is
 * `php artisan admin:create` over SSH. A named exception rather than a bare
 * RuntimeException so a screen can catch this one and say what happened,
 * instead of showing a stack trace.
 */
class LastSuperAdmin extends RuntimeException
{
    public function __construct(string $message = 'This is the last Super Admin. Give someone else that role first, or nobody will be able to reach the admin panel.')
    {
        parent::__construct($message);
    }
}
