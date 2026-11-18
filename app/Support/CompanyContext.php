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

    /** Work for one company for the length of a callback, then go back. */
    public function as(int $companyId, callable $callback): mixed
    {
        [$id, $resolved] = [$this->id, $this->resolved];
        [$this->id, $this->resolved] = [$companyId, true];

        try {
            return $callback();
        } finally {
            [$this->id, $this->resolved] = [$id, $resolved];
        }
    }
}
