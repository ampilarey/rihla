<?php

namespace App\Filament\Host\Pages;

use App\Exceptions\DeskRefusal;
use App\Models\HostInvitation;
use App\Models\HostMembership;
use App\Models\Partner;
use App\Models\User;
use App\Services\Hosts\HostTeam;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Who works here — §16.6 `TeamResource`, owner only.
 *
 * Invite by e-mail with a role; the link is shown on screen, because mail
 * may not be configured, and it is shown once. Change a role, or take
 * somebody off the team. A host always keeps an owner.
 */
class Team extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Team';

    protected static ?string $title = 'Team';

    protected static ?int $navigationSort = 8;

    protected string $view = 'filament.host.team';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::TEAM);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => HostMembership::query()
                ->where('partner_id', $this->host()->getKey())
                ->whereNotNull('accepted_at')
                ->with('user'))
            ->columns([
                TextColumn::make('user.name')->label('Name'),
                TextColumn::make('user.email')->label('E-mail'),
                TextColumn::make('role')->badge()->formatStateUsing(fn (string $state): string => HostRole::label($state)),
                TextColumn::make('accepted_at')->label('Joined')->date('j M Y'),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label('Change role')
                    ->icon('heroicon-o-arrows-right-left')
                    ->authorize(fn (): bool => self::canAccess())
                    ->fillForm(fn (HostMembership $record): array => ['role' => $record->role])
                    ->schema([Select::make('role')->options(self::roles())->required()])
                    ->action(fn (HostMembership $record, array $data) => $this->attempt(
                        fn () => app(HostTeam::class)->changeRole($this->mine($record), $data['role']),
                        'Role changed',
                    )),
                Action::make('remove')
                    ->label('Remove')
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->authorize(fn (): bool => self::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription('They lose access at once.')
                    ->action(fn (HostMembership $record) => $this->attempt(
                        fn () => app(HostTeam::class)->remove($this->mine($record)),
                        'Removed',
                    )),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('invite')
                ->label('Invite someone')
                ->icon('heroicon-o-envelope')
                ->authorize(fn (): bool => self::canAccess())
                ->schema([
                    TextInput::make('email')->email()->required()->maxLength(255),
                    Select::make('role')->options(self::roles())->required()->default(HostRole::RECEPTION)
                        ->helperText('Reception runs the desk. Managers also edit listings, the page and see earnings. Owners also manage the team.'),
                ])
                ->action(function (array $data): void {
                    try {
                        $token = app(HostTeam::class)->invite($this->host(), $data['email'], $data['role'], $this->user());
                    } catch (DeskRefusal $refusal) {
                        Notification::make()->danger()->title('Not invited')->body($refusal->getMessage())->send();

                        return;
                    }

                    // Shown once, here, because mail may not be configured:
                    // copy it into WhatsApp or an e-mail yourself.
                    Notification::make()
                        ->success()
                        ->persistent()
                        ->title('Send them this link — it is not shown again')
                        ->body(route('host.join', ['token' => $token]).' — it works once, for '.HostTeam::INVITATION_DAYS.' days, for '.$data['email'].'.')
                        ->send();
                }),
        ];
    }

    /** @return Collection<int, HostInvitation> */
    public function pendingInvitations(): Collection
    {
        return HostInvitation::query()->where('partner_id', $this->host()->getKey())->live()->latest()->get();
    }

    public function revokeInvitation(int $id): void
    {
        abort_unless(self::canAccess(), 403);

        HostInvitation::query()
            ->where('partner_id', $this->host()->getKey())
            ->whereKey($id)
            ->update(['expires_at' => now()]);

        Notification::make()->success()->title('Invitation cancelled')->send();
    }

    /** @return array<string, string> */
    private static function roles(): array
    {
        return array_combine(HostRole::ALL, array_map(HostRole::label(...), HostRole::ALL));
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }

    private function user(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    /** A membership of this host, never another's — whatever id arrived. */
    private function mine(HostMembership $membership): HostMembership
    {
        abort_unless((int) $membership->partner_id === (int) $this->host()->getKey(), 404);

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
