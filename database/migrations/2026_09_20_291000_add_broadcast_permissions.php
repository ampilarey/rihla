<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `broadcast.*` and grant it.
 *
 * `send` is separate from `create`, and no role but Operations holds it. A
 * broadcast reaches every household on a departure at once and cannot be
 * unsent; a tour leader in the middle of an incident is the worst-placed
 * person to decide that forty families should hear about it.
 *
 * No delete verb. What was sent in an emergency is the record of what was
 * said, and a record that can be removed is not one.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'broadcast.viewAny', 'broadcast.view', 'broadcast.create',
        'broadcast.update', 'broadcast.send',
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
