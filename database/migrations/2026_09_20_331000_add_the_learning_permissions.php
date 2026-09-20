<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `learning.*` — §7.3.
 *
 * A migration and not only the seeder, for the reason the two before it
 * record: the seeder has already run on every existing install, so a
 * permission that appears only on a fresh database is one production never
 * gets, and the screens then deny everybody but Super Admin.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'learning.viewAny', 'learning.view', 'learning.create',
        'learning.update', 'learning.review', 'learning.publish',
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
