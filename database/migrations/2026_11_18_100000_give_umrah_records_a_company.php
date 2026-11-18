<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every Umrah record says which company it belongs to — Phase 1a of
 * docs/UMRAH_COMPANIES_PLAN.md.
 *
 * **`company_id`, not `partner_id`.** `packages.partner_id` already means
 * the guesthouse host who wrote a package Rihla sells; a company column of
 * the same name would answer two questions. Both point at `partners`.
 *
 * **An index, not a foreign key**, on every table: adding a constrained
 * column makes SQLite rebuild the table, and the rebuild cascade-deletes
 * every row hanging off it (AGENTS.md). A company leaves through an export,
 * never a delete, so nothing waits on a missing constraint.
 *
 * **Every existing row is Rihla's**, because until now Rihla was the only
 * company. The id is looked up by the `is_rihla` flag on the day this runs
 * and never taken from a constant (AGENTS.md: a migration must never write
 * one). A database with no Rihla record has no Umrah rows to stamp.
 *
 * The table list is written out, not derived from the models, so what this
 * migration did cannot change when a model does. Down drops each index
 * before its column, in separate calls (AGENTS.md).
 */
return new class extends Migration
{
    private const TABLES = [
        // Products
        'packages', 'departures', 'price_tiers', 'itinerary_items', 'departure_flights',
        'departure_hotels', 'departure_transfers', 'departure_checklist_items',
        'departure_events', 'departure_costs', 'exchange_rates',
        // People
        'customers', 'travellers', 'customer_tags', 'people', 'family_accesses', 'portal_accesses',
        // Bookings
        'bookings', 'booking_lines', 'booking_status_transitions', 'booking_travellers',
        'seat_holds', 'waitlist_entries', 'quotations', 'discounts', 'payment_schedules',
        // Money
        'payments', 'payment_transactions',
        // Documents
        'documents', 'document_versions', 'document_extractions', 'visa_applications',
        'visa_application_events', 'nusuk_permits', 'nusuk_permit_events',
        // On the journey
        'bus_groups', 'cards', 'rooms', 'room_assignments', 'roll_calls', 'roll_call_marks',
        'incidents', 'incident_notes', 'operations_log_entries', 'announcements',
        'emergency_broadcasts', 'broadcast_deliveries', 'scholar_questions', 'assistant_exchanges',
        // Office
        'enquiries', 'enquiry_notes', 'crm_tasks', 'notices', 'audit_logs',
        'telegram_links', 'telegram_link_codes', 'telegram_updates',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('company_id')->nullable()->index();
            });
        }

        $rihla = DB::table('partners')->where('is_rihla', true)->value('id');

        if ($rihla === null) {
            return;
        }

        foreach (self::TABLES as $name) {
            DB::table($name)->whereNull('company_id')->update(['company_id' => $rihla]);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropIndex(['company_id']);
            });

            Schema::table($name, function (Blueprint $table): void {
                $table->dropColumn('company_id');
            });
        }
    }
};
