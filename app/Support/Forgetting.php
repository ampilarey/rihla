<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Honouring a deletion request for one person — §10.4.
 *
 * ## The shape of the problem
 *
 * "Delete my data" and "keep your books" are both obligations, and they
 * point in opposite directions. A travel agency has to be able to show
 * what it was paid, by whom and for which journey, years after the
 * journey. It does not have to keep the passport scan.
 *
 * So this **separates the person from the record**. Bookings and payments
 * survive with their references, amounts, dates and statuses intact — the
 * financial history is unchanged and still adds up. What goes is
 * everything that says *who*: the name, the contacts, the national ID,
 * the passport number and the scan of it, the medical notes, the
 * emergency contact, the free text staff typed about them, and every
 * portal link that would open their booking.
 *
 * ## Why this is a map and not a `delete()`
 *
 * Personal data about one customer is scattered across two dozen tables
 * reached by five different routes, and the dangerous failure is the
 * quiet one: a table nobody thought of, whose rows survive a deletion
 * request that was reported as honoured.
 *
 * {@see Anonymisation::SCRUB} already lists every table holding personal
 * data — it is checked against the live schema and has caught omissions
 * before. This classifies each of those tables one step further: either
 * it names **how one person's rows are found in it**, or it is recorded
 * as not being about one customer at all. {@see unreached()} is the
 * difference, and the command refuses to run while it is not empty.
 *
 * A new table that holds something personal therefore fails loudly, in
 * the direction where the data does not quietly survive.
 */
final class Forgetting
{
    /**
     * How one person's rows are found, per table.
     *
     * The value names the route, and `ForgetCustomer` reads it:
     *
     * - `self`       — the customer row itself
     * - `customer`   — `customer_id`
     * - `traveller`  — `traveller_id` among their travellers
     * - `booking`    — `booking_id` among their bookings
     * - `enquiry`    — `enquiry_id` among their enquiries
     * - `document`   — `document_id` among their travellers' documents
     * - `incident`   — `incident_id` among incidents naming them
     * - `visa`/`permit` — the event tables, through their parent
     * - `morph`      — `crm_tasks`, by `about_type` + `about_id`
     *
     * @var array<string, string>
     */
    public const REACHED = [
        'customers' => 'self',
        'travellers' => 'customer',
        'bookings' => 'customer',
        'enquiries' => 'customer',
        'customer_tags' => 'customer',
        'waitlist_entries' => 'customer',

        'payments' => 'booking',
        'notices' => 'booking',
        'portal_accesses' => 'booking',
        'family_accesses' => 'booking',
        'quotations' => 'booking',

        'documents' => 'traveller',
        'scholar_questions' => 'traveller',
        'assistant_exchanges' => 'traveller',
        'incidents' => 'traveller',
        'visa_applications' => 'traveller',
        'nusuk_permits' => 'traveller',
        'roll_call_marks' => 'traveller',
        'room_assignments' => 'traveller',

        'enquiry_notes' => 'enquiry',
        'document_versions' => 'document',
        'incident_notes' => 'incident',
        'visa_application_events' => 'visa',
        'nusuk_permit_events' => 'permit',

        'crm_tasks' => 'morph',
    ];

    /**
     * Holds personal data, but not one customer's — so a deletion request
     * does not touch it.
     *
     * Recorded with a reason rather than left out, because "this table was
     * not in the list" and "this table was considered and does not apply"
     * are the same silence otherwise.
     *
     * @var array<string, string>
     */
    public const NOT_ONE_PERSONS = [
        'people' => 'Staff, guides and scholars the company publishes — not customers.',
        'partners' => 'A guesthouse owner Rihla has a commercial arrangement with — a supplier, not a customer. Erasing one because a guest asked to be forgotten would delete the contact details for a building other guests are still booked into.',
        'users' => 'Staff accounts. A member of staff leaving is a different procedure.',
        'settings' => 'Company settings. Nothing in here is about a person.',
        'operations_log_entries' => 'Written about a departure and read by the whole group; scrubbing one for one person destroys the record for the rest.',
        'announcements' => 'Sent to a whole departure.',
        'emergency_broadcasts' => 'Sent to a whole departure, and a record of a safety event.',
    ];

    /**
     * Tables holding personal data that nobody has said how to reach.
     *
     * Empty is the only acceptable answer. Anything here is a table whose
     * rows would survive a deletion request that was reported as honoured.
     *
     * @return list<string>
     */
    public static function unreached(): array
    {
        return array_values(array_diff(
            array_keys(Anonymisation::SCRUB),
            array_keys(self::REACHED),
            array_keys(self::NOT_ONE_PERSONS),
        ));
    }

    /**
     * Tables named here that the schema does not have.
     *
     * The mirror of {@see unreached()}: a route kept for a table somebody
     * dropped is a step that silently does nothing.
     *
     * @return list<string>
     */
    public static function missingFromSchema(): array
    {
        return array_values(array_filter(
            array_merge(array_keys(self::REACHED), array_keys(self::NOT_ONE_PERSONS)),
            fn (string $table): bool => ! Schema::hasTable($table),
        ));
    }
}
