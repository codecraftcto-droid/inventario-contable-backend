<?php

namespace Modules\Core\Services;

use App\Enums\Platform;
use App\Models\Permission;
use App\Models\User;
use Illuminate\Support\Collection;
use Modules\Core\Models\Action;
use Modules\Core\Models\Module;

/**
 * Arma los árboles de módulos que consume el front:
 *  - tree():    mantenimiento de módulos (todos, con sus acciones habilitadas).
 *  - matrix():  matriz de permisos módulos × acciones (para roles y para "Permisos").
 *  - menuFor(): menú de un usuario filtrado por permisos y plataforma.
 */
class ModuleTreeService
{
    /**
     * Árbol completo para el mantenimiento de módulos.
     */
    public function tree(): array
    {
        $modules = Module::query()->orderBy('sort_order')->orderBy('name')->get();
        $actionsByModule = $this->actionCodesByModule(Permission::query()->whereNotNull('module_id')->get());

        return $this->buildLevel($modules, null, fn (Module $module): array => [
            'id' => $module->id,
            'parent_id' => $module->parent_id,
            'code' => $module->code,
            'name' => $module->name,
            'description' => $module->description,
            'icon' => $module->icon,
            'path' => $module->path,
            'sort_order' => $module->sort_order,
            'platform' => $module->platform->value,
            'is_menu' => $module->is_menu,
            'is_active' => $module->is_active,
            'is_locked' => $module->is_locked,
            'actions' => $actionsByModule[$module->id] ?? [],
        ]);
    }

    /**
     * Matriz de permisos: por cada módulo, las acciones habilitadas con su permission_id
     * y si están concedidas (según $grantedPermissionIds; null = no se marca nada).
     *
     * @param  int[]|null  $grantedPermissionIds
     * @return array{actions: array, modules: array}
     */
    public function matrix(?array $grantedPermissionIds = null): array
    {
        $actions = Action::query()->where('is_active', true)->orderBy('sort_order')->get();
        $actionCodes = $actions->pluck('code', 'id');
        $permissionsByModule = Permission::query()->whereNotNull('module_id')->get()->groupBy('module_id');
        $granted = array_flip($grantedPermissionIds ?? []);

        $modules = Module::query()->orderBy('sort_order')->orderBy('name')->get();

        return [
            'actions' => $actions->map(fn (Action $action): array => [
                'id' => $action->id,
                'code' => $action->code,
                'name' => $action->name,
                'icon' => $action->icon,
            ])->values()->all(),
            'modules' => $this->buildLevel($modules, null, function (Module $module) use ($permissionsByModule, $actionCodes, $granted): array {
                $cells = [];

                foreach ($permissionsByModule->get($module->id, collect()) as $permission) {
                    $code = $actionCodes[$permission->action_id] ?? null;

                    if ($code !== null) {
                        $cells[$code] = [
                            'permission_id' => $permission->id,
                            'granted' => isset($granted[$permission->id]),
                        ];
                    }
                }

                return [
                    'id' => $module->id,
                    'code' => $module->code,
                    'name' => $module->name,
                    'icon' => $module->icon,
                    'platform' => $module->platform->value,
                    'is_menu' => $module->is_menu,
                    'is_active' => $module->is_active,
                    'permissions' => (object) $cells,
                ];
            }),
        ];
    }

    /**
     * Menú del usuario para una plataforma. Un módulo aparece si el usuario tiene al
     * menos un permiso en él y su plataforma es la del cliente o AMBOS. Sus módulos
     * padre se incluyen siempre (como contenedores) aunque el permiso esté solo en el hijo.
     * Las opciones (is_menu = false) no se muestran: sus permisos habilitan botones
     * dentro de la pantalla del módulo padre, que sí aparece.
     */
    public function menuFor(User $user, Platform $platform): array
    {
        $modules = Module::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()->keyBy('id');
        $actionsByModule = $this->actionCodesByModule($user->getEffectivePermissions());
        $visiblePlatforms = Platform::visibleFor($platform);

        $includedIds = [];

        foreach ($actionsByModule as $moduleId => $codes) {
            $module = $modules->get($moduleId);

            if (! $module || ! in_array($module->platform->value, $visiblePlatforms, true)) {
                continue;
            }

            // Sube por la jerarquía; si algún ancestro está inactivo, la rama no se muestra.
            $chain = [];
            $current = $module;

            while ($current) {
                $chain[] = $current->id;

                if ($current->parent_id === null) {
                    array_push($includedIds, ...$chain);
                    break;
                }

                $current = $modules->get($current->parent_id);
            }
        }

        $included = $modules->only(array_unique($includedIds))
            ->filter(fn (Module $module): bool => $module->is_menu)
            ->values();

        return $this->buildLevel($included, null, fn (Module $module): array => [
            'id' => $module->id,
            'code' => $module->code,
            'name' => $module->name,
            'icon' => $module->icon,
            'path' => $module->path,
            'platform' => $module->platform->value,
            'actions' => $actionsByModule[$module->id] ?? [],
        ]);
    }

    /**
     * @param  Collection<int, Permission>  $permissions
     * @return array<int, string[]> module_id => códigos de acción, en el orden del catálogo
     */
    private function actionCodesByModule(Collection $permissions): array
    {
        $actions = Action::query()->orderBy('sort_order')->get(['id', 'code']);
        $order = $actions->pluck('id')->flip();
        $codes = $actions->pluck('code', 'id');

        return $permissions
            ->filter(fn (Permission $permission): bool => $permission->module_id !== null && isset($codes[$permission->action_id]))
            ->sortBy(fn (Permission $permission) => $order[$permission->action_id])
            ->groupBy('module_id')
            ->map(fn (Collection $group): array => $group->map(fn (Permission $p): string => $codes[$p->action_id])->values()->all())
            ->all();
    }

    /**
     * @param  Collection<int, Module>  $modules
     * @param  callable(Module): array  $transform
     */
    private function buildLevel(Collection $modules, ?int $parentId, callable $transform): array
    {
        return $modules
            ->where('parent_id', $parentId)
            ->map(fn (Module $module): array => [
                ...$transform($module),
                'children' => $this->buildLevel($modules, $module->id, $transform),
            ])
            ->values()
            ->all();
    }
}
