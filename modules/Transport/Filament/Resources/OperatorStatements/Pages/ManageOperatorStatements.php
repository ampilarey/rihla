<?php

namespace Modules\Transport\Filament\Resources\OperatorStatements\Pages;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Modules\Transport\Filament\Resources\OperatorStatements\OperatorStatementResource;
use Modules\Transport\Models\OperatorMembership;
use Modules\Transport\Services\OperatorStatements;
use Modules\Transport\Services\OperatorTeam;
use Modules\Transport\Support\OperatorContext;

class ManageOperatorStatements extends ManageRecords
{
    protected static string $resource = OperatorStatementResource::class;

    /** @return array<int, string> */
    private static function outsideOperators(): array
    {
        return Partner::query()->where('kind', Partner::KIND_OPERATOR)
            ->when(OperatorContext::id(), fn ($q, int $id) => $q->whereKey($id))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label('Issue statements')
                ->icon('heroicon-o-document-text')
                // Rihla's staff issue them; an operator only reads them.
                ->visible(fn (): bool => ! OperatorContext::inPanel() && auth()->user()?->can('transport.manage') === true)
                ->schema([
                    Select::make('month')->label('Sailings in')->required()->native(false)
                        ->options(fn (): array => collect(range(1, 6))
                            ->mapWithKeys(fn (int $back): array => [now()->subMonthsNoOverflow($back)->format('Y-m') => now()->subMonthsNoOverflow($back)->format('F Y')])
                            ->all())
                        ->default(now()->subMonthNoOverflow()->format('Y-m')),
                ])
                ->action(function (array $data): void {
                    $month = CarbonImmutable::createFromFormat('Y-m-d', $data['month'].'-01');
                    $made = 0;
                    $skipped = [];

                    foreach (Partner::query()->where('kind', Partner::KIND_OPERATOR)->orderBy('name')->get() as $operator) {
                        try {
                            $made += count(app(OperatorStatements::class)->issue($operator, $month, auth()->user()));
                        } catch (DeskRefusal $refusal) {
                            $skipped[] = $refusal->getMessage();
                        }
                    }

                    Notification::make()->success()->title($made.' statement(s) issued')
                        ->body($skipped === [] ? null : implode("\n", $skipped))->send();
                }),
            Action::make('team')
                ->label('Give access')
                ->icon('heroicon-o-user-plus')
                ->color('gray')
                ->visible(fn (): bool => auth()->user()?->can('transport.manage') === true && self::outsideOperators() !== [])
                ->modalDescription('They can sign in at /operator. Somebody new is e-mailed a link to set their password.')
                ->schema([
                    Select::make('partner_id')->label('Operator')->options(fn (): array => self::outsideOperators())->required()
                        ->hidden(fn (): bool => OperatorContext::inPanel()),
                    TextInput::make('name')->label('Name')->maxLength(120),
                    TextInput::make('email')->label('E-mail')->email()->required()->maxLength(190),
                    Select::make('role')->options(OperatorMembership::ROLES)->required()->default(OperatorMembership::CREW)
                        ->helperText('An owner runs the timetable and adds crew; crew board passengers.'),
                ])
                ->action(function (array $data): void {
                    $operator = OperatorContext::current() ?? Partner::query()->where('kind', Partner::KIND_OPERATOR)->find($data['partner_id'] ?? null);

                    try {
                        if ($operator === null) {
                            throw new DeskRefusal('Choose the operator.');
                        }

                        app(OperatorTeam::class)->add($operator, (string) ($data['name'] ?? ''), (string) $data['email'], (string) $data['role'], auth()->user());
                        Notification::make()->success()->title($data['email'].' can now sign in for '.$operator->name)->send();
                    } catch (DeskRefusal $refusal) {
                        Notification::make()->danger()->title($refusal->getMessage())->send();
                    }
                }),
        ];
    }
}
