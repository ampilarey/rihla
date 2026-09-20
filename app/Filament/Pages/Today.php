<?php

namespace App\Filament\Pages;

use App\Models\CrmTask;
use App\Models\Customer;
use App\Models\Departure;
use App\Support\Reengagement;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * What to do today — §8.1's follow-up tasks and reminders, as a screen.
 *
 * ## Mine first, then everybody's
 *
 * A shared list where nothing is yours is a list nobody works. The first
 * section is the logged-in person's own overdue and due work; the rest of
 * the office is below it and collapsed, so a supervisor can see the whole
 * board without it being the first thing anybody reads.
 *
 * ## The reminder is the screen, not an email
 *
 * There is no SMTP on this host and no queue worker (ADR 0002), so a
 * "reminder" that promised to arrive by email would be a promise nothing
 * keeps. This is the honest version: the list is here, it is sorted by how
 * late things are, and the navigation badge counts what is overdue.
 */
class Today extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';

    protected static ?string $navigationLabel = 'Today';

    protected static ?string $title = 'Today';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = -1;

    protected string $view = 'filament.pages.today';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('task.viewAny') === true;
    }

    /** Overdue work belonging to whoever is looking. */
    public static function getNavigationBadge(): ?string
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $mine = CrmTask::overdue()->where('owner_id', $user->getKey())->count();

        return $mine > 0 ? (string) $mine : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /** @return Collection<int, CrmTask> */
    public function getMine(): Collection
    {
        return CrmTask::query()
            ->dueBy(now()->endOfDay())
            ->where('owner_id', auth()->id())
            ->with(['about', 'owner'])
            ->orderBy('due_on')
            ->get();
    }

    /** @return Collection<int, CrmTask> */
    public function getEverybodyElse(): Collection
    {
        return CrmTask::query()
            ->dueBy(now()->endOfDay())
            ->where(fn ($query) => $query->whereNull('owner_id')->orWhere('owner_id', '!=', auth()->id()))
            ->with(['about', 'owner'])
            ->orderBy('due_on')
            ->get();
    }

    /** @return Collection<int, CrmTask> */
    public function getSoon(): Collection
    {
        return CrmTask::query()
            ->open()
            ->whereDate('due_on', '>', now()->toDateString())
            ->whereDate('due_on', '<=', now()->addDays(7)->toDateString())
            ->where('owner_id', auth()->id())
            ->with(['about', 'owner'])
            ->orderBy('due_on')
            ->get();
    }

    /**
     * People who travelled, went quiet, and are not already in a
     * conversation — §8.1's post-Umrah re-engagement.
     *
     * On this page rather than its own, because it is the same job: a list
     * of people to ring, worked by a human. It does not send anything.
     *
     * @return Collection<int, array{customer: Customer, last_journey: Departure, months: int}>
     */
    public function getQuietCustomers(): Collection
    {
        return auth()->user()?->can('enquiry.viewAny') === true
            ? Reengagement::candidates()->take(15)
            : new Collection;
    }
}
