<?php

namespace Modules\Stays\Support;

use App\Models\Partner;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Modules\Stays\Models\BlockedDate;
use Modules\Stays\Models\CalendarFeed;
use Modules\Stays\Models\Property;
use Modules\Stays\Models\PropertyAddon;
use Modules\Stays\Models\PropertyPhoto;
use Modules\Stays\Models\PropertyUnit;
use Modules\Stays\Models\Rate;
use Modules\Stays\Models\Review;
use Modules\Stays\Models\RoomType;
use Modules\Stays\Models\Stay;
use Modules\Stays\Models\StayDiscount;
use Modules\Umrah\Models\Package;

/**
 * "Is this request being answered for a host, and may they?" — §16.6.
 *
 * The policies are shared by both panels, because Laravel resolves one
 * policy per model. Inside `/host` a policy that opts in answers from the
 * person's {@see HostRole} at the current host and from whether the record
 * is that host's — never from staff permissions, which a host user does not
 * hold. Everywhere else nothing changes.
 *
 * Ownership is checked here as well as by Filament's tenant scoping of
 * queries: the scope decides what a list shows; this decides what an
 * action may touch, including a record reached by id.
 */
final class HostContext
{
    /** The host being worked in, or null outside the host panel. */
    public static function current(): ?Partner
    {
        if (Filament::getCurrentPanel()?->getId() !== 'host') {
            return null;
        }

        $tenant = Filament::getTenant();

        return $tenant instanceof Partner ? $tenant : null;
    }

    public static function allows(User $user, Partner $host, string $ability, ?Model $record = null): bool
    {
        if ($host->isSuspended() || ! HostRole::allows($user->roleAt($host), $ability)) {
            return false;
        }

        return $record === null || self::owns($host, $record);
    }

    /** Whether a record belongs to this host, followed to its building. */
    public static function owns(Partner $host, Model $record): bool
    {
        $partnerId = match (true) {
            $record instanceof Property, $record instanceof Review => $record->partner_id,
            $record instanceof StayDiscount => $record->property?->partner_id,
            // A package is the host's only if they wrote it — one of
            // Rihla's built on their guesthouse is still Rihla's.
            $record instanceof Package => $record->partner_id,
            $record instanceof RoomType, $record instanceof PropertyPhoto, $record instanceof PropertyAddon,
            $record instanceof PropertyUnit, $record instanceof Stay => $record->property?->partner_id,
            $record instanceof Rate, $record instanceof BlockedDate, $record instanceof CalendarFeed => $record->roomType?->property?->partner_id,
            default => null,
        };

        return $partnerId !== null && (int) $partnerId === (int) $host->getKey();
    }
}
