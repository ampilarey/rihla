<?php

namespace App\Filament\Company\Pages;

use App\Models\Partner;
use App\Support\CompanyRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What the company's own documents and payment instructions say about it —
 * companies plan, Phase 2c.
 *
 * Every invoice, receipt and printed list made in this panel carries these
 * details, and every bank-transfer instruction this account. A detail left
 * blank is printed as nothing rather than as Rihla's, so a pilgrim is never
 * told to call or pay somebody else. The Owner only.
 *
 * @property-read Schema $form
 */
class CompanySettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationLabel = 'Company details';

    protected static ?string $title = 'Company details';

    protected static ?string $slug = 'details';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.services';

    /** The currencies a pilgrim may be asked to pay in. */
    public const CURRENCIES = ['MVR', 'USD', 'SAR'];

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Partner
            && auth()->user()?->roleAtCompany($company) === CompanyRole::OWNER;
    }

    private function company(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }

    public function mount(): void
    {
        $company = $this->company();

        $this->form->fill([
            'name' => $company->name,
            'registration_number' => $company->registration_number,
            'address' => $company->address,
            'phone' => $company->phone,
            'email' => $company->email,
            'reference_prefix' => $company->reference_prefix,
            'bank_name' => $company->bank_name,
            'bank_account_name' => $company->bank_account_name,
            'bank_accounts' => $company->bank_accounts ?? [],
            'invoice_terms' => $company->invoice_terms,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('On your documents')
                    ->description('Printed at the top of every invoice, receipt and list your team makes here.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Company name')->required()->maxLength(255),
                        TextInput::make('registration_number')->label('Registration number')->maxLength(50),
                        TextInput::make('address')->maxLength(255)->columnSpanFull(),
                        TextInput::make('phone')->label('Telephone')->tel()->maxLength(30)
                            ->helperText('The number your pilgrims call. Documents print no number until you add one.'),
                        TextInput::make('email')->label('E-mail')->email()->maxLength(255),
                    ]),

                Section::make('References')
                    ->schema([
                        TextInput::make('reference_prefix')
                            ->label('Your letters')
                            ->maxLength(5)
                            ->regex('/^[A-Z]{2,5}$/')
                            ->notIn(['RIH'])
                            ->unique('partners', 'reference_prefix', ignorable: fn (): Partner => $this->company())
                            ->validationMessages([
                                'regex' => 'Two to five capital letters, like CRL.',
                                'not_in' => 'Those are Rihla\'s letters.',
                                'unique' => 'Another company already uses those letters.',
                            ])
                            ->helperText('In front of new booking, payment and quotation numbers: CRL-B-2026-0012. Existing numbers keep theirs.'),
                    ]),

                Section::make('Bank transfer')
                    ->description('Where pilgrims are told to send money. Leave an account blank and they are told to ask you instead; nobody is ever given Rihla\'s.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('bank_name')->label('Bank')->maxLength(100),
                        TextInput::make('bank_account_name')->label('Account name')->maxLength(150),
                        ...array_map(
                            fn (string $currency): TextInput => TextInput::make("bank_accounts.{$currency}")->label("{$currency} account number")->maxLength(40),
                            self::CURRENCIES,
                        ),
                    ]),

                Section::make('Terms')
                    ->schema([
                        Textarea::make('invoice_terms')
                            ->label('Line at the foot of your documents')
                            ->rows(3)
                            ->maxLength(1000)
                            ->helperText('Only what your terms actually say. Leave it empty if you have none written yet.'),
                    ]),
            ])
            ->statePath('data');
    }

    /** @return array<Action> */
    protected function getFormActions(): array
    {
        return [Action::make('save')->label('Save')->submit('save')];
    }

    public function save(): void
    {
        abort_unless(self::canAccess(), 403);

        $data = $this->form->getState();
        $company = $this->company();

        $company->forceFill([
            'name' => $data['name'],
            'registration_number' => $data['registration_number'] ?: null,
            'address' => $data['address'] ?: null,
            'phone' => $data['phone'] ?: null,
            'email' => $data['email'] ?: null,
            'reference_prefix' => $data['reference_prefix'] ?: null,
            'bank_name' => $data['bank_name'] ?: null,
            'bank_account_name' => $data['bank_account_name'] ?: null,
            'bank_accounts' => array_filter((array) ($data['bank_accounts'] ?? [])) ?: null,
            'invoice_terms' => $data['invoice_terms'] ?: null,
        ])->save();

        Notification::make()->success()->title('Saved')->send();
    }
}
