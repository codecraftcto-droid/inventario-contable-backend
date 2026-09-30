<?php

namespace Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Services\ModuleTreeService;
use Modules\Security\Http\Requests\RoleRequest;
use Modules\Security\Services\RoleService;

class RoleController extends Controller
{
    public function __construct(
        private readonly RoleService $roles,
        private readonly ModuleTreeService $moduleTree,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $roles = Role::query()
            ->withCount(['permissions', 'users'])
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $roles->map(fn (Role $role): array => $this->transform($role))->values(),
        ]);
    }

    public function store(RoleRequest $request): JsonResponse
    {
        $role = $this->roles->create($request->validated());

        return response()->json([
            'message' => 'Rol creado correctamente.',
            'data' => $this->transform($role->loadCount(['permissions', 'users'])),
        ], 201);
    }

    public function show(Role $role): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$this->transform($role->loadCount(['permissions', 'users'])),
                'permission_ids' => $role->permissions()->pluck('permissions.id')->values()->all(),
            ],
        ]);
    }

    public function update(RoleRequest $request, Role $role): JsonResponse
    {
        $role = $this->roles->update($role, $request->validated());

        return response()->json([
            'message' => 'Rol actualizado correctamente.',
            'data' => $this->transform($role->loadCount(['permissions', 'users'])),
        ]);
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roles->delete($role);

        return response()->json(['message' => 'Rol eliminado correctamente.']);
    }

    /**
     * Matriz de permisos del rol: filas = módulos/submódulos, columnas = acciones.
     */
    public function permissions(Role $role): JsonResponse
    {
        return response()->json([
            'data' => [
                'role' => $this->transform($role->loadCount(['permissions', 'users'])),
                ...$this->moduleTree->matrix($role->permissions()->pluck('permissions.id')->all()),
            ],
        ]);
    }

    public function syncPermissions(Request $request, Role $role): JsonResponse
    {
        $data = $request->validate([
            'permission_ids' => ['present', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ]);

        $this->roles->syncPermissions($role, $data['permission_ids']);

        return response()->json([
            'message' => 'Permisos del rol actualizados correctamente.',
            'data' => $this->transform($role->loadCount(['permissions', 'users'])),
        ]);
    }

    private function transform(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'is_system' => $role->is_system,
            'is_active' => $role->is_active,
            'permissions_count' => $role->permissions_count,
            'users_count' => $role->users_count,
        ];
    }
}
