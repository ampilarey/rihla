<?php

namespace Modules\Transport\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\AuditContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Str;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatTicket;
use Modules\Transport\Models\Sailing;
use Modules\Umrah\Services\Invoices\Documents;
use Modules\Umrah\Services\Operations\ListDocument;

/**
 * A sailing's passenger manifest — docs/TRANSPORT_PLAN.md (T3). Everybody
 * holding a seat, confirmed or not, with who has boarded; printed through
 * the same list template as every other list on the site.
 */
final class Manifest
{
    public function __construct(private readonly Documents $documents) {}

    public function list(Sailing $sailing): ListDocument
    {
        $sailing->loadMissing(['route.origin', 'route.destination', 'vessel']);

        $tickets = BoatTicket::query()
            ->whereIn('boat_booking_id', $sailing->bookings()->whereIn('status', BoatBooking::HOLDS_SEATS)->select('id'))
            ->with('booking')
            ->orderBy('boat_booking_id')->orderBy('id')
            ->get();

        $rows = [];
        foreach ($tickets->values() as $i => $ticket) {
            $booking = $ticket->booking;
            $rows[] = [
                'n' => $i + 1,
                'name' => $ticket->full_name,
                'age' => $ticket->is_child ? 'Child' : 'Adult',
                'booking' => $booking->reference.($booking->status === BoatBooking::CONFIRMED ? '' : ' *'),
                'contact' => $booking->contact_phone,
                'boarded' => $ticket->boarded_at?->format('H:i') ?? '',
            ];
        }

        $boarded = $tickets->whereNotNull('boarded_at')->count();

        return new ListDocument(
            title: 'Passenger manifest',
            subtitle: $sailing->route->label().' · '.$sailing->departs_at->format('D j M Y, H:i').' · '.$sailing->vessel->name
                .($sailing->vessel->registration_number ? ' ('.$sailing->vessel->registration_number.')' : '')
                .' · '.$boarded.' of '.$tickets->count().' on board',
            columns: ['n' => '#', 'name' => 'Name', 'age' => 'Fare', 'booking' => 'Booking', 'contact' => 'Contact', 'boarded' => 'Boarded'],
            rows: $rows,
            notes: ['A booking not yet confirmed is marked *. "Boarded" is the time the crew scanned the passenger on.'],
            filename: 'manifest-'.Str::slug($sailing->route->label()).'-'.$sailing->departs_at->format('Y-m-d-Hi'),
        );
    }

    public function pdf(Sailing $sailing): PdfDocument
    {
        return Pdf::loadView('pdf.list', ['list' => $this->list($sailing), 'issuer' => $this->documents->issuer()])->setPaper('a4');
    }

    public function filename(Sailing $sailing): string
    {
        return $this->list($sailing)->filename.'.pdf';
    }

    /** A list of names and phones leaving the building is recorded. */
    public function audit(Sailing $sailing, ?User $user): void
    {
        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => AuditLog::DOWNLOADED,
            'channel' => AuditContext::channel(),
            'auditable_type' => $sailing->getMorphClass(),
            'auditable_id' => $sailing->getKey(),
            'old_values' => null,
            'new_values' => ['document' => 'manifest'],
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255) ?: null,
            'url' => request()->fullUrl(),
        ]);
    }
}
