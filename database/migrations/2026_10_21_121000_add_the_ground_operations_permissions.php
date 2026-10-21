<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the ground-operations permissions: buses and who sits on them
 * (`bus.*`), the printed lists a departure runs on (`list.view`), and the
 * customer cards and luggage tags with the page that scans them
 * (`card.create`, `card.scan`).
 *
 * A migration and not only the seeder, for the reason the others record:
 * the seeder runs on a fresh database, and the databases that matter
 * already exist.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'bus.viewAny', 'bus.view', 'bus.create', 'bus.update', 'bus.delete', 'bus.assign', 'bus.checkIn',
        'list.view',
        'card.create', 'card.scan',
    ];

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
