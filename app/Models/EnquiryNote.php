<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line in an enquiry's history. Append-only.
 *
 * Notes somebody wrote and moves the system recorded sit in the same list,
 * because the useful view is "what has happened to this" in order, and
 * splitting them means reading two screens and merging them by eye.
 */
class EnquiryNote extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    /** Somebody wrote it. */
    public const NOTE = 'note';

    /** The system recorded a move. */
    public const STATUS = 'status';

    /** @return BelongsTo<Enquiry, $this> */
    public function enquiry(): BelongsTo
    {
        return $this->belongsTo(Enquiry::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
