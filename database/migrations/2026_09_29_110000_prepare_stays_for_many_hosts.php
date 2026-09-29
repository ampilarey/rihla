<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * The data a marketplace needs, and nothing that uses it yet — §16.5, §16 Phase 12.3.
 *
 * A partner becomes a host with a verification state, a status and a
 * settlement model; a property gains an approval state and a place on the
 * map; a stay records who made it, what Rihla's cut was when it was made,
 * and when the guest actually arrived and left. Three tables join them:
 * the photographs of a building, the physical rooms in it, and the bill a
 * stay runs up.
 *
 * **Nothing reads the new states yet.** No public page filters on approval,
 * no booking refuses on verification. Phase 13 and 14 add the readers; this
 * lays down the columns so those phases change behaviour and not schema.
 *
 * ## What the rows already here become
 *
 * Every partner and property that exists was entered by Rihla's own staff,
 * who rang the owner and agreed terms — which is the check §16's
 * verification exists to make. So the backfill marks them verified,
 * active and approved; leaving them `unverified` and `draft` would take
 * every listing down the day Phase 13 starts reading the columns. The
 * values are written as literals, never read from a model constant, for
 * the reason AGENTS.md records under "a migration must never write a
 * constant".
 *
 * Stays already on the books were all asked for by a guest through the
 * site, so `created_via` defaults to `guest`. No commission is written
 * onto them: what Rihla earned on a stay made before §16 is whatever was
 * agreed by phone, and a figure computed today would be invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('partners', function (Blueprint $table): void {
            // Nullable only for the backfill below; the model always mints
            // one, and the unique index is added once every row has one.
            $table->string('slug', 120)->nullable()->after('name');
            $table->string('kind', 20)->default('guesthouse')->after('slug');

            $table->string('registration_number', 60)->nullable()->after('island');
            $table->date('registration_expires_on')->nullable()->after('registration_number');
            $table->string('registration_document_path')->nullable()->after('registration_expires_on');

            $table->string('verification', 20)->default('unverified')->after('green_tax_mode');
            $table->timestamp('verified_at')->nullable()->after('verification');
            $table->foreignId('verified_by')->nullable()->after('verified_at')
                ->constrained('users')->nullOnDelete();
            $table->text('verification_note')->nullable()->after('verified_by');

            $table->string('status', 20)->default('pending')->after('verification_note');
            $table->text('suspended_reason')->nullable()->after('status');

            $table->timestamp('terms_accepted_at')->nullable()->after('suspended_reason');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');

            $table->string('settlement_model', 30)->default('commission_deposit')->after('commission_pct');

            $table->timestamp('recommended_at')->nullable()->after('terms_version');
            $table->string('recommended_note')->nullable()->after('recommended_at');

            $table->boolean('is_rihla')->default(false)->after('is_active');
        });

        $this->backfillPartners();

        Schema::table('partners', function (Blueprint $table): void {
            $table->unique('slug');
            $table->index(['status', 'verification']);
        });

        Schema::table('properties', function (Blueprint $table): void {
            // Nullable: a Malé rental may be a room or a whole flat, and
            // guessing which for the rows already here would be inventing.
            $table->string('kind', 20)->nullable()->after('type');
            $table->string('atoll', 60)->nullable()->after('island');
            $table->decimal('latitude', 10, 7)->nullable()->after('atoll');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');

            $table->string('approval', 20)->default('draft')->after('is_published');
            $table->timestamp('submitted_at')->nullable()->after('approval');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')
                ->constrained('users')->nullOnDelete();
            $table->text('approval_note')->nullable()->after('approved_by');

            $table->index(['approval', 'is_published']);
        });

        DB::table('properties')->update(['approval' => 'approved', 'approved_at' => now()]);
        DB::table('properties')->where('type', 'guesthouse')->update(['kind' => 'guesthouse']);

        Schema::create('property_photos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // A photograph of one room type, or of the building when null.
            $table->foreignId('room_type_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('disk', 40)->default('public');
            $table->string('path');
            $table->json('caption')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'sort_order']);
        });

        Schema::create('property_units', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_type_id')->constrained()->cascadeOnDelete();
            // "Room 4", "Upstairs flat" — what reception calls it.
            $table->string('label', 60);
            $table->string('floor', 20)->nullable();
            $table->string('housekeeping', 20)->default('clean');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['property_id', 'label']);
            $table->index(['room_type_id', 'is_active']);
        });

        Schema::table('stays', function (Blueprint $table): void {
            $table->foreignId('unit_id')->nullable()->after('room_type_id')
                ->constrained('property_units')->nullOnDelete();

            $table->string('created_via', 10)->default('guest')->after('source');
            $table->foreignId('created_by')->nullable()->after('created_via')
                ->constrained('users')->nullOnDelete();

            $table->unsignedSmallInteger('commission_pct_snapshot')->nullable()->after('paid_minor');
            $table->unsignedBigInteger('commission_minor')->nullable()->after('commission_pct_snapshot');
            $table->unsignedBigInteger('host_net_minor')->nullable()->after('commission_minor');
            $table->string('settlement_model_snapshot', 30)->nullable()->after('host_net_minor');

            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
            $table->timestamp('checked_out_at')->nullable()->after('checked_in_at');
        });

        Schema::create('stay_charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stay_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('description');
            $table->unsignedSmallInteger('quantity')->default(1);
            // Signed: a discount or an adjustment is a negative line.
            $table->bigInteger('unit_minor');
            $table->bigInteger('total_minor');
            $table->char('currency', 3);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['stay_id', 'kind']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->string('collected_by', 10)->default('rihla')->after('method');
            // Set when a host took the money themselves, so a host's cash
            // is theirs in every report. Restricted: a host with money on
            // record is not deletable, the rule bookings impose on customers.
            $table->foreignId('partner_id')->nullable()->after('collected_by')
                ->constrained()->restrictOnDelete();
        });
    }

    /**
     * A unique slug per partner, minted the way a property's is.
     *
     * Written out here rather than calling the model, so that whatever the
     * model does later cannot change what this migration did.
     */
    private function backfillPartners(): void
    {
        $taken = [];

        foreach (DB::table('partners')->orderBy('id')->get(['id', 'name']) as $partner) {
            $base = Str::slug((string) $partner->name) ?: 'host';
            $slug = $base;

            for ($n = 2; in_array($slug, $taken, true); $n++) {
                $slug = $base.'-'.$n;
            }

            $taken[] = $slug;

            DB::table('partners')->where('id', $partner->id)->update([
                'slug' => $slug,
                'verification' => 'verified',
                'verified_at' => now(),
                'status' => 'active',
            ]);
        }
    }

    public function down(): void
    {
        // Constraints first, each table in its own statements, then the
        // columns — MySQL refuses to drop an index a foreign key still
        // needs, and SQLite's rebuild fails on anything still naming a
        // departing column. AGENTS.md records both halves.
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['partner_id']);
        });

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropColumn(['collected_by', 'partner_id']);
        });

        Schema::dropIfExists('stay_charges');

        Schema::table('stays', function (Blueprint $table): void {
            $table->dropForeign(['unit_id']);
            $table->dropForeign(['created_by']);
        });

        Schema::table('stays', function (Blueprint $table): void {
            $table->dropColumn([
                'unit_id', 'created_via', 'created_by',
                'commission_pct_snapshot', 'commission_minor', 'host_net_minor', 'settlement_model_snapshot',
                'checked_in_at', 'checked_out_at',
            ]);
        });

        Schema::dropIfExists('property_units');
        Schema::dropIfExists('property_photos');

        Schema::table('properties', function (Blueprint $table): void {
            $table->dropForeign(['approved_by']);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->dropIndex(['approval', 'is_published']);
        });

        Schema::table('properties', function (Blueprint $table): void {
            $table->dropColumn([
                'kind', 'atoll', 'latitude', 'longitude',
                'approval', 'submitted_at', 'approved_at', 'approved_by', 'approval_note',
            ]);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropForeign(['verified_by']);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropUnique(['slug']);
            $table->dropIndex(['status', 'verification']);
        });

        Schema::table('partners', function (Blueprint $table): void {
            $table->dropColumn([
                'slug', 'kind', 'registration_number', 'registration_expires_on', 'registration_document_path',
                'verification', 'verified_at', 'verified_by', 'verification_note',
                'status', 'suspended_reason', 'terms_accepted_at', 'terms_version',
                'settlement_model', 'recommended_at', 'recommended_note', 'is_rihla',
            ]);
        });
    }
};
