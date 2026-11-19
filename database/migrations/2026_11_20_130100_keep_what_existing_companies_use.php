<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A company already working here keeps everything it was using when
 * features arrive (companies plan, Phase 5a): switching them off by
 * deploying would hide its own bookings from it overnight. Literals, never
 * the constants, so this records what it did on the day it ran (AGENTS.md).
 * Only a company with no features recorded is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('partners')
            ->where('is_umrah_company', true)
            ->whereNull('features')
            ->update([
                'features' => json_encode([
                    'bookings' => 'on', 'documents' => 'on', 'visas' => 'on', 'journey' => 'on', 'lists' => 'on', 'books' => 'on',
                    'portal' => 'on', 'telegram' => 'on', 'reading' => 'on', 'app' => 'on', 'domain' => 'on',
                ]),
            ]);
    }

    public function down(): void
    {
        // Nothing to undo: the column goes with the migration before this one.
    }
};
