<?php

namespace App\Models\Concerns;

use App\Exceptions\CompanyMismatch;
use App\Models\Partner;
use App\Support\CompanyContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * An Umrah record that belongs to exactly one company — Phase 1 of
 * docs/UMRAH_COMPANIES_PLAN.md.
 *
 * `company_id` points at `partners` (an Umrah company is a partner of kind
 * agency; Rihla's own is `is_rihla`). It is a plain indexed column, not a
 * foreign key, for the SQLite rebuild trap in AGENTS.md.
 *
 * **The company is copied, never chosen.** On create, a row takes the
 * company of the records it hangs off ({@see companyParents()}): a booking
 * from its departure and customer, a traveller from their customer. Only a
 * root record with no parent takes the current company. If two parents
 * disagree, or the row was handed a company its parents do not share, the
 * write is refused with {@see CompanyMismatch} — that is the one place a
 * booking for company A's departure could be written with company B's
 * customer, so it is refused here rather than hoped against in every form.
 *
 * Parents are read straight from their tables, without global scopes or
 * hydrating them, so the check costs one small query per parent and cannot
 * be fooled by a scope that hides the parent.
 *
 * A row's company never changes after it is set. Re-pointing a parent is
 * checked again on update.
 *
 * @property int|null $company_id
 */
trait BelongsToCompany
{
    /**
     * The relations whose company this row must share, in order.
     *
     * @return list<string>
     */
    abstract protected function companyParents(): array;

    public static function bootBelongsToCompany(): void
    {
        static::creating(function (self $row): void {
            $row->settleCompany();
        });

        static::updating(function (self $row): void {
            if ($row->isDirty('company_id') && $row->getOriginal('company_id') !== null) {
                throw new CompanyMismatch(class_basename($row).' #'.$row->getKey().' cannot move to another company.');
            }

            $row->settleCompany();
        });
    }

    /** @return BelongsTo<Partner, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'company_id');
    }

    private function settleCompany(): void
    {
        $companies = [];

        foreach ($this->companyParents() as $name) {
            // A polymorphic parent naming a class that does not exist (a
            // test planting a future kind of payable) cannot even be built
            // as a relation, and says nothing about the company.
            $type = $this->getAttributes()[Str::snake($name).'_type'] ?? null;
            if (is_string($type) && ! class_exists(Relation::getMorphedModel($type) ?? $type)) {
                continue;
            }

            $company = $this->parentCompany($this->{$name}());

            if ($company !== null) {
                $companies[$name] = $company;
            }
        }

        $distinct = array_values(array_unique($companies));

        if (count($distinct) > 1) {
            throw new CompanyMismatch(class_basename($this).' would join records of different companies: '.json_encode($companies).'.');
        }

        $fromParents = $distinct[0] ?? null;
        $own = $this->company_id === null ? null : (int) $this->company_id;

        if ($own !== null && $fromParents !== null && $own !== $fromParents) {
            throw new CompanyMismatch(class_basename($this)." was given company {$own} but hangs off company {$fromParents}.");
        }

        $company = $own ?? $fromParents ?? app(CompanyContext::class)->id();

        // Left unset rather than written as null, so a schema that has no
        // column yet (history being replayed in a test) is not handed one.
        if ($company !== null) {
            $this->company_id = $company;
        }
    }

    /** @param Relation<Model, $this, mixed> $relation */
    private function parentCompany(Relation $relation): ?int
    {
        if ($relation instanceof MorphTo) {
            $type = $this->getAttribute($relation->getMorphType());
            $id = $this->getAttribute($relation->getForeignKeyName());

            if ($type === null || $id === null) {
                return null;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            if (! class_exists($class)) {
                return null;
            }

            /** @var Model $parent */
            $parent = new $class;

            return $this->companyOf($parent, $parent->getKeyName(), $id);
        }

        if ($relation instanceof BelongsTo) {
            $id = $this->getAttribute($relation->getForeignKeyName());

            return $id === null ? null : $this->companyOf($relation->getRelated(), $relation->getOwnerKeyName(), $id);
        }

        return null;
    }

    private function companyOf(Model $parent, string $key, mixed $id): ?int
    {
        // A parent that is not an Umrah record (a stay a payment is for, a
        // trip an audit row describes) says nothing about the company.
        if (! in_array(BelongsToCompany::class, class_uses_recursive($parent), true)) {
            return null;
        }

        $company = DB::connection($parent->getConnectionName())
            ->table($parent->getTable())
            ->where($key, $id)
            ->value('company_id');

        return $company === null ? null : (int) $company;
    }
}
