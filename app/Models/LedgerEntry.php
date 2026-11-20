<?php

namespace App\Models;

use App\Exceptions\DeskRefusal;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Modules\Umrah\Models\Departure;

/**
 * One movement of money in or out of one account (companies plan, Phase
 * 3f, from Hijaz). Appended, never edited; voided with a reason when wrong,
 * and still there. Both rules are kept here, on the model, so no screen or
 * command can forget them: an edit to anything but the void, or a delete,
 * throws.
 *
 * A transfer between two accounts is two entries sharing a `group_key`, out
 * of one and into the other, and is voided as a pair. Only income and
 * expense reach the books: a transfer moves money the company already had.
 *
 * @property int $account_id
 * @property string $direction
 * @property string $kind
 * @property int|null $category_id
 * @property int|null $departure_id
 * @property Carbon $occurred_on
 * @property string $currency
 * @property int $amount_minor
 * @property string $description
 * @property string|null $counterparty
 * @property string|null $reference
 * @property string|null $group_key
 * @property int|null $recorded_by
 * @property Carbon|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 */
class LedgerEntry extends Model
{
    use BelongsToCompany;

    public const IN = 'in';

    public const OUT = 'out';

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    public const TRANSFER = 'transfer';

    /** @var array<string, string> */
    public const KINDS = [self::INCOME => 'Income', self::EXPENSE => 'Spending', self::TRANSFER => 'Transfer'];

    /** What may change on an entry once written: the void, and nothing else. */
    private const VOID_FIELDS = ['voided_at', 'voided_by', 'void_reason', 'updated_at'];

    protected $fillable = [
        'account_id', 'direction', 'kind', 'category_id', 'departure_id', 'occurred_on',
        'currency', 'amount_minor', 'description', 'counterparty', 'reference', 'group_key',
    ];

    protected $casts = [
        'occurred_on' => 'date',
        'amount_minor' => 'integer',
        'voided_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (LedgerEntry $entry): void {
            $entry->recorded_by ??= Auth::id();
        });

        static::updating(function (LedgerEntry $entry): void {
            $changed = array_diff(array_keys($entry->getDirty()), self::VOID_FIELDS);

            if ($changed !== [] || $entry->getOriginal('voided_at') !== null) {
                throw new DeskRefusal('A ledger entry is never edited. Void it with a reason and record it again.');
            }
        });

        static::deleting(function (): void {
            throw new DeskRefusal('A ledger entry is never deleted. Void it with a reason; it stays on the record.');
        });
    }

    /** @return BelongsTo<Account, $this> */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** @return BelongsTo<LedgerCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(LedgerCategory::class, 'category_id');
    }

    /** @return BelongsTo<Departure, $this> */
    public function departure(): BelongsTo
    {
        return $this->belongsTo(Departure::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function money(): Money
    {
        return Money::ofMinor($this->amount_minor, $this->currency);
    }

    /** Positive in, negative out: what it does to the account. */
    public function signedMinor(): int
    {
        return $this->direction === self::IN ? $this->amount_minor : -$this->amount_minor;
    }

    public function isVoided(): bool
    {
        return $this->voided_at !== null;
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /**
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeLive($query)
    {
        return $query->whereNull('voided_at');
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return ['account', 'departure'];
    }
}
