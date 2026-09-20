<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Create `quotation.*`, `task.*` and `customer.tag` — §8.1.
 *
 * A migration and not only the seeder, for the reason the others record:
 * the seeder has already run everywhere, so a permission that appears only
 * on a fresh database is one production never gets.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PERMISSIONS = [
        'quotation.viewAny', 'quotation.view', 'quotation.create',
        'quotation.update', 'quotation.send',
        'task.viewAny', 'task.view', 'task.create',
        'task.update', 'task.assign', 'task.delete',
        'customer.tag',
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
