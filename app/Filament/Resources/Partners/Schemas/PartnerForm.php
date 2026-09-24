<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Models\Partner;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PartnerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Who they are')->columns(2)->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->helperText('The business Rihla has the arrangement with.'),

                TextInput::make('island')
                    ->maxLength(120)
                    ->helperText('Where their guesthouses are. A partner with buildings on two islands still has one record.'),

                Toggle::make('is_active')
                    ->label('Working with us')
                    ->default(true)
                    ->helperText('A partner Rihla no longer works with is switched off here rather than deleted — which keeps the history of what was sold under their name.'),
            ]),

            // §15.2 decision 8: no partner portal. Confirmation is a staff
            // member pressing a button that messages these numbers, so an
            // arrangement with nobody's phone number on it does not work.
            Section::make('Who Rihla rings')->columns(2)->schema([
                TextInput::make('contact_name')->label('Contact')->maxLength(120),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('whatsapp')
                    ->label('WhatsApp')
                    ->tel()
                    ->maxLength(40)
                    ->helperText('How a confirmation actually reaches them.'),
                TextInput::make('email')->email()->maxLength(255),
            ]),

            Section::make('The arrangement')->columns(2)->schema([
                Select::make('pricing_model')
                    ->label('How they are paid')
                    ->options([
                        Partner::NET_RATE => 'Net rate — they quote, Rihla sells above it',
                        Partner::COMMISSION => 'Commission — their rate is shown, Rihla takes a cut',
                    ])
                    ->default(Partner::NET_RATE)
                    ->required()
                    ->live(),

                TextInput::make('commission_pct')
                    ->label('Commission %')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(50)
                    ->visible(fn ($get): bool => $get('pricing_model') === Partner::COMMISSION)
                    ->required(fn ($get): bool => $get('pricing_model') === Partner::COMMISSION),

                Select::make('green_tax_mode')
                    ->label('Green tax')
                    ->options([
                        Partner::GREEN_TAX_AT_PROPERTY => 'Collected at the property',
                        Partner::GREEN_TAX_INCLUDED => 'Included in the rate they quote',
                    ])
                    ->default(Partner::GREEN_TAX_AT_PROPERTY)
                    ->required()
                    ->helperText('Charged per guest per night. If it is not in the rate, the guest is asked for it at check-out — so the page has to say so.'),

                Textarea::make('allotment_notes')
                    ->label('Allotment')
                    ->rows(3)
                    ->columnSpanFull()
                    ->helperText('What rooms this partner has actually given Rihla to sell, and until when. Until that is written down, their properties stay on request rather than instant book.'),

                Textarea::make('contract_notes')
                    ->label('Contract notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ]),
        ]);
    }
}
