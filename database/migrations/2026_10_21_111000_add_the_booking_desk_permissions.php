<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the booking desk's permissions: opening a booking from the panel
 * (`booking.create`, which until now deliberately did not exist — the
 * checkout was the only way in) and giving a discount (`booking.discount`,
 * a money decision, so Finance's and the Operations Manager's).
 *
 * A migration and not only the seeder, for the reason the others record:
 * the seeder runs on a fresh database, and the databases that matter
 * already exist.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['booking.create', 'booking.discount'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Access::matrix() as $role => $permissions) {
            Role::where('name', $role)->first()
                ?->givePermissionTo(array_intersect($permissions, self::PERMISSIONS));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
