<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `permit.*` and `departure.nusuk`, and grant them to the roles whose
 * job they are.
 *
 * `departure.nusuk` sits under the departure prefix but is deliberately kept
 * out of the content set: it records a dealing with a Saudi system, and the
 * Content Manager — who edits the website — has no business asserting one.
 * The content set is defined by inclusion for exactly this reason, after a
 * past incident where a new verb under an existing prefix was granted by
 * accident.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'permit.viewAny', 'permit.view', 'permit.create', 'permit.update',
        'departure.nusuk',
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
