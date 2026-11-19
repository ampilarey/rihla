<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Security review before the pilot: `AuditObserver::NEVER_RECORD` matched
 * `password` exactly and missed `customers.portal_password`, so every
 * portal password set or changed wrote its bcrypt hash into the audit
 * trail — readable with `audit.view` and shipped in `company:export`.
 * The observer now drops the key; this removes the ones already written.
 * The fact that the row changed stays; only the hash goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('audit_logs')
            ->where(fn ($q) => $q->where('new_values', 'like', '%portal_password%')->orWhere('old_values', 'like', '%portal_password%'))
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach (['old_values', 'new_values'] as $column) {
                        $values = is_string($row->{$column}) ? json_decode($row->{$column}, true) : null;

                        if (is_array($values) && array_key_exists('portal_password', $values)) {
                            unset($values['portal_password']);
                            $changes[$column] = $values === [] ? null : json_encode($values);
                        }
                    }

                    if ($changes !== []) {
                        DB::table('audit_logs')->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    public function down(): void
    {
        // A hash removed from the trail is not put back.
    }
};
