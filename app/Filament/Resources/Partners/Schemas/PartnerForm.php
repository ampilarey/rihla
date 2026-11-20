<?php

namespace App\Filament\Resources\Partners\Schemas;

use App\Models\Partner;
use App\Support\CompanyFeatures;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\HtmlString;
use Modules\Companies\Services\CompanyUsage;

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

            // Companies plan, Phase 4a: Rihla's admin's two switches for an
            // Umrah company's own website. Saved by EditPartner with
            // forceFill, because neither is fillable.
            Section::make('Website')
                ->description('The company\'s own site at its own address. Nothing is shown until it is switched on here and the company has published it.')
                ->visible(fn (?Partner $record): bool => (bool) $record?->is_umrah_company)
                ->columns(2)
                ->schema([
                    Toggle::make('site_enabled')
                        ->label('Site switched on')
                        ->helperText('Once the registration fee is paid. Switching it off takes the site down at once; nothing is deleted.'),
                    Toggle::make('powered_by_rihla')
                        ->label('Say "Powered by Rihla"')
                        ->helperText('Off unless the company has agreed to it. When on, one line at the foot of their site links here.'),
                    // Phase 4e: the company's own domain, once it points at
                    // the server and cPanel has it with a certificate.
                    TextInput::make('site_domain')
                        ->label('Their own domain')
                        ->placeholder('coralumrah.mv')
                        ->maxLength(253)
                        ->regex('/^(?!www\.)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/')
                        ->notRegex('/(^|\.)rihla\.mv$/')
                        ->unique('partners', 'site_domain', ignoreRecord: true)
                        ->validationMessages([
                            'regex' => 'A bare domain in small letters, without www or https: coralumrah.mv.',
                            'not_regex' => 'That is one of Rihla\'s own addresses.',
                            'unique' => 'Another company already has that domain.',
                        ])
                        ->helperText('Only once the domain points at the server and cPanel has it, with a certificate. www is answered too.')
                        ->columnSpanFull(),
                ]),

            // Companies plan, Phase 5b: the licence Rihla's admin checked,
            // and how much the company uses Rihla — counts, never contents.
            Section::make('Licence and usage')
                ->visible(fn (?Partner $record): bool => (bool) $record?->is_umrah_company)
                ->columns(3)
                ->schema([
                    TextInput::make('licence_number')->label('Licence number')->maxLength(60),
                    DatePicker::make('licence_expires_on')->label('Licence expires')
                        ->helperText(fn (?Partner $record): ?string => match ($record?->licenceStanding()) {
                            'expired' => 'This licence has expired.',
                            'expiring' => 'This licence expires '.$record->licence_expires_on?->diffForHumans().'. Ask for the renewal.',
                            default => null,
                        }),
                    TextInput::make('licence_register')->label('Checked against')->maxLength(120),
                    Placeholder::make('suspension')
                        ->label('Suspended')
                        ->visible(fn (?Partner $record): bool => (bool) $record?->isSuspended())
                        ->content(fn (?Partner $record): string => trim(($record?->suspended_at?->toFormattedDateString() ?? '').' — '.($record->suspended_reason ?? ''), ' —'))
                        ->columnSpanFull(),
                    Placeholder::make('usage')
                        ->label('Usage')
                        ->content(fn (?Partner $record): HtmlString => $record === null ? new HtmlString('') : new HtmlString(implode(' · ', array_map(
                            fn (string $label, int $count): string => e($label).': <strong>'.number_format($count).'</strong>',
                            array_keys($counts = app(CompanyUsage::class)->counts($record)),
                            $counts,
                        ))))
                        ->columnSpanFull(),
                ]),

            // Companies plan, Phase 5a: what the company has paid for. Set
            // here by Rihla's admin, saved by EditPartner, never fillable.
            Section::make('What they have paid for')
                ->description('The registration fee opens the basic website. Every other feature is switched on here once it is paid for. Switching one to read-only keeps their records visible and stops new work; nothing is ever deleted.')
                ->visible(fn (?Partner $record): bool => (bool) $record?->is_umrah_company)
                ->columns(3)
                ->schema([
                    TextInput::make('registration_fee')
                        ->label('Registration fee')
                        ->numeric()
                        ->minValue(0)
                        ->formatStateUsing(fn (?Partner $record): ?string => $record?->registration_fee_minor === null ? null : number_format($record->registration_fee_minor / 100, 2, '.', '')),
                    Select::make('registration_fee_currency')
                        ->label('In')
                        ->options(['MVR' => 'MVR', 'USD' => 'USD'])
                        ->default('MVR'),
                    DatePicker::make('registration_paid_at')
                        ->label('Paid on')
                        ->maxDate(now())
                        ->helperText('Until this is set, their site cannot be switched on. Recording their registration invoice paid sets it.'),
                    // Billing automation: the monthly fee, invoiced for each
                    // whole calendar month from the month billing starts.
                    TextInput::make('monthly_fee')
                        ->label('Monthly fee')
                        ->numeric()
                        ->minValue(0)
                        ->formatStateUsing(fn (?Partner $record): ?string => $record?->monthly_fee_minor === null ? null : number_format($record->monthly_fee_minor / 100, 2, '.', ''))
                        ->helperText('Leave empty for no monthly invoice.'),
                    Select::make('monthly_fee_currency')
                        ->label('In')
                        ->options(['MVR' => 'MVR', 'USD' => 'USD'])
                        ->default('MVR'),
                    DatePicker::make('billing_starts_on')
                        ->label('Billed from')
                        ->helperText('Invoices start with this month, a whole month each, issued on the 1st.')
                        ->requiredWith('monthly_fee'),
                    TextInput::make('staff_seats')
                        ->label('Staff seats')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(500)
                        ->helperText('Leave empty for no limit.'),
                    ...array_map(
                        fn (string $feature): Select => Select::make('features.'.$feature)
                            ->label(CompanyFeatures::FEATURES[$feature]['label'])
                            ->options([
                                CompanyFeatures::OFF => 'Off',
                                CompanyFeatures::ON => 'On',
                                CompanyFeatures::READ_ONLY => 'Read-only',
                            ])
                            ->default(CompanyFeatures::OFF)
                            ->selectablePlaceholder(false)
                            ->formatStateUsing(fn (?Partner $record): string => CompanyFeatures::state($record, $feature)),
                        array_keys(CompanyFeatures::FEATURES),
                    ),
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
