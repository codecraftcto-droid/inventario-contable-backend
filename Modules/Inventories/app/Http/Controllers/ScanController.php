<?php

namespace Modules\Inventories\Http\Controllers;

use App\Enums\InventoryStatus;
use App\Enums\ScanCondition;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Modules\Inventories\Services\ScanIngestService;
use Modules\Security\Services\AuthService;

/**
 * API de la toma de inventario para la app (funciona sin señal y sincroniza después).
 *
 * Las descargas usan paginación por cursor (after_id), no por página: es estable
 * aunque lleguen datos nuevos mientras se descarga y permite retomar una descarga
 * cortada desde el último bloque recibido.
 */
class ScanController extends Controller
{
    private const MAX_LIMIT = 2000;

    public function __construct(private readonly ScanIngestService $ingest) {}

    /**
     * Base contable del inventario, en bloques, para guardarla en el equipo.
     */
    public function base(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        [$afterId, $limit] = $this->cursor($request);

        $items = $inventory->assets()
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get(['id', 'codigo', 'nombre', 'unidad_medida', 'cantidad']);

        return $this->cursorResponse($items, $limit, fn ($asset): array => [
            'id' => $asset->id,
            'code' => $asset->codigo,
            'description' => $asset->nombre,
            'unit' => $asset->unidad_medida,
            'quantity' => $asset->expectedQuantity(),   // Cantidad de la base (para conciliar en el equipo)
        ], ['total' => $afterId === 0 ? $inventory->assets()->count() : null]);
    }

    /**
     * Lecturas ya registradas (de todos los equipos), en bloques.
     */
    public function index(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        $request->validate(['changed_since' => ['nullable', 'date']]);

        [$afterId, $limit] = $this->cursor($request);
        $serverTime = now();

        // Modo "cambios" (tiempo real): altas y EDICIONES desde la última consulta, más las
        // eliminaciones. La app pide con changed_since = hora del servidor de su consulta anterior
        // (menos un margen); repetir un registro no hace daño: el equipo lo actualiza.
        $since = $request->filled('changed_since') ? \Illuminate\Support\Carbon::parse($request->string('changed_since'))->setTimezone(config('app.timezone')) : null;

        $scans = $inventory->scans()
            ->with('user:id,first_name,last_name')
            ->when($since, fn ($query) => $query->where('updated_at', '>=', $since))
            ->where('id', '>', $afterId)
            ->orderBy('id')
            ->limit($limit + 1)
            ->get();

        $extra = [];
        if ($since !== null) {
            $extra['server_time'] = $serverTime->toISOString();
            if ($afterId === 0) {
                $extra['deleted'] = DB::table('inventory_scan_deletions')
                    ->where('inventory_id', $inventory->id)
                    ->where('deleted_at', '>=', $since)
                    ->pluck('client_uuid')
                    ->all();
            }
        }

        return $this->cursorResponse($scans, $limit, fn (InventoryScan $scan): array => [
            'id' => $scan->id,
            'uuid' => $scan->client_uuid,
            'code' => $scan->code,
            'asset_id' => $scan->asset_id,
            'outcome' => $scan->outcome->value,
            'quantity' => (float) $scan->quantity,
            'condition' => $scan->condition?->value,
            'site_id' => $scan->site_id,
            'pocket_id' => $scan->pocket_id,
            'location' => $scan->location,
            'pocket' => $scan->pocket,
            'detail' => $scan->detail,
            'observations' => $scan->observations,
            'scanned_at' => $scan->scanned_at->toISOString(),
            'updated_at' => $scan->updated_at?->toISOString(),
            'user' => $scan->user?->full_name,
        ], $extra);
    }

    /**
     * Recibe un lote de lecturas hechas en el equipo (con o sin señal).
     */
    public function store(Request $request, Inventory $inventory, AuthService $auth): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $data = $request->validate([
            'scans' => ['required', 'array', 'min:1', 'max:500'],
            'scans.*.uuid' => ['required', 'uuid', 'distinct'],
            'scans.*.code' => ['required', 'string', 'max:100'],
            'scans.*.scanned_at' => ['required', 'date'],
            // Datos del registro (como el APK). Opcionales para que las versiones anteriores
            // de la app sigan sincronizando; la app actual los exige en su formulario.
            'scans.*.quantity' => ['nullable', 'numeric', 'gt:0', 'max:9999999'],
            'scans.*.condition' => ['nullable', Rule::enum(ScanCondition::class)],
            'scans.*.site_id' => ['nullable', 'integer'],
            'scans.*.pocket_id' => ['nullable', 'integer'],
            'scans.*.location' => ['nullable', 'string', 'max:150'],
            'scans.*.pocket' => ['nullable', 'string', 'max:50'],
            'scans.*.detail' => ['nullable', 'string', 'max:255'],
            'scans.*.observations' => ['nullable', 'string', 'max:500'],
            'scans.*.updated_at' => ['nullable', 'date'],   // Hora de la última edición en el equipo
        ]);

        if ($inventory->isClosed()) {
            return response()->json([
                'message' => "El inventario {$inventory->code} ya está finalizado y no admite más registros.",
                'code' => 'INVENTORY_CLOSED',
            ], 409);
        }

        // Sin iniciar o en pausa: no se registra. El equipo conserva lo suyo como pendiente y lo
        // envía solo cuando el inventario se inicie / reanude (no se pierde el trabajo de campo).
        if (! $inventory->acceptsRecords()) {
            $paused = $inventory->status === InventoryStatus::PAUSADO;

            return response()->json([
                'message' => $paused
                    ? "El inventario {$inventory->code} está en pausa. Tus registros quedan guardados y se enviarán al reanudarlo."
                    : "El inventario {$inventory->code} aún no se inició. Tus registros quedan guardados y se enviarán al iniciarlo.",
                'code' => $paused ? 'INVENTORY_PAUSED' : 'INVENTORY_NOT_STARTED',
                'status' => $inventory->status->value,
            ], 409);
        }

        $deviceId = $auth->currentSession()?->device_id;
        $result = $this->ingest->ingest($inventory, $request->user(), $deviceId, $data['scans']);

        return response()->json([
            'message' => count($result).' registro(s) recibidos.',
            'data' => ['accepted' => $result],
        ]);
    }

    /**
     * Registros que el equipo eliminó (solo los del propio usuario). Idempotente.
     */
    public function destroyBatch(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:500'],
            'uuids.*' => ['required', 'uuid'],
        ]);

        if ($inventory->isClosed()) {
            return response()->json([
                'message' => "El inventario {$inventory->code} ya está cerrado y no admite cambios.",
                'code' => 'INVENTORY_CLOSED',
            ], 409);
        }

        $deleted = $this->ingest->delete($inventory, $request->user(), $data['uuids']);

        // Se confirman todos: los que no existían ya estaban "borrados" para el equipo.
        return response()->json(['message' => count($deleted).' registro(s) eliminado(s).', 'data' => ['deleted' => $data['uuids']]]);
    }

    /**
     * Sedes de la empresa del inventario (la "ubicación" de cada registro). La app las
     * guarda en el equipo junto con la base contable para trabajar sin señal.
     */
    public function sites(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $sites = $inventory->company->sites()->where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']);

        return response()->json(['data' => $sites]);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function cursor(Request $request): array
    {
        return [
            max(0, $request->integer('after_id')),
            min(max($request->integer('limit', 500), 1), self::MAX_LIMIT),
        ];
    }

    private function cursorResponse($items, int $limit, callable $transform, array $extraMeta = []): JsonResponse
    {
        $hasMore = $items->count() > $limit;
        $page = $items->take($limit);

        return response()->json([
            'data' => $page->map($transform)->values(),
            'meta' => array_filter([
                'next_after_id' => $page->last()?->id,
                'has_more' => $hasMore,
                ...$extraMeta,
            ], fn ($value) => $value !== null),
        ]);
    }
}
