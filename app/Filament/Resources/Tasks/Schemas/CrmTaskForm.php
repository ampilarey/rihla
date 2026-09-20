<?php

namespace App\Filament\Resources\Tasks\Schemas;

use App\Models\Booking;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class CrmTaskForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('subject')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull()
                    ->helperText('What has to happen, in the words you would say on the phone. "Ring about the deposit", not "follow up".'),

                DatePicker::make('due_on')
                    ->label('By when')
                    ->required()
                    ->default(now()->addDay()),

                Select::make('owner_id')
                    ->label('Whose job')
                    ->searchable()
                    ->options(fn (): array => User::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->default(fn () => auth()->id())
                    // Work everybody can hand around is work nobody owns.
                    ->disabled(fn (): bool => auth()->user()?->can('task.assign') !== true)
                    ->dehydrated()
                    ->helperText('Left as you, unless you can hand it to somebody else.'),

                Textarea::make('detail')->rows(3)->columnSpanFull(),
            ]),

            Section::make('What it is about')
                // Three kinds rather than one, because "ring them about
                // their passport" is not a lead and inventing a fake
                // enquiry to hold a reminder puts a deal nobody is working
                // into the pipeline.
                ->description('Optional. A task can stand on its own, but one linked to a record shows up on that record\'s screen too.')
                ->columns(2)
                ->schema([
                    Select::make('about_type')
                        ->label('Linked to')
                        ->live()
                        ->options([
                            Enquiry::class => 'An enquiry',
                            Customer::class => 'A customer',
                            Booking::class => 'A booking',
                        ]),

                    Select::make('about_id')
                        ->label('Which one')
                        ->searchable()
                        ->visible(fn (Get $get): bool => filled($get('about_type')))
                        ->options(fn (Get $get): array => match ($get('about_type')) {
                            Enquiry::class => Enquiry::query()
                                ->orderByDesc('created_at')
                                ->limit(100)
                                ->get()
                                ->mapWithKeys(fn (Enquiry $e): array => [
                                    $e->getKey() => $e->name.' — '.$e->created_at?->translatedFormat('j M Y'),
                                ])
                                ->all(),
                            Customer::class => Customer::query()->orderBy('name')->limit(200)->pluck('name', 'id')->all(),
                            Booking::class => Booking::query()
                                ->orderByDesc('created_at')
                                ->limit(100)
                                ->pluck('reference', 'id')
                                ->all(),
                            default => [],
                        }),
                ]),
        ]);
    }
}
