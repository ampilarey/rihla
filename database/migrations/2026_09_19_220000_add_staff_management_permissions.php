<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create the `user.*` permissions the staff screen authorises against.
 *
 * `RolesAndPermissionsSeeder` creates them too, but a seeder only governs the
 * next seed — deploys run `migrate --force`, not `db:seed`. The same gap left
 * placeholder media on the live site after its seeder was cleaned.
 *
 * No role is granted them. Super Admin reaches them through `Gate::before`,
 * and handing staff management to another role is a decision for whoever
 * needs it: a role that can grant roles can grant its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ($this->permissions() as $permission) {
            Permission::findOrCreate($permission, 'web');
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
        return array_values(array_filter(
            Access::PERMISSIONS,
            static fn (string $permission): bool => str_starts_with($permission, 'user.'),
        ));
    }
};
