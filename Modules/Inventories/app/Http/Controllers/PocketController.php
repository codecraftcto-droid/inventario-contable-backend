<?php

namespace Modules\Inventories\Http\Controllers;

use App\Enums\PocketStatus;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryPocket;

/**
 * Pockets del inventario (zonas / tandas de conteo) y su asignación a inventariadores.
 *
 * - Web (INV_POCK): crear (uno o varios de una vez), editar, asignar usuarios, ver avance, reabrir.
 * - Teléfono (INV_TOMA): "mis pockets" y "terminar pocket".
 * Asignar un pocket a alguien lo asigna también al inventario (si no, no podría verlo).
 */
class PocketController extends Controller
{
    private const SCAN_PERMISSION = 'INV_TOMA:ESCANEAR';

    public function index(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        $request->validate(['status' => ['nullable', Rule::enum(PocketStatus::class)]]);

        $pockets = $inventory->pockets()
            ->with(['site:id,name', 'users:id,first_name,last_name'])
            ->withCount('scans')
            ->withSum('scans as units', 'quantity')
            ->withMax('scans as last_scan_at', 'scanned_at')
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('search'), fn ($query) => $query->where(fn ($q) => $q
                ->where('code', 'like', '%'.mb_strtoupper($request->string('search')).'%')
                ->orWhere('name', 'like', "%{$request->string('search')}%")))
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'name', 'status'], 'code'))
            ->paginate($this->perPage($request));

        return $this->paginated($pockets, fn (InventoryPocket $pocket): array => $this->transform($pocket));
    }

    public function store(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);
        $data = $this->validated($request, $inventory);

        $pocket = DB::transaction(function () use ($inventory, $data, $request): InventoryPocket {
            $pocket = $inventory->pockets()->create($data);
            $this->syncUsers($inventory, $pocket, $data['user_ids'] ?? [], $request->user());

            return $pocket;
        });

        return response()->json(['message' => "Pocket {$pocket->code} creado.", 'data' => $this->transform($this->fresh($pocket))], 201);
    }

    /**
     * Crea varios pockets de una vez: prefijo + rango (P-01 … P-20). Los que ya existen se omiten.
     */
    public function bulk(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);
        $data = $request->validate([
            'prefix' => ['nullable', 'string', 'max:20'],
            'from' => ['required', 'integer', 'min:0', 'max:9999'],
            'to' => ['required', 'integer', 'gte:from', 'max:9999', fn ($attribute, $value, $fail) => $value - $request->integer('from') >= 500 ? $fail('Máximo 500 pockets por vez.') : null],
            'digits' => ['nullable', 'integer', 'min:1', 'max:6'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('company_id', $inventory->company_id)],
        ]);

        $existing = $inventory->pockets()->pluck('code')->flip();
        $created = 0;
        $userId = $request->user()->id;
        $now = now();
        $rows = [];
        for ($n = $data['from']; $n <= $data['to']; $n++) {
            $code = mb_strtoupper(trim(($data['prefix'] ?? '').str_pad((string) $n, $data['digits'] ?? 2, '0', STR_PAD_LEFT)));
            if ($existing->has($code)) {
                continue;
            }
            $rows[] = ['inventory_id' => $inventory->id, 'site_id' => $data['site_id'] ?? null, 'code' => $code, 'status' => PocketStatus::PENDIENTE->value,
                'created_by' => $userId, 'updated_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
            $created++;
        }
        InventoryPocket::insert($rows);

        return response()->json(['message' => "{$created} pocket(s) creado(s).", 'data' => ['created' => $created]], 201);
    }

    public function update(Request $request, Inventory $inventory, InventoryPocket $pocket): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);
        $data = $this->validated($request, $inventory, $pocket);

        DB::transaction(function () use ($inventory, $pocket, $data, $request): void {
            $pocket->update($data);
            // El código del pocket se copia en sus registros (Excel, búsquedas).
            $pocket->scans()->update(['pocket' => $pocket->code, 'updated_at' => now()]);
            if (array_key_exists('user_ids', $data)) {
                $this->syncUsers($inventory, $pocket, $data['user_ids'] ?? [], $request->user());
            }
        });

        return response()->json(['message' => "Pocket {$pocket->code} actualizado.", 'data' => $this->transform($this->fresh($pocket))]);
    }

    public function destroy(Request $request, Inventory $inventory, InventoryPocket $pocket): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);

        if ($pocket->scans()->exists()) {
            throw ValidationException::withMessages(['pocket' => "El pocket {$pocket->code} ya tiene registros: no se puede eliminar."]);
        }
        $pocket->delete();

        return response()->json(['message' => "Pocket {$pocket->code} eliminado."]);
    }

    /**
     * El supervisor vuelve a abrir un pocket terminado (p. ej. para recontar).
     */
    public function reopen(Request $request, Inventory $inventory, InventoryPocket $pocket): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);
        InventoryController::ensureOpen($inventory);

        $pocket->forceFill([
            'status' => $pocket->scans()->exists() ? PocketStatus::EN_CONTEO : PocketStatus::PENDIENTE,
            'finished_at' => null,
            'finished_by' => null,
        ])->save();

        return response()->json(['message' => "Pocket {$pocket->code} reabierto.", 'data' => $this->transform($this->fresh($pocket))]);
    }

    /**
     * Personal que se puede asignar a un pocket (interno, activo, que escanea). Máx. 20.
     */
    public function candidates(Request $request, Inventory $inventory): JsonResponse
    {
        InventoryController::ensureVisible($request->user(), $inventory);

        $users = $this->assignable()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('document_number', 'like', $term));
            })
            ->orderBy('first_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'document_number']);

        return response()->json(['data' => $users->map(fn (User $user) => ['id' => $user->id, 'full_name' => $user->full_name, 'document_number' => $user->document_number])]);
    }

    // ------------------------------------------------------------------ Teléfono

    /**
     * Pockets del usuario en este inventario (todos si puede ver todo: supervisor / contable).
     */
    public function mine(Request $request, Inventory $inventory): JsonResponse
    {
        $user = $request->user();
        InventoryController::ensureVisible($user, $inventory);

        $pockets = $inventory->pockets()
            ->with('site:id,name')
            ->when(! $user->hasPermissionTo(Inventory::SEE_ALL_PERMISSION), fn ($query) => $query->whereHas('users', fn ($q) => $q->whereKey($user->id)))
            ->orderBy('code')
            ->get();

        return response()->json([
            'data' => $pockets->map(fn (InventoryPocket $pocket): array => [
                'id' => $pocket->id,
                'code' => $pocket->code,
                'name' => $pocket->name,
                'site_id' => $pocket->site_id,
                'site' => $pocket->site?->name,
                'status' => $pocket->status->value,
            ]),
            // Si el inventario tiene pockets, en el teléfono el pocket es obligatorio y se elige de esta lista.
            'meta' => ['inventory_has_pockets' => $inventory->pockets()->exists()],
        ]);
    }

    /**
     * "Terminar pocket": lo marca el inventariador asignado (o quien administra pockets).
     */
    public function finish(Request $request, Inventory $inventory, InventoryPocket $pocket): JsonResponse
    {
        $user = $request->user();
        InventoryController::ensureVisible($user, $inventory);
        InventoryController::ensureOpen($inventory);
        abort_unless($pocket->users()->whereKey($user->id)->exists() || $user->hasPermissionTo('INV_POCK:EDITAR'), 403, 'No tienes asignado este pocket.');

        if ($pocket->status !== PocketStatus::TERMINADO) {
            $pocket->forceFill(['status' => PocketStatus::TERMINADO, 'finished_at' => now(), 'finished_by' => $user->id])->save();
        }

        return response()->json(['message' => "Pocket {$pocket->code} terminado.", 'data' => ['id' => $pocket->id, 'status' => $pocket->status->value]]);
    }

    // ------------------------------------------------------------------ Apoyo

    private function validated(Request $request, Inventory $inventory, ?InventoryPocket $pocket = null): array
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => mb_strtoupper(trim($request->input('code')))]);
        }

        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('inventory_pockets', 'code')->where('inventory_id', $inventory->id)->ignore($pocket?->id)],
            'name' => ['nullable', 'string', 'max:255'],
            'site_id' => ['nullable', 'integer', Rule::exists('sites', 'id')->where('company_id', $inventory->company_id)],
            'user_ids' => ['sometimes', 'array', 'max:50'],
            'user_ids.*' => ['integer', 'distinct'],
        ], ['code.unique' => 'El inventario ya tiene un pocket con ese código.'], ['code' => 'código', 'site_id' => 'sede']);

        if (! empty($data['user_ids']) && $this->assignable()->whereIn('users.id', $data['user_ids'])->count() !== count($data['user_ids'])) {
            throw ValidationException::withMessages(['user_ids' => 'Solo se puede asignar personal interno activo con permiso para escanear.']);
        }

        return $data;
    }

    private function syncUsers(Inventory $inventory, InventoryPocket $pocket, array $userIds, User $by): void
    {
        $pivot = collect($userIds)->mapWithKeys(fn ($id) => [$id => ['assigned_by' => $by->id]])->all();
        $pocket->users()->sync($pivot);
        // Quien tiene un pocket debe poder ver el inventario.
        $inventory->assignees()->syncWithoutDetaching($pivot);
    }

    private function assignable()
    {
        return User::query()->whereNull('company_id')->where('is_active', true)->permission(self::SCAN_PERMISSION);
    }

    private function fresh(InventoryPocket $pocket): InventoryPocket
    {
        return InventoryPocket::query()->whereKey($pocket->id)
            ->with(['site:id,name', 'users:id,first_name,last_name'])
            ->withCount('scans')->withSum('scans as units', 'quantity')->withMax('scans as last_scan_at', 'scanned_at')
            ->firstOrFail();
    }

    private function transform(InventoryPocket $pocket): array
    {
        return [
            'id' => $pocket->id,
            'code' => $pocket->code,
            'name' => $pocket->name,
            'site' => $pocket->site ? ['id' => $pocket->site->id, 'name' => $pocket->site->name] : null,
            'status' => $pocket->status->value,
            'users' => $pocket->users->map(fn (User $user) => ['id' => $user->id, 'full_name' => $user->full_name])->values(),
            'records' => (int) ($pocket->scans_count ?? 0),
            'units' => (float) ($pocket->units ?? 0),
            'last_scan_at' => $pocket->last_scan_at ? \Illuminate\Support\Carbon::parse($pocket->last_scan_at)->toISOString() : null,
            'finished_at' => $pocket->finished_at?->toISOString(),
        ];
    }
}
