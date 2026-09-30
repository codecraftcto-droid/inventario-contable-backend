<?php

namespace Modules\Inventories\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use Modules\Inventories\Exports\AssetsExport;
use Modules\Inventories\Exports\AssetsTemplateExport;
use Modules\Inventories\Http\Requests\AssetRequest;
use Modules\Inventories\Imports\AssetsImport;
use Modules\Inventories\Models\Asset;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Services\ScanRelinker;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Base de datos contable (activos) de un inventario.
 */
class AssetController extends Controller
{
    public function __construct(private readonly ScanRelinker $relinker) {}

    public function index(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $assets = $inventory->assets()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('codigo', 'like', $term)->orWhere('nombre', 'like', $term));
            })
            ->tap(fn ($query) => $this->applySort($query, $request, ['codigo', 'nombre', 'unidad_medida', 'cantidad', 'valor'], 'codigo'))
            ->paginate($this->perPage($request));

        return $this->paginated($assets);
    }

    public function store(AssetRequest $request, Inventory $inventory): JsonResponse
    {
        $this->ensureEditable($request, $inventory);

        $asset = $inventory->assets()->create($request->validated());
        $this->relinker->relink($inventory, [$asset->codigo]);

        return response()->json(['message' => 'Activo registrado correctamente.', 'data' => $asset], 201);
    }

    public function update(AssetRequest $request, Inventory $inventory, Asset $asset): JsonResponse
    {
        $this->ensureEditable($request, $inventory);

        $previous = $asset->codigo;
        $asset->update($request->validated());
        $this->relinker->relink($inventory, [$previous, $asset->codigo]);   // Si cambió el código, ambos

        return response()->json(['message' => 'Activo actualizado correctamente.', 'data' => $asset]);
    }

    public function destroy(Request $request, Inventory $inventory, Asset $asset): JsonResponse
    {
        $this->ensureEditable($request, $inventory);

        $asset->delete();
        $this->relinker->relink($inventory, [$asset->codigo]);   // Sus registros pasan a sobrante

        return response()->json(['message' => 'Activo eliminado correctamente.']);
    }

    public function template(): BinaryFileResponse
    {
        return Excel::download(new AssetsTemplateExport, 'plantilla-base-contable.xlsx');
    }

    public function export(Request $request, Inventory $inventory): BinaryFileResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        return Excel::download(new AssetsExport($inventory), "base-contable-{$inventory->code}.xlsx");
    }

    /**
     * Carga de la base de datos contable desde Excel.
     */
    public function import(Request $request, Inventory $inventory): JsonResponse
    {
        $this->ensureEditable($request, $inventory);

        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls,csv', 'max:20480'],
        ]);

        $import = new AssetsImport($inventory);
        Excel::import($import, $request->file('file'));


        $failures = $import->failures();   // [{ row: fila del Excel, errors: [...] }]

        return response()->json([
            'message' => "Carga completada: {$import->createdCount()} producto(s) nuevo(s) y {$import->updatedCount()} actualizado(s).",
            'data' => [
                'imported' => $import->importedCount(),
                'created' => $import->createdCount(),
                'updated' => $import->updatedCount(),
                'failures' => array_slice($failures, 0, 500),   // Tope para no enviar respuestas gigantes
                'failures_count' => count($failures),
            ],
        ]);
    }

    private function ensureEditable(Request $request, Inventory $inventory): void
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);
    }
}
