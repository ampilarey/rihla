<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The service registry — §15.3 (Phase 8.1) of the upgrade plan.
 *
 * Rihla is becoming a company with more than one line of business, and the
 * owner asked for one thing above everything else: the ability to turn a
 * line on, mark it "coming soon", or take it off, from the admin, without a
 * deploy. This is that switch.
 *
 * Stored as a single row in `settings` (key: `services`) — the same pattern
 * `Setting::getSocialSettings()` already uses: a small, infrequently-written
 * blob that several pages read per request. A dedicated table would give
 * Filament a marginally nicer screen for what is, today, three rows that
 * will not grow past a dozen; it would also be a migration, a model and a
 * seeder for what one JSON value already expresses. Reused deliberately,
 * not from thrift — if this ever needs per-service audit history or a
 * partner-visible flag, that is the day it earns its own table.
 *
 * Only services that gate something real belong in {@see catalogue()}. Umrah
 * is not here: nothing checks it, and a switch that does nothing when
 * flipped is the same class of defect as the invented social links that
 * reached the live site — see `AGENTS.md` and `Access::PERMISSIONS`'s own
 * docblock, which states the identical rule for permission strings.
 */
final class Services
{
    /** Reachable, and sold. */
    public const ON = 'on';

    /** Reachable, but the page it gates knows to offer enquiry only. */
    public const COMING_SOON = 'coming_soon';

    /** Not reachable. A gated route 404s. */
    public const OFF = 'off';

    /** @var list<string> */
    public const STATES = [self::ON, self::COMING_SOON, self::OFF];

    private const SETTINGS_KEY = 'services';

    /** Per-request memoisation only — see {@see Setting::getSocialSettings()}. */
    private const CACHE = 'rihla.services';

    /**
     * Every service the site knows how to gate, in catalogue order.
     *
     * Adding a new one — Phase 9's `stays_guesthouses` going live is not a
     * new entry, it is a state change — is one line here plus whatever
     * route actually starts checking it.
     *
     * @return array<string, array{label: string, default: string}>
     */
    public static function catalogue(): array
    {
        return [
            'stays_guesthouses' => ['label' => 'Guesthouses', 'default' => self::OFF],
            'stays_island_holidays' => ['label' => 'Island holidays', 'default' => self::OFF],
            'stays_rooms' => ['label' => 'Rooms in Malé', 'default' => self::OFF],
        ];
    }

    /**
     * The current state of every known service, catalogue defaults filled in.
     *
     * A service present in the catalogue but never saved reads as its
     * default rather than as "off" — so adding a new service to the
     * catalogue above does not silently disable something the next deploy
     * was meant to switch on. A stored value that is not one of
     * {@see STATES} (a typo written by hand against the `settings` table, or
     * a state name later retired) falls back to the default the same way,
     * rather than being trusted as-is.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        $stored = self::stored();
        $result = [];

        foreach (self::catalogue() as $key => $meta) {
            $value = $stored[$key] ?? $meta['default'];
            $result[$key] = in_array($value, self::STATES, true) ? $value : $meta['default'];
        }

        return $result;
    }

    /** A key outside the catalogue reads as {@see OFF}: unknown is not on. */
    public static function state(string $key): string
    {
        return self::all()[$key] ?? self::OFF;
    }

    public static function isOn(string $key): bool
    {
        return self::state($key) === self::ON;
    }

    public static function isComingSoon(string $key): bool
    {
        return self::state($key) === self::COMING_SOON;
    }

    /** Neither on nor coming soon — the section must not be reachable. */
    public static function isOff(string $key): bool
    {
        return self::state($key) === self::OFF;
    }

    /**
     * Persist a full set of states from the admin form.
     *
     * Keyed by the catalogue, not by whatever the caller sends: a key the
     * catalogue does not recognise is dropped rather than stored, and a
     * catalogue key the caller omits keeps its default rather than
     * disappearing from the row.
     *
     * @param  array<string, string>  $states
     */
    public static function save(array $states): void
    {
        $clean = [];

        foreach (self::catalogue() as $key => $meta) {
            $value = $states[$key] ?? $meta['default'];
            $clean[$key] = in_array($value, self::STATES, true) ? $value : $meta['default'];
        }

        Setting::updateOrCreate(['key' => self::SETTINGS_KEY], ['value' => $clean]);

        app()->forgetInstance(self::CACHE);
    }

    /** @return array<string, string> */
    private static function stored(): array
    {
        if (app()->bound(self::CACHE)) {
            return app()->make(self::CACHE);
        }

        $value = Setting::where('key', self::SETTINGS_KEY)->first()?->value;
        $value = is_array($value) ? $value : [];

        app()->instance(self::CACHE, $value);

        return $value;
    }
}
