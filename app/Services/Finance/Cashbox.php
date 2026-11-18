<?php

namespace App\Services\Finance;

use App\Exceptions\DeskRefusal;
use App\Models\Account;
use App\Models\LedgerCategory;
use App\Models\LedgerEntry;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Writes the ledger (companies plan, Phase 3f): income, spending, and a
 * transfer between two accounts, and voids any of them with a reason. The
 * one place entries are made, so the rules about them live in one place:
 * an amount is positive, a category is of the right kind, a transfer is a
 * pair, and a void takes its pair with it.
 */
final class Cashbox
{
    /**
     * @param  array{category_id?: int|null, departure_id?: int|null, counterparty?: string|null, reference?: string|null}  $details
     */
    public function record(Account $account, string $kind, Money $amount, Carbon $on, string $description, array $details = []): LedgerEntry
    {
        if (! in_array($kind, [LedgerEntry::INCOME, LedgerEntry::EXPENSE], true)) {
            throw new DeskRefusal('Record a transfer with the Transfer action, so both sides are written.');
        }

        $this->checkAmount($account, $amount);

        $categoryId = $details['category_id'] ?? null;

        if ($categoryId !== null && LedgerCategory::query()->whereKey($categoryId)->value('kind') !== $kind) {
            throw new DeskRefusal('That category is for '.($kind === LedgerEntry::INCOME ? 'spending' : 'income').'.');
        }

        return LedgerEntry::query()->create([
            'account_id' => $account->getKey(),
            'direction' => $kind === LedgerEntry::INCOME ? LedgerEntry::IN : LedgerEntry::OUT,
            'kind' => $kind,
            'category_id' => $categoryId,
            'departure_id' => $details['departure_id'] ?? null,
            'occurred_on' => $on->toDateString(),
            'currency' => $amount->currency,
            'amount_minor' => $amount->minor,
            'description' => $description,
            'counterparty' => $details['counterparty'] ?? null,
            'reference' => $details['reference'] ?? null,
        ]);
    }

    /**
     * Money moved between two of the company's own accounts. In different
     * currencies — riyal bought with rufiyaa — each side says what it was.
     *
     * @return array{0: LedgerEntry, 1: LedgerEntry}
     */
    public function transfer(Account $from, Account $to, Money $out, Money $in, Carbon $on, string $description): array
    {
        if ($from->is($to)) {
            throw new DeskRefusal('A transfer needs two different accounts.');
        }

        $this->checkAmount($from, $out);
        $this->checkAmount($to, $in);

        return DB::transaction(function () use ($from, $to, $out, $in, $on, $description): array {
            $group = (string) Str::ulid();
            $base = ['kind' => LedgerEntry::TRANSFER, 'occurred_on' => $on->toDateString(), 'description' => $description, 'group_key' => $group];

            return [
                LedgerEntry::query()->create($base + ['account_id' => $from->getKey(), 'direction' => LedgerEntry::OUT, 'currency' => $out->currency, 'amount_minor' => $out->minor, 'counterparty' => $to->name]),
                LedgerEntry::query()->create($base + ['account_id' => $to->getKey(), 'direction' => LedgerEntry::IN, 'currency' => $in->currency, 'amount_minor' => $in->minor, 'counterparty' => $from->name]),
            ];
        });
    }

    /** Void an entry, and the other half of its transfer. The entries stay. */
    public function void(LedgerEntry $entry, string $reason, ?User $by = null): void
    {
        if (trim($reason) === '') {
            throw new DeskRefusal('Say why it is being voided; the reason stays on the record.');
        }

        DB::transaction(function () use ($entry, $reason, $by): void {
            // Read back from the table, never the object handed in: one with
            // an unsaved change would carry it into the void's save.
            $entries = $entry->group_key === null
                ? LedgerEntry::query()->whereKey($entry->getKey())->get()
                : LedgerEntry::query()->where('group_key', $entry->group_key)->get();

            foreach ($entries as $one) {
                if ($one->isVoided()) {
                    continue;
                }

                $one->forceFill(['voided_at' => now(), 'voided_by' => $by?->getKey() ?? auth()->id(), 'void_reason' => $reason])->save();
            }
        });
    }

    private function checkAmount(Account $account, Money $amount): void
    {
        if ($amount->minor <= 0) {
            throw new DeskRefusal('An amount is more than nothing. Money going out is spending, not a negative.');
        }

        if ($amount->currency !== $account->currency) {
            throw new DeskRefusal($account->name.' holds '.$account->currency.', not '.$amount->currency.'.');
        }
    }
}
