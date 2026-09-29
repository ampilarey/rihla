<?php

namespace App\Filament\Host\Pages;

use App\Models\HostStatement;
use App\Models\Partner;
use App\Models\User;
use App\Services\Hosts\Earnings as EarningsReport;
use App\Services\Hosts\Statements;
use App\Support\HostContext;
use App\Support\HostRole;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;

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

    /**
     * Statements issued to this host, newest first — §16.9.
     *
     * @return Collection<int, HostStatement>
     */
    public function statements()
    {
        return HostStatement::query()
            ->where('partner_id', $this->host()->getKey())
            ->orderByDesc('period_start')
            ->orderBy('currency')
            ->limit(24)
            ->get();
    }

    public function downloadStatement(int $id): StreamedResponse
    {
        abort_unless(self::canAccess(), 403);

        $statement = HostStatement::query()->where('partner_id', $this->host()->getKey())->findOrFail($id);
        $pdf = app(Statements::class)->pdf($statement);

        return response()->streamDownload(fn () => print ($pdf), $statement->reference.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function host(): Partner
    {
        return HostContext::current() ?? abort(404);
    }
}
