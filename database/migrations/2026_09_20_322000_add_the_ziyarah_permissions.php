<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `ziyarah.*` — §7.2.
 *
 * Needed as a migration, not only in the seeder, because the seeder has
 * already run on every existing install: a permission that only appears on
 * a fresh database is one production never gets, and the screens would
 * deny everybody but Super Admin.
 *
 * `review` and `publish` stay separate for the reason §6.4 gives about the
 * Knowledge Centre, which applies unchanged here: a location page asserts
 * history, significance and etiquette, and a reviewer holding the publish
 * button performs the check on themselves.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'ziyarah.viewAny', 'ziyarah.view', 'ziyarah.create',
        'ziyarah.update', 'ziyarah.review', 'ziyarah.publish',
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
