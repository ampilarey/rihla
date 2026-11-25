<?php

namespace App\Console\Commands;

use App\Mail\NoticeMail;
use App\Models\Notice;
use App\Models\NoticeEmail;
use App\Models\Partner;
use App\Support\OutgoingMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * E-mail each new notice to its customer, when they gave an address.
 *
 * Run by the scheduler every five minutes rather than inside the request
 * that raised the notice: there is no queue worker on this host (ADR 0002),
 * and a slow or failing SMTP server must never slow down — or undo — a
 * payment, a cancellation or a quote. Safe to run late and to run twice:
 * a notice is sent once, retried on failure up to three times, and left to
 * WhatsApp after that. The staff queue is untouched; e-mail is in addition.
 */
class EmailNotices extends Command
{
    protected $signature = 'notices:email {--limit=50}';

    protected $description = 'E-mail new notices to customers who gave an address';

    /** Older notices are not dug up the first time this runs on a site. */
    private const WINDOW_DAYS = 3;

    public function handle(): int
    {
        if (! OutgoingMail::isReal()) {
            $this->line('Not sending: '.OutgoingMail::whyNot());

            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        $notices = Notice::query()
            ->whereNull('handled_at')
            ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
            ->whereNotIn('id', NoticeEmail::query()->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('attempts', '>=', NoticeEmail::MAX_ATTEMPTS))->select('notice_id'))
            ->with('noticeable')
            ->orderBy('id')
            ->limit(max(1, (int) $this->option('limit')))
            ->get();

        foreach ($notices as $notice) {
            $address = $notice->recipientEmail();

            if ($address === null) {
                continue;
            }

            $record = NoticeEmail::query()->where('notice_id', $notice->getKey())->first() ?? new NoticeEmail;
            $record->forceFill(['notice_id' => $notice->getKey(), 'address' => $address]);

            try {
                Mail::to($address)->send(new NoticeMail($notice, self::business($notice)));
                $record->forceFill(['sent_at' => now(), 'last_error' => null])->save();
                $sent++;
            } catch (Throwable $e) {
                $record->forceFill(['attempts' => $record->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 255)])->save();
                $failed++;
            }
        }

        $this->info("Sent {$sent} notice e-mail(s)".($failed > 0 ? ", {$failed} failed and will be retried" : '').'.');

        return self::SUCCESS;
    }

    /** Signed by the company whose notice it is, never by Rihla on its behalf. */
    private static function business(Notice $notice): string
    {
        $company = $notice->company_id === null ? null : Partner::query()->find($notice->company_id);

        return $company !== null && ! $company->is_rihla ? $company->name : (string) config('app.name');
    }
}
