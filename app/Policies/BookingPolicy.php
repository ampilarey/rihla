<?php

namespace App\Policies;

use App\Policies\Concerns\ChecksPermissions;

/**
 * `booking.create` and `booking.delete` do not exist, so the inherited
 * create() and delete() deny everybody — which is the intent. A booking is
 * created by the checkout flow, and a booking is a financial record that is
 * cancelled through its status machine, with a row saying who and why, never
 * removed.
 */
class BookingPolicy
{
    use ChecksPermissions;

    protected function resource(): string
    {
        return 'booking';
    }
}
