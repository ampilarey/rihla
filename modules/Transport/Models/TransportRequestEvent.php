<?php

namespace Modules\Transport\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One step of a cargo or charter request: who moved it, to what, and why.
 * Append-only.
 */
class TransportRequestEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [];

    /** @return BelongsTo<TransportRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(TransportRequest::class, 'transport_request_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function record(TransportRequest $request, string $status, ?User $by, ?string $note = null): self
    {
        $event = new self;
        $event->forceFill([
            'transport_request_id' => $request->getKey(),
            'status' => $status,
            'user_id' => $by?->getKey(),
            'note' => $note === null ? null : mb_substr($note, 0, 500),
        ])->save();

        return $event;
    }
}
