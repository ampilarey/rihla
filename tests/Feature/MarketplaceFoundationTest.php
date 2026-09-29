<?php

namespace Tests\Feature;

use App\Filament\Resources\Partners\Pages\CreatePartner;
use App\Filament\Resources\Properties\Pages\CreateProperty;
use App\Models\Customer;
use App\Models\Partner;
use App\Models\Payment;
use App\Models\Property;
use App\Models\PropertyPhoto;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayCharge;
use App\Models\User;
use App\Support\Access;
use App\Support\Anonymisation;
use App\Support\Forgetting;
use App\Support\ResponsiveImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The data a marketplace needs — §16.5, §16 Phase 12.3.
 *
 * Nothing here is visible to a guest yet. What is tested is that the rows
 * already on the books come through the migration still live, that the
 * states which decide whether a host may sell cannot be set by a form,
 * and that a photograph's files go when the photograph does.
 */
class MarketplaceFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = __DIR__.'/../../database/migrations/2026_09_29_110000_prepare_stays_for_many_hosts.php';

    private function superAdmin(): User
    {
        return User::factory()->create()->assignRole(Access::SUPER_ADMIN);
    }

    // ── What the rows already here become ────────────────────────────────

    /**
     * Every partner and property before §16 was entered by staff after a
     * phone call, so it comes through verified, active and approved. Left
     * at the column defaults, every listing would drop off the site the
     * day Phase 13 starts reading them.
     *
     * Seeded in the old shape and migrated, because a fresh database runs
     * the backfill over nothing — the trap AGENTS.md records under "a
     * migration must never write a constant".
     */
    public function test_existing_partners_and_properties_come_through_live(): void
    {
        $this->artisan('migrate:rollback', ['--path' => self::MIGRATION, '--realpath' => true])->assertSuccessful();

        $now = now();
        $first = DB::table('partners')->insertGetId(['name' => 'Island Breeze', 'created_at' => $now, 'updated_at' => $now]);
        $second = DB::table('partners')->insertGetId(['name' => 'Island Breeze', 'created_at' => $now, 'updated_at' => $now]);
        $nameless = DB::table('partners')->insertGetId(['name' => '—', 'created_at' => $now, 'updated_at' => $now]);

        $guesthouse = DB::table('properties')->insertGetId([
            'partner_id' => $first, 'type' => 'guesthouse', 'slug' => 'breeze-maafushi',
            'name' => json_encode(['en' => 'Breeze Maafushi']), 'created_at' => $now, 'updated_at' => $now,
        ]);
        $rental = DB::table('properties')->insertGetId([
            'partner_id' => $second, 'type' => 'rental', 'slug' => 'breeze-male',
            'name' => json_encode(['en' => 'Breeze Malé']), 'created_at' => $now, 'updated_at' => $now,
        ]);

        // SQLite adds a foreign key by rebuilding the table, and the rebuild
        // turns foreign keys off with a pragma that is ignored inside a
        // transaction — which RefreshDatabase always has open. Outside a
        // test the migration runs against linked rows without complaint
        // (checked against a file database); here the check is deferred
        // to a commit that never comes, because the test rolls back.
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('PRAGMA defer_foreign_keys = ON');
        }

        $this->artisan('migrate', ['--path' => self::MIGRATION, '--realpath' => true])->assertSuccessful();

        try {
            $this->assertSame(
                ['island-breeze', 'island-breeze-2', 'host'],
                DB::table('partners')->whereIn('id', [$first, $second, $nameless])->orderBy('id')->pluck('slug')->all(),
            );

            foreach ([$first, $second, $nameless] as $id) {
                $partner = DB::table('partners')->find($id);
                $this->assertSame('verified', $partner->verification);
                $this->assertSame('active', $partner->status);
                $this->assertSame('commission_deposit', $partner->settlement_model);
            }

            $this->assertSame('approved', DB::table('properties')->find($guesthouse)->approval);
            $this->assertSame('approved', DB::table('properties')->find($rental)->approval);
            $this->assertSame('guesthouse', DB::table('properties')->find($guesthouse)->kind);
            // A Malé rental may be a room or a flat; guessing would be inventing.
            $this->assertNull(DB::table('properties')->find($rental)->kind);
        } finally {
            // DDL commits on MySQL, so these would outlive the test.
            DB::table('properties')->whereIn('id', [$guesthouse, $rental])->delete();
            DB::table('partners')->whereIn('id', [$first, $second, $nameless])->delete();
        }
    }

    // ── A new host ───────────────────────────────────────────────────────

    public function test_a_new_partner_starts_unchecked_with_a_slug_of_its_own(): void
    {
        $one = Partner::create(['name' => 'Coral Garden Inn']);
        $two = Partner::create(['name' => 'Coral Garden Inn']);

        $this->assertSame('coral-garden-inn', $one->slug);
        $this->assertSame('coral-garden-inn-2', $two->slug);
        $this->assertSame(Partner::UNVERIFIED, $one->verification);
        $this->assertSame(Partner::STATUS_PENDING, $one->status);
        $this->assertSame(Partner::COMMISSION_DEPOSIT, $one->settlement_model);
        $this->assertFalse($one->is_rihla);

        // The in-memory defaults match the columns.
        $fresh = $one->fresh();
        $this->assertSame(Partner::UNVERIFIED, $fresh->verification);
        $this->assertSame(Partner::STATUS_PENDING, $fresh->status);
    }

    /** A form that forgot to strip these would let a host verify themselves. */
    public function test_a_host_cannot_mass_assign_their_own_standing(): void
    {
        $partner = Partner::create([
            'name' => 'Self Approved',
            'verification' => Partner::VERIFIED,
            'status' => Partner::STATUS_ACTIVE,
            'settlement_model' => Partner::FULL_COLLECTION,
            'is_rihla' => true,
            'recommended_at' => now(),
        ])->fresh();

        $this->assertSame(Partner::UNVERIFIED, $partner->verification);
        $this->assertSame(Partner::STATUS_PENDING, $partner->status);
        $this->assertSame(Partner::COMMISSION_DEPOSIT, $partner->settlement_model);
        $this->assertFalse($partner->is_rihla);
        $this->assertNull($partner->recommended_at);

        $property = Property::factory()->create(['partner_id' => $partner->id]);
        $property->forceFill(['approval' => Property::DRAFT])->save();
        $property->fill(['approval' => Property::APPROVED, 'approved_at' => now()])->save();

        $this->assertSame(Property::DRAFT, $property->fresh()->approval);
        $this->assertNull($property->fresh()->approved_at);

        $stay = Stay::factory()->create();
        $stay->update(['commission_pct_snapshot' => 0, 'commission_minor' => 0, 'settlement_model_snapshot' => 'full_collection', 'checked_in_at' => now()]);
        $stay->refresh();

        $this->assertNull($stay->commission_pct_snapshot);
        $this->assertNull($stay->commission_minor);
        $this->assertNull($stay->settlement_model_snapshot);
        $this->assertNull($stay->checked_in_at);
    }

    /** Staff are the ones who check, so what they enter is checked. */
    public function test_a_partner_staff_enter_is_verified_and_active(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreatePartner::class)
            ->fillForm([
                'name' => 'Thulusdhoo Surf Lodge',
                'pricing_model' => Partner::NET_RATE,
                'green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $partner = Partner::where('name', 'Thulusdhoo Surf Lodge')->sole();

        $this->assertSame('thulusdhoo-surf-lodge', $partner->slug);
        $this->assertSame(Partner::VERIFIED, $partner->verification);
        $this->assertSame(Partner::STATUS_ACTIVE, $partner->status);
        $this->assertSame($admin->id, $partner->verified_by);
        $this->assertNotNull($partner->verified_at);
    }

    public function test_a_property_staff_enter_is_approved(): void
    {
        $admin = $this->superAdmin();

        Livewire::actingAs($admin)
            ->test(CreateProperty::class)
            ->fillForm([
                'partner_id' => Partner::factory()->create()->id,
                'slug' => 'staff-entered',
                'name' => ['en' => 'Staff Entered'],
                'summary' => ['en' => 'Rang the owner on Tuesday.'],
                'currency' => 'USD',
                'deposit_pct' => 30,
                'balance_days_before' => 14,
                'free_cancel_days' => 14,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $property = Property::where('slug', 'staff-entered')->sole();

        $this->assertSame(Property::APPROVED, $property->approval);
        $this->assertSame($admin->id, $property->approved_by);
    }

    /** Created outside the staff form — a host's, from Phase 14 — it waits. */
    public function test_any_other_new_property_starts_as_a_draft(): void
    {
        $property = Property::create([
            'partner_id' => Partner::factory()->create()->id,
            'name' => ['en' => 'Host Entered'],
        ]);

        $this->assertSame(Property::DRAFT, $property->approval);
        $this->assertSame(Property::DRAFT, $property->fresh()->approval);
    }

    // ── Photographs: both halves ─────────────────────────────────────────

    private function jpeg(string $path, int $width = 2000, int $height = 1000): void
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, (int) imagecolorallocate($image, 10, 87, 84));

        ob_start();
        imagejpeg($image, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        Storage::disk('public')->put($path, $bytes);
    }

    /** @return list<string> */
    private function filesFor(string $path): array
    {
        $all = [$path];

        foreach (ResponsiveImage::WIDTHS as $width) {
            $all[] = ResponsiveImage::variantPath($path, $width);
        }

        return array_values(array_filter($all, fn (string $file): bool => Storage::disk('public')->exists($file)));
    }

    public function test_a_first_photo_gets_its_variants(): void
    {
        Storage::fake('public');
        $this->jpeg('properties/photos/pool.jpg');

        PropertyPhoto::factory()->create(['path' => 'properties/photos/pool.jpg']);

        $this->assertCount(1 + count(ResponsiveImage::WIDTHS), $this->filesFor('properties/photos/pool.jpg'));
    }

    public function test_a_replaced_photo_takes_its_old_files_with_it(): void
    {
        Storage::fake('public');
        $this->jpeg('properties/photos/old.jpg');
        $this->jpeg('properties/photos/new.jpg');

        $photo = PropertyPhoto::factory()->create(['path' => 'properties/photos/old.jpg']);
        $photo->update(['path' => 'properties/photos/new.jpg']);

        $this->assertSame([], $this->filesFor('properties/photos/old.jpg'));
        $this->assertCount(1 + count(ResponsiveImage::WIDTHS), $this->filesFor('properties/photos/new.jpg'));
    }

    /** The cleanup keyed on the wrong condition would look exactly like this passing. */
    public function test_an_unrelated_edit_leaves_the_files_standing(): void
    {
        Storage::fake('public');
        $this->jpeg('properties/photos/keep.jpg');

        $photo = PropertyPhoto::factory()->create(['path' => 'properties/photos/keep.jpg']);
        $photo->update(['caption' => ['en' => 'Sunset from the jetty'], 'sort_order' => 4]);

        $this->assertCount(1 + count(ResponsiveImage::WIDTHS), $this->filesFor('properties/photos/keep.jpg'));
    }

    public function test_a_deleted_photo_leaves_nothing_on_disk(): void
    {
        Storage::fake('public');
        $this->jpeg('properties/photos/gone.jpg');

        PropertyPhoto::factory()->create(['path' => 'properties/photos/gone.jpg'])->delete();

        $this->assertSame([], $this->filesFor('properties/photos/gone.jpg'));
    }

    /**
     * The database cascades the rows and never fires a model event, so
     * without the hooks every photo of a deleted listing stays on disk.
     */
    public function test_deleting_a_listing_or_a_room_takes_its_photos_files(): void
    {
        Storage::fake('public');
        $this->jpeg('properties/photos/building.jpg');
        $this->jpeg('properties/photos/room.jpg');

        $property = Property::factory()->create();
        $room = RoomType::factory()->create(['property_id' => $property->id]);
        $roomPhoto = PropertyPhoto::factory()->create(['property_id' => $property->id, 'room_type_id' => $room->id, 'path' => 'properties/photos/room.jpg']);
        PropertyPhoto::factory()->create(['property_id' => $property->id, 'path' => 'properties/photos/building.jpg']);

        $room->delete();

        $this->assertSame([], $this->filesFor('properties/photos/room.jpg'));
        $this->assertModelMissing($roomPhoto);
        $this->assertNotSame([], $this->filesFor('properties/photos/building.jpg'));

        $property->delete();

        $this->assertSame([], $this->filesFor('properties/photos/building.jpg'));
        $this->assertSame(0, PropertyPhoto::count());
    }

    // ── Units, charges, payments ─────────────────────────────────────────

    public function test_a_unit_belongs_to_its_rooms_building_and_can_hold_a_stay(): void
    {
        $unit = PropertyUnit::factory()->create(['label' => 'Room 4']);
        $stay = Stay::factory()->create([
            'property_id' => $unit->property_id,
            'room_type_id' => $unit->room_type_id,
            'unit_id' => $unit->id,
        ]);

        $this->assertSame($unit->roomType->property_id, $unit->property_id);
        $this->assertSame(PropertyUnit::CLEAN, $unit->fresh()->housekeeping);
        $this->assertTrue($stay->unit->is($unit));

        // A unit taken out of service leaves the stay's history intact.
        $unit->delete();
        $this->assertNull($stay->fresh()->unit_id);
    }

    /** A stay made before §16 was asked for through the site. */
    public function test_a_stay_defaults_to_one_a_guest_made(): void
    {
        $stay = Stay::factory()->create();

        $this->assertSame(Stay::VIA_GUEST, $stay->created_via);
        $this->assertSame(Stay::VIA_GUEST, $stay->fresh()->created_via);
    }

    public function test_a_charge_adds_up_whatever_the_form_says(): void
    {
        $stay = Stay::factory()->create(['currency' => 'USD']);

        $transfer = StayCharge::create([
            'stay_id' => $stay->id, 'kind' => StayCharge::EXTRA, 'description' => 'Speedboat for two',
            'quantity' => 2, 'unit_minor' => 3500, 'total_minor' => 1, 'currency' => 'USD',
        ]);
        $discount = StayCharge::create([
            'stay_id' => $stay->id, 'kind' => StayCharge::DISCOUNT, 'description' => 'Returning guest',
            'unit_minor' => -1000, 'currency' => 'USD',
        ]);

        $this->assertSame(7000, $transfer->fresh()->total_minor);
        $this->assertSame(-1000, $discount->fresh()->total_minor);
        $this->assertSame(6000, (int) $stay->charges()->sum('total_minor'));
        $this->assertSame(['transfer' => 'Speedboat for two', 'discount' => 'Returning guest'], [
            'transfer' => $stay->charges[0]->description,
            'discount' => $stay->charges[1]->description,
        ]);
    }

    public function test_money_is_rihlas_unless_a_host_is_recorded_as_taking_it(): void
    {
        $payment = Payment::create([
            'payable_type' => Stay::class, 'payable_id' => Stay::factory()->create()->id,
            'method' => Payment::CASH, 'currency' => 'USD', 'amount_minor' => 5000,
            'collected_by' => Payment::COLLECTED_BY_HOST, 'partner_id' => Partner::factory()->create()->id,
        ])->fresh();

        $this->assertSame(Payment::COLLECTED_BY_RIHLA, $payment->collected_by);
        $this->assertNull($payment->partner_id);
    }

    // ── Privacy ──────────────────────────────────────────────────────────

    public function test_every_new_table_is_classified_in_both_lists(): void
    {
        $this->assertSame([], Anonymisation::unclassified(['property_photos', 'property_units', 'stay_charges']));
        $this->assertSame([], Forgetting::unreached());
        $this->assertSame([], Forgetting::missingFromSchema());

        foreach (['property_photos', 'property_units', 'stay_charges'] as $table) {
            $this->assertContains($table, Anonymisation::classified());
        }

        $this->assertSame('stay', Forgetting::REACHED['stay_charges']);
    }

    /** The slug is minted from the real name, so the scrub has to reach it. */
    public function test_anonymising_scrubs_the_slug_with_the_name(): void
    {
        $partner = Partner::factory()->create(['name' => 'Aishath Guest House']);
        StayCharge::factory()->create(['description' => 'Birthday cake for Aishath']);

        $this->artisan('data:anonymise', ['--force' => true])->assertSuccessful();

        $partner->refresh();
        $this->assertStringNotContainsString('aishath', $partner->slug);
        $this->assertSame('placeholder-'.$partner->id, $partner->slug);
        $this->assertStringNotContainsString('Aishath', (string) StayCharge::sole()->description);
    }

    public function test_forgetting_a_guest_reaches_their_stays_bill(): void
    {
        $customer = Customer::factory()->create();
        $stay = Stay::factory()->create(['customer_id' => $customer->id]);
        $charge = StayCharge::factory()->create(['stay_id' => $stay->id, 'description' => 'Cake for Mariyam']);
        $other = StayCharge::factory()->create(['description' => 'Cake for somebody else']);

        $this->artisan('data:forget', ['customer' => $customer->id])->assertSuccessful();

        $this->assertStringNotContainsString('Mariyam', (string) $charge->fresh()?->description);
        $this->assertSame('Cake for somebody else', $other->fresh()->description);
    }
}
