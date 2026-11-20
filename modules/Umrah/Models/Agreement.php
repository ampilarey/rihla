<?php

namespace Modules\Umrah\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\User;
use App\Support\CompanyDetails;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A booking's agreement (companies plan, Phase 3h, part 3, from Hijaz).
 *
 * Exists only once the company has written its terms
 * ({@see CompanyDetails::terms()}): an agreement to nothing
 * stated is not one, and an invented clause is worse than none. The
 * wording is frozen when it is signed.
 *
 * @property int $booking_id
 * @property string|null $wording
 * @property string|null $method
 * @property string|null $signed_name
 * @property string|null $signature
 * @property Carbon|null $signed_at
 * @property string|null $signed_ip
 * @property string|null $signed_user_agent
 * @property int|null $recorded_by
 */
class Agreement extends Model
{
    use BelongsToCompany;

    /** Signed on screen in the portal. */
    public const ON_SCREEN = 'screen';

    /** A signed paper copy, scanned, from the customer or the office. */
    public const PAPER = 'paper';

    protected $fillable = ['booking_id'];

    protected $hidden = ['signature'];

    protected $casts = ['signed_at' => 'datetime'];

    /** @return BelongsTo<Booking, $this> */
    public function booking(): BelongsTo
    {
        return $this->belongsTo(Booking::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }

    public function statusWords(): string
    {
        if (! $this->isSigned()) {
            return 'Not signed yet';
        }

        return ($this->method === self::PAPER ? 'Signed on paper' : 'Signed on screen by '.$this->signed_name)
            .', '.$this->signed_at?->format('j M Y');
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['booking'];
    }
}
