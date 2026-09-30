<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Models\Module;
use Modules\Core\Services\ModuleService;
use Modules\Core\Services\ModuleTreeService;

/**
 * Permisos = qué acciones del catálogo están habilitadas en cada módulo.
 */
class PermissionController extends Controller
{
    public function __construct(
        private readonly ModuleService $modules,
        private readonly ModuleTreeService $tree,
    ) {}

    /**
     * Matriz módulos × acciones con el permission_id de cada combinación habilitada.
     */
    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->tree->matrix()]);
    }

    public function syncModuleActions(Request $request, Module $module): JsonResponse
    {
        $data = $request->validate([
            'action_ids' => ['present', 'array'],
            'action_ids.*' => ['integer', 'distinct', 'exists:actions,id'],
        ]);

        $this->modules->syncActions($module, $data['action_ids']);

        return response()->json([
            'message' => "Acciones del módulo {$module->name} actualizadas.",
            'data' => $this->tree->matrix(),
        ]);
    }
}
