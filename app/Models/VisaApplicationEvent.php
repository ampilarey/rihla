<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One stage of one visa application: from, to, who, why, and what they saw.
 *
 * Append-only, like audit_logs and booking_status_transitions. There is no
 * updated_at and nothing updates a row.
 */
class VisaApplicationEvent extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    /** @return BelongsTo<VisaApplication, $this> */
    public function application(): BelongsTo
    {
        return $this->belongsTo(VisaApplication::class, 'visa_application_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The evidence for this stage — a receipt, a scan, a refusal letter. */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
