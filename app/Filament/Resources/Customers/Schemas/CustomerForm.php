<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Models\Customer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                TextInput::make('phone')->tel()->maxLength(40),
                TextInput::make('email')->email()->maxLength(255),
                TextInput::make('national_id')->label('ID number')->maxLength(40),
                Textarea::make('address')->rows(2)->columnSpanFull(),
                Textarea::make('notes')->rows(3)->columnSpanFull(),
            ]),

            Section::make('How they found us')
                ->description('§8.1\'s referral tracking. The point of recording it is to be able to thank the person who made the referral, so it is a customer we already have rather than a name typed in a box.')
                ->columns(2)
                ->schema([
                    Select::make('referred_by_customer_id')
                        ->label('Referred by')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => Customer::query()
                            ->where('name', 'like', "%{$search}%")
                            ->limit(20)
                            ->pluck('name', 'id')
                            ->all())
                        ->getOptionLabelUsing(fn ($value): ?string => Customer::find($value)?->name),

                    Select::make('referral_source')
                        ->label('Or where from')
                        ->options([
                            'google' => 'Found us online',
                            'facebook' => 'Facebook',
                            'instagram' => 'Instagram',
                            'walk_in' => 'Walked in',
                            'returning' => 'Travelled with us before',
                            'other' => 'Somewhere else',
                        ])
                        ->helperText('Only when it was not a person.'),
                ]),
        ]);
    }
}
