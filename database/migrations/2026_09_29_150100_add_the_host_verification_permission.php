<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `partner.verify` — §16 Phase 14.1, the office checking a host.
 * A migration and not only the seeder, because the databases that matter
 * already exist.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['partner.verify'];

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
