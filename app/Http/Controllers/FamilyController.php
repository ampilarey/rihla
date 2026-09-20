<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\EmergencyBroadcast;
use App\Models\FamilyAccess;
use App\Models\RollCall;
use App\Models\Traveller;
use App\Services\Family\Doorkeeper;
use App\Support\JourneyProgress;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The Family Portal — §6.2.
 *
 * "Families at home are the strongest referral channel Rihla has… with
 * privacy controls the pilgrim owns."
 *
 * ## What a family sees without anybody's permission
 *
 * Group-level things only: where the departure is in its journey, and what
 * staff have announced to the whole group. Neither says anything about one
 * person, which is what makes it safe to show by default.
 *
 * ## What needs the pilgrim to have said yes
 *
 * One thing, and it is a boolean on the link: whether *their traveller was
 * accounted for at the last head count*. Not where they are — nothing in
 * this system records a location, which is the strongest privacy control
 * there is.
 *
 * ## What is never shown, at any setting
 *
 * Money, documents, passport details, other pilgrims' names, room
 * allocations. A family link will be forwarded into a group chat; that is
 * not a risk to mitigate, it is the expected use, and the page is built for
 * it.
 */
class FamilyController extends Controller
{
    public function __construct(private readonly Doorkeeper $doorkeeper) {}

    /**
     * Spend a link and open a session.
     *
     * The only route that ever sees a token, so it is the only place one
     * can end up in a Referer header or a screenshot of the address bar.
     */
    public function enter(Request $request, string $token): mixed
    {
        $access = $this->doorkeeper->find($token);

        if ($access === null || ! $access->isLive()) {
            return redirect()->route('family.locked')
                ->with('family_reason', $access?->whyNot() ?? 'That link does not work.');
        }

        $this->doorkeeper->admit($access);

        return redirect()->route('family.home');
    }

    public function locked(Request $request): View
    {
        return view('family.locked', [
            'reason' => session('family_reason'),
        ]);
    }

    public function home(Request $request): View
    {
        /** @var FamilyAccess $access */
        $access = $request->attributes->get('family_access');

        $booking = $access->booking;
        $departure = $booking->departure;

        return view('family.home', [
            'access' => $access,
            'departure' => $departure,
            'progress' => JourneyProgress::of($departure),
            // Group-level and urgent, so it needs no permission from
            // anybody: an emergency message that waits for a privacy
            // setting is not an emergency message.
            'broadcasts' => EmergencyBroadcast::where('departure_id', $departure->getKey())
                ->sent()
                ->orderByDesc('sent_at')
                ->limit(5)
                ->get(),
            'announcements' => Announcement::where('departure_id', $departure->getKey())
                ->live()
                ->orderByDesc('published_at')
                ->limit(20)
                ->get(),
            // Only when the pilgrim said yes. The method returns an empty
            // collection otherwise rather than the caller remembering to
            // check — a privacy rule enforced at the call site is one that
            // gets forgotten at the second call site.
            'accountedFor' => $this->attendanceFor($access),
        ]);
    }

    public function leave(Request $request): mixed
    {
        $this->doorkeeper->leave();

        return redirect()->route('family.locked');
    }

    /**
     * Whether this booking's travellers were accounted for at the most
     * recent head count — and nothing else.
     *
     * Empty unless the pilgrim turned it on. The check lives here rather
     * than in the view so there is one place to get it right, and so a
     * second screen cannot forget it.
     *
     * @return Collection<int, array{name: string, accounted: bool}>
     */
    private function attendanceFor(FamilyAccess $access): Collection
    {
        if (! $access->shares_attendance) {
            return collect();
        }

        $departure = $access->booking->departure;

        $rollCall = RollCall::where('departure_id', $departure->getKey())
            ->with(['marks.traveller', 'departure'])
            ->orderByDesc('taken_at')
            ->first();

        if ($rollCall === null) {
            return collect();
        }

        $missing = $rollCall->unaccountedFor()->pluck('id');

        // This booking's travellers only. A family must never learn who
        // else is on the trip, let alone who is missing from it.
        return $access->booking->travellers
            ->map(fn ($line) => $line->traveller)
            ->filter()
            ->map(fn (Traveller $traveller): array => [
                'name' => (string) $traveller->full_name,
                'accounted' => ! $missing->contains($traveller->getKey()),
            ])
            ->values();
    }
}
