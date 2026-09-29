<?php

namespace App\Filament\Host\Resources\Bookings\Pages;

use App\Filament\Host\Resources\Bookings\BookingResource;
use App\Filament\Resources\Stays\Schemas\StayDetails;
use App\Models\AuditLog;
use App\Models\StayGuest;
use App\Models\User;
use App\Services\Hosts\Reports;
use App\Support\HostContext;
use App\Support\HostRole;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Pages\ListRecords;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListBookings extends ListRecords
{
    protected static string $resource = BookingResource::class;

    protected function getHeaderActions(): array
    {
        return [$this->registerExport()];
    }

    /**
     * The guest register for a range, as CSV — §16.10. Everybody at the
     * desk may take it; identifiers are whole only for whoever may see
     * them whole (owner and manager), masked for reception. Every export
     * is written to the audit log: it is a list of passport numbers.
     */
    private function registerExport(): Action
    {
        return Action::make('register')
            ->label('Guest register (CSV)')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->schema([
                DatePicker::make('from')->required()->default(now()->startOfMonth()),
                DatePicker::make('until')->required()->default(now()->endOfMonth())->afterOrEqual('from'),
            ])
            ->action(function (array $data): StreamedResponse {
                $host = HostContext::current() ?? abort(404);
                $user = auth()->user();
                abort_unless($user instanceof User && HostContext::allows($user, $host, HostRole::BOOKINGS), 403);

                $from = CarbonImmutable::parse($data['from'])->startOfDay();
                $end = CarbonImmutable::parse($data['until'])->startOfDay()->addDay();
                $whole = HostRole::allows($user->roleAt($host), HostRole::REGISTER_UNMASKED);

                $guests = StayGuest::query()
                    ->with('stay.property')
                    ->whereHas('stay', fn ($query) => $query
                        ->whereIn('status', Reports::COUNTED)
                        ->whereDate('check_in', '<', $end->toDateString())
                        ->whereDate('check_out', '>', $from->toDateString())
                        ->whereHas('property', fn ($q) => $q->where('partner_id', $host->getKey())))
                    ->get();

                AuditLog::create([
                    'user_id' => $user->getKey(),
                    'user_name' => $user->name,
                    'user_email' => $user->email,
                    'event' => AuditLog::DOWNLOADED,
                    'auditable_type' => $host->getMorphClass(),
                    'auditable_id' => $host->getKey(),
                    'old_values' => null,
                    'new_values' => [
                        'export' => 'guest register',
                        'from' => $from->toDateString(),
                        'until' => $end->subDay()->toDateString(),
                        'rows' => $guests->count(),
                        'identifiers' => $whole ? 'whole' : 'masked',
                    ],
                    'ip_address' => request()->ip(),
                    'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
                    'url' => request()->fullUrl(),
                ]);

                return response()->streamDownload(function () use ($guests, $whole): void {
                    $out = fopen('php://output', 'w');
                    fputcsv($out, ['Stay', 'Listing', 'Check in', 'Check out', 'Name', 'Nationality', 'Born', 'Document', 'Number', 'Lead guest']);

                    foreach ($guests as $guest) {
                        fputcsv($out, [
                            $guest->stay?->reference,
                            (string) $guest->stay?->property?->name,
                            $guest->stay?->check_in->toDateString(),
                            $guest->stay?->check_out->toDateString(),
                            $guest->full_name,
                            $guest->nationality,
                            $guest->date_of_birth?->toDateString(),
                            $guest->id_type,
                            $whole ? $guest->id_number : StayDetails::mask($guest->id_number),
                            $guest->is_lead ? 'yes' : 'no',
                        ]);
                    }

                    fclose($out);
                }, 'guest-register-'.$from->toDateString().'.csv', ['Content-Type' => 'text/csv']);
            });
    }
}
