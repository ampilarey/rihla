<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event in the life of one payment. Append-only.
 *
 * `(provider, provider_event_id)` is unique, and that constraint is the
 * whole idempotency guarantee for gateway callbacks: a webhook delivered
 * twice — which they all are, eventually — fails the second insert, and the
 * money is recorded once. Internal events carry no provider, and repeated
 * NULLs are allowed in a unique index, which is exactly what is wanted.
 */
class PaymentTransaction extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = [
        'payload' => 'array',
        'amount_minor' => 'integer',
        'created_at' => 'datetime',
    ];

    /** The row was made. */
    public const CREATED = 'created';

    /** A customer or a member of staff attached the transfer slip. */
    public const SLIP_UPLOADED = 'slip_uploaded';

    /**
     * A better copy arrived. The old file is never deleted, and this row
     * carries its path and checksum so the replaced one can still be found.
     */
    public const SLIP_REPLACED = 'slip_replaced';

    /** Somebody moved the status. */
    public const REVIEWED = 'reviewed';

    /** A gateway said something. The payload is kept verbatim. */
    public const CALLBACK = 'callback';

    /** @return BelongsTo<Payment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
