<?php

namespace App\Support;

/**
 * What each person working for a host may do — §16.6.
 *
 * Host-side, and deliberately not spatie roles: a host user holds **no**
 * spatie role and no `admin.access`, so nothing granted here can ever open
 * `/staff`. One map, read by every host-side policy, so the table in the
 * plan and the behaviour cannot drift apart.
 */
final class HostRole
{
    public const OWNER = 'owner';

    public const MANAGER = 'manager';

    public const RECEPTION = 'reception';

    /** @var list<string> */
    public const ALL = [self::OWNER, self::MANAGER, self::RECEPTION];

    // ── Abilities ────────────────────────────────────────────────────────

    public const BOOKINGS = 'bookings';

    public const LISTINGS = 'listings';

    public const MESSAGES = 'messages';

    public const REVIEWS = 'reviews';

    public const MY_PAGE = 'my_page';

    public const EARNINGS = 'earnings';

    /** See identifiers on the guest register unmasked, and export it. */
    public const REGISTER_UNMASKED = 'register_unmasked';

    /** Team, payout details, settlement, terms. */
    public const TEAM = 'team';

    /** @var array<string, list<string>> */
    private const ALLOWS = [
        self::OWNER => [
            self::BOOKINGS, self::LISTINGS, self::MESSAGES, self::REVIEWS,
            self::MY_PAGE, self::EARNINGS, self::REGISTER_UNMASKED, self::TEAM,
        ],
        self::MANAGER => [
            self::BOOKINGS, self::LISTINGS, self::MESSAGES, self::REVIEWS,
            self::MY_PAGE, self::EARNINGS, self::REGISTER_UNMASKED,
        ],
        self::RECEPTION => [self::BOOKINGS, self::MESSAGES],
    ];

    public static function allows(?string $role, string $ability): bool
    {
        return $role !== null && in_array($ability, self::ALLOWS[$role] ?? [], true);
    }

    public static function label(string $role): string
    {
        return match ($role) {
            self::OWNER => 'Owner',
            self::MANAGER => 'Manager',
            default => 'Reception',
        };
    }
}
