<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Enquiry;
use App\Models\Payment;
use App\Models\Stay;
use App\Support\Anonymisation;
use App\Support\Forgetting;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Honour a deletion request for one person — §10.4.
 *
 * ## What it keeps, and why that is not a loophole
 *
 * Bookings and payments survive with their references, amounts, dates and
 * statuses intact. That is not the company keeping what it was asked to
 * delete: it is the difference between "who" and "what was paid". The
 * books still add up, `Kpis` and `JourneyProfit` still compute, and
 * nothing in those rows says whose journey it was.
 *
 * Everything that says *who* goes, including the files: the passport
 * scan and the transfer slip are deleted from disk, not merely
 * dereferenced.
 *
 * ## Two refusals, both fail-safe
 *
 * **It refuses while the schema has moved.** {@see Forgetting::unreached()}
 * is the list of tables holding personal data that nobody has said how to
 * reach. A deletion request reported as honoured while a table nobody
 * thought of still holds the person is the worst outcome this could have,
 * so an unclassified table stops the run.
 *
 * **It refuses while a booking is still live.** You cannot forget
 * somebody you are about to fly, and a held or confirmed seat is money
 * and a place on an aircraft. Cancel or complete first.
 */
class ForgetCustomer extends Command
{
    protected $signature = 'data:forget
        {customer : The customer id, or their e-mail address}
        {--dry-run : Report what would be erased and erase nothing}';

    protected $description = 'Honour a deletion request: erase one person, keep the financial record';

    /** Booking statuses that mean the journey is not finished with. */
    private const LIVE = [Booking::DRAFT, Booking::HELD, Booking::CONFIRMED];

    public function handle(): int
    {
        $unreached = Forgetting::unreached();

        if ($unreached !== []) {
            $this->error('Refusing to run: these tables hold personal data and nothing says how to reach one person in them.');

            foreach ($unreached as $table) {
                $this->line('  '.$table);
            }

            $this->line('Classify each in App\Support\Forgetting, then run this again.');

            return self::FAILURE;
        }

        $customer = $this->resolve((string) $this->argument('customer'));

        if ($customer === null) {
            $this->error('No customer matches that id or e-mail address.');

            return self::FAILURE;
        }

        $live = $customer->bookings()->whereIn('status', self::LIVE)->get();

        if ($live->isNotEmpty()) {
            $this->error('Refusing to run: this person still has '.$live->count().' booking(s) that are not finished with.');

            foreach ($live as $booking) {
                $this->line('  '.$booking->reference.' — '.$booking->status);
            }

            $this->line('Cancel or complete them first. A held or confirmed seat is money and a place on an aircraft.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');

        $travellerIds = $customer->travellers()->pluck('id')->all();
        $bookingIds = $customer->bookings()->pluck('id')->all();
        $enquiryIds = DB::table('enquiries')->where('customer_id', $customer->getKey())->pluck('id')->all();
        $stayIds = DB::table('stays')->where('customer_id', $customer->getKey())->pluck('id')->all();
        $documentIds = $travellerIds === [] ? [] : DB::table('documents')->whereIn('traveller_id', $travellerIds)->pluck('id')->all();
        $incidentIds = $travellerIds === [] ? [] : DB::table('incidents')->whereIn('traveller_id', $travellerIds)->pluck('id')->all();
        $visaIds = $travellerIds === [] ? [] : DB::table('visa_applications')->whereIn('traveller_id', $travellerIds)->pluck('id')->all();
        $permitIds = $travellerIds === [] ? [] : DB::table('nusuk_permits')->whereIn('traveller_id', $travellerIds)->pluck('id')->all();

        $keys = [
            'self' => [$customer->getKey()],
            'customer' => [$customer->getKey()],
            'traveller' => $travellerIds,
            'booking' => $bookingIds,
            'enquiry' => $enquiryIds,
            'stay' => $stayIds,
            'document' => $documentIds,
            'incident' => $incidentIds,
            'visa' => $visaIds,
            'permit' => $permitIds,
        ];

        $columns = [
            'self' => 'id', 'customer' => 'customer_id', 'traveller' => 'traveller_id',
            'booking' => 'booking_id', 'enquiry' => 'enquiry_id', 'document' => 'document_id',
            'incident' => 'incident_id', 'visa' => 'visa_application_id', 'permit' => 'nusuk_permit_id',
            'stay' => 'stay_id',
        ];

        $this->line($dry ? 'Would erase:' : 'Erasing:');

        $files = $this->files($documentIds, $bookingIds);
        $erased = 0;

        DB::transaction(function () use ($keys, $columns, $customer, $dry, &$erased): void {
            foreach (Forgetting::REACHED as $table => $route) {
                // Every table in REACHED is in SCRUB — `Forgetting::unreached()`
                // is checked above and the run stops if it is not.
                $scrub = Anonymisation::SCRUB[$table];

                $query = $this->queryFor($table, $route, $customer, $keys, $columns);

                $count = $query->count();

                if ($count === 0) {
                    continue;
                }

                $this->line('  '.str_pad($table, 26).$count.' row(s)');
                $erased += $count;

                if ($dry) {
                    continue;
                }

                foreach ($query->get() as $row) {
                    DB::table($table)->where('id', $row->id)->update(
                        collect($scrub)
                            ->map(fn (string $strategy): ?string => Anonymise::standIn($strategy, (int) $row->id))
                            ->all(),
                    );
                }
            }
        });

        if ($dry) {
            $this->newLine();
            $this->info('Would erase '.$erased.' row(s) and '.count($files).' file(s). Nothing was changed.');

            return self::SUCCESS;
        }

        foreach ($files as [$disk, $path]) {
            Storage::disk($disk)->delete($path);
        }

        // The id, not the name — recording who was forgotten would undo
        // the forgetting. This row says a request was honoured and when,
        // which is what an auditor needs and all they need.
        AuditLog::create([
            'event' => 'forgotten',
            'auditable_type' => Customer::class,
            'auditable_id' => $customer->getKey(),
            'new_values' => ['rows' => $erased, 'files' => count($files)],
        ]);

        $this->newLine();
        $this->info('Erased '.$erased.' row(s) and deleted '.count($files).' file(s).');
        $this->line('Bookings and payments were kept: their references, amounts and dates are the financial record.');

        return self::SUCCESS;
    }

    /**
     * Every file on disk that belongs to this person.
     *
     * Gathered before the rows are scrubbed, because scrubbing nulls the
     * paths that name them.
     *
     * @param  list<int>  $documentIds
     * @param  list<int>  $bookingIds
     * @return list<array{string, string}>
     */
    private function files(array $documentIds, array $bookingIds): array
    {
        $files = [];

        if ($documentIds !== []) {
            foreach (DB::table('document_versions')->whereIn('document_id', $documentIds)->get() as $version) {
                if ($version->path !== null && $version->path !== '') {
                    $files[] = [(string) $version->disk, (string) $version->path];
                }
            }
        }

        if ($bookingIds !== []) {
            foreach (Payment::query()->where('payable_type', Booking::class)->whereIn('payable_id', $bookingIds)->whereNotNull('slip_path')->get() as $payment) {
                $files[] = [(string) $payment->slip_disk, (string) $payment->slip_path];
            }
        }

        return $files;
    }

    /**
     * The rows in one table belonging to this person.
     *
     * Most tables carry a single foreign key and are one `whereIn`. Two
     * are not, and both would lose rows if they were forced into that
     * shape: a CRM task hangs off whichever record it was written about,
     * and a quotation belongs to an enquiry that may never have become a
     * booking — which is the ordinary case for somebody who asked, was
     * quoted, and did not travel.
     *
     * @param  array<string, list<int>>  $keys
     * @param  array<string, string>  $columns
     */
    private function queryFor(string $table, string $route, Customer $customer, array $keys, array $columns): Builder
    {
        if ($route === 'morph') {
            return DB::table('crm_tasks')->where(function (Builder $query) use ($customer, $keys): void {
                $query->where(function (Builder $q) use ($customer): void {
                    $q->where('about_type', Customer::class)->where('about_id', $customer->getKey());
                })->orWhere(function (Builder $q) use ($keys): void {
                    $q->where('about_type', Booking::class)->whereIn('about_id', $keys['booking'] ?: [0]);
                })->orWhere(function (Builder $q) use ($keys): void {
                    $q->where('about_type', Enquiry::class)->whereIn('about_id', $keys['enquiry'] ?: [0]);
                });
            });
        }

        // Payments hang off a polymorphic payable since §15.3 (Phase 8.6),
        // so there is no `booking_id` column to match on.
        //
        // **Both payables, not just bookings.** This read `Booking::class`
        // alone with a note saying a stay's money would be reached "when
        // Phase 9 gives it a customer of its own". Phase 9 did, in §15.4,
        // and nothing came back to this line — so between then and now a
        // guesthouse deposit would have survived a deletion request that
        // reported itself honoured. Phase 11 (§15.6) closes it.
        if ($table === 'payments') {
            return DB::table('payments')->where(function (Builder $query) use ($keys): void {
                $query->where(function (Builder $q) use ($keys): void {
                    $q->where('payable_type', Booking::class)
                        ->whereIn('payable_id', $keys['booking'] ?: [0]);
                })->orWhere(function (Builder $q) use ($keys): void {
                    $q->where('payable_type', Stay::class)
                        ->whereIn('payable_id', $keys['stay'] ?: [0]);
                });
            });
        }

        // Notices hang off a polymorphic owner since §15.7, so there is no
        // `booking_id` column here either — and both owners matter for the
        // same reason payments' did: a headline reading "Ibrahim, your
        // guesthouse has confirmed" is the person's name, in a table a
        // deletion request has already reported clean.
        if ($table === 'notices') {
            return DB::table('notices')->where(function (Builder $query) use ($keys): void {
                $query->where(function (Builder $q) use ($keys): void {
                    $q->where('noticeable_type', Booking::class)
                        ->whereIn('noticeable_id', $keys['booking'] ?: [0]);
                })->orWhere(function (Builder $q) use ($keys): void {
                    $q->where('noticeable_type', Stay::class)
                        ->whereIn('noticeable_id', $keys['stay'] ?: [0]);
                });
            });
        }

        if ($table === 'quotations') {
            return DB::table('quotations')->where(function (Builder $query) use ($keys): void {
                $query->whereIn('booking_id', $keys['booking'] ?: [0])
                    ->orWhereIn('enquiry_id', $keys['enquiry'] ?: [0]);
            });
        }

        return DB::table($table)->whereIn($columns[$route], $keys[$route] ?: [0]);
    }

    private function resolve(string $given): ?Customer
    {
        if (ctype_digit($given)) {
            return Customer::query()->find((int) $given);
        }

        return Customer::query()->where('email', $given)->first();
    }
}
