<?php

namespace App\Support;

use Filament\Facades\Filament;

/**
 * Where the current change is arriving from, for the audit trail.
 *
 * Set by an entry point that knows (`use()`), otherwise worked out from the
 * request: the Filament panel being served, or the route's name. Read by
 * AuditObserver on every row it writes, so a verification done from a chat
 * is recorded as such, and a document a pilgrim uploaded is not recorded
 * as something a member of staff did.
 *
 * The override is process-wide and explicit, because the places that need
 * it — a console command, the Telegram webhook — are not routes a name can
 * tell apart. Reset it when the work is done.
 */
final class AuditContext
{
    /** The staff panel at /staff, and the Blade admin at /admin. */
    public const STAFF = 'staff';

    /** The host panel at /host: a guesthouse running its own business. */
    public const HOST = 'host';

    /** A pilgrim on their own portal, by one-time link. */
    public const PILGRIM = 'pilgrim';

    /** A relative on the family portal, by one-time link. */
    public const FAMILY = 'family';

    /** The tour leader's phone page on the ground, including its outbox sync. */
    public const LEADER = 'leader';

    /** A guesthouse guest on their own stay page. */
    public const GUEST = 'guest';

    /** The public website: a visitor booking or enquiring. */
    public const WEB = 'web';

    public const CONSOLE = 'console';

    public const TELEGRAM = 'telegram';

    public const API = 'api';

    /** @var list<string> */
    public const ALL = [
        self::STAFF, self::HOST, self::PILGRIM, self::FAMILY, self::LEADER,
        self::GUEST, self::WEB, self::CONSOLE, self::TELEGRAM, self::API,
    ];

    /**
     * Route-name prefixes, in the order they are tried. The panels are not
     * here: a Filament action arrives on Livewire's own route, whose name
     * says nothing, so the panel is read from Filament instead.
     *
     * @var array<string, string>
     */
    private const BY_ROUTE = [
        'portal.' => self::PILGRIM,
        'learning.' => self::PILGRIM,
        'family.' => self::FAMILY,
        'leader.' => self::LEADER,
        'my-stay.' => self::GUEST,
        'stays.review' => self::GUEST,
        'admin.' => self::STAFF,
        'staff.' => self::STAFF,
        'mfa.' => self::STAFF,
        'devices.' => self::STAFF,
        'profile.' => self::STAFF,
        'host.' => self::HOST,
        'api.' => self::API,
    ];

    private static ?string $channel = null;

    public static function channel(): string
    {
        if (self::$channel !== null) {
            return self::$channel;
        }

        $panel = Filament::getCurrentPanel()?->getId();

        if ($panel === 'host') {
            return self::HOST;
        }

        if ($panel !== null) {
            return self::STAFF;
        }

        if (app()->runningInConsole() && ! app()->runningUnitTests()) {
            return self::CONSOLE;
        }

        $route = (string) request()->route()?->getName();

        foreach (self::BY_ROUTE as $prefix => $channel) {
            if (str_starts_with($route, $prefix)) {
                return $channel;
            }
        }

        if (request()->is('api/*')) {
            return self::API;
        }

        return self::WEB;
    }

    public static function use(string $channel): void
    {
        self::$channel = $channel;
    }

    public static function reset(): void
    {
        self::$channel = null;
    }
}
