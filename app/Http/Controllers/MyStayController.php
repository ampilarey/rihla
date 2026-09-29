<?php

namespace App\Http\Controllers;

use App\Models\Stay;
use App\Services\Payments\Drivers\BankTransfer;
use App\Services\Payments\SlipVault;
use App\Services\Stays\StayAllocator;
use App\Services\Stays\StayBooking;
use App\Services\Stays\StayGatekeeper;
use App\Support\Money;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The guest's own stay — `/my-stay`, §16.7, §16 Phase 13.3.
 *
 * Entered through a link, exactly as the Pilgrim Portal is
 * ({@see StayGatekeeper}); the stay is read from the session, never from
 * the URL, so there is no address that names somebody's booking.
 *
 * What a guest can do here: see where their stay stands and what it has
 * cost, send a transfer slip for what is owed, and cancel while the policy
 * they agreed to says cancelling is free. Paying by card arrives with the
 * BML driver (Phase 8.5) — the page offers what is actually switched on.
 */
class MyStayController extends Controller
{
    public function __construct(
        private readonly StayGatekeeper $gatekeeper,
        private readonly StayBooking $booking,
        private readonly StayAllocator $allocator,
        private readonly BankTransfer $bank,
    ) {}

    public function enter(string $token): RedirectResponse
    {
        $access = $this->gatekeeper->find($token);

        if ($access === null) {
            return redirect()->route('my-stay.locked')
                ->with('stay_problem', __('messages.That link is not one of ours. Check it was copied in full.'));
        }

        if (! $access->isLive()) {
            return redirect()->route('my-stay.locked')->with('stay_problem', $access->whyNot());
        }

        $this->gatekeeper->admit($access, request()->ip());

        return redirect()->route('my-stay.home');
    }

    public function locked(): View
    {
        return view('my-stay.locked');
    }

    public function home(Request $request): View
    {
        $stay = $this->stay($request);
        $due = $this->amountDue($stay);

        return view('my-stay.home', [
            'stay' => $stay,
            'property' => $stay->property,
            'due' => $due,
            'transfer' => $due !== null && $this->bank->isAvailable() && $this->bank->hasAccountFor($stay->currency)
                ? $this->bank->instructions($due)
                : null,
            'canSendSlip' => $due !== null && $this->bank->isAvailable(),
            'canCancel' => $this->canCancel($stay),
            'freeCancelUntil' => $stay->check_in->copy()->subDays((int) ($stay->rate_snapshot['policy']['free_cancel_days'] ?? 14)),
            'freshLink' => session('stay_link'),
        ]);
    }

    public function storePayment(Request $request, SlipVault $slips): RedirectResponse
    {
        $stay = $this->stay($request);
        $due = $this->amountDue($stay);

        abort_if($due === null || ! $this->bank->isAvailable(), 404);

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

        // A claim, never money received: Finance decides whether it
        // arrived, and only then does the stay move (StayBooking::settle).
        $payment = $this->bank->start($stay, Money::ofMajor((int) $validated['amount'], $stay->currency), [
            'paid_at' => $validated['paid_at'] ?? null,
            'payer_name' => $validated['payer_name'] ?? null,
            'payer_reference' => $validated['payer_reference'] ?? null,
            'notes' => 'Sent by the guest from their stay page.',
        ]);

        $slips->attach($payment, $validated['slip']);

        return redirect()->route('my-stay.home')
            ->with('status', __('messages.Thank you — we have your slip. We will confirm once the money is in.'));
    }

    /**
     * Cancel while the terms agreed on the day say it is free — read from
     * the stay's own snapshot, not the property's current policy.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $stay = $this->stay($request);

        abort_unless($this->canCancel($stay), 403);

        $reason = $stay->paid_minor > 0
            ? 'Cancelled by the guest online, inside the free window. Their payment of '.$stay->paid()->format().' is to be refunded.'
            : 'Cancelled by the guest online, inside the free window.';

        // Under the room's lock, as every other change of a stay's dates
        // is; a request holds none, and release() knows that.
        $this->allocator->release($stay, Stay::CANCELLED, $reason);

        return redirect()->route('my-stay.home')
            ->with('status', $stay->paid_minor > 0
                ? __('messages.Your stay is cancelled. We will refund what you paid and tell you when it is on its way.')
                : __('messages.Your stay is cancelled. You owe nothing.'));
    }

    public function leave(): RedirectResponse
    {
        $this->gatekeeper->leave();

        return redirect()->route('stays.index');
    }

    /**
     * What the guest should send now, or null when nothing is.
     *
     * Held: the deposit, less anything already sent. Confirmed: the
     * balance. A request owes nothing — §15.2 decision 1 — and neither does
     * anything finished or cancelled.
     */
    private function amountDue(Stay $stay): ?Money
    {
        $owed = match ($stay->status) {
            Stay::HELD => max(0, $stay->deposit_minor - $stay->paid_minor),
            Stay::CONFIRMED => max(0, $stay->total_minor - $stay->paid_minor),
            default => 0,
        };

        return $owed > 0 ? Money::ofMinor($owed, $stay->currency) : null;
    }

    private function canCancel(Stay $stay): bool
    {
        return in_array($stay->status, [Stay::REQUESTED, Stay::HELD, Stay::CONFIRMED], true)
            && $this->booking->cancellationIsFree($stay);
    }

    /** Put on the request by StaySession, never read from the URL. */
    private function stay(Request $request): Stay
    {
        /** @var Stay */
        return $request->attributes->get('my_stay');
    }
}
