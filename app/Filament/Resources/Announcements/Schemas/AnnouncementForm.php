<?php

namespace App\Filament\Resources\Announcements\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Umrah\Models\Departure;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        ->notOverBy(now()->subMonth())
                        ->orderBy('date_start')
                        ->get()
                        ->mapWithKeys(fn (Departure $departure): array => [
                            $departure->getKey() => sprintf(
                                '%s (%s)',
                                $departure->package->title ?? 'Departure',
                                $departure->startLabel('j M Y'),
                            ),
                        ])
                        ->all()),

                TextInput::make('headline')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    // Said out loud, because it is easy to forget who is
                    // reading: this is the line a mother at home sees.
                    ->helperText('Families at home read this. Say what happened, not what you are about to do.'),

                Textarea::make('body')->rows(5)->columnSpanFull(),
            ]),
        ]);
    }
}
