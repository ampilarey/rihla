<?php

namespace App\Support;

use App\Models\Partner;
use Illuminate\Database\QueryException;

/**
 * Which Umrah company the current request or command is working for —
 * Phase 1 of docs/UMRAH_COMPANIES_PLAN.md.
 *
 * Until a company has a panel of its own (Phase 2), every surface works for
 * Rihla's own company: `/staff`, rihla.mv and the console. So the default is
 * the partner whose `is_rihla` is true, found by the flag and not the slug,
 * for the reason {@see Partner::rihla()} gives.
 *
 * Bound as a scoped instance, so a value set for one request or queued job
 * never leaks into the next, and a test's fresh database is never answered
 * from the last test's cache.
 */
class CompanyContext
{
    private ?int $id = null;

    private bool $resolved = false;

    /** The current company's partner id, or null on a database with no Rihla record yet. */
    public function id(): ?int
    {
        if (! $this->resolved) {
            try {
                $this->id = Partner::rihla()?->getKey();
            } catch (QueryException) {
                // Only a schema rolled back past the partners migrations
                // (PackageDepartureTest replays history) has no `is_rihla`.
                // There are no company columns to fill on such a schema.
                $this->id = null;
            }

            $this->resolved = true;
        }

        return $this->id;
    }

    private bool $everyCompany = false;

    /**
     * The company every Umrah query is narrowed to, or null for none: on a
     * schema with no Rihla record, and inside {@see acrossEveryCompany()}.
     */
    public function scopeId(): ?int
    {
        return $this->everyCompany ? null : $this->id();
    }

    /**
     * Run a callback with no company scope at all.
     *
     * For Rihla's operator tools that must see every company's rows by
     * design: `data:forget`, `data:anonymise`, backups. Never for a page,
     * and never for anything a company's staff can start.
     */
    public function acrossEveryCompany(callable $callback): mixed
    {
        $was = $this->everyCompany;
        $this->everyCompany = true;

        try {
            return $callback();
        } finally {
            $this->everyCompany = $was;
        }
    }

    /** Whether a record belongs to the current company, or there is no scope to break. */
    public function owns(?int $companyId): bool
    {
        $scope = $this->scopeId();

        return $scope === null || $companyId === $scope;
    }

    /** Work for one company for the length of a callback, then go back. */
    public function as(int $companyId, callable $callback): mixed
    {
        [$id, $resolved, $every] = [$this->id, $this->resolved, $this->everyCompany];
        [$this->id, $this->resolved, $this->everyCompany] = [$companyId, true, false];

        try {
            return $callback();
        } finally {
            [$this->id, $this->resolved, $this->everyCompany] = [$id, $resolved, $every];
        }
    }
}
