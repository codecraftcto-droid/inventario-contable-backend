<?php

namespace Modules\Inventories\Services;

use App\Enums\PocketStatus;
use App\Enums\ScanOutcome;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Inventories\Models\Asset;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryPocket;
use Modules\Inventories\Models\InventoryScan;

/**
 * Registra (o actualiza) en lote los registros de la toma hechos en el equipo, con o sin señal.
 *
 * - Idempotente por client_uuid: reenviar el mismo lote tras un corte de red no duplica nada.
 * - Un uuid ya conocido es una EDICIÓN: se aplica solo si es del mismo usuario y más reciente
 *   (client_updated_at) que lo guardado; una edición atrasada no pisa una más nueva.
 * - El mismo código puede registrarse varias veces (p. ej. 5 cajas en Planta y 3 en CDA):
 *   cada registro cuenta y las cantidades se suman. El servidor decide si el código está en
 *   la base contable (FOUND) o no (SURPLUS).
 * - La sede debe ser de la empresa del inventario; si no lo es (p. ej. la eliminaron mientras
 *   el equipo estaba sin señal) el registro se guarda igual, sin sede, conservando el nombre
 *   de la ubicación que envió el equipo.
 */
class ScanIngestService
{
    /** Campos que el equipo puede editar después de registrar. */
    private const EDITABLE = ['code', 'quantity', 'condition', 'site_id', 'pocket_id', 'location', 'pocket', 'detail', 'observations'];

    /**
     * @param  array<int, array<string, mixed>>  $scans
     * @return array<int, array{uuid: string, id: int, outcome: string, asset_id: int|null}>
     */
    public function ingest(Inventory $inventory, User $user, ?string $deviceId, array $scans): array
    {
        return DB::transaction(function () use ($inventory, $user, $deviceId, $scans): array {
            Inventory::query()->whereKey($inventory->id)->lockForUpdate()->first();

            // client_uuid es único en toda la tabla: se busca sin filtrar por inventario para
            // que un uuid repetido nunca rompa el insert.
            $existing = InventoryScan::query()
                ->whereIn('client_uuid', array_column($scans, 'uuid'))
                ->get()
                ->keyBy('client_uuid');

            $codes = array_values(array_unique(array_map(fn (array $scan): string => $this->normalize($scan['code']), $scans)));
            $assets = Asset::query()->where('inventory_id', $inventory->id)->whereIn('codigo', $codes)->pluck('id', 'codigo');
            $sites = $inventory->company->sites()->pluck('id')->flip();
            $pockets = $inventory->pockets()->get(['id', 'code', 'status'])->keyBy('id');

            // Registros que ya se eliminaron (p. ej. anulados en la web): un reenvío atrasado de una
            // edición no debe volver a crearlos. Se informan como eliminados para que el equipo los quite.
            $deleted = DB::table('inventory_scan_deletions')->whereIn('client_uuid', array_column($scans, 'uuid'))->pluck('client_uuid')->flip();

            $now = now();
            $rows = [];
            $result = [];

            foreach ($scans as $scan) {
                if ($deleted->has($scan['uuid']) && ! $existing->has($scan['uuid'])) {
                    $result[] = ['uuid' => $scan['uuid'], 'id' => null, 'outcome' => null, 'asset_id' => null, 'deleted' => true];

                    continue;
                }

                $code = $this->normalize($scan['code']);
                $assetId = $assets[$code] ?? null;
                $fields = [
                    'code' => $code,
                    'asset_id' => $assetId,
                    'outcome' => ($assetId !== null ? ScanOutcome::FOUND : ScanOutcome::SURPLUS)->value,
                    'quantity' => $scan['quantity'] ?? 1,
                    'condition' => $scan['condition'] ?? null,
                    'site_id' => isset($scan['site_id'], $sites[$scan['site_id']]) ? (int) $scan['site_id'] : null,
                    'location' => $scan['location'] ?? null,
                    // Pocket: si viene uno del inventario se enlaza y su código manda; si no, texto libre.
                    'pocket_id' => isset($scan['pocket_id'], $pockets[$scan['pocket_id']]) ? (int) $scan['pocket_id'] : null,
                    'pocket' => isset($scan['pocket_id'], $pockets[$scan['pocket_id']]) ? $pockets[$scan['pocket_id']]->code : $this->upper($scan['pocket'] ?? null),
                    'detail' => $scan['detail'] ?? null,
                    'observations' => $scan['observations'] ?? null,
                    'client_updated_at' => isset($scan['updated_at']) ? $this->localTime($scan['updated_at']) : null,
                ];

                if ($saved = $existing->get($scan['uuid'])) {
                    $this->applyEdit($saved, $inventory->id, $user, $fields);
                    $result[] = $this->accepted($saved);

                    continue;
                }

                $rows[] = [
                    ...$fields,
                    'inventory_id' => $inventory->id,
                    'client_uuid' => $scan['uuid'],
                    'user_id' => $user->id,
                    'device_id' => $deviceId,
                    'scanned_at' => $this->localTime($scan['scanned_at']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                InventoryScan::insert($rows);

                $ids = InventoryScan::query()->whereIn('client_uuid', array_column($rows, 'client_uuid'))->pluck('id', 'client_uuid');
                foreach ($rows as $row) {
                    $result[] = ['uuid' => $row['client_uuid'], 'id' => $ids[$row['client_uuid']], 'outcome' => $row['outcome'], 'asset_id' => $row['asset_id']];
                }

            }

            // Los pockets que recibieron su primer registro pasan a "en conteo".
            $touched = array_values(array_unique(array_filter(array_column($rows, 'pocket_id'))));
            if ($touched !== []) {
                InventoryPocket::query()->whereIn('id', $touched)->where('status', PocketStatus::PENDIENTE)->update(['status' => PocketStatus::EN_CONTEO]);
            }

            return $result;
        });
    }

    /**
     * Borra registros del propio usuario (el equipo los eliminó). Idempotente: los que ya
     * no existen se ignoran. Devuelve los uuid efectivamente borrados.
     *
     * @param  string[]  $uuids
     * @return string[]
     */
    public function delete(Inventory $inventory, User $user, array $uuids): array
    {
        return DB::transaction(function () use ($inventory, $user, $uuids): array {
            Inventory::query()->whereKey($inventory->id)->lockForUpdate()->first();

            $scans = InventoryScan::query()
                ->where('inventory_id', $inventory->id)
                ->where('user_id', $user->id)
                ->whereIn('client_uuid', $uuids)
                ->get();

            foreach ($scans as $scan) {
                $scan->deleteFiles();
                $scan->delete();
            }

            return $scans->pluck('client_uuid')->all();
        });
    }

    public function normalize(string $code): string
    {
        return mb_strtoupper(trim($code));
    }

    private function applyEdit(InventoryScan $saved, int $inventoryId, User $user, array $fields): void
    {
        // Solo el autor edita su registro, y solo si la edición es más nueva que lo guardado.
        if ($saved->inventory_id !== $inventoryId || $saved->user_id !== $user->id || $fields['client_updated_at'] === null) {
            return;
        }
        if ($saved->client_updated_at !== null && Carbon::parse($fields['client_updated_at'])->lte($saved->client_updated_at)) {
            return;
        }

        $saved->forceFill(array_intersect_key($fields, array_flip([...self::EDITABLE, 'asset_id', 'outcome', 'client_updated_at'])))->save();
    }

    private function accepted(InventoryScan $scan): array
    {
        return ['uuid' => $scan->client_uuid, 'id' => $scan->id, 'outcome' => $scan->outcome->value, 'asset_id' => $scan->asset_id];
    }

    /**
     * El equipo envía la hora en UTC (ISO 8601); se guarda en la zona del sistema, igual que
     * el resto de fechas (el insert masivo no pasa por los casts del modelo).
     */
    private function localTime(string $value): string
    {
        return Carbon::parse($value)->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function upper(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === null || $value === '' ? null : mb_strtoupper($value);
    }
}
