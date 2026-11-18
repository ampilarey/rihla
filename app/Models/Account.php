<?php

namespace App\Models;

use App\Casts\EncryptedIdentifier;
use App\Models\Concerns\BelongsToCompany;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Where some of the company's money sits (companies plan, Phase 3f, from
 * Hijaz): a bank account, the office cash box, a tour leader's float.
 *
 * Its balance is its live ledger entries and nothing else. A booking's
 * payment is not posted here — the books read payments from their own
 * table, and posting them twice would count them twice — so an account
 * holds what the ledger says moved through it, starting from an opening
 * balance entered as income.
 *
 * @property string $name
 * @property string $kind
 * @property string $currency
 * @property string|null $bank
 * @property string|null $account_name
 * @property string|null $account_number
 * @property int|null $holder_user_id
 * @property bool $is_active
 * @property int $sort_order
 */
class Account extends Model
{
    use BelongsToCompany;

    public const BANK = 'bank';

    public const CASH = 'cash';

    public const LEADER_CASH = 'leader_cash';

    /** @var array<string, string> */
    public const KINDS = [
        self::BANK => 'Bank account',
        self::CASH => 'Cash box',
        self::LEADER_CASH => 'Tour leader\'s float',
    ];

    protected $fillable = [
        'name', 'kind', 'currency', 'bank', 'account_name', 'account_number',
        'holder_user_id', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
        // A bank account number, encrypted the way a passport number is.
        'account_number' => EncryptedIdentifier::class,
    ];

    /** @return BelongsTo<User, $this> */
    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function balance(): Money
    {
        $in = (int) $this->entries()->live()->where('direction', LedgerEntry::IN)->sum('amount_minor');
        $out = (int) $this->entries()->live()->where('direction', LedgerEntry::OUT)->sum('amount_minor');

        return Money::ofMinor($in - $out, $this->currency);
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    /** The last four digits, for lists where the whole number has no business being. */
    public function maskedNumber(): ?string
    {
        $number = $this->account_number;

        if ($number === null || $number === '') {
            return null;
        }

        return str_repeat('•', max(0, mb_strlen($number) - 4)).mb_substr($number, -4);
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return [];
    }
}
