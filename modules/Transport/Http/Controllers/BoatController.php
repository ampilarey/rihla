<?php

namespace Modules\Transport\Http\Controllers;

use App\Exceptions\DeskRefusal;
use App\Http\Controllers\Controller;
use App\Models\Island;
use App\Support\CompanyDetails;
use App\Support\Services;
use Carbon\CarbonImmutable;
use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Modules\Telegram\Services\Notifier;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Services\TransportDesk;

/**
 * Booking a boat online — docs/TRANSPORT_PLAN.md, T2. Search by from, to and
 * date; pick a sailing; name the passengers; the seats are held and the
 * booker is sent to their tickets, a random link that is their only key.
 */
class BoatController extends Controller
{
    public function index(Request $request): View
    {
        $routes = BoatRoute::query()->where('is_active', true)->with(['origin', 'destination'])->get();
        $from = $request->integer('from') ?: null;
        $to = $request->integer('to') ?: null;
        $date = $this->date($request->query('date'));

        $sailings = Sailing::query()
            ->onSale()
            ->whereHas('route', fn ($q) => $q->where('is_active', true)
                ->when($from, fn ($q) => $q->where('origin_island_id', $from))
                ->when($to, fn ($q) => $q->where('destination_island_id', $to)))
            ->whereBetween('departs_at', [$date->startOfDay(), $date->addDays(6)->endOfDay()])
            ->with(['route.origin', 'route.destination', 'vessel'])
            ->orderBy('departs_at')
            ->limit(60)
            ->get();

        return view('transport.index', [
            'origins' => $this->islands($routes->pluck('origin_island_id')),
            'destinations' => $this->islands($routes->pluck('destination_island_id')),
            'from' => $from,
            'to' => $to,
            'date' => $date,
            'sailings' => $sailings,
            'selling' => Services::state('transport') === Services::ON,
        ]);
    }

    public function book(Sailing $sailing): View
    {
        abort_unless($this->sells($sailing), 404);

        return view('transport.book', ['sailing' => $sailing->load(['route.origin', 'route.destination', 'vessel'])]);
    }

    public function store(Request $request, Sailing $sailing, TransportDesk $desk, Notifier $notifier): RedirectResponse
    {
        abort_unless($this->sells($sailing), 404);

        // A field a person never sees; a bot fills it in.
        if (filled($request->input('website'))) {
            return redirect()->route('transport.index');
        }

        $data = $request->validate([
            'contact_name' => ['required', 'string', 'max:120'],
            'contact_phone' => ['required', 'string', 'max:30'],
            'passengers' => ['required', 'array', 'min:1', 'max:10'],
            'passengers.*.full_name' => ['nullable', 'string', 'max:120'],
            'passengers.*.is_child' => ['nullable', 'boolean'],
        ]);

        $passengers = array_values(array_map(
            fn (array $p): array => ['full_name' => (string) ($p['full_name'] ?? ''), 'is_child' => (bool) ($p['is_child'] ?? false)],
            $data['passengers'],
        ));

        try {
            $booking = $desk->book($sailing, $passengers, $data['contact_name'], $data['contact_phone'],
                heldUntil: $this->heldUntil($sailing));
        } catch (DeskRefusal $refusal) {
            return back()->withInput()->withErrors(['passengers' => $refusal->getMessage()]);
        }

        $notifier->tell('transport.book', '⛴ New boat booking '.$booking->reference.': '
            .$booking->seats.' on '.$sailing->route->label().', '.$sailing->departs_at->format('D j M H:i')
            .'. '.$booking->contact_name.', '.$booking->contact_phone.'.');

        return redirect()->to(self::ticketUrl($booking));
    }

    public function tickets(string $key): View
    {
        $booking = BoatBooking::query()->where('tickets_key', $key)->firstOrFail();
        $booking->load(['sailing.route.origin', 'sailing.route.destination', 'sailing.vessel', 'tickets']);

        return view('transport.tickets', [
            'booking' => $booking,
            'qr' => $booking->tickets->mapWithKeys(fn ($ticket) => [$ticket->id => $this->qr($ticket->token)]),
            'transfer' => $this->transfer($booking),
        ]);
    }

    /** The booker's key to their tickets: a random link, nothing to sign in to. */
    public static function ticketUrl(BoatBooking $booking): string
    {
        return route('transport.tickets', ['locale' => app()->getLocale(), 'key' => $booking->tickets_key]);
    }

    /**
     * Where the booker sends the money, or null when no account is set for
     * this currency — then the page says to message us instead.
     *
     * @return array{bank: ?string, account_name: ?string, account: string}|null
     */
    private function transfer(BoatBooking $booking): ?array
    {
        $bank = CompanyDetails::bank();
        $account = $bank['accounts'][$booking->currency] ?? null;

        return $account === null || $account === '' ? null
            : ['bank' => $bank['bank'], 'account_name' => $bank['account_name'], 'account' => (string) $account];
    }

    /** The online hold, never past the moment the boat leaves. */
    private function heldUntil(Sailing $sailing): CarbonImmutable
    {
        $until = CarbonImmutable::now()->addMinutes((int) config('transport.online_hold_minutes', 60));

        return $until->gt($sailing->departs_at) ? CarbonImmutable::parse($sailing->departs_at) : $until;
    }

    private function sells(Sailing $sailing): bool
    {
        return Services::state('transport') === Services::ON && $sailing->isOnSale() && $sailing->route->is_active;
    }

    private function date(mixed $given): CarbonImmutable
    {
        try {
            $date = is_string($given) && $given !== '' ? CarbonImmutable::parse($given) : CarbonImmutable::today();
        } catch (\Throwable) {
            $date = CarbonImmutable::today();
        }

        return $date->lt(CarbonImmutable::today()) ? CarbonImmutable::today() : $date;
    }

    /**
     * @param  Collection<int, mixed>  $ids
     * @return array<int, string>
     */
    private function islands($ids): array
    {
        return Island::query()->whereIn('id', $ids->unique())->orderBy('name')->get()
            ->mapWithKeys(fn (Island $island): array => [$island->id => $island->name])->all();
    }

    /**
     * A link to the crew's boarding page, so any phone's camera opens it.
     * The token alone opens nothing: the page needs a signed-in crew member.
     */
    private function qr(string $token): string
    {
        return (string) (new QRCode(new QROptions([
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'eccLevel' => EccLevel::M,
            'scale' => 6,
            'addQuietzone' => true,
            'imageTransparent' => false,
        ])))->render(BoatScanRedirect::urlFor($token));
    }
}
