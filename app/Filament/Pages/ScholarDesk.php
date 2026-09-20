<?php

namespace App\Filament\Pages;

use App\Support\ReviewQueue;
use App\Support\ReviewQueueItem;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * The Scholar Portal — §6.4, as one screen rather than a section.
 *
 * By the end of Phase 5 there are three resources a scholar reviews and a
 * question queue they answer, each behind its own navigation entry with its
 * own badge. A reviewer who has to check four screens to find out whether
 * anybody needs them checks none, and the editorial gate quietly becomes a
 * bottleneck nobody can see the length of. This is that length.
 *
 * It reads and does not act: signing off happens on the thing being signed
 * off, where the sources and the text are. See {@see ReviewQueue}.
 */
class ScholarDesk extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static ?string $navigationLabel = 'Waiting on you';

    protected static ?string $title = 'Waiting on you';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 0;

    protected string $view = 'filament.pages.scholar-desk';

    /**
     * Anybody who can sign something off or answer a question.
     *
     * Not a permission of its own: this page shows nothing that is not on
     * one of the four screens it links to, and inventing a fifth permission
     * would mean a scholar who can review but cannot see their own queue.
     */
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && (
            $user->can('knowledge.review')
            || $user->can('ziyarah.review')
            || $user->can('learning.review')
            || $user->can('question.answer')
        );
    }

    public static function getNavigationBadge(): ?string
    {
        $waiting = ReviewQueue::count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** @return Collection<int, ReviewQueueItem> */
    public function getQueue(): Collection
    {
        return ReviewQueue::build();
    }
}
