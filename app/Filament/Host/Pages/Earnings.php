<?php

namespace App\Filament\Host\Pages;

use App\Models\Partner;
use App\Models\User;
use App\Services\Hosts\Earnings as EarningsReport;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * What the host earned, month by month — §16.6. Owner and manager only;
 * reception runs the desk and does not see the money (§16.6's role table).
 */
class Earnings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Earnings';

    protected static ?string $title = 'Earnings';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.host.earnings';

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::EARNINGS);
    }

    public function mount(): void
    {
        $this->month = $this->start()->format('Y-m');
    }

    public function start(): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month) === 1
            ? CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth()
            : CarbonImmutable::today()->startOfMonth();
    }

    public function previousMonth(): void
    {
        $this->month = $this->start()->subMonth()->format('Y-m');
    }

    public function nextMonth(): void
    {
        $this->month = $this->start()->addMonth()->format('Y-m');
    }

    /** @return array<string, array<string, mixed>> */
    public function report(): array
    {
        return app(EarningsReport::class)->forMonth($this->host(), $this->start());
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }
}
