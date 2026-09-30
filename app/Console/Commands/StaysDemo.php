<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\HostMembership;
use App\Models\HostPage;
use App\Models\Package;
use App\Models\Partner;
use App\Models\Property;
use App\Models\PropertyAddon;
use App\Models\PropertyUnit;
use App\Models\RoomType;
use App\Models\Stay;
use App\Models\StayDiscount;
use App\Models\StayMessage;
use App\Models\User;
use App\Services\Hosts\Statements;
use App\Services\Stays\Commission;
use App\Services\Stays\Reviews;
use App\Services\Stays\StayDesk;
use App\Services\Stays\StayMessages;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A demo host to click through on the test server — §16.
 *
 * One guesthouse with two room types and three rooms, a published page,
 * add-ons and a long-stay discount; a finished stay with a review, a guest
 * in the house today, a confirmed arrival and a request waiting for an
 * answer; last month's statement; a package sent to Rihla. And an owner
 * login, printed once.
 *
 * Built through the application's own services wherever one exists — the
 * check-in desk, reviews, messages, statements — so the demo looks like real
 * use rather than like rows. No factories: the test server installs without
 * dev dependencies.
 *
 * **Refuses in production.** It invents guests and bookings. Running it
 * again leaves the data as it is and issues a new password.
 *
 * It switches nothing on. The host panel works regardless; the public Stays
 * pages show the demo only once the Stays switch is on.
 */
class StaysDemo extends Command
{
    public const SLUG = 'demo-coral-garden';

    public const EMAIL = 'demo-host@example.invalid';

    protected $signature = 'stays:demo';

    protected $description = 'Create a demo host on a non-production server and print its login';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Refused: this invents guests and bookings, and this is production.');

            return self::FAILURE;
        }

        $password = Str::password(16, symbols: false);

        $user = DB::transaction(function () use ($password): User {
            $host = Partner::where('slug', self::SLUG)->first() ?? $this->build();

            $user = User::firstOrNew(['email' => self::EMAIL]);
            $user->forceFill(['name' => 'Demo host', 'password' => Hash::make($password), 'email_verified_at' => now()])->save();

            HostMembership::firstOrCreate(
                ['partner_id' => $host->getKey(), 'user_id' => $user->getKey()],
                ['role' => 'owner', 'accepted_at' => now()],
            );

            return $user;
        });

        $this->info('Demo host ready: Coral Garden Inn (demo).');
        $this->line('Sign in at '.url('/host/login'));
        $this->line('Email:    '.$user->email);
        $this->line('Password: '.$password);
        $this->line('Shown once. Run this command again for a new one.');

        return self::SUCCESS;
    }

    private function build(): Partner
    {
        $host = Partner::create([
            'name' => 'Coral Garden Inn (demo)',
            'slug' => self::SLUG,
            'island' => 'Fulidhoo',
            'contact_name' => 'Demo host',
            'phone' => '3000000',
            'email' => self::EMAIL,
            'pricing_model' => Partner::COMMISSION,
            'commission_pct' => 15,
            'green_tax_mode' => Partner::GREEN_TAX_AT_PROPERTY,
            'is_active' => true,
        ]);
        $host->forceFill([
            'verification' => Partner::VERIFIED,
            'status' => Partner::STATUS_ACTIVE,
            'verified_at' => now(),
            'payout_bank_name' => 'Demo Bank',
            'payout_account_name' => 'Coral Garden Inn (demo)',
            'payout_account_number' => '0000000000',
        ])->save();

        $property = Property::create([
            'partner_id' => $host->getKey(),
            'type' => Property::GUESTHOUSE,
            'kind' => Property::KIND_GUESTHOUSE,
            'slug' => self::SLUG.'-house',
            'island' => 'Fulidhoo',
            'atoll' => 'Vaavu',
            'name' => ['en' => 'Coral Garden (demo)'],
            'summary' => ['en' => 'A family guesthouse behind the harbour, a short walk from the bikini beach.'],
            'description' => ['en' => "Three rooms around a shaded garden.\n\nThis is demo content for trying the host panel."],
            'house_rules' => ['en' => 'Modest dress in the village. No alcohol on the island.'],
            'check_in_instructions' => ['en' => 'Call from the ferry and we will meet you at the jetty.'],
            'amenities' => ['en' => ['Air conditioning', 'Wi-Fi', 'Breakfast available']],
            'check_in_time' => '14:00',
            'check_out_time' => '11:00',
            'min_nights' => 1,
            'currency' => 'USD',
            'is_published' => true,
        ]);
        $property->forceFill(['approval' => Property::APPROVED])->save();

        $garden = RoomType::create([
            'property_id' => $property->getKey(), 'name' => ['en' => 'Garden Double'], 'sleeps' => 2, 'beds' => '1 double',
            'quantity' => 2, 'base_rate_minor' => 8500, 'local_rate_minor' => 90000,
        ]);
        $sea = RoomType::create([
            'property_id' => $property->getKey(), 'name' => ['en' => 'Sea View Room'], 'sleeps' => 3, 'beds' => '1 double + 1 single',
            'quantity' => 1, 'base_rate_minor' => 12000,
        ]);

        $room1 = PropertyUnit::create(['property_id' => $property->getKey(), 'room_type_id' => $garden->getKey(), 'label' => 'Room 1', 'housekeeping' => PropertyUnit::CLEAN, 'is_active' => true]);
        PropertyUnit::create(['property_id' => $property->getKey(), 'room_type_id' => $garden->getKey(), 'label' => 'Room 2', 'housekeeping' => PropertyUnit::CLEAN, 'is_active' => true]);
        PropertyUnit::create(['property_id' => $property->getKey(), 'room_type_id' => $sea->getKey(), 'label' => 'Room 3', 'housekeeping' => PropertyUnit::CLEAN, 'is_active' => true]);

        $page = new HostPage([
            'layout' => HostPage::STORY,
            'font' => 'inter',
            'tagline' => ['en' => 'Two minutes from the harbour.'],
            'about' => ['en' => 'Our family has welcomed guests to Fulidhoo since 2016. (Demo content.)'],
            'sections' => array_keys(HostPage::SECTIONS),
        ]);
        $page->partner_id = $host->getKey();
        $page->save();
        $page->forceFill(['published_at' => now()])->save();

        PropertyAddon::create(['property_id' => $property->getKey(), 'name' => ['en' => 'Speedboat transfer'], 'pricing' => PropertyAddon::PER_STAY, 'price_minor' => 5000]);
        PropertyAddon::create(['property_id' => $property->getKey(), 'name' => ['en' => 'Island breakfast'], 'pricing' => PropertyAddon::PER_PERSON, 'price_minor' => 1200, 'local_price_minor' => 15000]);
        StayDiscount::create(['property_id' => $property->getKey(), 'name' => 'Week-long stay', 'kind' => StayDiscount::LONG_STAY, 'percent' => 10, 'min_nights' => 7]);

        $today = CarbonImmutable::today();
        $lastMonth = $today->subMonthNoOverflow()->startOfMonth();

        // A finished marketplace stay last month, with a review.
        $done = $this->stay($property, $garden, 'Aisha (demo guest)', $lastMonth->addDays(9), 3, Stay::COMPLETED, Commission::MARKETPLACE);
        $done->forceFill(['unit_id' => $room1->getKey(), 'checked_in_at' => $done->check_in, 'checked_out_at' => $done->check_out])->save();
        $review = app(Reviews::class)->submit($done, [
            'rating' => 5, 'cleanliness' => 5, 'communication' => 5,
            'body' => 'Lovely family, spotless room, and they met us at the ferry. (Demo review.)', 'locale' => 'en',
        ]);
        $review->forceFill(['published_at' => now()])->save();

        // A guest in the house today, booked on the phone.
        $here = $this->stay($property, $garden, 'Ibrahim (demo guest)', $today->subDay(), 3, Stay::CONFIRMED, 'phone');
        app(StayDesk::class)->checkIn($here, [
            ['full_name' => 'Ibrahim (demo guest)', 'nationality' => 'Maldivian', 'id_type' => 'national_id', 'id_number' => 'A000000', 'is_lead' => true],
        ], $room1);

        // A confirmed arrival next week, who has written to the host.
        $arriving = $this->stay($property, $sea, 'Sara (demo guest)', $today->addDays(7), 4, Stay::CONFIRMED, Commission::MARKETPLACE);
        app(StayMessages::class)->post($arriving, StayMessage::GUEST, 'Hello! We land at 3pm — could you book the 4pm speedboat for us? (Demo message.)');

        // A request waiting for the host's answer.
        $this->stay($property, $garden, 'Lena (demo guest)', $today->addMonth(), 2, Stay::REQUESTED, Commission::MARKETPLACE);

        app(Statements::class)->issue($host, $lastMonth);

        $package = Package::create([
            'slug' => self::SLUG.'-weekend',
            'type' => Package::ISLAND_HOLIDAY,
            'property_id' => $property->getKey(),
            'title' => ['en' => 'A Fulidhoo weekend (demo)'],
            'summary' => ['en' => 'Two nights, the speedboat both ways and a sandbank picnic.'],
            'nights' => 2,
            'sold_to' => 'both',
            'is_published' => false,
        ]);
        $package->forceFill(['partner_id' => $host->getKey(), 'submitted_at' => now()])->save();

        return $host;
    }

    private function stay(Property $property, RoomType $room, string $guest, CarbonImmutable $checkIn, int $nights, string $status, string $source): Stay
    {
        $customer = Customer::create(['name' => $guest, 'email' => Str::slug($guest).'@example.invalid', 'phone' => '3000001']);
        $nightly = [];

        for ($night = 0; $night < $nights; $night++) {
            $nightly[$checkIn->addDays($night)->toDateString()] = (int) $room->base_rate_minor;
        }

        $total = array_sum($nightly);
        $commission = $source === Commission::MARKETPLACE ? intdiv($total * 15, 100) : 0;

        $stay = new Stay;
        $stay->forceFill([
            'customer_id' => $customer->getKey(),
            'property_id' => $property->getKey(),
            'room_type_id' => $room->getKey(),
            'audience' => 'tourist',
            'check_in' => $checkIn->toDateString(),
            'check_out' => $checkIn->addDays($nights)->toDateString(),
            'nights' => $nights,
            'adults' => 2,
            'children' => 0,
            'currency' => 'USD',
            'rate_snapshot' => ['currency' => 'USD', 'audience' => 'tourist', 'nightly' => $nightly, 'total_minor' => $total, 'minimum_nights' => 1],
            'total_minor' => $total,
            'deposit_minor' => $commission,
            'status' => $status,
            'requested_at' => now(),
            'partner_confirmed_at' => $status === Stay::REQUESTED ? null : now(),
            'confirmed_at' => $status === Stay::REQUESTED ? null : now(),
            'source' => $source,
            'created_via' => $source === Commission::MARKETPLACE ? Stay::VIA_GUEST : Stay::VIA_HOST,
            'commission_pct_snapshot' => $source === Commission::MARKETPLACE ? 15 : 0,
            'commission_minor' => $commission,
            'host_net_minor' => $total - $commission,
            'settlement_model_snapshot' => Partner::COMMISSION_DEPOSIT,
        ])->save();

        return $stay;
    }
}
