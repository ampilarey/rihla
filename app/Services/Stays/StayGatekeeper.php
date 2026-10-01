<?php

namespace App\Services\Stays;

use App\Models\Stay;
use App\Models\StayAccess;
use App\Models\User;
use App\Services\Portal\Gatekeeper;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Issuing, checking and revoking the way into a guest's own stay — §16.7.
 *
 * Deliberately a copy of {@see Gatekeeper} rather than
 * a generalisation of it (§16.7: "two small classes beat one clever one
 * until a third portal exists"). The same rules, for the same reasons:
 * 40 random characters, only the SHA-256 stored, the plaintext handed back
 * once; a link is spent for a session that expires on its own, so the
 * token is not in the address bar of every page.
 *
 * The session keys are the stay's own. A pilgrim-portal session never
 * opens a stay, and a stay session never opens the pilgrim portal.
 */
final class StayGatekeeper
{
    /** Public for the message limiter, which counts per stay (§16.11). */
    public const SESSION_STAY = 'my_stay.stay';

    private const SESSION_UNTIL = 'my_stay.until';

    private const SESSION_OPENED = 'my_stay.opened';

    public function issue(Stay $stay, ?User $actor = null): string
    {
        $token = Str::random(40);

        StayAccess::create([
            'stay_id' => $stay->getKey(),
            'token_hash' => $this->hash($token),
            'expires_at' => now()->addDays((int) config('portal.link_days', 30)),
            'issued_by' => $actor?->getKey(),
        ]);

        return $token;
    }

    /** The access a token names, live or not, so the page can say why. */
    public function find(string $token): ?StayAccess
    {
        return StayAccess::where('token_hash', $this->hash($token))->first();
    }

    public function admit(StayAccess $access, ?string $ip = null): void
    {
        $access->forceFill([
            'uses' => $access->uses + 1,
            'last_used_at' => now(),
            'first_used_ip' => $access->first_used_ip ?? $ip,
        ])->save();

        $this->open($access->stay_id);
    }

    /**
     * Open the session for a stay directly — the guest who has just made
     * it, in the same browser, should not have to click their own link.
     */
    public function open(int $stayId): void
    {
        // A new session id on the way in, so an id planted before the link
        // was clicked is not the one that gets the stay (security review).
        Session::regenerate();
        Session::put(self::SESSION_STAY, $stayId);
        Session::put(self::SESSION_UNTIL, now()->addHours((int) config('portal.session_hours', 12))->timestamp);
        Session::put(self::SESSION_OPENED, now()->timestamp);
    }

    public function stay(): ?Stay
    {
        $id = Session::get(self::SESSION_STAY);
        $until = Session::get(self::SESSION_UNTIL);

        if ($id === null || $until === null || now()->timestamp > $until) {
            return null;
        }

        // Cancelling the links ends the sessions they opened, not only the
        // next click (security review, §16). Sessions from before this was
        // recorded date from the start of their window.
        $opened = (int) (Session::get(self::SESSION_OPENED) ?? $until - (int) config('portal.session_hours', 12) * 3600);

        if (StayAccess::where('stay_id', $id)->where('revoked_at', '>=', Carbon::createFromTimestamp($opened))->exists()) {
            $this->leave();

            return null;
        }

        return Stay::with(['property.partner', 'roomType', 'charges', 'payments'])->find($id);
    }

    public function leave(): void
    {
        Session::forget([self::SESSION_STAY, self::SESSION_UNTIL, self::SESSION_OPENED]);
        // The old id is destroyed, not merely emptied (security review).
        Session::regenerate(true);
    }

    public function revokeAllFor(Stay $stay): int
    {
        return StayAccess::where('stay_id', $stay->getKey())->live()->update(['revoked_at' => now()]);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
