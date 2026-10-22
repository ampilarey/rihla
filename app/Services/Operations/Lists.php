<?php

namespace App\Services\Operations;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\BusGroup;
use App\Models\Departure;
use App\Models\DepartureHotel;
use App\Models\Document;
use App\Models\NusukPermit;
use App\Models\Room;
use App\Models\VisaApplication;
use App\Services\Invoices\Documents;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * The lists a departure runs on — Hijaz's, brought across.
 *
 * The manifest in the shape each reader wants (the office, the airline,
 * the hotel, the ministry), one list per bus, one rooming list per hotel in
 * an office copy and a hotel copy, who still owes what, and which papers
 * are still missing. Built from the same rows, printed as PDF or exported
 * as CSV, and every print of personal data is audited by the controller
 * that serves it.
 *
 * Money is a separate disclosure from a name. The office list carries a
 * "Paid" column only for a reader holding `payment.viewAny`, and the
 * payment-status list is closed to anybody else; a tour leader prints the
 * bus list and never learns who is behind on their instalments.
 */
final class Lists
{
    /** @var array<string, string> */
    public const PRESETS = [
        'office' => 'Office list (everything)',
        'airline' => 'Airline list (passport details and tickets)',
        'hotel' => 'Hotel list (names, passports, rooms)',
        'ministry' => 'Ministry list (passport, date of birth, nationality)',
    ];

    /** Said under every list that prints a phone, because the column falls back. */
    private const PHONE_NOTE = 'Phone is the traveller\'s own, or else their family head\'s, or else the person who booked.';

    /**
     * Bookings whose people are on a list: holding a seat while the money
     * comes in, confirmed, or home. A draft is a checkout somebody walked
     * away from, and an expired or cancelled booking is nobody travelling.
     *
     * @var list<string>
     */
    public const LISTED = [Booking::HELD, Booking::CONFIRMED, Booking::COMPLETED];

    public function __construct(
        private readonly Documents $documents,
        private readonly Families $families,
    ) {}

    /**
     * @param  Collection<int, BookingTraveller>|null  $only  a selection of the departure's travellers, or everybody
     * @param  bool  $withMoney  whether the reader may see what is owed (`payment.viewAny`)
     */
    public function manifest(Departure $departure, string $preset = 'office', ?Collection $only = null, bool $withMoney = true): ListDocument
    {
        $travellers = $only ?? $this->travellers($departure);
        $context = $this->context($departure);
        $roomColumns = $this->roomColumns($context['hotels']);

        $columns = match ($preset) {
            'airline' => ['n' => '#', 'name' => 'Name as on passport', 'passport' => 'Passport no', 'nationality' => 'Nat.', 'dob' => 'Date of birth', 'gender' => 'Sex', 'expiry' => 'Expiry', 'ticket' => 'Ticket', 'seat' => 'Seat'],
            'hotel' => ['n' => '#', 'name' => 'Name', 'gender' => 'Sex', 'passport' => 'Passport no', 'nationality' => 'Nat.', ...$roomColumns],
            'ministry' => ['n' => '#', 'name' => 'Name', 'passport' => 'Passport no', 'dob' => 'Date of birth', 'gender' => 'Sex', 'nationality' => 'Nationality', 'phone' => 'Phone'],
            default => [
                'n' => '#', 'name' => 'Name', 'passport' => 'Passport', 'expiry' => 'Expiry', 'dob' => 'DOB', 'gender' => 'Sex',
                'phone' => 'Phone', 'booked_by' => 'Booked by', 'booking' => 'Booking', 'bus' => 'Bus',
                ...$roomColumns,
                'visa' => 'Visa', 'ticket' => 'Ticket',
                ...($withMoney ? ['paid' => 'Paid'] : []),
            ],
        };

        $rows = [];

        foreach ($travellers->values() as $i => $member) {
            $rows[] = $this->row($member, $i + 1, $context, $withMoney, markHead: $preset === 'office');
        }

        $notes = [];

        if ($preset === 'office') {
            $notes[] = 'A booking not yet confirmed is marked *. (head) marks the head of each family, who holds its documents. '.self::PHONE_NOTE;

            if ($withMoney) {
                $notes[] = 'Paid shows what is still owed on the booking.';
            }
        }

        return new ListDocument(
            title: self::PRESETS[$preset] ?? 'List',
            subtitle: $this->subtitle($departure).' · '.count($rows).' '.Str::plural('traveller', count($rows)),
            columns: $columns,
            rows: $rows,
            landscape: count($columns) > 7,
            notes: $notes,
            filename: $this->slug($departure).'-'.$preset.'-list',
        );
    }

    /** One coach: who is on it, a box to tick as each boards, and what the leader needs to know. */
    public function busList(BusGroup $bus): ListDocument
    {
        $departure = $bus->departure;
        $context = $this->context($departure);

        $members = $this->inFamilies($bus->members()->with(['traveller', 'booking.customer', 'busGroup'])->get(), $context);

        $rows = [];

        foreach ($members as $i => $member) {
            $rows[] = $this->row($member, $i + 1, $context, false, markHead: true) + [
                'tick' => $member->isCheckedIn() ? '☑' : '☐',
                'needs' => $this->needs($member),
            ];
        }

        return new ListDocument(
            title: $bus->name,
            subtitle: $this->subtitle($departure)
                .' · leader '.($bus->leaderLabel() ?? '—').($bus->leader_phone ? ' '.$bus->leader_phone : '')
                .' · '.count($rows).' on board'.($bus->capacity ? ' of '.$bus->capacity : ''),
            columns: ['tick' => '✓', 'n' => '#', 'name' => 'Name', 'phone' => 'Phone', 'booked_by' => 'Booked by', ...$this->roomColumns($context['hotels']), 'needs' => 'Needs / emergency'],
            rows: $rows,
            landscape: true,
            notes: ['Tick the box as each person boards. (head) marks the head of each family, who holds its documents. '.self::PHONE_NOTE],
            filename: $this->slug($departure).'-'.Str::slug($bus->name),
        );
    }

    /** The hotel's copy has no phone numbers and no booker; the office copy has both, and marks each family's head. */
    public function roomList(DepartureHotel $hotel, bool $forHotel = false): ListDocument
    {
        $rows = [];
        $families = $this->families->onDeparture($hotel->departure);

        foreach ($hotel->rooms()->with(['assignments.traveller.customer'])->get() as $room) {
            $occupants = $room->occupants();

            if ($occupants->isEmpty()) {
                $rows[] = ['room' => $room->label, 'beds' => $room->capacity, 'type' => $room->genderLabel(), 'name' => '(empty)', 'passport' => '', 'nationality' => '', 'phone' => '', 'booked_by' => ''];

                continue;
            }

            foreach ($occupants as $traveller) {
                // A traveller belongs to the customer who books them, so
                // their family is the one booked under that customer.
                $family = $families->get((int) $traveller->customer_id);
                $member = $family?->memberFor((int) $traveller->getKey());
                $isHead = false;
                $phone = trim((string) $traveller->phone) !== '' ? (string) $traveller->phone : (string) $traveller->customer->phone;

                if ($family !== null && $member !== null) {
                    $isHead = $family->isHead($member);
                    $phone = (string) ($family->phoneFor($member) ?? $phone);
                }

                $rows[] = [
                    'room' => $room->label,
                    'beds' => $room->capacity,
                    'type' => $room->genderLabel(),
                    'name' => $traveller->full_name.(! $forHotel && $isHead ? ' (head)' : ''),
                    'passport' => (string) $traveller->passport_number,
                    'nationality' => (string) $traveller->nationality,
                    'phone' => $phone,
                    'booked_by' => (string) $traveller->customer->name,
                ];
            }
        }

        $columns = $forHotel
            ? ['room' => 'Room', 'beds' => 'Beds', 'type' => 'Type', 'name' => 'Guest', 'passport' => 'Passport no', 'nationality' => 'Nat.']
            : ['room' => 'Room', 'beds' => 'Beds', 'type' => 'Type', 'name' => 'Guest', 'passport' => 'Passport no', 'booked_by' => 'Booked by', 'phone' => 'Phone'];

        $departure = $hotel->departure;

        return new ListDocument(
            title: $hotel->name.' rooming list'.($forHotel ? ' (hotel copy)' : ''),
            subtitle: $hotel->cityLabel().' · '.$this->subtitle($departure),
            columns: $columns,
            rows: $rows,
            landscape: false,
            notes: $forHotel ? [] : ['(head) marks the head of each family, who holds its documents. '.self::PHONE_NOTE],
            filename: $this->slug($departure).'-'.Str::slug((string) $hotel->city).'-rooms'.($forHotel ? '-hotel' : ''),
        );
    }

    /** Who still owes what, booking by booking. For `payment.viewAny` only. */
    public function payments(Departure $departure): ListDocument
    {
        $rows = [];

        $bookings = Booking::query()
            ->where('departure_id', $departure->getKey())
            ->whereIn('status', self::LISTED)
            ->with(['customer', 'travellers'])
            ->orderBy('id')
            ->get();

        foreach ($bookings as $i => $booking) {
            $rows[] = [
                'n' => $i + 1,
                'booking' => (string) $booking->reference,
                'customer' => (string) $booking->customer->name,
                'phone' => (string) $booking->customer->phone,
                'people' => $booking->travellers->count(),
                'status' => ucfirst((string) $booking->status),
                'total' => $booking->total()->format(),
                'paid' => $booking->paid()->format(),
                'balance' => $booking->balance()->minor > 0 ? $booking->balance()->format() : 'paid',
            ];
        }

        return new ListDocument(
            title: 'Payment status',
            subtitle: $this->subtitle($departure).' · '.count($rows).' '.Str::plural('booking', count($rows)),
            columns: ['n' => '#', 'booking' => 'Booking', 'customer' => 'Customer', 'phone' => 'Phone', 'people' => 'People', 'status' => 'Status', 'total' => 'Total', 'paid' => 'Received', 'balance' => 'Balance'],
            rows: $rows,
            landscape: true,
            filename: $this->slug($departure).'-payments',
        );
    }

    /** Which papers are still missing, person by person. For `document.viewAny` only. */
    public function documents(Departure $departure): ListDocument
    {
        $travellers = $this->travellers($departure);
        $context = $this->context($departure);

        $passports = Document::query()
            ->whereIn('traveller_id', $travellers->pluck('traveller_id'))
            ->where('type', Document::PASSPORT)
            ->with('versions')
            ->get()
            ->keyBy('traveller_id');

        $rows = [];

        foreach ($travellers as $i => $member) {
            $passport = $passports->get($member->traveller_id);
            $key = $member->booking_id.'-'.$member->traveller_id;
            $family = $context['families']->get((int) $member->booking->customer_id);

            $rows[] = [
                'n' => $i + 1,
                'name' => $member->traveller->full_name.($family?->isHead($member) === true ? ' (head)' : ''),
                'phone' => $this->phoneOf($member, $context),
                'passport_copy' => match (true) {
                    ! $passport instanceof Document || $passport->currentVersion() === null => 'missing',
                    $passport->status === Document::VERIFIED => 'verified',
                    $passport->status === Document::REJECTED => 'rejected',
                    default => 'to check',
                },
                'expiry' => $member->traveller->passport_expiry?->format('d/m/Y') ?? '',
                'visa' => $context['visas'][$key] ?? 'none',
                'permit' => $context['permits'][$key] ?? 'none',
                'ticket' => $member->ticket_number ? 'issued' : '',
            ];
        }

        return new ListDocument(
            title: 'Documents still owed',
            subtitle: $this->subtitle($departure),
            columns: ['n' => '#', 'name' => 'Name', 'phone' => 'Phone', 'passport_copy' => 'Passport copy', 'expiry' => 'Expiry', 'visa' => 'Visa', 'permit' => 'Umrah permit', 'ticket' => 'Ticket'],
            rows: $rows,
            landscape: true,
            notes: ['(head) marks the head of each family, who holds its documents: ask them for what the family still owes. '.self::PHONE_NOTE],
            filename: $this->slug($departure).'-documents',
        );
    }

    public function pdf(ListDocument $list): PdfDocument
    {
        return Pdf::loadView('pdf.list', ['list' => $list, 'issuer' => $this->documents->issuer()])
            ->setPaper('a4', $list->landscape ? 'landscape' : 'portrait');
    }

    /**
     * Everybody on this departure's listed bookings, confirmed first, then
     * by who booked them, each family together with its head first.
     *
     * @return Collection<int, BookingTraveller>
     */
    public function travellers(Departure $departure): Collection
    {
        $members = BookingTraveller::query()
            ->whereIn(
                'booking_id',
                Booking::query()->where('departure_id', $departure->getKey())->whereIn('status', self::LISTED)->select('id'),
            )
            ->with(['traveller', 'booking.customer', 'busGroup'])
            ->get();

        return $this->inFamilies($members, ['families' => $this->families->group($members)]);
    }

    // ── Internals ────────────────────────────────────────────────────────

    /**
     * What every row needs and no row should query for itself: the hotels,
     * who is in which room, and where each visa and permit stands.
     *
     * @return array{hotels: Collection<int, DepartureHotel>, rooms: array<int, array<int, string>>, visas: array<string, string>, permits: array<string, string>, families: Collection<int, TravellingFamily>}
     */
    private function context(Departure $departure): array
    {
        $hotels = $departure->hotels()->get();
        $rooms = [];

        foreach (Room::query()->whereIn('departure_hotel_id', $hotels->modelKeys())->with('assignments')->get() as $room) {
            foreach ($room->assignments as $assignment) {
                $rooms[(int) $room->departure_hotel_id][(int) $assignment->traveller_id] = (string) $room->label;
            }
        }

        $bookingIds = Booking::query()->where('departure_id', $departure->getKey())->select('id');

        $visas = [];

        // Ordered by attempt, so the latest overwrites the earlier ones.
        foreach (VisaApplication::query()->whereIn('booking_id', $bookingIds)->orderBy('attempt')->get() as $visa) {
            $visas[$visa->booking_id.'-'.$visa->traveller_id] = str_replace('_', ' ', (string) $visa->status);
        }

        $permits = [];

        foreach (NusukPermit::query()->whereIn('booking_id', $bookingIds)->where('kind', NusukPermit::UMRAH)->orderBy('id')->get() as $permit) {
            $permits[$permit->booking_id.'-'.$permit->traveller_id] = str_replace('_', ' ', (string) $permit->status);
        }

        return [
            'hotels' => $hotels,
            'rooms' => $rooms,
            'visas' => $visas,
            'permits' => $permits,
            // Every family on the departure, not only those on the page, so
            // a selection or one bus still knows who heads each family.
            'families' => $this->families->onDeparture($departure),
        ];
    }

    /**
     * Members in list order: unconfirmed last, then by who booked them,
     * each family together with its head first.
     *
     * @param  Collection<int, BookingTraveller>  $members  with traveller and booking.customer loaded
     * @param  array{families: Collection<int, TravellingFamily>}  $context
     * @return Collection<int, BookingTraveller>
     */
    private function inFamilies(Collection $members, array $context): Collection
    {
        return $members
            ->sortBy(fn (BookingTraveller $member): string => implode("\0", [
                $member->booking->status === Booking::HELD ? '1' : '0',
                $member->booking->customer->name,
                sprintf('%012d', $member->booking->customer_id),
                $context['families']->get((int) $member->booking->customer_id)?->isHead($member) === true ? '0' : '1',
                $member->traveller->full_name,
            ]))
            ->values();
    }

    /**
     * The number to ring about somebody: their own, or else their family
     * head's, or else the person who booked them.
     *
     * @param  array{families: Collection<int, TravellingFamily>}  $context
     */
    private function phoneOf(BookingTraveller $member, array $context): string
    {
        $family = $context['families']->get((int) $member->booking->customer_id);

        return (string) ($family?->phoneFor($member) ?? $member->booking->customer->phone);
    }

    /**
     * @param  Collection<int, DepartureHotel>  $hotels
     * @return array<string, string>
     */
    private function roomColumns(Collection $hotels): array
    {
        return $hotels->mapWithKeys(fn (DepartureHotel $hotel): array => ['room_'.$hotel->getKey() => $hotel->cityLabel().' room'])->all();
    }

    /**
     * Every fact a list might print about one person; the columns choose.
     *
     * `$markHead` adds "(head)" to the name, for the lists the office and
     * the leader read; never for the airline, hotel or ministry, whose
     * name column must be the name as on the passport.
     *
     * @param  array{hotels: Collection<int, DepartureHotel>, rooms: array<int, array<int, string>>, visas: array<string, string>, permits: array<string, string>, families: Collection<int, TravellingFamily>}  $context
     * @return array<string, string|int|null>
     */
    private function row(BookingTraveller $member, int $n, array $context, bool $withMoney, bool $markHead = false): array
    {
        $traveller = $member->traveller;
        $booking = $member->booking;
        $key = $booking->getKey().'-'.$traveller->getKey();
        $isHead = $markHead && $context['families']->get((int) $booking->customer_id)?->isHead($member) === true;

        $row = [
            'n' => $n,
            'name' => $traveller->full_name.($isHead ? ' (head)' : '').($booking->status === Booking::HELD ? ' *' : ''),
            'passport' => (string) $traveller->passport_number,
            'nationality' => (string) $traveller->nationality,
            'expiry' => $traveller->passport_expiry?->format('d/m/Y') ?? '',
            'dob' => $traveller->date_of_birth?->format('d/m/Y') ?? '',
            'gender' => $traveller->gender === null ? '' : strtoupper(substr((string) $traveller->gender, 0, 1)),
            'phone' => $this->phoneOf($member, $context),
            'booked_by' => (string) $booking->customer->name,
            'booking' => (string) $booking->reference,
            'bus' => (string) $member->busGroup?->name,
            'visa' => $context['visas'][$key] ?? 'none',
            'ticket' => (string) $member->ticket_number,
            'seat' => (string) $member->seat_number,
        ];

        if ($withMoney) {
            $row['paid'] = $booking->balance()->minor > 0 ? $booking->balance()->format().' due' : 'paid';
        }

        foreach ($context['hotels'] as $hotel) {
            $row['room_'.$hotel->getKey()] = $context['rooms'][$hotel->getKey()][$traveller->getKey()] ?? '';
        }

        return $row;
    }

    /** What the person at the coach door needs to know: the medical note, and who to ring. */
    private function needs(BookingTraveller $member): string
    {
        $traveller = $member->traveller;
        $parts = [];

        if (filled($traveller->medical_notes)) {
            $parts[] = Str::limit((string) $traveller->medical_notes, 60);
        }

        if (filled($traveller->emergency_contact_phone)) {
            $parts[] = 'Emergency: '.trim(($traveller->emergency_contact_name ? $traveller->emergency_contact_name.' ' : '').$traveller->emergency_contact_phone);
        }

        return implode('; ', $parts);
    }

    private function subtitle(Departure $departure): string
    {
        return ($departure->package->title ?? 'Departure').' · '.$departure->date_start->format('j M').' – '.$departure->date_end->format('j M Y');
    }

    private function slug(Departure $departure): string
    {
        return Str::slug(($departure->package->title ?? 'departure').'-'.$departure->date_start->format('Y-m-d'));
    }
}
