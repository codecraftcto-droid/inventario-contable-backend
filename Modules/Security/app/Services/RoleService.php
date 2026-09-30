<?php

namespace Modules\Security\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Module;
use Spatie\Permission\PermissionRegistrar;

class RoleService
{
    public function create(array $data): Role
    {
        return DB::transaction(function () use ($data): Role {
            $role = Role::create([
                'name' => $data['name'],
                'guard_name' => 'api',
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_system' => false,
            ]);

            $this->syncPermissions($role, $data['permission_ids'] ?? []);

            return $role;
        });
    }

    public function update(Role $role, array $data): Role
    {
        return DB::transaction(function () use ($role, $data): Role {
            if ($role->is_system && $data['name'] !== $role->name) {
                throw ValidationException::withMessages(['name' => 'No se puede cambiar el nombre de un rol de sistema.']);
            }

            if ($role->is_system && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'Un rol de sistema no se puede desactivar.']);
            }

            $role->update(collect($data)->only(['name', 'description', 'is_active'])->all());

            if (array_key_exists('permission_ids', $data)) {
                $this->syncPermissions($role, $data['permission_ids']);
            }

            $this->flushCache();

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        if ($role->is_system) {
            throw ValidationException::withMessages(['role' => 'Los roles de sistema no se pueden eliminar.']);
        }

        $usersCount = $role->users()->count();

        if ($usersCount > 0) {
            throw ValidationException::withMessages(['role' => "El rol tiene {$usersCount} usuario(s) asignado(s). Quítaselo antes de eliminarlo."]);
        }

        $role->delete();
        $this->flushCache();
    }

    /**
     * @param  int[]  $permissionIds
     */
    public function syncPermissions(Role $role, array $permissionIds): void
    {
        if ($role->name === Role::ADMIN) {
            // ADMIN siempre conserva todos los permisos.
            $permissionIds = Permission::where('guard_name', 'api')->pluck('id')->all();
        }

        $this->ensureAllowedForCompanyUsers($role, $permissionIds);

        $role->syncPermissions(Permission::whereIn('id', $permissionIds)->get());
        $this->flushCache();
    }

    /**
     * Si el rol ya está asignado a usuarios de empresas, no puede recibir permisos de
     * módulos de administración (config security.internal_only_modules).
     *
     * @param  int[]  $permissionIds
     */
    private function ensureAllowedForCompanyUsers(Role $role, array $permissionIds): void
    {
        if ($permissionIds === [] || ! $role->users()->whereNotNull('company_id')->exists()) {
            return;
        }

        $forbidden = Permission::query()
            ->whereIn('id', $permissionIds)
            ->whereIn('module_id', Module::internalOnlyIds())
            ->pluck('name');

        if ($forbidden->isNotEmpty()) {
            throw ValidationException::withMessages([
                'permission_ids' => "El rol está asignado a usuarios de empresas, que no pueden tener permisos de administración: {$forbidden->implode(', ')}.",
            ]);
        }
    }

    private function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
