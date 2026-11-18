<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * What a ledger entry was for (companies plan, Phase 3f). Each company has
 * its own list, started from {@see DEFAULTS} the first time it is asked
 * for, and may add to it.
 *
 * The defaults leave out what the books already read from elsewhere: no
 * "package sales" (a booking's payments), and no airfare or hotels (a
 * departure's costs). A category for those would invite the same money
 * twice.
 *
 * @property string $kind
 * @property string $slug
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 */
class LedgerCategory extends Model
{
    use BelongsToCompany;

    public const INCOME = 'income';

    public const EXPENSE = 'expense';

    /** @var array<string, array<string, string>> kind => slug => name */
    public const DEFAULTS = [
        self::INCOME => [
            'opening_balance' => 'Opening balance',
            'fees_income' => 'Fees and extras charged',
            'commission_income' => 'Commission received',
            'other_income' => 'Other income',
        ],
        self::EXPENSE => [
            'bank_charges' => 'Bank charges and transfer fees',
            'exchange_loss' => 'Exchange loss',
            'office' => 'Office',
            'staff' => 'Staff',
            'marketing' => 'Marketing',
            'other_expense' => 'Other expense',
        ],
    ];

    protected $fillable = ['kind', 'slug', 'name', 'sort_order', 'is_active'];

    protected $casts = ['is_active' => 'boolean', 'sort_order' => 'integer'];

    /** @return HasMany<LedgerEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class, 'category_id');
    }

    /** The current company's list, made from the defaults if it has none yet. */
    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }

        $order = 0;

        foreach (self::DEFAULTS as $kind => $categories) {
            foreach ($categories as $slug => $name) {
                static::query()->create(['kind' => $kind, 'slug' => $slug, 'name' => $name, 'sort_order' => ++$order]);
            }
        }
    }

    /** @return array<int, string> */
    public static function options(?string $kind = null): array
    {
        self::ensureDefaults();

        return static::query()
            ->where('is_active', true)
            ->when($kind, fn ($query) => $query->where('kind', $kind))
            ->orderBy('sort_order')
            ->pluck('name', 'id')
            ->all();
    }

    /** @return list<string> */
    protected function companyParents(): array
    {
        return [];
    }
}
