<?php

namespace App\Filament\Host\Pages;

use App\Models\Partner;
use App\Models\User;
use App\Services\Hosts\Reports as HostReports;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * A host's reports — §16.10. Owner and manager: these are money.
 */
class Reports extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Reports';

    protected static ?string $title = 'Reports';

    protected static ?int $navigationSort = 7;

    protected string $view = 'filament.host.reports';

    #[Url]
    public string $from = '';

    #[Url]
    public string $until = '';

    public static function canAccess(): bool
    {
        $host = HostContext::current();
        $user = auth()->user();

        return $host !== null && $user instanceof User && HostContext::allows($user, $host, HostRole::EARNINGS);
    }

    public function mount(): void
    {
        [$from, $until] = $this->range();
        $this->from = $from->toDateString();
        $this->until = $until->toDateString();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function range(): array
    {
        try {
            $from = $this->from !== '' ? CarbonImmutable::parse($this->from) : CarbonImmutable::today()->startOfMonth();
            $until = $this->until !== '' ? CarbonImmutable::parse($this->until) : CarbonImmutable::today()->endOfMonth();
        } catch (\Throwable) {
            $from = CarbonImmutable::today()->startOfMonth();
            $until = CarbonImmutable::today()->endOfMonth();
        }

        // A year at most, and never backwards.
        if ($until->lessThan($from)) {
            $until = $from;
        }

        return [$from->startOfDay(), $until->greaterThan($from->addYear()) ? $from->addYear()->startOfDay() : $until->startOfDay()];
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        [$from, $until] = $this->range();

        return app(HostReports::class)->forRange($this->host(), $from, $until);
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }
}
