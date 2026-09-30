<?php

namespace Modules\Core\Http\Controllers;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Core\Http\Requests\ModuleRequest;
use Modules\Core\Models\Module;
use Modules\Core\Services\ModuleService;
use Modules\Core\Services\ModuleTreeService;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleService $modules,
        private readonly ModuleTreeService $tree,
    ) {}

    /**
     * Árbol completo de módulos (jerarquía sin límite de niveles).
     */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->tree->tree(),
            'meta' => ['platforms' => array_column(Platform::cases(), 'value')],
        ]);
    }

    public function store(ModuleRequest $request): JsonResponse
    {
        $module = $this->modules->create($request->validated());

        return response()->json([
            'message' => 'Módulo creado correctamente.',
            'data' => $module->fresh(),
        ], 201);
    }

    public function show(Module $module): JsonResponse
    {
        return response()->json([
            'data' => [
                ...$module->toArray(),
                'action_ids' => $module->permissions()->pluck('action_id')->values()->all(),
            ],
        ]);
    }

    public function update(ModuleRequest $request, Module $module): JsonResponse
    {
        $module = $this->modules->update($module, $request->validated());

        return response()->json([
            'message' => 'Módulo actualizado correctamente.',
            'data' => $module->fresh(),
        ]);
    }

    public function destroy(Module $module): JsonResponse
    {
        $this->modules->delete($module);

        return response()->json(['message' => 'Módulo eliminado correctamente.']);
    }
}
