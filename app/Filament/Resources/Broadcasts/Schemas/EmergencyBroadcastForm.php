<?php

namespace App\Filament\Resources\Broadcasts\Schemas;

use App\Models\Departure;
use App\Models\Incident;
use App\Services\Broadcasts\Broadcaster;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class EmergencyBroadcastForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('What to tell everybody')->columns(2)->schema([
                Select::make('departure_id')
                    ->label('Departure')
                    ->required()
                    ->searchable()
                    ->preload()
                    ->live()
                    ->options(fn (): array => Departure::query()
                        ->with('package')
                        ->where('date_end', '>=', now()->subWeek())
                        ->orderBy('date_start')
                        ->get()
                        ->mapWithKeys(fn (Departure $departure): array => [
                            $departure->getKey() => sprintf(
                                '%s (%s)',
                                $departure->package->title ?? 'Departure',
                                $departure->date_start->format('j M Y'),
                            ),
                        ])
                        ->all()),

                Select::make('incident_id')
                    ->label('About which incident')
                    ->searchable()
                    ->options(fn (Get $get): array => blank($get('departure_id'))
                        ? []
                        : Incident::where('departure_id', $get('departure_id'))
                            ->orderByDesc('happened_at')
                            ->pluck('summary', 'id')
                            ->all())
                    ->helperText('Optional. "Everybody is safe, ignore the news" is a broadcast with no incident behind it.'),

                TextInput::make('headline')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('Read first, often on a lock screen. Say the thing itself.'),

                Textarea::make('body')
                    ->required()
                    ->rows(5)
                    ->columnSpanFull(),
            ]),

            Section::make('How this will reach people')->schema([
                // Stated before somebody presses send, not discovered
                // after. If SMS is not set up, the decision to phone people
                // instead has to be made now.
                Text::make(fn (): string => self::channelSummary()),
            ]),
        ]);
    }

    /**
     * Which channels work on this host, right now, and why the others do
     * not.
     */
    private static function channelSummary(): string
    {
        $lines = [];

        foreach (app(Broadcaster::class)->readiness() as $channel) {
            $lines[] = $channel['available']
                ? ucfirst($channel['channel']).': working.'
                : ucfirst($channel['channel']).': not set up. '.$channel['why'];
        }

        return implode(' ', $lines);
    }
}
