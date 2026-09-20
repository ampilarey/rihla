<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `person.*` and grant it to the roles that hold the rest of content.
 *
 * A seeder only governs the next seed; deploys run `migrate --force`. Without
 * the grant below the permissions exist and nobody holds them, and the screen
 * is invisible to everyone but Super Admin.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'person.viewAny', 'person.view', 'person.create', 'person.update', 'person.delete',
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
