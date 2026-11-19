<?php

namespace App\Filament\Company\Pages;

use App\Exceptions\DeskRefusal;
use App\Models\CompanyMembership;
use App\Models\Partner;
use App\Models\User;
use App\Services\Companies\CompanyTeam;
use App\Support\CompanyRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who works for the company, and in which role — companies plan, Phase 2c.
 *
 * The Owner adds people by e-mail (somebody new is sent a link to choose a
 * password), changes roles and takes people off the team. The roles are
 * Rihla's own jobs, narrowed to the Umrah back office ({@see CompanyRole}).
 */
class Team extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $title = 'Team';

    protected static ?int $navigationSort = 91;

    protected string $view = 'filament.company.team';

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Partner
            && auth()->user()?->roleAtCompany($company) === CompanyRole::OWNER;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => CompanyMembership::query()
                ->where('company_id', $this->company()->getKey())
                ->whereNotNull('accepted_at')
                ->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('user.email')->label('E-mail'),
                TextColumn::make('role')->badge(),
                TextColumn::make('accepted_at')->label('Joined')->date('j M Y'),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon('heroicon-o-arrows-right-left')
                    ->authorize(fn (): bool => self::canAccess())
                    ->fillForm(fn (CompanyMembership $record): array => ['role' => $record->role])
                    ->schema([Select::make('role')->options(self::roles())->required()])
                    ->action(fn (CompanyMembership $record, array $data) => $this->attempt(
                        fn () => app(CompanyTeam::class)->changeRole($this->mine($record), (string) $data['role']),
                        'Role changed',
                    )),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->authorize(fn (): bool => self::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription('They lose access at once.')
                    ->action(fn (CompanyMembership $record) => $this->attempt(
                        fn () => app(CompanyTeam::class)->remove($this->mine($record)),
                        'Removed',
                    )),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label('Add someone')
                ->icon('heroicon-o-user-plus')
                ->authorize(fn (): bool => self::canAccess())
                ->schema([
                    TextInput::make('name')->required()->maxLength(255),
                    TextInput::make('email')->label('E-mail')->email()->required()->maxLength(255),
                    TextInput::make('phone')->label('Mobile number')->tel()->maxLength(40)
                        ->helperText('Optional. They can sign in with it instead of their e-mail.'),
                    Select::make('role')->options(self::roles())->required()->default(CompanyRole::ROLES[2])
                        ->helperText('The same jobs as at Rihla. An Owner can also manage this team and your company details.'),
                ])
                ->action(fn (array $data) => $this->attempt(
                    fn () => app(CompanyTeam::class)->add($this->company(), (string) $data['name'], (string) $data['email'], (string) $data['role'], $this->user(), $data['phone'] ?? null),
                    'Added. Somebody new is e-mailed a link to choose their password.',
                )),
        ];
    }

    /** @return array<string, string> */
    private static function roles(): array
    {
        return array_combine(CompanyRole::ROLES, CompanyRole::ROLES);
    }

    private function company(): Partner
    {
        $company = Filament::getTenant();

        return $company instanceof Partner ? $company : abort(404);
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** A membership of this company, never another's — whatever id arrived. */
    private function mine(CompanyMembership $membership): CompanyMembership
    {
        abort_unless((int) $membership->company_id === (int) $this->company()->getKey(), 404);

        return $membership;
    }

    private function attempt(callable $change, string $done): void
    {
        try {
            $change();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title('Not changed')->body($refusal->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($done)->send();
    }
}
