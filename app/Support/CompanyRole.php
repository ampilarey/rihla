<?php

namespace App\Support;

/**
 * What a member of an Umrah company may do in that company's panel —
 * Phase 2a of docs/UMRAH_COMPANIES_PLAN.md.
 *
 * **The same roles Rihla's own staff hold, narrowed to the Umrah back
 * office.** A company's Operations Manager can do what Rihla's can to
 * bookings, departures, money and the journey, read from {@see Access}, so
 * the two never drift apart. What they never get is Rihla's: the website's
 * editorial content, the guesthouse marketplace, Rihla's own staff accounts,
 * settings, the audit of Rihla's panel and Pulse. Those permissions are not
 * in {@see UMRAH_AREAS}, so no company role can hold them, whatever
 * {@see Access::matrix()} grows.
 *
 * The Owner holds every Umrah permission, as Rihla's Super Admin holds
 * everything, and is the one who adds the company's other members.
 *
 * Answered from the membership, never from spatie roles: a company member
 * holds no spatie role at all, so `/staff` stays closed to them.
 */
final class CompanyRole
{
    public const OWNER = 'Owner';

    /** The roles a company can give its members, Owner first. */
    public const ROLES = [
        self::OWNER,
        Access::OPERATIONS_MANAGER,
        Access::BOOKING_STAFF,
        Access::FINANCE,
        Access::VISA_STAFF,
        Access::PILGRIM_SUPPORT,
        Access::TOUR_LEADER,
        Access::REPORTING,
    ];

    /**
     * The permission areas of the Umrah back office: the part before the dot.
     *
     * @var list<string>
     */
    public const UMRAH_AREAS = [
        'package', 'departure', 'person', 'booking', 'customer', 'document', 'visa', 'permit',
        'payment', 'rate', 'ledger', 'enquiry', 'quotation', 'task', 'cost', 'profit', 'books', 'kpi',
        'draft', 'rooming', 'logistics', 'checklist', 'incident', 'attendance', 'bus', 'list',
        'card', 'telegram', 'opslog', 'announcement', 'broadcast', 'notice', 'question',
    ];

    /**
     * Every Umrah permission, which is what an Owner holds.
     *
     * @return list<string>
     */
    public static function umrahPermissions(): array
    {
        return array_values(array_filter(
            Access::PERMISSIONS,
            fn (string $permission): bool => self::isUmrah($permission),
        ));
    }

    public static function isUmrah(string $permission): bool
    {
        return in_array(explode('.', $permission)[0], self::UMRAH_AREAS, true);
    }

    /**
     * What a role may do, Umrah permissions only.
     *
     * @return list<string>
     */
    public static function permissions(?string $role): array
    {
        if ($role === self::OWNER) {
            return self::umrahPermissions();
        }

        if ($role === null || ! in_array($role, self::ROLES, true)) {
            return [];
        }

        return array_values(array_filter(
            Access::matrix()[$role] ?? [],
            fn (string $permission): bool => self::isUmrah($permission),
        ));
    }

    public static function allows(?string $role, string $permission): bool
    {
        return in_array($permission, self::permissions($role), true);
    }
}
