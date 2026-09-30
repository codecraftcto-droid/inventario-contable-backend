<?php

namespace Modules\Inventories\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Inventories\Models\Inventory;

/**
 * Opción "Asignar inventariadores" de cada inventario: qué personal interno trabaja en él.
 * Solo se asigna personal interno activo con permiso para escanear (INV_TOMA:ESCANEAR).
 */
class AssignmentController extends Controller
{
    private const SCAN_PERMISSION = 'INV_TOMA:ESCANEAR';

    public function index(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $users = $inventory->assignees()
            ->with('roles:id,name')
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();

        $assigners = User::query()->whereIn('id', $users->pluck('pivot.assigned_by')->filter()->unique())->get()->keyBy('id');

        return response()->json(['data' => $users->map(fn (User $user): array => [
            ...$this->transform($user),
            'assigned_at' => $user->pivot->created_at?->toISOString(),
            'assigned_by' => $assigners->get($user->pivot->assigned_by)?->full_name,
        ])->values()]);
    }

    /**
     * Personal que se puede asignar (búsqueda en el servidor, máx. 20 resultados).
     */
    public function candidates(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $assigned = $inventory->assignees()->pluck('users.id')->flip();

        $users = $this->assignableUsers()
            ->with('roles:id,name')
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('document_number', 'like', $term)
                    ->orWhere('email', 'like', $term));
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get();

        return response()->json(['data' => $users->map(fn (User $user): array => [
            ...$this->transform($user),
            'assigned' => $assigned->has($user->id),
        ])->values()]);
    }

    public function store(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);

        $data = $request->validate([
            'user_ids' => ['required', 'array', 'min:1', 'max:100'],
            'user_ids.*' => ['integer', 'distinct'],
        ]);

        // Se valida solo contra los ids enviados (no se carga todo el personal).
        $valid = $this->assignableUsers()->whereIn('users.id', $data['user_ids'])->count();
        if ($valid !== count($data['user_ids'])) {
            throw ValidationException::withMessages([
                'user_ids' => 'Solo se puede asignar personal interno activo con permiso para escanear.',
            ]);
        }

        $inventory->assignees()->syncWithoutDetaching(
            collect($data['user_ids'])->mapWithKeys(fn (int $id): array => [$id => ['assigned_by' => $request->user()->id]])->all()
        );

        return response()->json(['message' => count($data['user_ids']).' inventariador(es) asignado(s).']);
    }

    public function destroy(Request $request, Inventory $inventory, User $user): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);

        $inventory->assignees()->detach($user->id);

        return response()->json(['message' => "{$user->full_name} ya no está asignado a este inventario."]);
    }

    /**
     * Personal interno activo cuyo rol le permite escanear.
     */
    private function assignableUsers()
    {
        return User::query()
            ->whereNull('company_id')
            ->where('is_active', true)
            ->permission(self::SCAN_PERMISSION);
    }

    private function transform(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'document_number' => $user->document_number,
            'email' => $user->email,
            'roles' => $user->roles->pluck('name')->values(),
        ];
    }
}
