<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `booking.*` and `customer.*` and grant them to the roles whose job
 * they are.
 *
 * Until now Booking Staff, Finance and Pilgrim Support held `admin.access`
 * and nothing else — three of the nine roles existed on paper with no work
 * to do. The public checkout writes bookings; this is what lets anybody at
 * Rihla see one.
 *
 * Content Manager is deliberately not on the list. Passport numbers and
 * phone numbers are not content, and nobody reaches them by being the person
 * who edits the website.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'booking.viewAny', 'booking.view', 'booking.update',
        'customer.viewAny', 'customer.view', 'customer.update',
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
