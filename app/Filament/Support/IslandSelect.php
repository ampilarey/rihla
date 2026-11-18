<?php

namespace App\Filament\Support;

use App\Models\Island;
use Filament\Forms\Components\Select;

/**
 * The island an address is on (companies plan, Phase 3d): searchable by
 * the island or the atoll, written as an address writes it.
 */
final class IslandSelect
{
    public static function make(string $name = 'island_id'): Select
    {
        return Select::make($name)
            ->label('Island')
            ->native(false)
            ->searchable()
            ->options(fn (): array => self::options())
            ->helperText('Search by island or atoll.');
    }

    /** @return array<int, string> */
    public static function options(): array
    {
        return Island::query()
            ->active()
            ->orderBy('atoll_code')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Island $island): array => [$island->getKey() => $island->label().' ('.$island->atoll_name.')'])
            ->all();
    }
}
