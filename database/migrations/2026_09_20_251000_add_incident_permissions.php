<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `incident.*` and grant it.
 *
 * No delete verb, deliberately, and none is ever added: an incident report
 * that can be removed is evidence that can be removed. The same reasoning
 * that kept `delete` off visa applications and Nusuk permits.
 *
 * The Tour Leader can *create* one. They are the person standing there when
 * it happens, and an incident that has to wait for the office to be open is
 * an incident that is recorded from memory two days later, if at all.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'incident.viewAny', 'incident.view', 'incident.create',
        'incident.update', 'incident.assign', 'incident.resolve',
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
