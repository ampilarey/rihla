<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Nothing a Livewire method returns reaches the browser as a model.
 *
 * Livewire lets the browser call any public method on a component and
 * sends back whatever it returns, JSON-encoded — every attribute of an
 * Eloquent model included, decrypted casts and all. The security review
 * (§16) found a reception member reading a host's bank account through
 * `$wire.host()` on the dashboard, and guests' passport numbers through
 * Filament's own public `getRecord()` on a booking page, around the masking
 * the page itself applies.
 *
 * Hiding the columns on the models would close it and break every staff
 * edit form, which fills from the same attribute list. So the rule is
 * applied at the one place every call passes: a model comes back as
 * nothing, and a list keeps its non-model values. No screen in this
 * application reads a model from a call on the client side.
 */
final class LivewireReturns
{
    /** For the `call` hook, where null means "unchanged": a stripped top-level value is `false`. */
    public static function forBrowser(mixed $value): mixed
    {
        $stripped = self::strip($value);

        return $stripped === null && $value !== null ? false : $stripped;
    }

    public static function strip(mixed $value): mixed
    {
        if ($value instanceof Model) {
            return null;
        }

        if ($value instanceof Collection) {
            $value = $value->all();
        }

        if (is_array($value)) {
            return array_map(self::strip(...), $value);
        }

        return $value;
    }
}
