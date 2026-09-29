<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Rihla as a host of its own rooms — §16 Phase 12.5, ADR 0008 decision 3.
 *
 * Rooms Rihla sells for itself go through the same tables as anybody
 * else's, so Rihla needs a host record: the only one with `is_rihla`, on
 * `full_collection` (every payment is Rihla's already) and a commission of
 * nothing (it would be paying itself).
 *
 * **Every value is a literal**, not `Partner::FULL_COLLECTION` or any other
 * constant — D95, and AGENTS.md under "a migration must never write a
 * constant": a constant renamed later would change what this migration
 * meant after it had already run.
 *
 * Found by its slug, not created blindly, so running it against a database
 * where somebody already made the row by hand does not make a second. It
 * does not look for a partner *named* Rihla: a guesthouse that happened to
 * share the word must never be turned into the company.
 */
return new class extends Migration
{
    private const SLUG = 'rihla-travels';

    public function up(): void
    {
        $values = [
            'kind' => 'agency',
            'pricing_model' => 'commission',
            'commission_pct' => 0,
            'settlement_model' => 'full_collection',
            'green_tax_mode' => 'at_property',
            'verification' => 'verified',
            'verified_at' => now(),
            'status' => 'active',
            'is_active' => true,
            'is_rihla' => true,
            'updated_at' => now(),
        ];

        if (DB::table('partners')->where('slug', self::SLUG)->exists()) {
            DB::table('partners')->where('slug', self::SLUG)->update($values);

            return;
        }

        DB::table('partners')->insert($values + [
            'name' => 'Rihla Travels',
            'slug' => self::SLUG,
            'created_at' => now(),
        ]);
    }

    public function down(): void
    {
        // Only while nothing hangs off it. Once Rihla's own rooms exist
        // the row is holding them up, and the restrict on
        // `properties.partner_id` is right to refuse.
        DB::table('partners')
            ->where('slug', self::SLUG)
            ->whereNotExists(fn ($query) => $query->from('properties')->whereColumn('properties.partner_id', 'partners.id'))
            ->delete();
    }
};
