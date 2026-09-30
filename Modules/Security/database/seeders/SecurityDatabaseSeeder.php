<?php

namespace Modules\Security\Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

class SecurityDatabaseSeeder extends Seeder
{
    /**
     * Roles iniciales. "*" = todos los permisos; "MODULO:*" = todas las acciones del módulo.
     */
    private const ROLES = [
        Role::ADMIN => [
            'description' => 'Administrador del sistema (acceso total).',
            'is_system' => true,
            'permissions' => ['*'],
        ],
        'CONTABLE' => [
            'description' => 'Gestión completa de inventarios (carga contable, tomas, cierre).',
            'is_system' => false,
            'permissions' => ['DASH:VER', 'EMP:VER', 'EMP_SEDE:VER', 'INV:*', 'INV_ASIG:*', 'INV_POCK:*', 'INV_BASE:*', 'INV_TOMA:*'],
        ],
        'INVENTARIADOR' => [
            'description' => 'Toma de inventario en campo (web y app móvil).',
            'is_system' => false,
            'permissions' => ['DASH:VER', 'INV:VER', 'INV_TOMA:VER', 'INV_TOMA:ESCANEAR', 'INV_TOMA:SINCRONIZAR'],
        ],
        'CLIENTE' => [
            'description' => 'Para usuarios de empresas: consulta y exporta los inventarios de su empresa.',
            'is_system' => false,
            'permissions' => ['DASH:VER', 'INV:VER', 'INV:EXPORTAR', 'INV_BASE:VER', 'INV_TOMA:VER'],
        ],
    ];

    public function run(): void
    {
        $guard = config('auth.defaults.guard');
        $allPermissions = Permission::where('guard_name', $guard)->get();

        foreach (self::ROLES as $name => $definition) {
            $role = Role::updateOrCreate(
                ['name' => $name, 'guard_name' => $guard],
                ['description' => $definition['description'], 'is_system' => $definition['is_system'], 'is_active' => true]
            );

            $role->syncPermissions($this->resolvePermissions($allPermissions, $definition['permissions']));
        }

        $admin = User::updateOrCreate(
            ['username' => env('ADMIN_USERNAME', 'admin')],
            [
                'email' => mb_strtolower(env('ADMIN_EMAIL', 'admin@inventario.local')),
                'document_number' => env('ADMIN_DOCUMENT_NUMBER', '00000000'),
                'password' => env('ADMIN_PASSWORD', 'ChangeMe123!'),
                'first_name' => env('ADMIN_FIRST_NAME', 'Administrador'),
                'last_name' => env('ADMIN_LAST_NAME', 'del Sistema'),
                'is_active' => true,
                'is_root_admin' => true,
                'must_change_password' => true,
            ]
        );

        $admin->syncRoles([Role::ADMIN]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function resolvePermissions($allPermissions, array $patterns)
    {
        if (in_array('*', $patterns, true)) {
            return $allPermissions;
        }

        return $allPermissions->filter(function (Permission $permission) use ($patterns): bool {
            foreach ($patterns as $pattern) {
                if ($pattern === $permission->name
                    || (str_ends_with($pattern, ':*') && str_starts_with($permission->name, substr($pattern, 0, -1)))) {
                    return true;
                }
            }

            return false;
        })->values();
    }
}
