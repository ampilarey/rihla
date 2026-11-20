<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Providers\Filament\CompanyPanelProvider;
use App\Support\CompanyFeatures;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Modules\Companies\Services\AddCompany;

class ListPartners extends ListRecords
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            // Companies plan, decisions 6 and 17: Rihla's admin adds an
            // Umrah company once its licence is checked, with its owner.
            Action::make('addCompany')
                ->label('Add an Umrah company')
                ->icon('heroicon-o-building-office-2')
                ->visible(fn (): bool => auth()->user()->can('partner.create') && auth()->user()->can('user.create'))
                ->modalDescription('Only after you have checked the company\'s licence. The owner is e-mailed a link to choose their password and can then add their own team.')
                ->schema([
                    TextInput::make('name')->label('Company name')->required()->maxLength(255),
                    TextInput::make('owner_name')->label('Owner\'s name')->required()->maxLength(255),
                    TextInput::make('owner_email')->label('Owner\'s e-mail')->email()->required()->maxLength(255),
                    // Phase 5b: the licence you checked, recorded as checked.
                    TextInput::make('licence_number')->label('Licence number')->required()->maxLength(60),
                    DatePicker::make('licence_expires_on')->label('Licence expires')->required()->minDate(now()),
                    TextInput::make('licence_register')
                        ->label('Checked against')
                        ->required()
                        ->maxLength(120)
                        ->placeholder('Ministry of Islamic Affairs register of Hajj and Umrah operators'),
                    TextInput::make('registration_fee')->label('Registration fee (MVR)')->numeric()->minValue(0),
                    TextInput::make('staff_seats')->label('Staff seats')->integer()->minValue(1)->maxValue(500)
                        ->helperText('Leave empty for no limit.'),
                    // Phase 5a: only what they have paid for. The basic
                    // website comes with the registration fee and is not here.
                    CheckboxList::make('features')
                        ->label('What they have paid for')
                        ->options(array_map(fn (array $feature): string => $feature['label'], CompanyFeatures::FEATURES))
                        ->columns(2)
                        ->helperText('Leave it all unticked for the basic website alone. Each can be changed later on their record.'),
                ])
                ->action(function (array $data): void {
                    $company = app(AddCompany::class)(
                        (string) $data['name'],
                        (string) $data['owner_name'],
                        (string) $data['owner_email'],
                        auth()->user(),
                        array_fill_keys((array) ($data['features'] ?? []), CompanyFeatures::ON),
                        [
                            'licence_number' => $data['licence_number'] ?? null,
                            'licence_expires_on' => $data['licence_expires_on'] ?? null,
                            'licence_register' => $data['licence_register'] ?? null,
                            'registration_fee_minor' => filled($data['registration_fee'] ?? null) ? (int) round(((float) $data['registration_fee']) * 100) : null,
                            'registration_fee_currency' => 'MVR',
                            'staff_seats' => filled($data['staff_seats'] ?? null) ? (int) $data['staff_seats'] : null,
                        ],
                    );

                    Notification::make()
                        ->success()
                        ->title("{$company->name} is added")
                        ->body('Their panel is at /'.CompanyPanelProvider::PATH.'/'.$company->slug.'.')
                        ->send();
                }),
        ];
    }
}
