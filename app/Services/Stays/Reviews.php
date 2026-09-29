<?php

namespace App\Services\Stays;

use App\Exceptions\ReviewRefused;
use App\Models\Review;
use App\Models\ReviewInvitation;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Guests' reviews — §16.11.
 *
 * Every rule lives here, so the guest's page, the invitation link, the
 * host's reply and Rihla's hide button cannot disagree about one.
 */
class Reviews
{
    /** How long an invitation link works. */
    public const INVITATION_DAYS = 30;

    /**
     * A guest reviews their stay.
     *
     * Only a stay that happened — `completed` — and only once: the unique
     * key on `stay_id` refuses a second one even if two requests race past
     * the check above it. Published by itself after
     * `stays.reviews.publish_after_hours`, unless Rihla hides it first.
     *
     * @param  array{rating: int, cleanliness?: ?int, accuracy?: ?int, communication?: ?int, value?: ?int, body?: ?string, locale?: ?string}  $data
     *
     * @throws ReviewRefused
     */
    public function submit(Stay $stay, array $data, ?ReviewInvitation $invitation = null): Review
    {
        if ($stay->status !== Stay::COMPLETED) {
            throw new ReviewRefused(__('messages.A stay can be reviewed once it is over.'));
        }

        if ($stay->review()->exists()) {
            throw new ReviewRefused(__('messages.This stay has been reviewed already. Thank you.'));
        }

        $rating = (int) $data['rating'];

        if ($rating < 1 || $rating > 5) {
            throw new ReviewRefused(__('messages.Choose between one and five stars.'));
        }

        try {
            return DB::transaction(function () use ($stay, $data, $rating, $invitation): Review {
                $review = new Review;
                $review->forceFill([
                    'stay_id' => $stay->getKey(),
                    'property_id' => $stay->property_id,
                    'partner_id' => $stay->property?->partner_id,
                    'customer_id' => $stay->customer_id,
                    'rating' => $rating,
                    'cleanliness' => $this->aspect($data['cleanliness'] ?? null),
                    'accuracy' => $this->aspect($data['accuracy'] ?? null),
                    'communication' => $this->aspect($data['communication'] ?? null),
                    'value' => $this->aspect($data['value'] ?? null),
                    'body' => filled($data['body'] ?? null) ? trim((string) $data['body']) : null,
                    'locale' => (string) ($data['locale'] ?? app()->getLocale()),
                    'submitted_at' => now(),
                    'published_at' => now()->addHours((int) config('stays.reviews.publish_after_hours', 48)),
                ])->save();

                $invitation?->forceFill(['used_at' => now()])->save();

                return $review;
            });
        } catch (QueryException) {
            // The unique key: another request got there first.
            throw new ReviewRefused(__('messages.This stay has been reviewed already. Thank you.'));
        }
    }

    /**
     * A link that opens the review form for one stay — minted when asked
     * for, shown once, stored hashed (the portal's rule).
     */
    public function invite(Stay $stay, ?User $by = null): string
    {
        $token = Str::random(40);

        ReviewInvitation::create([
            'stay_id' => $stay->getKey(),
            'token_hash' => hash('sha256', $token),
            'issued_by' => $by?->getKey(),
            'expires_at' => now()->addDays(self::INVITATION_DAYS),
        ]);

        return $token;
    }

    /**
     * The invitation behind a link. Spent ones still open — to show the
     * guest the review they wrote — but only until they expire, and the
     * form does not come back: the stay has its review.
     */
    public function invitation(string $token): ?ReviewInvitation
    {
        return ReviewInvitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())
            ->first();
    }

    /** Rihla hides a review. The reason is shown to its author, never to the public. */
    public function hide(Review $review, string $reason, ?User $by = null): Review
    {
        $review->forceFill([
            'hidden_at' => now(),
            'hidden_reason' => $reason,
            'hidden_by' => $by?->getKey(),
        ])->save();

        return $review;
    }

    public function unhide(Review $review): Review
    {
        $review->forceFill(['hidden_at' => null, 'hidden_reason' => null, 'hidden_by' => null])->save();

        return $review;
    }

    /**
     * The host answers — once, and correctable for a day.
     *
     * @throws ReviewRefused
     */
    public function reply(Review $review, string $text): Review
    {
        if (! $review->replyIsEditable()) {
            throw new ReviewRefused('Your reply can no longer be changed.');
        }

        $text = trim($text);

        if ($text === '') {
            throw new ReviewRefused('Write something to reply with.');
        }

        $review->forceFill([
            'host_reply' => $text,
            'host_replied_at' => $review->host_replied_at ?? now(),
        ])->save();

        return $review;
    }

    private function aspect(mixed $value): ?int
    {
        $value = is_numeric($value) ? (int) $value : null;

        return $value !== null && $value >= 1 && $value <= 5 ? $value : null;
    }
}
