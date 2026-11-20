<?php

namespace Modules\Content\Console\Commands;

use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Modules\Content\Models\AssistantExchange;

/**
 * Retire old assistant exchanges — §9.6's logging, with an end to it.
 *
 * §9.6 requires prompts and responses to be logged, and says nothing about
 * for how long. A religious question is often personal in a way the asker
 * would not expect to be filed, so the answer here is `assistant.log_retention_days`
 * and not "for ever".
 *
 * A cron line, not a queue job: ADR 0002 rules out a worker on this host.
 * It belongs beside `notices:sweep` and `holds:expire` in the cPanel cron.
 */
class PruneAssistantLog extends Command
{
    protected $signature = 'assistant:prune {--days= : Override the configured retention}';

    protected $description = 'Delete pilgrim assistant exchanges older than the retention period';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('assistant.log_retention_days', 180));

        if ($days < 1) {
            $this->error('Retention must be at least one day. Refusing to delete the whole log.');

            return self::FAILURE;
        }

        $cutoff = Carbon::now()->subDays($days);

        // Every company's exchanges, not only Rihla's (security review): the
        // log is company-scoped and a console run defaults to Rihla, so a
        // company's pilgrims' questions were kept past the retention rule.
        $deleted = (int) app(CompanyContext::class)->acrossEveryCompany(
            fn (): int => AssistantExchange::query()->where('created_at', '<', $cutoff)->delete(),
        );

        $this->info($deleted === 0
            ? 'Nothing older than '.$days.' days.'
            : 'Deleted '.$deleted.' '.($deleted === 1 ? 'exchange' : 'exchanges').' older than '.$days.' days.');

        return self::SUCCESS;
    }
}
