<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * One line of an incident's narrative.
 *
 * Append-only by intent: there is no update path anywhere in the admin, and
 * `updated_at` exists only because the base migration gives it. An incident
 * report that can be quietly rewritten after the fact is not evidence.
 */
class IncidentNote extends Model
{
    use HasFactory;

    protected $fillable = ['incident_id', 'author_id', 'body'];

    protected static function booted(): void
    {
        static::creating(function (self $note): void {
            $note->author_id ??= Auth::id();
        });
    }

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
