<?php

namespace App\Support;

use App\Console\Commands\Anonymise;

/**
 * Which tables hold somebody's personal data, and what to do with each —
 * §10.4's "staging never holds unmasked production data".
 *
 * ## Why this is an explicit list of every table
 *
 * The plan asked for an anonymising export **before Phase 3 shipped**, and
 * it did not get written. What makes that dangerous is not the missing
 * command: it is that `test.rihla.mv` auto-deploys from `main` and is
 * reachable on the public internet, so the first time somebody restores a
 * production backup onto it to reproduce a bug, real passport numbers are
 * on a public host.
 *
 * So every table in the schema appears here, classified. Not the ones
 * somebody remembered — **all of them**, checked against the live schema at
 * runtime. {@see Anonymise} refuses to run at all
 * when the two disagree.
 *
 * That is the whole design. A scrubber built from a list of tables somebody
 * thought of is a scrubber that silently misses the table added last
 * Tuesday, and it misses it in the direction where real data survives on a
 * public server. Failing loudly on an unknown table is the only behaviour
 * that degrades safely.
 */
final class Anonymisation
{
    /**
     * Columns replaced with generated stand-ins, per table.
     *
     * The strategy names are read by {@see Anonymise}:
     *
     * - `name`    — "Placeholder Person 41"
     * - `email`   — "person41@example.invalid" (a reserved TLD, so a stray
     *               send cannot reach a real inbox)
     * - `phone`   — a 7-digit number in a range the Maldives does not issue
     * - `text`    — "Placeholder text, scrubbed for the test server."
     * - `token`   — 64 random hex characters, so old links stop working
     * - `gone`    — "(file deleted)", for a file pointer that cannot be null
     * - `null`    — emptied outright, which the column must allow
     *
     * @var array<string, array<string, string>>
     */
    public const SCRUB = [
        'customers' => [
            'name' => 'name', 'email' => 'email', 'phone' => 'phone',
            'national_id' => 'name', 'address' => 'text', 'notes' => 'text',
        ],
        'travellers' => [
            'full_name' => 'name', 'passport_number' => 'name',
            'medical_notes' => 'null', 'emergency_contact_name' => 'name',
            'emergency_contact_phone' => 'phone',
        ],
        'people' => ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'bio' => 'text'],
        // A partner is a real guesthouse owner, and their record holds the
        // phone number Rihla actually rings, their WhatsApp, and what was
        // agreed with them commercially — §15.4. None of it belongs on a
        // public test server. The rate and the percentage stay: they are
        // what makes the test data behave like the real thing, and neither
        // identifies anybody on its own.
        'partners' => [
            'name' => 'name', 'contact_name' => 'name', 'email' => 'email',
            'phone' => 'phone', 'whatsapp' => 'phone',
            'allotment_notes' => 'text', 'contract_notes' => 'text',
        ],
        // Every account, the administrators included. The password hash is
        // left alone rather than set to something known: nobody should be
        // able to sign in to the test server with a credential this command
        // minted. Make an account afterwards with `admin:create`.
        'users' => ['name' => 'name', 'email' => 'email', 'remember_token' => 'null'],
        'enquiries' => [
            'name' => 'name', 'phone' => 'phone', 'email' => 'email',
            'message' => 'text', 'next_action' => 'text', 'lost_reason' => 'text',
        ],
        'enquiry_notes' => ['body' => 'text'],
        'bookings' => ['notes' => 'text', 'cancellation_reason' => 'text'],
        'payments' => [
            'payer_name' => 'name', 'payer_bank' => 'text', 'payer_reference' => 'text',
            'notes' => 'text', 'rejection_reason' => 'text',
            'slip_path' => 'null', 'slip_original_filename' => 'null', 'slip_checksum' => 'null',
        ],
        'documents' => ['notes' => 'text', 'rejection_reason' => 'text'],
        // `path` and `original_filename` are NOT NULL, so they take the
        // `gone` stand-in rather than `null` — setting them to null failed
        // the whole run on the first database that had a single document
        // in it, which is every real one.
        'document_versions' => ['path' => 'gone', 'original_filename' => 'gone', 'checksum' => 'null'],
        'incidents' => ['summary' => 'text', 'detail' => 'text', 'location' => 'text', 'resolution' => 'text'],
        'incident_notes' => ['body' => 'text'],
        'scholar_questions' => ['body' => 'text', 'answer' => 'text', 'declined_reason' => 'text'],
        'assistant_exchanges' => ['question' => 'text', 'answer' => 'text', 'reason' => 'text'],
        'crm_tasks' => ['subject' => 'text', 'detail' => 'text'],
        'customer_tags' => ['tag' => 'text', 'note' => 'text'],
        'quotations' => ['includes' => 'text', 'excludes' => 'text', 'decline_reason' => 'text'],
        'visa_applications' => ['reference' => 'name', 'rejection_reason' => 'text', 'notes' => 'text'],
        'visa_application_events' => ['note' => 'text'],
        'nusuk_permits' => ['reference' => 'name', 'refusal_reason' => 'text', 'notes' => 'text'],
        'nusuk_permit_events' => ['note' => 'text'],
        'operations_log_entries' => ['body' => 'text'],
        'roll_call_marks' => ['note' => 'text'],
        'room_assignments' => ['note' => 'text'],
        'notices' => ['body' => 'text'],
        'announcements' => ['title' => 'text', 'body' => 'text'],
        'emergency_broadcasts' => ['subject' => 'text', 'body' => 'text'],
        'waitlist_entries' => ['name' => 'name', 'email' => 'email', 'phone' => 'phone', 'note' => 'text'],
        'settings' => [],
        // The tokens, so a link somebody was sent for a real booking does
        // not open a scrubbed one on a public host.
        'portal_accesses' => ['token_hash' => 'token'],
        'family_accesses' => ['token_hash' => 'token', 'label' => 'name'],
    ];

    /**
     * Emptied outright rather than scrubbed.
     *
     * Each of these is a record *of* something somebody did, and a scrubbed
     * one is worse than none: an audit log full of "Placeholder Person 41"
     * reads as evidence, and a session table is a live credential.
     *
     * @var list<string>
     */
    public const EMPTY_OUT = [
        'audit_logs',
        // The cache holds whatever was last put in it, which on this
        // application includes serialised Eloquent models of real people
        // (the homepage's why-section, Pulse's dashboard rows). Emptying it
        // is also what `cache:clear` would do, so nothing is lost.
        'cache',
        'cache_locks',
        'sessions',
        'password_reset_tokens',
        'failed_jobs',
        'jobs',
        'job_batches',
        'imports',
        'exports',
        'failed_import_rows',
        'pulse_entries',
        'pulse_aggregates',
        'pulse_values',
        'broadcast_deliveries',
        'seat_holds',
    ];

    /**
     * Left exactly as they are, because nothing in them is about a person.
     *
     * Listed rather than defaulted to, so that adding a table is a decision
     * somebody records here instead of an omission the scrubber forgives.
     *
     * @var list<string>
     */
    public const KEEP = [
        'article_references',
        'articles',
        'booking_lines',
        'booking_status_transitions',
        'booking_travellers',
        'departure_costs',
        'departure_hotels',
        'departures',
        'guide_steps',
        'hero_banners',
        'itinerary_items',
        'knowledge_articles',
        'learning_modules',
        'learning_path_module',
        'learning_paths',
        'location_misconceptions',
        'media',
        'migrations',
        'model_has_permissions',
        'model_has_roles',
        'module_completions',
        'packages',
        'payment_transactions',
        'permissions',
        'price_tiers',
        // Product content, not people. A property's name, description and
        // house rules are what the public page already shows, and its room
        // types are what a night in it buys. The partner *behind* it is
        // scrubbed above; the building is not a person.
        'properties',
        'room_types',
        'quiz_options',
        'quiz_questions',
        'role_has_permissions',
        'roles',
        'roll_calls',
        'rooms',
        'trips',
        'why_features',
        'why_sections',
        'ziyarah_locations',
    ];

    /**
     * Every table this class has an opinion about.
     *
     * @return list<string>
     */
    public static function classified(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(self::SCRUB),
            self::EMPTY_OUT,
            self::KEEP,
        )));
    }

    /**
     * Tables in the database that nobody has classified.
     *
     * The reason the command exists in this shape. A non-empty result means
     * somebody added a table and did not say whether it holds personal
     * data, and the safe reading of silence is that it does.
     *
     * @param  list<string>  $inDatabase
     * @return list<string>
     */
    public static function unclassified(array $inDatabase): array
    {
        return array_values(array_diff($inDatabase, self::classified()));
    }

    /**
     * Tables this class names that the database does not have.
     *
     * Harmless to scrub, but worth reporting: it usually means a table was
     * renamed and one half of the rename was missed.
     *
     * @param  list<string>  $inDatabase
     * @return list<string>
     */
    public static function stale(array $inDatabase): array
    {
        return array_values(array_diff(self::classified(), $inDatabase));
    }
}
