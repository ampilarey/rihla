<?php

namespace App\Models\Concerns;

use App\Models\Notice;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Something a customer can be told about — §15.7.
 *
 * A booking or a stay. Both are things a person bought, both produce news
 * worth passing on, and since {@see Notice} became polymorphic neither has
 * a foreign key doing the tidying up.
 *
 * **That is the whole reason this is a trait rather than two relations.**
 * `notices.booking_id` was `cascadeOnDelete`, so the database removed a
 * booking's notices with the booking — a notice about a booking that no
 * longer exists is not about anything. A polymorphic column carries no
 * constraint, so nothing does that any more, and nothing says so: the rows
 * simply start surviving their owner, carrying a headline with a customer's
 * name in it into a table where a deletion request has already reported
 * itself honoured.
 *
 * Restored here, in one place, so a third kind of owner cannot be added
 * without it.
 */
trait HasNotices
{
    public static function bootHasNotices(): void
    {
        // `self`, not `Model`: inside a trait it resolves to the class
        // using it, so static analysis can see `notices()` — a bare Model
        // cannot, and widening the call to make that go away would give up
        // the only check that this trait is on something that has one.
        static::deleting(function (self $owner): void {
            $owner->notices()->delete();
        });
    }

    /** @return MorphMany<Notice, $this> */
    public function notices(): MorphMany
    {
        return $this->morphMany(Notice::class, 'noticeable')->latest();
    }
}
