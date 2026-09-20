<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `payment.*` and grant it to the roles whose job it is.
 *
 * `payment.reconcile` is deliberately held by Finance alone. Booking Staff
 * can record that a customer says the money is sent; deciding that it has
 * actually arrived is a separate act by a separate person, and collapsing
 * the two into one permission is how an unchecked slip becomes a confirmed
 * booking and a seat nobody paid for.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'payment.viewAny', 'payment.view', 'payment.create', 'payment.update',
        'payment.download', 'payment.reconcile', 'payment.refund',
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
