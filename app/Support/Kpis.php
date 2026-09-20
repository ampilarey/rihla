<?php

namespace App\Support;

use App\Models\Booking;
use App\Models\BookingTraveller;
use App\Models\Customer;
use App\Models\Departure;
use App\Models\DepartureCost;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Enquiry;
use App\Models\EnquiryNote;
use App\Models\LearningModule;
use App\Models\ModuleCompletion;
use App\Models\NusukPermit;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The dozen numbers §10.5 says are worth instrumenting — §8.5's dashboard.
 *
 * ## Computed, never stored
 *
 * The same reasoning as {@see DepartureReadiness}, {@see JourneyProfit} and
 * every other read model here. A stored KPI is wrong from the next booking,
 * and nothing on this screen is worth a nightly job on a host with no queue
 * worker (ADR 0002).
 *
 * ## Nine of the thirteen are real; four are named absences
 *
 * §10.5 lists thirteen measures. Nine of them are arithmetic over rows this
 * application already holds. Four are not, and the difference is the whole
 * point of this class:
 *
 * - **visit → enquiry** needs a page-view log. There is none.
 * - **portal weekly-active pilgrims** and **family-portal engagement** need
 *   a visit log. `portal_accesses.last_used_at` is one timestamp that is
 *   overwritten on every visit — it answers "when was this person last
 *   here", which is a different question, and counting last-seen stamps
 *   inside a week undercounts everybody who came twice.
 * - **NPS after return** is a survey answer. Nobody has been asked.
 *
 * Each of those appears on the dashboard as itself, saying what is missing.
 * Substituting a proxy is how a last-seen count becomes "weekly actives" in
 * a report to a bank, and there is no way back from that once it is quoted.
 *
 * ## The window means different things to different measures
 *
 * An enquiry conversion rate is about enquiries that arrived; a seats-sold
 * rate is about departures that flew. Both are honest and they are not the
 * same span of rows, so every measure states its own basis in words rather
 * than leaving the reader to assume one window covers all of them.
 */
final class Kpis
{
    /** How far back to look, and what to call it. */
    public const WINDOWS = [
        30 => 'Last 30 days',
        90 => 'Last 90 days',
        365 => 'Last 12 months',
    ];

    private function __construct(
        public readonly CarbonInterface $since,
        public readonly CarbonInterface $until,
        public readonly int $days,
        /** @var Collection<int, Kpi> */
        public readonly Collection $kpis,
    ) {}

    /**
     * @param  bool  $includeMargin  False for a reader without `profit.view`.
     *                               The margin row is then absent rather than
     *                               blank — see the dashboard, which says so.
     */
    public static function build(int $days = 90, bool $includeMargin = true): self
    {
        $days = array_key_exists($days, self::WINDOWS) ? $days : 90;

        $until = Carbon::now();
        $since = $until->copy()->subDays($days)->startOfDay();

        $board = new self($since, $until, $days, collect());

        $kpis = [
            $board->enquiryToBooking(),
            $board->enquiryResponseTime(),
            $board->depositToFullPayment(),
            $board->seatsAgainstCapacity(),
            $board->documentVerificationTime(),
            $board->permitsIssuedBeforeDeparture(),
            $board->repeatAndReferralShare(),
            $board->learningCompletion(),
        ];

        if ($includeMargin) {
            $kpis[] = $board->marginPerTraveller();
        }

        foreach (self::absences() as $absence) {
            $kpis[] = $absence;
        }

        return new self($since, $until, $days, collect($kpis));
    }

    public function windowLabel(): string
    {
        return self::WINDOWS[$this->days] ?? 'Last 90 days';
    }

    /** "21 June to 20 September 2026" — so nobody has to work out the span. */
    public function windowSpoken(): string
    {
        return $this->since->format('j M Y').' to '.$this->until->format('j M Y');
    }

    /** @return Collection<int, Kpi> */
    public function measured(): Collection
    {
        return $this->kpis->filter(fn (Kpi $kpi): bool => $kpi->state === Kpi::MEASURED)->values();
    }

    /** @return Collection<int, Kpi> */
    public function waiting(): Collection
    {
        return $this->kpis->filter(fn (Kpi $kpi): bool => $kpi->state === Kpi::NOTHING_TO_MEASURE)->values();
    }

    /** @return Collection<int, Kpi> */
    public function notInstrumented(): Collection
    {
        return $this->kpis->filter(fn (Kpi $kpi): bool => $kpi->state === Kpi::NOT_INSTRUMENTED)->values();
    }

    // ── The measures ────────────────────────────────────────────────────

    /**
     * Enquiry → booking.
     *
     * The denominator is enquiries that *arrived* in the window, which
     * means the most recent of them have not had time to go either way.
     * That bias is real and it only ever depresses the figure, so the
     * detail line says how many are still open rather than quietly
     * excluding them — excluding them would flatter the number.
     */
    private function enquiryToBooking(): Kpi
    {
        $key = 'enquiry.to.booking';
        $name = 'Enquiry → booking';
        $question = 'Of the people who asked, how many travelled with us?';

        /** @var EloquentCollection<int, Enquiry> $enquiries */
        $enquiries = Enquiry::query()
            ->whereBetween('created_at', [$this->since, $this->until])
            ->get(['id', 'status', 'booking_id']);

        $total = $enquiries->count();

        if ($total === 0) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No enquiry arrived between '.$this->windowSpoken().'. A rate of nothing over nothing is not zero — it is a quiet window.');
        }

        $won = $enquiries->whereNotNull('booking_id')->count();
        $open = $enquiries->whereIn('status', [Enquiry::NEW, Enquiry::WORKING])->count();

        $detail = $won.' of '.$total.' '.($total === 1 ? 'enquiry' : 'enquiries').' became a booking.';

        if ($open > 0) {
            $detail .= ' '.$open.' '.($open === 1 ? 'is' : 'are')
                .' still open, so this figure can only go up.';
        }

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($won, $total) ?? '—',
            detail: $detail,
            tone: $won / $total >= 0.2 ? 'success' : 'warning',
        );
    }

    /**
     * How long somebody waited to hear back.
     *
     * Measured to the first note a member of staff wrote on the enquiry,
     * because that is the only trace a reply leaves in this system. An
     * enquiry answered on the phone with nothing written down reads here as
     * never answered, and the detail line says so — otherwise the office
     * reads a bad number and concludes the software is wrong rather than
     * that the note is missing.
     */
    private function enquiryResponseTime(): Kpi
    {
        $key = 'enquiry.response.time';
        $name = 'Time to first reply';
        $question = 'How long does somebody wait to hear back from us?';

        /** @var EloquentCollection<int, Enquiry> $enquiries */
        $enquiries = Enquiry::query()
            ->whereBetween('created_at', [$this->since, $this->until])
            ->get(['id', 'created_at']);

        if ($enquiries->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No enquiry arrived between '.$this->windowSpoken().', so nobody was waiting.');
        }

        // A note of type `status` is a move the system recorded, not a reply.
        $firstReplies = EnquiryNote::query()
            ->whereIn('enquiry_id', $enquiries->modelKeys())
            ->where('type', EnquiryNote::NOTE)
            ->whereNotNull('user_id')
            ->selectRaw('enquiry_id, MIN(created_at) as first_at')
            ->groupBy('enquiry_id')
            ->pluck('first_at', 'enquiry_id');

        /** @var list<float> $waits */
        $waits = [];

        foreach ($enquiries as $enquiry) {
            $repliedAt = $firstReplies->get($enquiry->getKey());

            if ($repliedAt === null || $enquiry->created_at === null) {
                continue;
            }

            $waits[] = max(0.0, (float) $enquiry->created_at->diffInMinutes(Carbon::parse((string) $repliedAt)));
        }

        $answered = count($waits);
        $silent = $enquiries->count() - $answered;

        if ($answered === 0) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'None of the '.$enquiries->count().' enquiries in this window has a note written by a member of staff. '
                .'That may mean nobody replied, or it may mean the replies went out by phone and WhatsApp and were never written down — this measure cannot tell those apart.');
        }

        $median = Kpi::median($waits);

        $detail = 'Median over the '.$answered.' '.($answered === 1 ? 'enquiry' : 'enquiries')
            .' with a written reply.';

        if ($silent > 0) {
            $detail .= ' '.$silent.' '.($silent === 1 ? 'has' : 'have')
                .' no note at all and '.($silent === 1 ? 'is' : 'are').' not counted here — a reply given on the phone leaves no trace.';
        }

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::duration((float) $median),
            detail: $detail,
            tone: $median <= 240 ? 'success' : ($median <= 1440 ? 'warning' : 'danger'),
            target: 'Within a few hours, while they are still deciding.',
        );
    }

    /**
     * Deposit → paid in full.
     *
     * Only journeys that have already flown, because a booking six weeks
     * out with a deposit against it has not failed to pay in full — it is
     * not due. Counting those would put a permanent false floor under this
     * number and the floor would move with the season.
     */
    private function depositToFullPayment(): Kpi
    {
        $key = 'deposit.to.full';
        $name = 'Deposit → paid in full';
        $question = 'Of the people who paid a deposit, how many settled the rest?';

        /** @var EloquentCollection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->whereIn('departure_id', $this->departedInWindow()->modelKeys())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->where('deposit_minor', '>', 0)
            ->where('paid_minor', '>', 0)
            ->get(['id', 'total_minor', 'paid_minor', 'deposit_minor']);

        if ($bookings->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No booking on a departure that flew between '.$this->windowSpoken()
                .' has both a deposit recorded and money against it. This measure needs the journey to have happened, so the question is settled.');
        }

        $settled = $bookings->filter(
            fn (Booking $booking): bool => $booking->paid_minor >= $booking->total_minor,
        )->count();

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($settled, $bookings->count()) ?? '—',
            detail: $settled.' of '.$bookings->count().' '.($bookings->count() === 1 ? 'booking' : 'bookings')
                .' on departures that have already flown were paid in full.',
            tone: $settled === $bookings->count() ? 'success' : 'warning',
            target: '100% — a journey that flew with a balance outstanding is money owed.',
        );
    }

    /** Seats sold against capacity, on the departures that flew. */
    private function seatsAgainstCapacity(): Kpi
    {
        $key = 'seats.vs.capacity';
        $name = 'Seats sold against capacity';
        $question = 'Are we filling the aircraft we book?';

        $departures = $this->departedInWindow()->filter(
            fn (Departure $departure): bool => $departure->capacity_total > 0,
        );

        if ($departures->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No departure with a capacity set flew between '.$this->windowSpoken().'.');
        }

        $capacity = (int) $departures->sum('capacity_total');

        $sold = (int) Booking::query()
            ->whereIn('departure_id', $departures->modelKeys())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->sum('seats');

        $share = $sold / $capacity;

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($sold, $capacity) ?? '—',
            detail: $sold.' of '.$capacity.' seats across '.$departures->count().' '
                .($departures->count() === 1 ? 'departure' : 'departures').' that flew.',
            tone: $share >= 0.85 ? 'success' : ($share >= 0.6 ? 'warning' : 'danger'),
        );
    }

    /** How long a document sat between upload and somebody looking at it. */
    private function documentVerificationTime(): Kpi
    {
        $key = 'document.cycle.time';
        $name = 'Document verification time';
        $question = 'How long does a passport sit before somebody checks it?';

        /** @var EloquentCollection<int, Document> $verified */
        $verified = Document::query()
            ->where('status', Document::VERIFIED)
            ->whereBetween('verified_at', [$this->since, $this->until])
            ->get(['id', 'verified_at']);

        if ($verified->isEmpty()) {
            $pending = Document::query()->where('status', Document::PENDING)->count();

            return Kpi::nothingToMeasure($key, $name, $question,
                'No document was verified between '.$this->windowSpoken().'.'
                .($pending > 0 ? ' '.$pending.' '.($pending === 1 ? 'is' : 'are').' still waiting to be checked.' : ''));
        }

        $uploaded = DocumentVersion::query()
            ->whereIn('document_id', $verified->modelKeys())
            ->selectRaw('document_id, MIN(created_at) as uploaded_at')
            ->groupBy('document_id')
            ->pluck('uploaded_at', 'document_id');

        /** @var list<float> $waits */
        $waits = [];

        foreach ($verified as $document) {
            $uploadedAt = $uploaded->get($document->getKey());

            if ($uploadedAt === null || $document->verified_at === null) {
                continue;
            }

            $waits[] = max(0.0, (float) Carbon::parse((string) $uploadedAt)->diffInMinutes($document->verified_at));
        }

        if ($waits === []) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'The documents verified in this window have no uploaded file against them, so there is no upload time to measure from.');
        }

        $median = Kpi::median($waits);
        $stillPending = Document::query()->where('status', Document::PENDING)->count();

        $detail = 'Median over '.count($waits).' '.(count($waits) === 1 ? 'document' : 'documents')
            .' verified in this window.';

        if ($stillPending > 0) {
            $detail .= ' '.$stillPending.' '.($stillPending === 1 ? 'document is' : 'documents are')
                .' still pending and '.($stillPending === 1 ? 'is' : 'are').' not in this figure — a document nobody has looked at yet has no cycle time.';
        }

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::duration((float) $median),
            detail: $detail,
            tone: $median <= 2880 ? 'success' : 'warning',
        );
    }

    /**
     * Permits issued before the aircraft left. §10.5 puts the target at 100%.
     *
     * The distinction that matters here: no permit rows at all is **not** a
     * 0% issue rate. It is an empty register, and reporting it as 0% would
     * be an accusation against the visa desk rather than a fact about it.
     */
    private function permitsIssuedBeforeDeparture(): Kpi
    {
        $key = 'permit.before.departure';
        $name = 'Nusuk permits before departure';
        $question = 'Did everybody who flew have their Umrah permit first?';

        $departures = $this->departedInWindow();

        /** @var EloquentCollection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->whereIn('departure_id', $departures->modelKeys())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->get(['id', 'departure_id']);

        if ($bookings->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'Nobody flew on a confirmed booking between '.$this->windowSpoken().'.');
        }

        /** @var EloquentCollection<int, BookingTraveller> $seats */
        $seats = BookingTraveller::query()
            ->whereIn('booking_id', $bookings->modelKeys())
            ->get(['booking_id', 'traveller_id']);

        if ($seats->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'The bookings on those departures have no travellers entered against them, so there is nobody to check a permit for.');
        }

        /** @var EloquentCollection<int, NusukPermit> $permits */
        $permits = NusukPermit::query()
            ->whereIn('booking_id', $bookings->modelKeys())
            ->where('kind', NusukPermit::UMRAH)
            ->get(['booking_id', 'traveller_id', 'status', 'issued_at']);

        if ($permits->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'Not one Nusuk permit has been recorded for the '.$seats->count().' '
                .($seats->count() === 1 ? 'traveller' : 'travellers')
                .' who flew in this window. That is an empty register, not a 0% issue rate — the permits may well have been obtained and never entered here.');
        }

        $flewOn = $bookings->mapWithKeys(
            fn (Booking $booking): array => [$booking->getKey() => $booking->departure_id],
        );

        // End of the departure day, not midnight at the start of it: a
        // permit issued at nine in the morning on the day they flew was
        // issued in time, and comparing against a date cast at 00:00 would
        // record it as late.
        $lastMoment = $departures->mapWithKeys(
            fn (Departure $departure): array => [
                $departure->getKey() => Carbon::parse($departure->date_start->toDateString())->endOfDay(),
            ],
        );

        $inTime = 0;

        foreach ($seats as $seat) {
            $bookingId = $seat->booking_id;
            $departureId = $flewOn->get($bookingId);
            $deadline = $departureId === null ? null : $lastMoment->get($departureId);

            if ($deadline === null) {
                continue;
            }

            $held = $permits->first(fn (NusukPermit $permit): bool => $permit->booking_id === $bookingId
                && $permit->traveller_id === $seat->traveller_id
                && $permit->status === NusukPermit::ISSUED
                && $permit->issued_at !== null
                && $permit->issued_at->lessThanOrEqualTo($deadline));

            if ($held !== null) {
                $inTime++;
            }
        }

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($inTime, $seats->count()) ?? '—',
            detail: $inTime.' of '.$seats->count().' '.($seats->count() === 1 ? 'traveller' : 'travellers')
                .' had an issued Umrah permit dated on or before the day they flew.',
            tone: $inTime === $seats->count() ? 'success' : 'danger',
            target: '100%. A pilgrim who flies without one cannot perform the Umrah they paid for.',
        );
    }

    /** How much of the business comes from people we have already served. */
    private function repeatAndReferralShare(): Kpi
    {
        $key = 'repeat.and.referral';
        $name = 'Repeat and referred bookings';
        $question = 'How much of the business comes from people who already know us?';

        /** @var EloquentCollection<int, Booking> $bookings */
        $bookings = Booking::query()
            ->whereBetween('created_at', [$this->since, $this->until])
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->get(['id', 'customer_id', 'created_at']);

        if ($bookings->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No booking was confirmed between '.$this->windowSpoken().'.');
        }

        /** @var EloquentCollection<int, Customer> $customers */
        $customers = Customer::query()
            ->whereIn('id', $bookings->pluck('customer_id')->unique()->all())
            ->get(['id', 'referred_by_customer_id', 'referral_source']);

        $referred = $customers->filter(
            fn (Customer $customer): bool => $customer->referred_by_customer_id !== null
                || ($customer->referral_source !== null && $customer->referral_source !== ''),
        )->modelKeys();

        // An earlier journey, not merely an earlier row: a second booking
        // made the same afternoon for the same family is one decision.
        $earlier = Booking::query()
            ->whereIn('customer_id', $bookings->pluck('customer_id')->unique()->all())
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->where('created_at', '<', $this->since)
            ->pluck('customer_id')
            ->unique();

        $known = $bookings->filter(
            fn (Booking $booking): bool => in_array($booking->customer_id, $referred, true)
                || $earlier->contains($booking->customer_id),
        )->count();

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($known, $bookings->count()) ?? '—',
            detail: $known.' of '.$bookings->count().' '.($bookings->count() === 1 ? 'booking' : 'bookings')
                .' came from somebody who had travelled with us before, or who was referred by somebody who had.',
            tone: $known > 0 ? 'success' : 'warning',
        );
    }

    /** Learning modules read, among the people who are actually travelling. */
    private function learningCompletion(): Kpi
    {
        $key = 'learning.completion';
        $name = 'Learning modules read';
        $question = 'Are the pilgrims who booked reading what we prepared for them?';

        /** @var EloquentCollection<int, LearningModule> $published */
        $published = LearningModule::query()->live()->get(['id']);

        if ($published->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No learning module has been published yet, so there is nothing for anybody to have read. '
                .'A module needs a scholar to approve it, and no reviewer has been named.');
        }

        $bookingIds = Booking::query()
            ->whereIn('departure_id', Departure::query()
                ->whereDate('date_start', '>=', $this->since->toDateString())
                ->select('id'))
            ->whereIn('status', Customer::TRAVELLED_ON)
            ->pluck('id');

        $travellerIds = BookingTraveller::query()
            ->whereIn('booking_id', $bookingIds)
            ->pluck('traveller_id')
            ->unique();

        if ($travellerIds->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'Nobody is booked onto a departure from '.$this->since->format('j M Y').' onwards, so there is nobody to have read them.');
        }

        $expected = $travellerIds->count() * $published->count();

        $read = ModuleCompletion::query()
            ->whereIn('traveller_id', $travellerIds->all())
            ->whereIn('learning_module_id', $published->modelKeys())
            ->whereNotNull('read_at')
            ->count();

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: Kpi::percentOf($read, $expected) ?? '—',
            detail: $read.' of a possible '.$expected.' — '.$published->count().' published '
                .($published->count() === 1 ? 'module' : 'modules').' across '.$travellerIds->count().' booked '
                .($travellerIds->count() === 1 ? 'pilgrim' : 'pilgrims').'.',
            tone: $read > 0 ? 'success' : 'warning',
        );
    }

    /**
     * What one traveller left behind, averaged over the journeys that flew.
     *
     * Inherits {@see JourneyProfit}'s refusal to invent an exchange rate: a
     * journey whose money spans currencies with no rate set contributes
     * nothing here, and the reason is repeated rather than swallowed.
     */
    private function marginPerTraveller(): Kpi
    {
        $key = 'margin.per.traveller';
        $name = 'Margin per traveller';
        $question = 'What does one pilgrim leave behind, once the journey is paid for?';

        $departures = $this->departedInWindow();

        if ($departures->isEmpty()) {
            return Kpi::nothingToMeasure($key, $name, $question,
                'No departure flew between '.$this->windowSpoken().'. A journey still selling has a forecast, not a margin.');
        }

        $totalMinor = 0;
        $travellers = 0;
        $counted = 0;
        $refusals = [];

        foreach ($departures as $departure) {
            $profit = JourneyProfit::build($departure, DepartureCost::COMMITTED);

            if ($profit->travellers < 1) {
                continue;
            }

            $margin = $profit->margin();

            if ($margin === null) {
                $refusals[] = $profit->whyNoSingleFigure();

                continue;
            }

            $totalMinor += $margin->minor;
            $travellers += $profit->travellers;
            $counted++;
        }

        if ($travellers === 0) {
            return Kpi::nothingToMeasure($key, $name, $question,
                $refusals === []
                    ? 'Nobody travelled on the departures that flew in this window, so there is no margin per traveller.'
                    : (string) $refusals[0]);
        }

        $perHead = Money::ofMinor(intdiv($totalMinor, $travellers), strtoupper((string) config('finance.rates.to', 'MVR')));

        $detail = 'Across '.$counted.' '.($counted === 1 ? 'journey' : 'journeys').' and '.$travellers.' '
            .($travellers === 1 ? 'traveller' : 'travellers').', counting costs agreed or paid.';

        if ($refusals !== []) {
            $detail .= ' '.count($refusals).' '.(count($refusals) === 1 ? 'journey is' : 'journeys are')
                .' left out because no exchange rate has been set for the currencies on '
                .(count($refusals) === 1 ? 'it' : 'them').'.';
        }

        return Kpi::measured(
            key: $key,
            name: $name,
            question: $question,
            figure: (string) $perHead,
            detail: $detail,
            tone: $perHead->minor >= 0 ? 'success' : 'danger',
        );
    }

    // ── The four §10.5 asks for and nothing here records ────────────────

    /** @return list<Kpi> */
    private static function absences(): array
    {
        return [
            Kpi::notInstrumented(
                'visit.to.enquiry',
                'Visit → enquiry',
                'Of the people who came to the website, how many got in touch?',
                'Nothing records a website visit. There is no page-view log, and a web server access log '
                .'cannot tell a person reading a package page from a crawler fetching it — so any figure here '
                .'would be an invention with a percent sign after it. This needs analytics the site does not have.',
            ),
            Kpi::notInstrumented(
                'portal.weekly.active',
                'Portal weekly-active pilgrims',
                'How many pilgrims used their portal this week?',
                '`portal_accesses.last_used_at` holds one timestamp that is overwritten on every visit. It answers '
                .'"when was this pilgrim last here", which is a different question: counting last-seen stamps inside a week '
                .'misses everybody who came back twice, and counts nobody\'s second visit. A weekly-active figure needs a '
                .'record of each visit, and there is none.',
            ),
            Kpi::notInstrumented(
                'family.portal.engagement',
                'Family-portal engagement',
                'Are the families at home actually using what the pilgrim shared with them?',
                'The same gap as the pilgrim portal: `family_accesses.last_used_at` is a single overwritten stamp, not a visit log. '
                .'And engagement is more than arriving — it is which pages were opened — which nothing here records either.',
            ),
            Kpi::notInstrumented(
                'nps.after.return',
                'NPS after return',
                'Would the people who travelled recommend us?',
                'Nobody has been asked. NPS is a survey answer, not something that can be derived from bookings, '
                .'and there is no return survey in the system. Inferring it from repeat bookings would be a different '
                .'measure wearing its name.',
            ),
        ];
    }

    // ── Shared query ────────────────────────────────────────────────────

    /** @var EloquentCollection<int, Departure>|null */
    private ?EloquentCollection $departed = null;

    /**
     * Departures that actually flew inside the window.
     *
     * Memoised: six of the measures need this same set, and the dashboard
     * is read on a shared host.
     *
     * @return EloquentCollection<int, Departure>
     */
    private function departedInWindow(): EloquentCollection
    {
        return $this->departed ??= Departure::query()
            ->whereDate('date_start', '>=', $this->since->toDateString())
            ->whereDate('date_start', '<', Carbon::now()->toDateString())
            ->get(['id', 'date_start', 'capacity_total', 'package_id']);
    }
}
