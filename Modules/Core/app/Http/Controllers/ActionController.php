<?php

namespace Modules\Core\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Modules\Core\Http\Requests\ActionRequest;
use Modules\Core\Models\Action;

/**
 * Catálogo global de acciones (VER, CREAR, ... ESCANEAR, SINCRONIZAR).
 */
class ActionController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Action::query()->withCount('permissions')->orderBy('sort_order')->get(),
        ]);
    }

    public function store(ActionRequest $request): JsonResponse
    {
        return response()->json([
            'message' => 'Acción creada correctamente.',
            'data' => Action::create($request->validated()),
        ], 201);
    }

    public function update(ActionRequest $request, Action $action): JsonResponse
    {
        if ($action->code !== $request->validated('code') && $action->permissions()->exists()) {
            throw ValidationException::withMessages(['code' => 'No se puede cambiar el código de una acción que ya está en uso en permisos.']);
        }

        $action->update($request->validated());

        return response()->json([
            'message' => 'Acción actualizada correctamente.',
            'data' => $action->fresh(),
        ]);
    }

    public function destroy(Action $action): JsonResponse
    {
        if ($action->permissions()->exists()) {
            throw ValidationException::withMessages(['action' => 'La acción está habilitada en uno o más módulos. Quítala de ellos antes de eliminarla.']);
        }

        $action->delete();

        return response()->json(['message' => 'Acción eliminada correctamente.']);
    }
}
