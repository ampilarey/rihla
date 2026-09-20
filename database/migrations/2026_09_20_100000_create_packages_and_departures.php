<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The product/date split: a package is what Rihla sells, a departure is one
 * dated instance of it.
 *
 * `trips` is a flat table where the product and the date are the same row, so
 * running the same fourteen-night Ramadan package twice means two rows that
 * share nothing but a retyped description — and the second one gets a
 * different URL, its own SEO history and its own drifting copy. Everything
 * Phase 2 sells on (seats remaining, a countdown, comparing two departures of
 * the same package, a day-by-day itinerary, how far the hotel is from the
 * Haram) needs the two to be separate.
 *
 * **Additive and reversible.** `trips` is not touched, not renamed and not
 * dropped. Every row is copied into one package and one departure, and the
 * departure records which trip it came from, so the backfill can be checked
 * and this whole migration rolled back without losing anything. `/trips` and
 * `/trips/{slug}` keep working exactly as they do now; packages get their own
 * URLs. `trips` is retired only after a full season runs on the new model,
 * which is a decision for later and not this migration's business.
 *
 * Translatable columns follow docs/adr/0001-how-content-is-translated.md:
 * one JSON column per field, `{"en": …, "dv": …}`, per-field fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();

            // One product, one URL. Not translatable, for the same reason a
            // trip's slug is not: the locale is already a path segment.
            $table->string('slug')->unique();

            $table->json('title');
            $table->json('summary')->nullable();
            $table->json('details')->nullable();

            // What the price does and does not cover. A list per language,
            // stored as {"en": [...], "dv": [...]}, because "inclusions" is
            // the single most asked question and burying it in prose means
            // nobody reads it.
            $table->json('inclusions')->nullable();
            $table->json('exclusions')->nullable();

            $table->unsignedSmallInteger('nights')->nullable();

            // Walking distances, stairs and wheelchair suitability, which
            // nobody in this market publishes. Free text per language rather
            // than a star rating: "1.2 km to the Haram, some stairs at the
            // hotel entrance" is useful; "difficulty 3" is not.
            $table->string('accessibility_rating', 20)->nullable();
            $table->json('accessibility_notes')->nullable();

            $table->string('cover_image')->nullable();
            $table->boolean('is_published')->default(false);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_published', 'sort_order']);
        });

        Schema::create('departures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->cascadeOnDelete();

            // Lineage. Which `trips` row this was copied from, so the
            // backfill can be audited and re-run, and so a trip is never
            // copied twice. Null for a departure created directly.
            $table->foreignId('trip_id')->nullable()->constrained()->nullOnDelete();

            $table->date('date_start');
            $table->date('date_end');
            $table->string('airline')->nullable();

            // Capacity, split three ways because a seat that is held is
            // neither free nor sold. Phase 2 only reads these to draw the
            // seats-remaining bar; Phase 3's seat holds and the database-level
            // overbooking constraint are what make `held` move.
            $table->unsignedSmallInteger('capacity_total')->default(0);
            $table->unsignedSmallInteger('capacity_held')->default(0);
            $table->unsignedSmallInteger('capacity_confirmed')->default(0);

            $table->string('status', 20)->default('upcoming');
            $table->boolean('is_published')->default(false);
            $table->timestamps();

            $table->index(['is_published', 'date_start']);
            $table->index(['package_id', 'date_start']);
            $table->unique('trip_id');
        });

        Schema::create('price_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // Occupancy is what actually changes an Umrah price: a quad room
            // costs a third of a single.
            $table->string('occupancy', 20);
            $table->string('pax_type', 20)->default('adult');

            // [R-7] Integer minor units — laari for MVR, cents for USD. Never
            // a float, never a decimal-as-string. 28,500 rufiyaa is stored as
            // 2_850_000. Retrofitting this after the first real payment means
            // migrating money, which is the one migration nobody wants.
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('MVR');

            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['departure_id', 'occupancy', 'pax_type']);
        });

        Schema::create('departure_hotels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();

            // Makkah or Madinah. A string, not an enum: Rihla may add a
            // Jeddah transit night, and a migration to add one value to an
            // enum is not worth the constraint here.
            $table->string('city', 40);
            $table->string('name');
            $table->string('rating', 10)->nullable();

            // The thing pilgrims actually compare. "300 m, about 4 minutes'
            // walk to the Haram" beats "5-star hotel" every time, and no
            // competitor in this market publishes it.
            $table->unsignedSmallInteger('distance_metres')->nullable();
            $table->unsignedSmallInteger('walk_minutes')->nullable();

            $table->unsignedSmallInteger('nights')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['departure_id', 'sort_order']);
        });

        Schema::create('itinerary_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('departure_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('day_number');
            $table->json('title');
            $table->json('description')->nullable();
            $table->string('city', 40)->nullable();
            $table->timestamps();

            $table->index(['departure_id', 'day_number']);
        });

        $this->backfillFromTrips();
    }

    /**
     * Every trip becomes one package and one departure.
     *
     * Deliberately dumb: no attempt to guess that two trips are the same
     * package run twice. A person can merge them in the admin later, and a
     * wrong guess here would silently join two products that only looked
     * alike. The lineage column records where each departure came from.
     */
    private function backfillFromTrips(): void
    {
        if (! Schema::hasTable('trips')) {
            return;
        }

        $now = now();

        foreach (DB::table('trips')->orderBy('id')->get() as $trip) {
            $packageId = DB::table('packages')->insertGetId([
                // Suffixed, so the package never collides with the trip's own
                // slug while both are live and both are routed.
                'slug' => $this->uniquePackageSlug($trip->slug ?: 'package-'.$trip->id),
                'title' => $this->json($trip->title),
                'summary' => $this->json($trip->summary),
                'details' => $this->json($trip->details),
                'nights' => $this->nightsBetween($trip->date_start, $trip->date_end),
                'cover_image' => $trip->cover_image,
                'is_published' => $trip->is_published,
                'created_at' => $trip->created_at ?? $now,
                'updated_at' => $trip->updated_at ?? $now,
            ]);

            $departureId = DB::table('departures')->insertGetId([
                'package_id' => $packageId,
                'trip_id' => $trip->id,
                'date_start' => $trip->date_start,
                'date_end' => $trip->date_end,
                'status' => $trip->status ?? 'upcoming',
                'is_published' => $trip->is_published,
                'created_at' => $trip->created_at ?? $now,
                'updated_at' => $trip->updated_at ?? $now,
            ]);

            // `trips.price_from_mvr` is whole rufiyaa; price tiers are laari.
            if (filled($trip->price_from_mvr)) {
                DB::table('price_tiers')->insert([
                    'departure_id' => $departureId,
                    // The old column says "from", with no occupancy attached.
                    // Quad is the cheapest room and therefore what a "from"
                    // price almost always quoted, so that is where it lands —
                    // stated here rather than left to be inferred later.
                    'occupancy' => 'quad',
                    'pax_type' => 'adult',
                    'amount_minor' => (int) $trip->price_from_mvr * 100,
                    'currency' => 'MVR',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    private function uniquePackageSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while (DB::table('packages')->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function nightsBetween(?string $start, ?string $end): ?int
    {
        if (blank($start) || blank($end)) {
            return null;
        }

        return (int) Carbon::parse($start)->diffInDays(Carbon::parse($end));
    }

    /**
     * `trips` translatable columns are already `{"en": …}` JSON. Anything
     * else — a bare string from an older row — becomes English.
     */
    private function json(?string $value): string
    {
        if (blank($value)) {
            return json_encode([], JSON_UNESCAPED_UNICODE);
        }

        $decoded = json_decode($value, true);

        return json_encode(
            is_array($decoded) ? $decoded : ['en' => $value],
            JSON_UNESCAPED_UNICODE,
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('itinerary_items');
        Schema::dropIfExists('departure_hotels');
        Schema::dropIfExists('price_tiers');
        Schema::dropIfExists('departures');
        Schema::dropIfExists('packages');
    }
};
