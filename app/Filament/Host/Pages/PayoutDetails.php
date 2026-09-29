<?php

namespace App\Filament\Host\Pages;

use App\Models\Partner;
use App\Models\User;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;

/**
 * Where Rihla sends the host's money — §16.9, §16 Phase 16. The owner's
 * alone (the `team` ability): a manager who could change the account a
 * payout goes to could redirect the business's money.
 *
 * Written with `forceFill` — the columns are deliberately not fillable on
 * {@see Partner}, so no other form can reach them by accident.
 *
 * @property-read Schema $form
 */
class PayoutDetails extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationLabel = 'Payout details';

    protected static ?string $title = 'Payout details';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'payout-details';

    protected string $view = 'filament.pages.services';

    /** @var array<string, mixed> */
    public array $data = [];

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::TEAM);
    }

    public function mount(): void
    {
        $host = $this->host();

        $this->form->fill([
            'payout_bank_name' => $host->payout_bank_name,
            'payout_account_name' => $host->payout_account_name,
            'payout_account_number' => $host->payout_account_number,
        ]);
    }

    public function getSubheading(): ?string
    {
        return $this->host()->settlement_model === Partner::FULL_COLLECTION
            ? 'Rihla collects your guests\' payments and sends your share here each month, against your statement.'
            : 'Your guests pay you at the property; Rihla sends money here only when it holds some of yours — shown on your statement.';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('payout_bank_name')->label('Bank')->required()->maxLength(120)
                    ->helperText('"Bank of Maldives", "State Bank of India — Malé".'),
                TextInput::make('payout_account_name')->label('Account name')->required()->maxLength(255)
                    ->helperText('Exactly as the bank has it.'),
                TextInput::make('payout_account_number')->label('Account number')->required()->maxLength(40)
                    ->regex('/^[0-9A-Za-z \-]+$/')
                    ->helperText('Stored encrypted. Only Rihla\'s Finance team sees it in full.'),
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

        $this->host()->forceFill([
            'payout_bank_name' => trim((string) $data['payout_bank_name']),
            'payout_account_name' => trim((string) $data['payout_account_name']),
            'payout_account_number' => preg_replace('/\s+/', '', (string) $data['payout_account_number']),
        ])->save();

        Notification::make()->success()->title('Saved')->send();
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }
}
