<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the `package.*` and `departure.*` permissions, and grant them to the
 * roles that already hold the rest of the content set.
 *
 * `RolesAndPermissionsSeeder` creates them too, but a seeder only governs the
 * next seed — deploys run `migrate --force`, not `db:seed`.
 *
 * Granted to Content Manager and Operations Manager, and read-only to
 * Reporting, because a package description is public-facing copy edited by
 * whoever edits the rest of the site. That follows from naming the two
 * prefixes in Access::matrix()'s content list; this migration applies the
 * same thing to roles that already exist in the database.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PREFIXES = ['package', 'departure'];

    /** @var list<string> */
    private const ACTIONS = ['viewAny', 'view', 'create', 'update', 'delete'];

    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Re-apply the matrix so roles created by an earlier seed pick these
        // up. Without this the permissions exist and nobody holds them, and
        // the screens are invisible to everyone but Super Admin.
        foreach (Access::matrix() as $role => $permissions) {
            $model = Role::where('name', $role)->first();

            $model?->givePermissionTo(array_intersect($permissions, $this->permissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Permission::whereIn('name', $this->permissions())->where('guard_name', 'web')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** @return list<string> */
    private function permissions(): array
    {
        $permissions = [];

        foreach (self::PREFIXES as $prefix) {
            foreach (self::ACTIONS as $action) {
                $permissions[] = $prefix.'.'.$action;
            }
        }

        return $permissions;
    }
};
