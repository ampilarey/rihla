<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A page somebody from Rihla opened inside a company's panel under a support
 * grant — companies plan, Phase 5c. Written by `RecordSupportVisit`, read by
 * the company on its Support access page. Never updated.
 *
 * @property int $support_grant_id
 * @property int $company_id
 * @property int $user_id
 * @property string $path
 * @property Carbon $visited_at
 */
class SupportVisit extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['visited_at' => 'datetime'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
