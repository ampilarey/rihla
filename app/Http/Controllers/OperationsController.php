<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Card;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Services\Operations\Cards;
use App\Services\Operations\ListDocument;
use App\Services\Operations\Lists;
use App\Support\AuditContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lists, manifests, cards and tags, rendered when asked for by signed-in
 * staff who may see lists.
 *
 * Not signed, for the reason the staff invoice gives: a member of staff
 * printing a list they may already read in the panel is not the threat a
 * WhatsApp link is. The permission is the gate — `list.view` for a list,
 * and on top of it `payment.viewAny` for anything that says who owes what
 * and `document.viewAny` for who is missing which paper, because a name
 * and a debt are different disclosures. Every print of personal data is
 * audited, with the shape and the row count.
 */
class OperationsController extends Controller
{
    public function __construct(private readonly Lists $lists, private readonly Cards $cards) {}

    public function manifest(Departure $departure, Request $request): Response
    {
        $this->allow($request, 'list.view');

        $preset = (string) $request->query('preset', 'office');
        abort_unless(array_key_exists($preset, Lists::PRESETS), 404);

        $only = $request->filled('ids')
            ? $this->lists->travellers($departure)->whereIn('id', self::ids($request))->values()
            : null;

        $withMoney = $request->user()?->can('payment.viewAny') === true;

        return $this->send(
            $this->lists->manifest($departure, $preset, $only, $withMoney),
            $request,
            $departure,
            ['list' => 'manifest', 'preset' => $preset],
        );
    }

    public function busList(BusGroup $busGroup, Request $request): Response
    {
        $this->allow($request, 'list.view');

        return $this->send($this->lists->busList($busGroup), $request, $busGroup, ['list' => 'bus']);
    }

    public function roomList(DepartureHotel $departureHotel, Request $request): Response
    {
        $this->allow($request, 'list.view');

        $forHotel = $request->boolean('hotel');

        return $this->send(
            $this->lists->roomList($departureHotel, $forHotel),
            $request,
            $departureHotel,
            ['list' => 'rooms', 'hotel_copy' => $forHotel],
        );
    }

    public function payments(Departure $departure, Request $request): Response
    {
        $this->allow($request, 'list.view');
        $this->allow($request, 'payment.viewAny');

        return $this->send($this->lists->payments($departure), $request, $departure, ['list' => 'payments']);
    }

    public function documents(Departure $departure, Request $request): Response
    {
        $this->allow($request, 'list.view');
        $this->allow($request, 'document.viewAny');

        return $this->send($this->lists->documents($departure), $request, $departure, ['list' => 'documents']);
    }

    /** A sheet of cards or tags for the people named, or a whole bus or departure. */
    public function cards(Request $request, string $kind): Response
    {
        $this->allow($request, 'card.create');
        abort_unless(array_key_exists($kind, Card::KINDS), 404);

        $query = BookingTraveller::query()
            ->whereHas('booking', fn ($booking) => $booking->whereIn('status', Lists::LISTED));

        if ($request->filled('ids')) {
            $query->whereKey(self::ids($request));
        } elseif ($request->filled('bus')) {
            $query->where('bus_group_id', (int) $request->query('bus'));
        } elseif ($request->filled('departure')) {
            $query->whereHas('booking', fn ($booking) => $booking->where('departure_id', (int) $request->query('departure')));
        } else {
            abort(404);
        }

        $members = $query->with(['traveller', 'booking.customer', 'booking.departure'])->get();
        abort_if($members->isEmpty(), 404);

        $members = $members
            ->sortBy(fn (BookingTraveller $member): string => $member->booking->customer->name.' '.$member->traveller->full_name)
            ->values();

        $this->audit($members->first()->booking->departure, $request, ['document' => $kind.'_cards', 'count' => $members->count()]);

        return $this->cards->sheet($members, $kind)
            ->stream(($kind === Card::LUGGAGE ? 'luggage-tags' : 'customer-cards').'-'.now()->format('Ymd-Hi').'.pdf');
    }

    private function allow(Request $request, string $permission): void
    {
        abort_unless($request->user()?->can($permission) === true, 403);
    }

    /** @return list<int> */
    private static function ids(Request $request): array
    {
        return array_values(array_filter(array_map('intval', explode(',', (string) $request->query('ids')))));
    }

    /** @param  array<string, mixed>  $meta */
    private function send(ListDocument $list, Request $request, Model $subject, array $meta): Response
    {
        $format = (string) $request->query('format', 'pdf');

        $this->audit($subject, $request, $meta + ['format' => $format, 'rows' => count($list->rows)]);

        if ($format === 'csv') {
            return response()->streamDownload(
                function () use ($list): void {
                    echo $list->csv();
                },
                $list->filename.'.csv',
                ['Content-Type' => 'text/csv; charset=UTF-8'],
            );
        }

        return $this->lists->pdf($list)->stream($list->filename.'.pdf');
    }

    /**
     * Straight into `audit_logs`, the way a document download is: who
     * printed what, in which shape, and how many people were on it.
     *
     * @param  array<string, mixed>  $meta
     */
    private function audit(Model $subject, Request $request, array $meta): void
    {
        $user = $request->user();

        AuditLog::create([
            'user_id' => $user?->getKey(),
            'user_name' => $user?->name,
            'user_email' => $user?->email,
            'event' => AuditLog::DOWNLOADED,
            'channel' => AuditContext::channel(),
            'auditable_type' => $subject->getMorphClass(),
            'auditable_id' => $subject->getKey(),
            'old_values' => null,
            'new_values' => $meta,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 255) ?: null,
            'url' => $request->fullUrl(),
        ]);
    }
}
