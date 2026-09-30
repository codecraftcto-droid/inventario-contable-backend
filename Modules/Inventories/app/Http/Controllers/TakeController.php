<?php

namespace Modules\Inventories\Http\Controllers;

use App\Enums\ReconciliationStatus;
use App\Enums\ScanCondition;
use App\Enums\ScanOutcome;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventories\Exports\TakeResultExport;
use Modules\Inventories\Models\Asset;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Modules\Inventories\Services\ReconciliationService;
use Modules\Inventories\Services\ScanRemovalService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Opción "Toma de inventario" en la web: avance, lecturas, faltantes y resultado en Excel.
 * Todo paginado en el servidor (un inventario puede tener decenas de miles de activos).
 * El registro de lecturas desde la web usa el mismo endpoint de lotes que la app.
 */
class TakeController extends Controller
{
    /**
     * Avance de la toma: totales por resultado, quién está leyendo y última lectura.
     */
    public function summary(Request $request, Inventory $inventory, ReconciliationService $reconciliation): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        // Por resultado: registros, productos distintos y unidades (el mismo producto puede
        // registrarse varias veces, p. ej. en distintas sedes).
        $byOutcome = $inventory->scans()
            ->selectRaw('outcome, COUNT(*) as records, COUNT(DISTINCT asset_id) as assets, COUNT(DISTINCT code) as codes, SUM(quantity) as units')
            ->groupBy('outcome')
            ->get()
            ->keyBy(fn ($row) => $row->getRawOriginal('outcome'));

        $total = $inventory->assets()->count();
        $foundRow = $byOutcome->get(ScanOutcome::FOUND->value);
        $surplusRow = $byOutcome->get(ScanOutcome::SURPLUS->value);
        $found = (int) ($foundRow?->getAttribute('assets') ?? 0);

        // Resultado por sede (ubicación)
        $bySite = $inventory->scans()
            ->leftJoin('sites', 'sites.id', '=', 'inventory_scans.site_id')
            ->selectRaw("COALESCE(sites.name, inventory_scans.location, 'Sin ubicación') as site, COUNT(*) as records, SUM(inventory_scans.quantity) as units")
            ->groupByRaw("COALESCE(sites.name, inventory_scans.location, 'Sin ubicación')")
            ->orderByDesc('records')
            ->limit(20)
            ->toBase()
            ->get();

        $readers = $inventory->scans()
            ->selectRaw('user_id, COUNT(*) as total, MAX(scanned_at) as last_scan_at')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(10)
            ->with('user:id,first_name,last_name')
            ->get();

        $last = $inventory->scans()->with('user:id,first_name,last_name')->latest('scanned_at')->first();

        return response()->json(['data' => [
            'total' => $total,
            'found' => $found,
            'missing' => max($total - $found, 0),
            'surplus' => (int) ($surplusRow?->getAttribute('codes') ?? 0),       // códigos distintos fuera de la base
            'duplicates' => (int) ($byOutcome->get(ScanOutcome::DUPLICATE->value)?->getAttribute('records') ?? 0),   // solo lecturas antiguas
            'scans' => (int) $byOutcome->sum(fn ($row) => $row->getAttribute('records')),
            'units' => (float) ($foundRow?->getAttribute('units') ?? 0),            // unidades contadas de productos de la base
            'reconciliation' => $reconciliation->summary($inventory),
            'by_site' => $bySite->map(fn ($row): array => ['site' => $row->site, 'records' => (int) $row->records, 'units' => (float) $row->units])->values(),
            'progress' => $total > 0 ? round($found * 100 / $total, 1) : 0,
            'last_scan' => $last ? [
                'code' => $last->code,
                'user' => $last->user?->full_name,
                'scanned_at' => $last->scanned_at->toISOString(),
            ] : null,
            'readers' => $readers->map(fn (InventoryScan $row): array => [
                'user' => $row->user?->full_name ?? 'Usuario eliminado',
                'total' => (int) $row->getAttribute('total'),
                'last_scan_at' => $row->getAttribute('last_scan_at'),
            ])->values(),
        ]]);
    }

    /**
     * Lecturas registradas (de la app y de la web), con filtro por resultado.
     */
    public function scans(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $request->validate([
            'outcome' => ['nullable', Rule::enum(ScanOutcome::class)],
            'condition' => ['nullable', Rule::enum(ScanCondition::class)],
            'site_id' => ['nullable', 'integer'],
            'pocket_id' => ['nullable', 'integer'],
        ]);

        $scans = $inventory->scans()
            ->with(['user:id,first_name,last_name', 'asset:id,nombre,unidad_medida', 'site:id,name', 'photos:id,inventory_scan_id,client_uuid'])
            ->when($request->filled('outcome'), fn ($query) => $query->where('outcome', $request->string('outcome')))
            ->when($request->filled('condition'), fn ($query) => $query->where('condition', $request->string('condition')))
            ->when($request->filled('site_id'), fn ($query) => $query->where('site_id', $request->integer('site_id')))
            ->when($request->filled('pocket_id'), fn ($query) => $query->where('pocket_id', $request->integer('pocket_id')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = '%'.mb_strtoupper(trim($request->string('search'))).'%';
                $query->where(fn ($q) => $q->where('code', 'like', $term)
                    ->orWhere('pocket', 'like', $term)
                    ->orWhere('location', 'like', "%{$request->string('search')}%"));
            })
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'outcome', 'quantity', 'condition', 'pocket', 'location', 'scanned_at'], 'scanned_at', 'desc'))
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return $this->paginated($scans, fn (InventoryScan $scan): array => [
            'id' => $scan->id,
            'code' => $scan->code,
            'outcome' => $scan->outcome->value,
            'description' => $scan->asset?->nombre,
            'unit' => $scan->asset?->unidad_medida,
            'quantity' => (float) $scan->quantity,
            'condition' => $scan->condition?->value,
            'site' => $scan->site?->name,
            'location' => $scan->site?->name ?? $scan->location,
            'pocket' => $scan->pocket,
            'detail' => $scan->detail,
            'observations' => $scan->observations,
            'photos' => $scan->photos->map(fn ($photo) => ScanPhotoController::transform($inventory, $photo))->values(),
            'user' => $scan->user?->full_name,
            'scanned_at' => $scan->scanned_at->toISOString(),
        ]);
    }

    /**
     * Conciliación por producto: base vs. lo registrado (paginada, con filtro por estado).
     */
    public function reconciliation(Request $request, Inventory $inventory, ReconciliationService $reconciliation): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        $request->validate(['status' => ['nullable', Rule::enum(ReconciliationStatus::class)]]);

        $rows = $reconciliation->query($inventory)
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('code', 'like', mb_strtoupper($term))->orWhere('name', 'like', $term));
            })
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'name', 'expected', 'counted', 'difference', 'status'], 'code'))
            ->paginate($this->perPage($request));

        return $this->paginated($rows, fn ($row): array => [
            'code' => $row->code,
            'name' => $row->name,
            'unit' => $row->unit,
            'value' => $row->value === null ? null : (float) $row->value,
            'expected' => (float) $row->expected,
            'counted' => (float) $row->counted,
            'difference' => (float) $row->difference,
            'records' => (int) $row->records,
            'status' => $row->status,
        ]);
    }

    /**
     * Activos de la base contable que aún no se han encontrado.
     */
    public function missing(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $assets = Asset::query()
            ->where('inventory_id', $inventory->id)
            ->missingIn($inventory)
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('codigo', 'like', $term)->orWhere('nombre', 'like', $term));
            })
            ->tap(fn ($query) => $this->applySort($query, $request, ['codigo', 'nombre', 'unidad_medida'], 'codigo'))
            ->paginate($this->perPage($request));

        return $this->paginated($assets, fn (Asset $asset): array => [
            'id' => $asset->id,
            'code' => $asset->codigo,
            'description' => $asset->nombre,
            'unit' => $asset->unidad_medida,
        ]);
    }

    /**
     * Anula una lectura equivocada (solo con el inventario abierto).
     */
    public function destroy(Request $request, Inventory $inventory, InventoryScan $scan, ScanRemovalService $removal): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);

        $removal->remove($inventory, $scan);

        return response()->json(['message' => "Registro {$scan->code} anulado."]);
    }

    /**
     * Resultado de la toma en Excel: encontrados, faltantes y sobrantes (una hoja cada uno).
     */
    public function export(Request $request, Inventory $inventory): BinaryFileResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        return Excel::download(new TakeResultExport($inventory), "toma-{$inventory->code}.xlsx");
    }
}
