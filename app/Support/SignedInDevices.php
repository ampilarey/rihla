<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Where an account is signed in, and how to sign it out — §10.4.
 *
 * ## Why this reads the sessions table directly
 *
 * The session *is* the record of being signed in. A separate devices
 * table would be a second copy of the same fact, and the two would part
 * company the first time a session expired quietly — leaving a screen
 * that lists a telephone somebody sold last year and offers to sign it
 * out, which does nothing and says it worked.
 *
 * ## It only works on the database driver, and it says so
 *
 * `session.driver` is `database` here (config/session.php), which is what
 * makes any of this visible. On `file` or `cookie` there is nothing to
 * list — and an empty list would read as **"you are only signed in
 * here"**, which is the opposite of the truth and exactly the wrong thing
 * to tell somebody checking whether they have been broken into. So
 * {@see available()} is checked first and the screen says it cannot see.
 */
final class SignedInDevices
{
    /** Whether sessions are stored somewhere this can read. */
    public static function available(): bool
    {
        return config('session.driver') === 'database';
    }

    /**
     * Every device this account is signed in on, most recent first.
     *
     * @return Collection<int, Device>
     */
    public static function for(User $user, ?string $currentId = null): Collection
    {
        if (! self::available()) {
            return collect();
        }

        return self::table()
            ->where('user_id', $user->getKey())
            ->orderByDesc('last_activity')
            ->get()
            ->map(fn (object $row): Device => new Device(
                id: (string) $row->id,
                ipAddress: $row->ip_address === null ? null : (string) $row->ip_address,
                userAgent: $row->user_agent === null ? null : (string) $row->user_agent,
                lastActive: Carbon::createFromTimestamp((int) $row->last_activity),
                isCurrent: $currentId !== null && (string) $row->id === $currentId,
            ))
            ->values();
    }

    /**
     * Sign out one device.
     *
     * Scoped to the account as well as the id, so a session id guessed or
     * taken from somewhere else cannot be used to sign out a colleague.
     */
    public static function signOut(User $user, string $id): bool
    {
        if (! self::available()) {
            return false;
        }

        return self::table()
            ->where('user_id', $user->getKey())
            ->where('id', $id)
            ->delete() > 0;
    }

    /**
     * Sign out everywhere except here.
     *
     * @return int how many were signed out
     */
    public static function signOutOthers(User $user, ?string $currentId): int
    {
        if (! self::available()) {
            return 0;
        }

        $query = self::table()->where('user_id', $user->getKey());

        if ($currentId !== null) {
            $query->where('id', '!=', $currentId);
        }

        return $query->delete();
    }

    /**
     * Sign out everywhere, this session included.
     *
     * What a password *reset* calls. Somebody resetting a password from an
     * e-mailed link is frequently doing it because they think somebody
     * else is in their account, and leaving that somebody signed in is the
     * one outcome the reset was for. They sign in again straight after,
     * which is the whole cost.
     *
     * A password *change* on the profile page is different: that person has
     * just proved the old password in this session, so
     * {@see signOutOthers()} keeps them where they are.
     *
     * @return int how many were signed out
     */
    public static function signOutEverywhere(User $user): int
    {
        if (! self::available()) {
            return 0;
        }

        return self::table()->where('user_id', $user->getKey())->delete();
    }

    private static function table(): Builder
    {
        $connection = config('session.connection');

        return DB::connection($connection === null ? null : (string) $connection)
            ->table((string) config('session.table', 'sessions'));
    }
}
