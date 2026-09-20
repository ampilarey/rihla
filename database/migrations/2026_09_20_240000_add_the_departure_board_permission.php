<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `departure.board` and grant it.
 *
 * Its own permission rather than a fold into `departure.view`, because of
 * what the board aggregates: money outstanding across a departure's
 * bookings, and how many travellers are missing a passport, a visa or a
 * permit. Operations runs it, Booking Staff chase with it and Finance reads
 * the money line. Reporting is deliberately left out — it does not hold
 * payments at all, and the board's headline number is money.
 */
return new class extends Migration
{
    private const PERMISSION = 'departure.board';

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::findOrCreate(self::PERMISSION, 'web');

        foreach (Access::matrix() as $role => $permissions) {
            if (in_array(self::PERMISSION, $permissions, true)) {
                Role::where('name', $role)->first()?->givePermissionTo(self::PERMISSION);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
