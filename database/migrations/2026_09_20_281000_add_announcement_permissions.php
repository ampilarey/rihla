<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `announcement.*` and grant it.
 *
 * `publish` is its own verb. Writing an announcement and putting it in
 * front of forty families are different acts, and the second is the one
 * that cannot be taken back — so a tour leader drafts and the office
 * decides it goes out.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'announcement.viewAny', 'announcement.view', 'announcement.create',
        'announcement.update', 'announcement.publish', 'announcement.delete',
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
