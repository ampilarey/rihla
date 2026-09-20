<?php

namespace App\Http\Controllers;

use App\Http\Middleware\PortalSession;
use App\Models\Booking;
use App\Models\Document;
use App\Models\EmergencyBroadcast;
use App\Models\FamilyAccess;
use App\Models\Notice;
use App\Models\NusukPermit;
use App\Models\VisaApplication;
use App\Services\Documents\DocumentWallet;
use App\Services\Family\Doorkeeper;
use App\Services\Notices\Sweep;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\SlipVault;
use App\Services\Portal\Gatekeeper;
use App\Support\Money;
use App\Support\TravelReadiness;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The Pilgrim Portal — §6.1, first version.
 *
 * ## Only what is backed by a record
 *
 * §6.1 lists a flight centre, learning progress, a Ziyarah companion and a
 * packing checklist. None of those has any data behind it, and a portal
 * section that is permanently empty — or worse, filled with plausible
 * invented content — is the same defect as the fabricated social links that
 * reached the live site. What this shows is the booking, what has been paid,
 * what documents are on file, where the visa and permit have got to, and the
 * itinerary. All of it is real.
 *
 * ## No identifier in any URL
 *
 * The booking comes from the session, put there by
 * {@see PortalSession}. Nothing here reads an id from
 * the path, so a visitor who edits the address bar sees their own booking or
 * is sent back to the door.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly Gatekeeper $gatekeeper,
        private readonly Doorkeeper $doorkeeper,
        private readonly Sweep $sweep,
    ) {}

    /**
     * The door: spend a link, open a session, and get the token out of the
     * address bar with a redirect.
     *
     * The redirect is not cosmetic. Left in the URL the token would sit in
     * every screenshot, in the history of a shared phone, and in the Referer
     * header of every outbound click from the portal.
     */
    public function enter(string $token): RedirectResponse
    {
        $access = $this->gatekeeper->find($token);

        if ($access === null) {
            return redirect()->route('portal.locked')
                ->with('portal_problem', __('messages.That link is not one of ours. Check it was copied in full.'));
        }

        if (! $access->isLive()) {
            return redirect()->route('portal.locked')
                ->with('portal_problem', $access->whyNot());
        }

        $this->gatekeeper->admit($access, request()->ip());

        return redirect()->route('portal.home');
    }

    /** The page somebody lands on when their link will not work. */
    public function locked(): View
    {
        return view('portal.locked');
    }

    /**
     * The pilgrim's own privacy controls — §6.2.
     *
     * "Privacy controls the pilgrim owns" has to mean a screen the pilgrim
     * can reach, or it means nothing. This is it: the links they have given
     * out, what each one shows, and a button to turn any of them off.
     */
    public function family(Request $request): View
    {
        $booking = $this->booking($request);

        return view('portal.family', [
            'booking' => $booking,
            'links' => FamilyAccess::where('booking_id', $booking->getKey())
                ->orderByDesc('created_at')
                ->get(),
            // Shown once and never again. Held in the flash rather than the
            // session proper so a refresh does not put it back on screen.
            'freshLink' => session('family_link'),
        ]);
    }

    /** Mint one. The plaintext is shown once, here, and never stored. */
    public function storeFamilyLink(Request $request): RedirectResponse
    {
        $booking = $this->booking($request);

        $validated = $request->validate([
            'label' => ['nullable', 'string', 'max:60'],
            'shares_attendance' => ['nullable', 'boolean'],
        ]);

        $token = $this->doorkeeper->issue(
            $booking,
            $validated['label'] ?? null,
            (bool) ($validated['shares_attendance'] ?? false),
        );

        return redirect()->route('portal.family')
            ->with('family_link', route('family.enter', ['token' => $token]));
    }

    /**
     * Turn one off, now.
     *
     * The family session re-reads the access on every request, so this
     * closes the page on whoever is already looking. A control that only
     * takes effect at the next sign-in is not one you own.
     */
    public function revokeFamilyLink(Request $request, FamilyAccess $familyAccess): RedirectResponse
    {
        $booking = $this->booking($request);

        // Never from the URL alone: a pilgrim must not be able to revoke
        // somebody else's link by editing the address bar.
        abort_unless($familyAccess->booking_id === $booking->getKey(), 404);

        $this->doorkeeper->revoke($familyAccess);

        return redirect()->route('portal.family')
            ->with('status', __('messages.That link has been turned off.'));
    }

    /**
     * Change what a link shows, without issuing a new one.
     *
     * Turning sharing *off* has to be as easy as turning it on, or the
     * control is a one-way door dressed up as a choice.
     */
    public function updateFamilyLink(Request $request, FamilyAccess $familyAccess): RedirectResponse
    {
        $booking = $this->booking($request);

        abort_unless($familyAccess->booking_id === $booking->getKey(), 404);

        $familyAccess->forceFill([
            'shares_attendance' => (bool) $request->boolean('shares_attendance'),
        ])->save();

        return redirect()->route('portal.family')
            ->with('status', __('messages.What that link shows has been updated.'));
    }

    /**
     * What this booking still needs to do.
     *
     * Marked seen as a side effect, which is the honest meaning of the
     * column: the customer has opened the page it is on. It deliberately
     * does not clear the staff queue — a notice disappearing because
     * somebody loaded a page is how a passport request goes unchased for a
     * fortnight.
     *
     * @return Collection<int, Notice>
     */
    private function outstandingNotices(Booking $booking)
    {
        // Raised on read, not only by cron.
        //
        // Nobody has confirmed that cron runs on this cPanel account, and
        // unlike a lapsed seat hold — which the next booking reclaims
        // inside its own row lock — a notice that is never raised simply
        // does not exist. So the portal computes its own, the same way the
        // booking path heals capacity: it is correct whether or not
        // `notices:sweep` has ever run.
        //
        // Idempotent, so opening the page twice does not stack anything.
        foreach ($this->sweep->noticesFor($booking) as $kind => $notice) {
            Notice::raise($booking, $kind, $notice['headline'], $notice['body']);
        }

        $notices = Notice::where('booking_id', $booking->getKey())
            ->outstanding()
            ->orderByDesc('created_at')
            ->get();

        foreach ($notices as $notice) {
            $notice->markSeen();
        }

        return $notices;
    }

    public function leave(): RedirectResponse
    {
        $this->gatekeeper->leave();

        return redirect()->route('home');
    }

    public function home(Request $request): View
    {
        $booking = $this->booking($request);

        return view('portal.home', [
            'booking' => $booking,
            // §11.2. Outstanding only — a passport request that has been
            // dealt with is not news. Marked seen here, because opening
            // the page is what "seen" means; whether *staff* have dealt
            // with it is a different fact and a different column.
            'notices' => $this->outstandingNotices($booking),
            // §6.5. At the top of the page a pilgrim actually opens, and
            // needing no credentials — which on this host is the whole
            // reason a broadcast reaches anybody at all.
            'broadcasts' => EmergencyBroadcast::where('departure_id', $booking->departure_id)
                ->sent()
                ->orderByDesc('sent_at')
                ->limit(5)
                ->get(),
            'departure' => $booking->departure,
            'package' => $booking->departure->package,
            'readiness' => $this->readiness($booking),
            'payments' => $booking->payments()->get(),
            'claimed' => (int) $booking->payments()->awaitingReview()->sum('amount_minor'),
            // Null while no account is configured. The page then says to get
            // in touch rather than printing an invented account number.
            'transfer' => $this->transferInstructions($booking),
        ]);
    }

    public function documents(Request $request): View
    {
        $booking = $this->booking($request);

        return view('portal.documents', [
            'booking' => $booking,
            'travellers' => $booking->travellers,
            // Keyed by traveller, so each person's row can say what is on
            // file for *them* rather than for the party.
            'documents' => Document::whereIn('traveller_id', $booking->travellers->pluck('traveller_id'))
                ->with('versions')
                ->get()
                ->groupBy('traveller_id'),
            'visas' => VisaApplication::where('booking_id', $booking->getKey())->get()->keyBy('traveller_id'),
            'permits' => NusukPermit::where('booking_id', $booking->getKey())
                ->where('kind', NusukPermit::UMRAH)
                ->get()
                ->keyBy('traveller_id'),
        ]);
    }

    /**
     * A passport, sent by the person it belongs to.
     *
     * Goes into the same wallet staff use, as an unverified version that
     * somebody has to look at — uploading is not verifying, and a document
     * that arrives already ticked is a document nobody checked.
     */
    public function storeDocument(Request $request): RedirectResponse
    {
        abort_unless((bool) config('portal.uploads.enabled', true), 404);

        $booking = $this->booking($request);

        $validated = $request->validate([
            'traveller_id' => ['required', 'integer'],
            'file' => [
                'required', 'file',
                'mimetypes:'.implode(',', (array) config('documents.mime_types')),
                'max:'.(int) config('documents.max_kilobytes'),
            ],
            'expires_at' => ['nullable', 'date', 'after:today'],
        ]);

        // The traveller must be on *this* booking. Without this the field is
        // a number in a form body and anybody could file a document against
        // a stranger.
        $line = $booking->travellers->firstWhere('traveller_id', (int) $validated['traveller_id']);

        abort_if($line === null, 403);

        app(DocumentWallet::class)->store(
            $line->traveller,
            $validated['file'],
            Document::IDENTITY,
            Document::PASSPORT,
            array_filter([
                'booking_id' => $booking->getKey(),
                'expires_at' => $validated['expires_at'] ?? null,
            ]),
        );

        return redirect()->route('portal.documents')
            ->with('status', __('messages.Thank you — we have it. Someone will check it and let you know.'));
    }

    /**
     * A transfer slip, sent by whoever paid.
     *
     * Recorded as a claim, never as money received: the amount is what the
     * customer says they sent, and Finance decides whether it arrived. A
     * portal that could mark its own payments as received would be a portal
     * that confirms bookings for free.
     */
    public function storePayment(Request $request): RedirectResponse
    {
        abort_unless((bool) config('portal.uploads.enabled', true), 404);

        $booking = $this->booking($request);

        $validated = $request->validate([
            'amount' => ['required', 'integer', 'min:1'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:today'],
            'payer_name' => ['nullable', 'string', 'max:255'],
            'payer_reference' => ['nullable', 'string', 'max:80'],
            'slip' => [
                'required', 'file',
                'mimetypes:'.implode(',', (array) config('payments.slips.mime_types')),
                'max:'.(int) config('payments.slips.max_kilobytes'),
            ],
        ]);

        $payment = app(BankTransfer::class)->start(
            $booking,
            Money::ofMajor((int) $validated['amount'], $booking->currency),
            [
                'paid_at' => $validated['paid_at'] ?? null,
                'payer_name' => $validated['payer_name'] ?? null,
                'payer_reference' => $validated['payer_reference'] ?? null,
                'notes' => 'Sent by the customer through the portal.',
            ],
        );

        app(SlipVault::class)->attach($payment, $validated['slip']);

        return redirect()->route('portal.home')
            ->with('status', __('messages.Thank you — we have your slip. We will confirm once the money is in.'));
    }

    /** @return array<string, array<string, bool>> traveller name => requirements */
    private function readiness(Booking $booking): array
    {
        $readiness = [];

        foreach ($booking->travellers as $line) {
            $readiness[$line->traveller->full_name] = TravelReadiness::forTraveller($booking, $line->traveller);
        }

        return $readiness;
    }

    /** @return array<string, string|null>|null */
    private function transferInstructions(Booking $booking): ?array
    {
        $bank = app(BankTransfer::class);

        return $bank->isAvailable() && $bank->hasAccountFor($booking->currency)
            ? $bank->instructions($booking->balance())
            : null;
    }

    /** Put on the request by PortalSession, never read from the URL. */
    private function booking(Request $request): Booking
    {
        /** @var Booking */
        return $request->attributes->get('portal_booking');
    }
}
