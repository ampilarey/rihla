<?php

namespace App\Filament\Pages;

use App\Services\Assistant\StaffDrafter;
use BackedEnum;
use Filament\Pages\Page;
use UnitEnum;

/**
 * Write me a first draft — §9.6's staff drafting assistant.
 *
 * ## Why a page rather than a button on the enquiry
 *
 * An inline "draft a reply" action on the enquiry screen is the better
 * product, and it is the next step. It is not this step, because nobody has
 * supplied an API key: that button would be dead on a screen booking staff
 * open forty times a day, and a dead button teaches people the software is
 * broken faster than a missing one teaches them anything at all.
 *
 * This page says plainly that it is not set up, and on the day a key is
 * pasted in it starts working. Moving it inline is then a small change to
 * screens that will have a working assistant behind them.
 *
 * ## Nothing here sends anything
 *
 * §9.6's human in the loop is the member of staff reading this page. The
 * draft appears in a box to copy and edit; nothing is saved, sent,
 * published or quoted. `draft.use` is separate from the permissions on the
 * things being drafted for exactly that reason.
 */
class Drafting extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-pencil-square';

    protected static ?string $navigationLabel = 'Write me a first draft';

    protected static ?string $title = 'Write me a first draft';

    protected static UnitEnum|string|null $navigationGroup = 'Content';

    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.pages.drafting';

    public string $kind = StaffDrafter::ENQUIRY_REPLY;

    public string $notes = '';

    public ?string $result = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('draft.use') === true;
    }

    /** @return array<string, string> */
    public function kindOptions(): array
    {
        return collect(StaffDrafter::KINDS)
            ->mapWithKeys(fn (string $kind): array => [$kind => StaffDrafter::kindLabel($kind)])
            ->all();
    }

    public function whyNotAvailable(): ?string
    {
        return StaffDrafter::make()->whyNotAvailable();
    }

    public function whyNoTranslation(): string
    {
        return StaffDrafter::whyNoTranslation();
    }

    public function draft(): void
    {
        $this->result = StaffDrafter::make()->draft($this->kind, $this->notes);
    }
}
