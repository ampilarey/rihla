<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use App\Providers\Filament\CompanyPanelProvider;
use App\Services\Companies\AddCompany;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

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
                ])
                ->action(function (array $data): void {
                    $company = app(AddCompany::class)((string) $data['name'], (string) $data['owner_name'], (string) $data['owner_email'], auth()->user());

                    Notification::make()
                        ->success()
                        ->title("{$company->name} is added")
                        ->body('Their panel is at /'.CompanyPanelProvider::PATH.'/'.$company->slug.'.')
                        ->send();
                }),
        ];
    }
}
