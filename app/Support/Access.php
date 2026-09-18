<?php

namespace App\Support;

/**
 * The vocabulary of authorisation: who staff are, and what they may do.
 *
 * Permissions are verbs, not screens, so a policy can ask the same question
 * however the action is reached — admin panel, console command or a future
 * API. Only verbs for functionality that exists are defined here. Booking,
 * refund, payment, visa and permit permissions belong with the features that
 * introduce them; defining them now would produce a list of inert strings
 * that look enforced and are not.
 */
final class Access
{
    // Roles — the nine real Rihla staff functions.
    public const SUPER_ADMIN = 'Super Admin';

    public const OPERATIONS_MANAGER = 'Operations Manager';

    public const BOOKING_STAFF = 'Booking Staff';

    public const FINANCE = 'Finance';

    public const VISA_STAFF = 'Visa Staff';

    public const PILGRIM_SUPPORT = 'Pilgrim Support';

    public const CONTENT_MANAGER = 'Content Manager';

    public const TOUR_LEADER = 'Tour Leader';

    public const REPORTING = 'Reporting';

    /** @var list<string> */
    public const ROLES = [
        self::SUPER_ADMIN,
        self::OPERATIONS_MANAGER,
        self::BOOKING_STAFF,
        self::FINANCE,
        self::VISA_STAFF,
        self::PILGRIM_SUPPORT,
        self::CONTENT_MANAGER,
        self::TOUR_LEADER,
        self::REPORTING,
    ];

    /**
     * Permissions covering what the application can do today.
     *
     * `viewAny` is separate from `view` throughout because a list is a
     * different disclosure from a single record: Reporting may see that
     * trips exist without being able to open every one.
     *
     * @var list<string>
     */
    public const PERMISSIONS = [
        'admin.access',

        'trip.viewAny',
        'trip.view',
        'trip.create',
        'trip.update',
        'trip.delete',

        'media.viewAny',
        'media.view',
        'media.create',
        'media.update',
        'media.delete',

        'guide.viewAny',
        'guide.view',
        'guide.create',
        'guide.update',
        'guide.delete',

        'heroBanner.viewAny',
        'heroBanner.view',
        'heroBanner.create',
        'heroBanner.update',
        'heroBanner.delete',

        'whySection.viewAny',
        'whySection.view',
        'whySection.update',

        'whyFeature.create',
        'whyFeature.update',
        'whyFeature.delete',

        'setting.view',
        'setting.update',

        // Reading the audit trail is an oversight function, not a content one.
        'audit.viewAny',
    ];

    /**
     * What each role may do.
     *
     * Super Admin is deliberately absent: it is granted everything through a
     * Gate::before hook rather than by holding every permission, so a
     * permission added later cannot silently leave it locked out.
     *
     * Roles whose work does not exist yet map to an empty set. They are still
     * defined so staff can be assigned to them now, and so the gap between a
     * job function and what the software supports is visible rather than
     * implied.
     *
     * @return array<string, list<string>>
     */
    public static function matrix(): array
    {
        $content = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => $permission !== 'admin.access'
                && ! str_starts_with($permission, 'setting.')
                && ! str_starts_with($permission, 'audit.'),
        ));

        $readOnly = array_values(array_filter(
            self::PERMISSIONS,
            fn (string $permission) => str_contains($permission, '.view'),
        ));

        return [
            self::SUPER_ADMIN => [],

            // Runs the operation: all content, plus settings.
            self::OPERATIONS_MANAGER => array_merge(
                ['admin.access', 'setting.view', 'setting.update', 'audit.viewAny'],
                $content,
            ),

            // Owns the public-facing content, but not the site's settings —
            // social links and contact details are an operations decision.
            self::CONTENT_MANAGER => array_merge(['admin.access'], $content),

            // Read-only across the board.
            self::REPORTING => array_merge(['admin.access'], $readOnly),

            // Trip-facing staff need to read trips to do their job, but the
            // trip listing is content, not theirs to change.
            self::TOUR_LEADER => ['admin.access', 'trip.viewAny', 'trip.view'],

            // No functionality exists for these yet. See the class docblock.
            self::BOOKING_STAFF => ['admin.access'],
            self::FINANCE => ['admin.access'],
            self::VISA_STAFF => ['admin.access'],
            self::PILGRIM_SUPPORT => ['admin.access'],
        ];
    }
}
