<?php

namespace Modules\Travel\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One step of a travel request: who moved it, to what, and why. Append-only. */
class TravelRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    /** @return BelongsTo<TravelRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TravelRequest::class, 'travel_request_id');
    }

    public static function record(TravelRequest $request, string $status, ?User $by, ?string $note = null): self
    {
        $event = new self;
        $event->forceFill([
            'travel_request_id' => $request->getKey(),
            'status' => $status,
            'user_id' => $by?->getKey(),
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ])->save();

        return $event;
    }
}
