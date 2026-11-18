<?php

namespace App\Console\Commands;

use App\Models\TelegramLink;
use App\Services\Telegram\Bot;
use App\Services\Telegram\DailySummary;
use App\Support\CompanyContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/** The morning message to every staff member who linked a chat. */
class TelegramDaily extends Command
{
    protected $signature = 'telegram:daily';

    protected $description = 'Send the morning summary to every linked staff member';

    public function handle(Bot $bot, DailySummary $summary, CompanyContext $companies): int
    {
        // Each company's linked staff, from that company's own bot and with
        // that company's numbers (companies plan, Phase 2d); Rihla's first,
        // as before. A company with no bot of its own sends nothing.
        $rihla = $companies->rihlaId();
        $withLinks = $companies->acrossEveryCompany(fn () => TelegramLink::query()->active()->distinct()->pluck('company_id'))
            ->prepend($rihla);

        $sent = array_sum($companies->eachCompany($withLinks, fn (): int => $this->sendFor($bot, $summary)));

        $this->info("Sent {$sent} summaries.");

        return self::SUCCESS;
    }

    /** The current company's summaries, one per linked person. */
    private function sendFor(Bot $bot, DailySummary $summary): int
    {
        if (! Bot::isConfigured()) {
            return 0;
        }

        $sent = 0;

        TelegramLink::query()->active()->with('user')->each(function (TelegramLink $link) use ($bot, $summary, &$sent): void {
            try {
                $bot->sendMessage($link->chat_id, $summary->for($link->user));
                $sent++;
            } catch (Throwable $e) {
                Log::warning('Daily summary failed', ['user_id' => $link->user_id, 'error' => $e->getMessage()]);
            }
        });

        return $sent;
    }
}
