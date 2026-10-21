<?php

namespace App\Services\Documents\Reading;

use App\Models\Traveller;
use Illuminate\Database\Eloquent\Collection;

/**
 * "Is this the same person?" — by ID number, passport number, or name and
 * date of birth. Asked when a reading is about to be accepted, so the
 * office sees that the passport it is filing is already on somebody
 * else's record before it files it twice.
 */
final class Duplicates
{
    /** @return Collection<int, Traveller> */
    public static function find(?string $nationalId, ?string $passportNumber, ?string $fullName, ?string $dateOfBirth, ?int $exceptId = null): Collection
    {
        $any = filled($nationalId) || filled($passportNumber) || (filled($fullName) && filled($dateOfBirth));

        if (! $any) {
            return new Collection;
        }

        $query = Traveller::query()->with('customer')->where(function ($query) use ($nationalId, $passportNumber, $fullName, $dateOfBirth): void {
            if (filled($nationalId)) {
                $query->orWhere('national_id', strtoupper(trim((string) $nationalId)));
            }

            if (filled($passportNumber)) {
                $query->orWhere('passport_number', strtoupper(trim((string) $passportNumber)));
            }

            if (filled($fullName) && filled($dateOfBirth)) {
                $first = trim(explode(' ', trim((string) $fullName))[0]);
                $query->orWhere(fn ($q) => $q->whereDate('date_of_birth', (string) $dateOfBirth)->where('full_name', 'like', '%'.$first.'%'));
            }
        });

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->limit(5)->get();
    }

    /**
     * The people a traveller's proposed reading would duplicate, in words.
     *
     * @param  array<string, mixed>  $fields
     */
    public static function warningFor(Traveller $traveller, array $fields): ?string
    {
        $others = self::find(
            isset($fields['national_id']) ? (string) $fields['national_id'] : null,
            isset($fields['passport_number']) ? (string) $fields['passport_number'] : null,
            isset($fields['full_name']) ? (string) $fields['full_name'] : null,
            isset($fields['date_of_birth']) ? (string) $fields['date_of_birth'] : null,
            (int) $traveller->getKey(),
        );

        if ($others->isEmpty()) {
            return null;
        }

        return 'Already on file for '.$others
            ->map(fn (Traveller $other): string => $other->full_name.' (booked by '.$other->customer->name.')')
            ->implode(', ').'. Accepting files it twice.';
    }
}
