<?php

namespace Modules\Core\Services;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Models\Action;
use Modules\Core\Models\Module;
use Spatie\Permission\PermissionRegistrar;

/**
 * Mantenimiento de módulos y de sus permisos (módulo + acción).
 */
class ModuleService
{
    public function create(array $data): Module
    {
        return DB::transaction(function () use ($data): Module {
            $module = Module::create(collect($data)->except('action_ids')->all());

            $this->syncActions($module, $data['action_ids'] ?? []);

            return $module;
        });
    }

    public function update(Module $module, array $data): Module
    {
        return DB::transaction(function () use ($module, $data): Module {
            if (isset($data['parent_id'])) {
                $this->ensureValidParent($module, (int) $data['parent_id']);
            }

            if ($module->is_locked && $data['code'] !== $module->code) {
                throw ValidationException::withMessages(['code' => 'No se puede cambiar el código de un módulo base del sistema.']);
            }

            if ($module->is_locked && array_key_exists('is_active', $data) && ! $data['is_active']) {
                throw ValidationException::withMessages(['is_active' => 'Un módulo base del sistema no se puede desactivar.']);
            }

            $oldCode = $module->code;
            $module->update(collect($data)->except('action_ids')->all());

            // El nombre del permiso depende del código del módulo (MODULO:ACCION).
            if ($oldCode !== $module->code) {
                $module->permissions()->with('action')->get()->each(
                    fn (Permission $permission) => $permission->update(['name' => Permission::nameFor($module->code, $permission->action->code)])
                );
            }

            if (array_key_exists('action_ids', $data)) {
                $this->syncActions($module, $data['action_ids']);
            }

            $this->flushCache();

            return $module;
        });
    }

    public function delete(Module $module): void
    {
        if ($module->is_locked) {
            throw ValidationException::withMessages(['module' => 'Este módulo es parte base del sistema y no se puede eliminar.']);
        }

        if ($module->children()->exists()) {
            throw ValidationException::withMessages(['module' => 'Primero elimina o mueve sus submódulos.']);
        }

        $module->delete();   // Sus permisos se eliminan en cascada (y con ellos, las asignaciones a roles)
        $this->flushCache();
    }

    /**
     * Deja habilitadas en el módulo exactamente las acciones indicadas: crea los
     * permisos que falten y elimina los que sobren.
     *
     * @param  int[]  $actionIds
     */
    public function syncActions(Module $module, array $actionIds): void
    {
        $actions = Action::query()->whereIn('id', $actionIds)->get();

        $module->permissions()->whereNotIn('action_id', $actions->pluck('id'))->get()->each->delete();

        $created = [];

        foreach ($actions as $action) {
            $permission = Permission::firstOrCreate(
                ['module_id' => $module->id, 'action_id' => $action->id, 'guard_name' => 'api'],
                ['name' => Permission::nameFor($module->code, $action->code)]
            );

            if ($permission->wasRecentlyCreated) {
                $created[] = $permission;
            }
        }

        // El rol ADMIN siempre tiene todos los permisos, también los que se crean después.
        if ($created !== []) {
            Role::query()->where('name', Role::ADMIN)->first()?->givePermissionTo($created);
        }

        $this->flushCache();
    }

    /**
     * Evita ciclos: el padre no puede ser el propio módulo ni uno de sus descendientes.
     */
    private function ensureValidParent(Module $module, int $parentId): void
    {
        $current = Module::find($parentId);

        while ($current) {
            if ($current->id === $module->id) {
                throw ValidationException::withMessages(['parent_id' => 'Un módulo no puede ser padre de sí mismo ni de uno de sus ancestros.']);
            }

            $current = $current->parent;
        }
    }

    private function flushCache(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
