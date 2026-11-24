<?php

namespace Modules\Transport\Filament;

use App\Models\Partner;

/**
 * Who may own a boat or a route: an operator, or Rihla itself.
 */
final class Operators
{
    /** @return array<int, string> */
    public static function options(): array
    {
        return Partner::query()
            ->where(fn ($q) => $q->where('kind', Partner::KIND_OPERATOR)->orWhere('is_rihla', true))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
