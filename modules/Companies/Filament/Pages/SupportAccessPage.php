<?php

namespace Modules\Companies\Filament\Pages;

use App\Exceptions\DeskRefusal;
use App\Models\Partner;
use App\Support\CompanyRole;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use Modules\Companies\Models\SupportGrant;
use Modules\Companies\Services\SupportAccess;

/**
 * Support access — companies plan, Phase 5c. Rihla asks; the Owner decides,
 * for how long, and can end it at any time. Every page Rihla opened under a
 * grant is listed against it. The Owner only.
 */
class SupportAccessPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-lifebuoy';

    protected static ?string $navigationLabel = 'Support access';

    protected static ?string $title = 'Support access';

    protected static ?string $slug = 'support-access';

    protected static ?int $navigationSort = 92;

    protected string $view = 'filament.company.support-access';

    public static function canAccess(): bool
    {
        $company = Filament::getTenant();

        return $company instanceof Partner
            && auth()->user()?->roleAtCompany($company) === CompanyRole::OWNER;
    }

    public function getSubheading(): ?string
    {
        return 'Rihla can look inside your panel only when you allow it, for as long as you choose, and can change nothing. Every page it opens is listed here.';
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => SupportGrant::query()
                ->where('company_id', $this->company()->getKey())
                ->with(['requester', 'decider'])
                ->withCount('visits')
                ->latest())
            ->columns([
                TextColumn::make('created_at')->label('Asked')->since(),
                TextColumn::make('requester.name')->label('Who'),
                TextColumn::make('reason')->label('Why')->wrap(),
                TextColumn::make('status')
                    ->label('Now')
                    ->badge()
                    ->state(fn (SupportGrant $record): string => match ($record->state()) {
                        SupportGrant::PENDING => 'Waiting for you',
                        SupportGrant::LIVE => 'Allowed until '.$record->expires_at?->format('j M, H:i'),
                        SupportGrant::DECLINED => 'Declined',
                        default => 'Ended',
                    })
                    ->color(fn (SupportGrant $record): string => match ($record->state()) {
                        SupportGrant::PENDING => 'warning',
                        SupportGrant::LIVE => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('visits_count')->label('Pages opened'),
            ])
            ->recordActions([
                Action::make('allow')
                    ->label('Allow')
                    ->icon('heroicon-o-check')
                    ->visible(fn (SupportGrant $record): bool => $record->state() === SupportGrant::PENDING)
                    ->schema([
                        Select::make('hours')
                            ->label('For')
                            ->options(array_combine(SupportAccess::HOURS, array_map(fn (int $h): string => $h === 1 ? '1 hour' : "{$h} hours", SupportAccess::HOURS)))
                            ->default(SupportAccess::HOURS[0])
                            ->required(),
                    ])
                    ->action(fn (SupportGrant $record, array $data) => $this->attempt(
                        fn () => app(SupportAccess::class)->grant($this->mine($record), auth()->user(), (int) $data['hours']),
                        'Allowed',
                    )),
                Action::make('decline')
                    ->label('Decline')
                    ->color('gray')
                    ->visible(fn (SupportGrant $record): bool => $record->state() === SupportGrant::PENDING)
                    ->requiresConfirmation()
                    ->action(fn (SupportGrant $record) => $this->attempt(fn () => app(SupportAccess::class)->decline($this->mine($record), auth()->user()), 'Declined')),
                Action::make('end')
                    ->label('End now')
                    ->color('danger')
                    ->visible(fn (SupportGrant $record): bool => $record->state() === SupportGrant::LIVE)
                    ->requiresConfirmation()
                    ->action(fn (SupportGrant $record) => $this->attempt(fn () => app(SupportAccess::class)->revoke($this->mine($record), auth()->user()), 'Ended')),
                Action::make('pages')
                    ->label('Pages opened')
                    ->color('gray')
                    ->visible(fn (SupportGrant $record): bool => $record->visits_count > 0)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalContent(fn (SupportGrant $record): HtmlString => new HtmlString('<ul style="margin:0;padding-inline-start:1.25rem;line-height:1.8;">'
                        .$this->mine($record)->visits()->orderBy('visited_at')->get()
                            ->map(fn ($visit): string => '<li>'.e($visit->visited_at->format('j M, H:i')).' — '.e($visit->path).'</li>')->implode('')
                        .'</ul>')),
            ])
            ->emptyStateHeading('Rihla has never asked')
            ->emptyStateDescription('When Rihla needs to look at something to help you, the request appears here for you to answer.');
    }

    private function attempt(callable $change, string $done): void
    {
        abort_unless(self::canAccess(), 403);

        try {
            $change();
            Notification::make()->success()->title($done)->send();
        } catch (DeskRefusal $refusal) {
            Notification::make()->danger()->title($refusal->getMessage())->send();
        }
    }

    /** A grant of this company's, whatever the table was handed. */
    private function mine(SupportGrant $grant): SupportGrant
    {
        return SupportGrant::query()->where('company_id', $this->company()->getKey())->findOrFail($grant->getKey());
    }

    private function company(): Partner
    {
        /** @var Partner */
        return Filament::getTenant();
    }
}
