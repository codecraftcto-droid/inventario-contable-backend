<?php

namespace Modules\Inventories\Http\Controllers;

use App\Enums\InventoryStatus;
use App\Enums\ScanOutcome;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Inventories\Http\Requests\InventoryRequest;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Modules\Inventories\Services\InventoryLifecycleService;

/**
 * Listado de inventarios por empresa. Las operaciones de cada inventario (carga de la
 * base contable, toma, cierre) se exponen como opciones según los permisos del usuario.
 * Un usuario cliente solo ve y opera los inventarios de su empresa.
 */
class InventoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $inventories = Inventory::query()
            ->visibleTo($user)
            ->with('company:id,code,name')
            ->withCount(['assets', 'assignees', 'pockets'])
            ->tap(fn ($query) => self::withProgress($query))
            ->when($request->boolean('only_open'), fn ($query) => $query->where('status', '!=', InventoryStatus::CERRADO))
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('code', 'like', $term)->orWhere('name', 'like', $term));
            })
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'name', 'status', 'start_date', 'end_date', 'created_at'], 'id', 'desc'))
            ->paginate($this->perPage($request));

        return $this->paginated($inventories, fn (Inventory $inventory): array => $this->transform($inventory));
    }

    public function show(Request $request, Inventory $inventory): JsonResponse
    {
        $this->ensureVisible($request->user(), $inventory);

        return response()->json(['data' => $this->transform($this->reload($inventory))]);
    }

    public function store(InventoryRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($user->isClientUser() && (int) $request->validated('company_id') !== $user->company_id) {
            throw ValidationException::withMessages(['company_id' => 'Solo puedes registrar inventarios de tu empresa.']);
        }

        $inventory = Inventory::create([...$request->validated(), 'status' => InventoryStatus::PENDIENTE]);

        return response()->json([
            'message' => 'Inventario creado correctamente.',
            'data' => $this->transform($this->reload($inventory)),
        ], 201);
    }

    public function update(InventoryRequest $request, Inventory $inventory): JsonResponse
    {
        $this->ensureVisible($request->user(), $inventory);
        $this->ensureOpen($inventory);

        $inventory->update($request->validated());

        return response()->json([
            'message' => 'Inventario actualizado correctamente.',
            'data' => $this->transform($this->reload($inventory)),
        ]);
    }

    public function destroy(Request $request, Inventory $inventory): JsonResponse
    {
        $this->ensureVisible($request->user(), $inventory);
        $this->ensureOpen($inventory);

        $inventory->delete();

        return response()->json(['message' => 'Inventario eliminado correctamente.']);
    }

    /**
     * Botones del inventario: iniciar, pausar, reanudar y finalizar (ver InventoryLifecycleService).
     */
    public function start(Request $request, Inventory $inventory, InventoryLifecycleService $lifecycle): JsonResponse
    {
        return $this->transition($request, $inventory, $lifecycle, 'INICIAR');
    }

    public function pause(Request $request, Inventory $inventory, InventoryLifecycleService $lifecycle): JsonResponse
    {
        return $this->transition($request, $inventory, $lifecycle, 'PAUSAR');
    }

    public function resume(Request $request, Inventory $inventory, InventoryLifecycleService $lifecycle): JsonResponse
    {
        return $this->transition($request, $inventory, $lifecycle, 'REANUDAR');
    }

    public function close(Request $request, Inventory $inventory, InventoryLifecycleService $lifecycle): JsonResponse
    {
        return $this->transition($request, $inventory, $lifecycle, 'FINALIZAR');
    }

    /**
     * Historial del ciclo: quién inició, pausó, reanudó o finalizó y cuándo.
     */
    public function history(Request $request, Inventory $inventory): JsonResponse
    {
        $this->ensureVisible($request->user(), $inventory);

        $logs = DB::table('inventory_status_logs AS l')
            ->leftJoin('users AS u', 'u.id', '=', 'l.user_id')
            ->where('l.inventory_id', $inventory->id)
            ->orderByDesc('l.created_at')->orderByDesc('l.id')
            ->limit(100)
            ->get(['l.action', 'l.from_status', 'l.to_status', 'l.created_at', 'u.first_name', 'u.last_name']);

        return response()->json(['data' => $logs->map(fn ($log): array => [
            'action' => $log->action,
            'from' => $log->from_status,
            'to' => $log->to_status,
            'user' => trim("{$log->first_name} {$log->last_name}") ?: null,
            'at' => \Illuminate\Support\Carbon::parse($log->created_at)->toISOString(),
        ])]);
    }

    private function transition(Request $request, Inventory $inventory, InventoryLifecycleService $lifecycle, string $action): JsonResponse
    {
        $this->ensureVisible($request->user(), $inventory);
        $message = $lifecycle->apply($inventory, $action, $request->user());

        return response()->json(['message' => $message, 'data' => $this->transform($this->reload($inventory))]);
    }

    /**
     * Avance de la toma como subconsultas. El mismo producto puede registrarse varias veces
     * (cantidades en distintas sedes), así que se cuentan PRODUCTOS distintos, no registros.
     */
    public static function withProgress(Builder $query): Builder
    {
        $scans = fn (ScanOutcome $outcome, string $column) => InventoryScan::query()
            ->selectRaw("COUNT(DISTINCT {$column})")
            ->whereColumn('inventory_scans.inventory_id', 'inventories.id')
            ->where('outcome', $outcome);

        return $query->addSelect([
            'found_count' => $scans(ScanOutcome::FOUND, 'asset_id'),
            'surplus_count' => $scans(ScanOutcome::SURPLUS, 'code'),
        ]);
    }

    /**
     * El inventario con empresa y conteos, tal como sale en el listado.
     */
    private function reload(Inventory $inventory): Inventory
    {
        return Inventory::query()
            ->whereKey($inventory->id)
            ->with('company:id,code,name')
            ->withCount(['assets', 'assignees', 'pockets'])
            ->tap(fn ($query) => self::withProgress($query))
            ->firstOrFail();
    }

    public static function ensureVisible(User $user, Inventory $inventory): void
    {
        if ($inventory->isVisibleTo($user)) {
            return;
        }

        // 404 y no 403: a un usuario cliente no se le revela que existen inventarios de otras empresas.
        abort_if($user->isClientUser(), 404, 'El inventario no existe.');

        // Personal sin asignación. El código le permite a la app marcar sus lecturas pendientes
        // como rechazadas (en lugar de reintentar sin fin) y quitar el inventario del equipo.
        throw new HttpResponseException(response()->json([
            'message' => "No estás asignado al inventario {$inventory->code}. Pide que te asignen para trabajar en él.",
            'code' => 'INVENTORY_NOT_ASSIGNED',
        ], 403));
    }

    public static function ensureOpen(Inventory $inventory): void
    {
        if ($inventory->isClosed()) {
            throw ValidationException::withMessages(['inventory' => 'El inventario está cerrado y ya no admite cambios.']);
        }
    }

    private function transform(Inventory $inventory): array
    {
        return [
            'id' => $inventory->id,
            'company' => $inventory->company ? [
                'id' => $inventory->company->id,
                'code' => $inventory->company->code,
                'name' => $inventory->company->name,
            ] : null,
            'code' => $inventory->code,
            'name' => $inventory->name,
            'description' => $inventory->description,
            'start_date' => $inventory->start_date?->toDateString(),
            'end_date' => $inventory->end_date?->toDateString(),
            'status' => $inventory->status->value,
            'status_label' => $inventory->status->label(),
            'started_at' => $inventory->started_at?->toISOString(),
            'paused_at' => $inventory->paused_at?->toISOString(),
            'pockets_count' => (int) ($inventory->pockets_count ?? 0),
            'assets_count' => $inventory->assets_count,
            'found_count' => (int) ($inventory->found_count ?? 0),
            'surplus_count' => (int) ($inventory->surplus_count ?? 0),
            'assignees_count' => (int) ($inventory->assignees_count ?? 0),
            'closed_at' => $inventory->closed_at?->toISOString(),
            'created_at' => $inventory->created_at?->toISOString(),
        ];
    }
}
