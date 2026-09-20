<?php

namespace App\Filament\Pages;

use App\Support\Alert;
use App\Support\Alerts;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What needs attention — §8.5's smart alerts.
 *
 * Open to anybody in the panel, because **the list, not the page, is what
 * is gated**: each alert names the permission its acting screen needs, and
 * a reader sees only the ones they could do something about. See
 * {@see Alerts}.
 *
 * An empty page is a good outcome and says so, rather than looking broken.
 */
class NeedsAttention extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationLabel = 'What needs attention';

    protected static ?string $title = 'What needs attention';

    protected static UnitEnum|string|null $navigationGroup = 'Travel';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.pages.needs-attention';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('admin.access') === true;
    }

    /**
     * The badge on the navigation item: how many are urgent.
     *
     * Only the urgent ones. A permanent "6" beside a menu entry is a "6"
     * nobody reads after a fortnight, and the number that should make
     * somebody click is the number of things due today.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $urgent = Alerts::for($user)->filter(fn (Alert $alert): bool => $alert->isUrgent())->count();

        return $urgent > 0 ? (string) $urgent : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return Collection<int, Alert> */
    public function getAlerts(): Collection
    {
        $user = auth()->user();

        return $user === null ? collect() : Alerts::for($user);
    }

    /**
     * How many alerts exist that this reader may not act on.
     *
     * Named rather than hidden, for the same reason the dashboard names the
     * margin it withholds: somebody comparing two screens in a meeting
     * must not find different lists and no explanation for it.
     */
    public function withheldCount(): int
    {
        $user = auth()->user();

        return $user === null ? 0 : Alerts::all()->count() - Alerts::for($user)->count();
    }
}
