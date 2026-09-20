<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `enquiry.*` and grant it to the roles that answer the phone.
 *
 * `enquiry.assign` is held only by Operations Manager: at this size "who
 * owns this" is a supervisor's call, and an enquiry everybody can reassign
 * is an enquiry nobody owns.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'enquiry.viewAny', 'enquiry.view', 'enquiry.create', 'enquiry.update', 'enquiry.assign',
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
