<?php

namespace Modules\Transport\Tests;

use App\Exceptions\DeskRefusal;
use App\Models\Island;
use App\Models\Partner;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Transport\Models\BoatBooking;
use Modules\Transport\Models\BoatRoute;
use Modules\Transport\Models\Sailing;
use Modules\Transport\Models\Vessel;
use Modules\Transport\Services\TransportDesk;
use Tests\TestCase;

/**
 * The boat seat counter is guarded by a row lock and nothing else —
 * docs/TRANSPORT_PLAN.md. As AGENTS.md records for stays, a lock test must
 * assert *which* statement blocked: a booking stopped by an incidental
 * foreign-key lock on a later write would pass a naive test with
 * `lockForUpdate()` deleted. So the failure must be the `select … for
 * update` on `sailings`.
 *
 * No RefreshDatabase: two connections must see committed rows, so this
 * file makes its fixture for real and removes it afterwards.
 */
class TransportLockTest extends TestCase
{
    private const LOCK_TIMEOUT_SECONDS = 1;

    private ?Sailing $sailing = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('Row locking needs MySQL or MariaDB; this runs in the MySQL job in CI.');
        }

        if (! Schema::hasTable('sailings')) {
            $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        }

        config(['database.connections.second' => config('database.connections.'.config('database.default'))]);
        DB::purge('second');
    }

    protected function tearDown(): void
    {
        foreach (['second', null] as $name) {
            try {
                $connection = DB::connection($name);
                while ($connection->transactionLevel() > 0) {
                    try {
                        $connection->rollBack();
                    } catch (\Throwable) {
                        break;
                    }
                }
                DB::disconnect($name);
            } catch (\Throwable) {
                // Never opened.
            }
        }

        if ($this->sailing !== null) {
            $routeId = $this->sailing->boat_route_id;
            $vesselId = $this->sailing->vessel_id;
            DB::table('boat_tickets')->whereIn('boat_booking_id', DB::table('boat_bookings')->where('sailing_id', $this->sailing->id)->pluck('id'))->delete();
            DB::table('boat_bookings')->where('sailing_id', $this->sailing->id)->delete();
            DB::table('sailings')->where('id', $this->sailing->id)->delete();
            DB::table('boat_routes')->where('id', $routeId)->delete();
            DB::table('vessels')->where('id', $vesselId)->delete();
        }

        parent::tearDown();
    }

    private function impatient(ConnectionInterface $connection): ConnectionInterface
    {
        $connection->unprepared('SET SESSION innodb_lock_wait_timeout = '.self::LOCK_TIMEOUT_SECONDS);

        return $connection;
    }

    private function lastSeat(): Sailing
    {
        $rihla = Partner::rihla();
        $this->assertNotNull($rihla);
        [$from, $to] = Island::query()->orderBy('id')->limit(2)->get()->all();
        $boat = Vessel::create(['partner_id' => $rihla->id, 'name' => 'Lock test', 'kind' => Vessel::SPEEDBOAT, 'seats' => 1]);
        $route = BoatRoute::create(['partner_id' => $rihla->id, 'origin_island_id' => $from->id, 'destination_island_id' => $to->id, 'duration_minutes' => 60, 'adult_fare_minor' => 100]);

        return $this->sailing = Sailing::create(['boat_route_id' => $route->id, 'vessel_id' => $boat->id, 'departs_at' => now()->addDays(3), 'seats_total' => 1]);
    }

    public function test_booking_takes_the_sailing_row_lock_before_anything_else(): void
    {
        $sailing = $this->lastSeat();

        // Somebody else is mid-booking on this sailing.
        $second = DB::connection('second');
        $second->beginTransaction();
        $second->table('sailings')->where('id', $sailing->id)->lockForUpdate()->first();

        $this->impatient(DB::connection());
        $failedOn = null;

        try {
            app(TransportDesk::class)->book($sailing, [['full_name' => 'Aminath']], 'Aminath', '7771234');
        } catch (QueryException $refusal) {
            $failedOn = strtolower((string) $refusal->getSql());
        }

        $this->assertNotNull($failedOn, 'A seat was taken while another session held the sailing row.');
        $this->assertStringContainsString('sailings', $failedOn, "Blocked on the wrong statement: {$failedOn}");
        $this->assertStringContainsString('for update', $failedOn, "The statement that blocked was not a row lock: {$failedOn}");
        $this->assertSame(0, BoatBooking::query()->where('sailing_id', $sailing->id)->count());
    }

    public function test_once_the_other_session_lets_go_the_last_seat_is_sold_once(): void
    {
        $sailing = $this->lastSeat();

        app(TransportDesk::class)->book($sailing, [['full_name' => 'First']], 'First', '1');

        $this->expectException(DeskRefusal::class);
        app(TransportDesk::class)->book($sailing->fresh(), [['full_name' => 'Second']], 'Second', '2');
    }
}
