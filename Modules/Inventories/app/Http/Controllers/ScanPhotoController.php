<?php

namespace Modules\Inventories\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Modules\Inventories\Models\InventoryScanPhoto;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fotos de los registros de la toma (hasta 3 por registro).
 *
 * La app las guarda en el equipo al instante y las sube cuando hay señal, DESPUÉS de que
 * su registro se sincronizó. Subir es idempotente (uuid de la foto): reintentar tras un
 * corte de red no duplica. Los archivos nunca son públicos: se sirven por la API con permisos.
 */
class ScanPhotoController extends Controller
{
    public function store(Request $request, Inventory $inventory, string $scan): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        $data = $request->validate([
            'uuid' => ['required', 'uuid'],
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('inventories.photo_max_kb')],
        ]);

        if ($inventory->isClosed()) {
            return response()->json(['message' => "El inventario {$inventory->code} ya está cerrado.", 'code' => 'INVENTORY_CLOSED'], 409);
        }

        $record = $this->ownScan($request, $inventory, $scan);
        if ($record === null) {
            // El registro aún no llegó (o se eliminó): la app reintenta después de sincronizarlo.
            return response()->json(['message' => 'El registro de la foto aún no está en el servidor.', 'code' => 'SCAN_NOT_SYNCED'], 409);
        }

        if ($existing = InventoryScanPhoto::query()->where('client_uuid', $data['uuid'])->first()) {
            return response()->json(['data' => $this->transform($inventory, $existing)]);
        }

        if ($record->photos()->count() >= InventoryScanPhoto::MAX_PER_SCAN) {
            return response()->json([
                'message' => 'Cada registro admite hasta '.InventoryScanPhoto::MAX_PER_SCAN.' fotos.',
                'code' => 'PHOTO_LIMIT',
            ], 422);
        }

        $disk = config('inventories.photos_disk');
        $file = $data['photo'];
        $path = $file->storeAs("scan-photos/{$inventory->id}/{$record->id}", $data['uuid'].'.'.$file->extension(), $disk);

        $photo = $record->photos()->create([
            'client_uuid' => $data['uuid'],
            'disk' => $disk,
            'path' => $path,
            'size' => $file->getSize(),
        ]);

        return response()->json(['message' => 'Foto guardada.', 'data' => $this->transform($inventory, $photo)], 201);
    }

    public function destroy(Request $request, Inventory $inventory, string $scan, string $photo): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $record = $this->ownScan($request, $inventory, $scan);
        $record?->photos()->where('client_uuid', $photo)->first()?->delete();

        // Idempotente: si ya no estaba, para el equipo también está eliminada.
        return response()->json(['message' => 'Foto eliminada.']);
    }

    /**
     * Muestra la foto (web: detalle de la toma). Requiere poder ver el inventario.
     */
    public function show(Request $request, Inventory $inventory, InventoryScanPhoto $photo): StreamedResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        abort_unless($photo->scan()->where('inventory_id', $inventory->id)->exists(), 404);

        return Storage::disk($photo->disk)->response($photo->path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    private function ownScan(Request $request, Inventory $inventory, string $uuid): ?InventoryScan
    {
        return InventoryScan::query()
            ->where('inventory_id', $inventory->id)
            ->where('client_uuid', $uuid)
            ->where('user_id', $request->user()->id)
            ->first();
    }

    public static function transform(Inventory $inventory, InventoryScanPhoto $photo): array
    {
        return [
            'id' => $photo->id,
            'uuid' => $photo->client_uuid,
            'url' => "/inventories/{$inventory->id}/photos/{$photo->id}",
        ];
    }
}
