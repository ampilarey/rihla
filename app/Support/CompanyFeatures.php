<?php

namespace App\Support;

use App\Models\Partner;

/**
 * What a company has paid for — companies plan, Phase 5a (decisions 13
 * and 14).
 *
 * One switch per feature, set by Rihla's admin. A feature is **on**,
 * **read-only** (a downgrade keeps the data and only lets it be seen) or,
 * by default, **off**. Each feature names the permission areas it covers,
 * so the company gate (`AuthServiceProvider`) narrows a person's role by
 * what the company has: an area that is off is refused everywhere Filament
 * asks — every screen, menu item and action — without a screen knowing
 * features exist. The parts that live outside the panel (the site's
 * checkout and portals, the app's files, an own domain, the bot, the
 * reader) ask {@see has()} by name.
 *
 * Rihla's own company has everything, always.
 */
final class CompanyFeatures
{
    public const ON = 'on';

    public const READ_ONLY = 'read_only';

    public const OFF = 'off';

    public const BOOKINGS = 'bookings';

    public const DOCUMENTS = 'documents';

    public const VISAS = 'visas';

    public const JOURNEY = 'journey';

    public const LISTS = 'lists';

    public const BOOKS = 'books';

    public const PORTAL = 'portal';

    public const TELEGRAM = 'telegram';

    public const READING = 'reading';

    public const APP = 'app';

    public const DOMAIN = 'domain';

    /**
     * Each feature, what it is called, and the permission areas it covers.
     * An area named by no feature (packages, departures) comes with the
     * basic website and is always on.
     *
     * @var array<string, array{label: string, areas: list<string>}>
     */
    public const FEATURES = [
        self::BOOKINGS => ['label' => 'Bookings and payments', 'areas' => ['booking', 'customer', 'payment', 'rate', 'quotation', 'enquiry', 'task', 'draft']],
        self::DOCUMENTS => ['label' => 'Pilgrims and documents', 'areas' => ['document']],
        self::VISAS => ['label' => 'Visas and permits', 'areas' => ['visa', 'permit']],
        self::JOURNEY => ['label' => 'The journey: rooms, buses, roll calls and notices', 'areas' => ['rooming', 'logistics', 'checklist', 'incident', 'attendance', 'bus', 'opslog', 'announcement', 'broadcast', 'notice', 'question', 'person']],
        self::LISTS => ['label' => 'Lists, cards and Scan', 'areas' => ['list', 'card']],
        self::BOOKS => ['label' => 'The books: costs, profit, accounts and reports', 'areas' => ['cost', 'profit', 'books', 'kpi', 'ledger']],
        self::PORTAL => ['label' => 'Customer and family portals', 'areas' => []],
        self::TELEGRAM => ['label' => 'Telegram bot', 'areas' => ['telegram']],
        self::READING => ['label' => 'Document reading', 'areas' => []],
        self::APP => ['label' => 'Installable app', 'areas' => []],
        self::DOMAIN => ['label' => 'Own domain', 'areas' => []],
    ];

    /** What a read-only area still lets through. */
    private const READING_VERBS = ['view', 'viewAny', 'download'];

    /** A feature's state for a company: Rihla is always on; a feature never set is off. */
    public static function state(?Partner $company, string $feature): string
    {
        if ($company === null || $company->is_rihla) {
            return self::ON;
        }

        $state = ($company->features ?? [])[$feature] ?? self::OFF;

        return in_array($state, [self::ON, self::READ_ONLY], true) ? $state : self::OFF;
    }

    /** Is the feature usable at all — on, or read-only when that is enough? */
    public static function has(?Partner $company, string $feature, bool $readOnlyWillDo = false): bool
    {
        $state = self::state($company, $feature);

        return $state === self::ON || ($readOnlyWillDo && $state === self::READ_ONLY);
    }

    /**
     * May this company's people use this permission at all? Null company or
     * a permission no feature covers: yes, the role decides alone.
     */
    public static function allows(?Partner $company, string $permission): bool
    {
        [$area, $verb] = array_pad(explode('.', $permission, 2), 2, '');
        $feature = self::featureOf($area);

        if ($feature === null) {
            return true;
        }

        return match (self::state($company, $feature)) {
            self::ON => true,
            self::READ_ONLY => in_array($verb, self::READING_VERBS, true),
            default => false,
        };
    }

    public static function featureOf(string $area): ?string
    {
        foreach (self::FEATURES as $feature => $definition) {
            if (in_array($area, $definition['areas'], true)) {
                return $feature;
            }
        }

        return null;
    }

    /**
     * The states to store, only on and read-only kept: off is the absence.
     *
     * @param  array<string, mixed>  $states
     * @return array<string, string>
     */
    public static function clean(array $states): array
    {
        return array_filter(
            array_intersect_key(array_map(fn (mixed $state): string => is_string($state) ? $state : self::OFF, $states), self::FEATURES),
            fn (string $state): bool => in_array($state, [self::ON, self::READ_ONLY], true),
        );
    }
}
