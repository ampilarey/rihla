<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `notice.*` and grant it.
 *
 * **No create verb, and none is ever added.** A notice is raised from a
 * record that already exists — a missing passport, an approaching
 * departure. The moment somebody can type one by hand, the portal starts
 * carrying claims nothing backs, which is the failure that put invented
 * social links on the live site.
 *
 * `handle` rather than `update`: the only thing staff do to a notice is say
 * they have dealt with it.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = ['notice.viewAny', 'notice.view', 'notice.handle'];

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
