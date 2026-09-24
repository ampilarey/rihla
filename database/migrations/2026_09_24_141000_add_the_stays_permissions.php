<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `partner.*`, `property.*` and `roomType.*` — §15.4 (Phase 9.1).
 *
 * A migration and not only the seeder, for the reason the others record: the
 * seeder runs on a fresh database, and the databases that matter already
 * exist.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'partner.viewAny', 'partner.view', 'partner.create', 'partner.update',
        'property.viewAny', 'property.view', 'property.create', 'property.update', 'property.delete',
        'roomType.viewAny', 'roomType.view', 'roomType.create', 'roomType.update', 'roomType.delete',
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
