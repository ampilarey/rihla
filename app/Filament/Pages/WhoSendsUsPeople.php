<?php

namespace App\Filament\Pages;

use App\Support\ReferralCredit;
use App\Support\Referrals;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use UnitEnum;

/**
 * Who sends us people — the other half of §8.1's referral tracking.
 *
 * Behind `customer.viewAny`, because this is a list of customers and what
 * they are worth to Rihla. It is not a separate disclosure from the
 * customer list itself, so it does not get a verb of its own.
 *
 * See {@see Referrals} for why it reports "last follow-up recorded" and
 * never "last thanked".
 */
class WhoSendsUsPeople extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationLabel = 'Who sends us people';

    protected static ?string $title = 'Who sends us people';

    protected static UnitEnum|string|null $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 4;

    protected string $view = 'filament.pages.who-sends-us-people';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('customer.viewAny') === true;
    }

    /** @return Collection<int, ReferralCredit> */
    public function getCredits(): Collection
    {
        return Referrals::build();
    }
}
