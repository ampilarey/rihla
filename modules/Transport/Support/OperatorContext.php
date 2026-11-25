<?php

namespace Modules\Transport\Support;

use App\Models\Partner;
use Filament\Facades\Filament;

/**
 * Which boat operator the current request is for — docs/TRANSPORT_PLAN.md
 * (T5). Inside the operator panel it is the panel's tenant; everywhere
 * else it is null, and Rihla's staff see every operator.
 *
 * The transport screens are shared between the staff panel and the
 * operator panel, so every query and option list asks this before it
 * reads: a screen that forgot would show one operator another's
 * passengers.
 */
final class OperatorContext
{
    public const PANEL = 'operator';

    public static function inPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === self::PANEL;
    }

    public static function current(): ?Partner
    {
        if (! self::inPanel()) {
            return null;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Partner ? $tenant : null;
    }

    /** The operator's id inside its panel, null outside. */
    public static function id(): ?int
    {
        return self::current()?->getKey();
    }
}
