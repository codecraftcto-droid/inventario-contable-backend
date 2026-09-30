<?php

namespace App\Http\Controllers;

use App\Enums\InventoryStatus;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Companies\Models\Company;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Services\InventoryDashboardService;

class DashboardController extends Controller
{
    /**
     * Dashboard del inicio, siempre enfocado en UN inventario:
     *  - Personal interno: elige empresa y luego uno de sus inventarios.
     *  - Usuario de una empresa (cliente): elige entre los inventarios de su empresa.
     * Por defecto se toma la empresa con el inventario más reciente y su inventario más reciente.
     * Solo se ofrecen los inventarios que el usuario puede ver.
     */
    public function index(Request $request, InventoryDashboardService $dashboard): JsonResponse
    {
        $request->validate([
            'company_id' => ['nullable', 'integer'],
            'inventory_id' => ['nullable', 'integer'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $visible = Inventory::query()->visibleTo($user);
        $internal = $user->canSeeAllCompanies();

        // --- Empresa ---
        $companies = $internal ? $this->companiesWithInventories(clone $visible) : collect();
        $companyId = $internal
            ? ($companies->firstWhere('id', $request->integer('company_id')) ?? $companies->first())['id'] ?? null
            : $user->company_id;

        // --- Inventario (más reciente primero) ---
        $inventories = $companyId === null ? collect() : (clone $visible)
            ->where('company_id', $companyId)
            ->latest('id')
            ->get(['id', 'company_id', 'code', 'name', 'status', 'created_at']);

        $selected = $inventories->firstWhere('id', $request->integer('inventory_id')) ?? $inventories->first();
        $inventory = $selected ? Inventory::with('company:id,name')->find($selected->id) : null;

        return response()->json([
            'data' => [
                'kpis' => $this->globalKpis($user, clone $visible),
                'filters' => [
                    'is_internal' => $internal,
                    'companies' => $companies->values(),
                    'company_id' => $companyId,
                    'inventories' => $inventories->map(fn (Inventory $item): array => [
                        'id' => $item->id,
                        'code' => $item->code,
                        'name' => $item->name,
                        'status' => $item->status->value,
                        'status_label' => $item->status->label(),
                        'created_at' => $item->created_at?->toISOString(),
                    ])->values(),
                    'inventory_id' => $inventory?->id,
                ],
                'inventory' => $inventory ? [
                    'id' => $inventory->id,
                    'code' => $inventory->code,
                    'name' => $inventory->name,
                    'company' => $inventory->company?->name,
                    'status' => $inventory->status->value,
                    'status_label' => $inventory->status->label(),
                    'start_date' => $inventory->start_date?->toDateString(),
                    'started_at' => $inventory->started_at?->toISOString(),
                    'paused_at' => $inventory->paused_at?->toISOString(),
                    'closed_at' => $inventory->closed_at?->toISOString(),
                ] : null,
                'stats' => $inventory ? $dashboard->stats($inventory) : null,
            ],
        ]);
    }

    /**
     * Empresas con al menos un inventario visible, la del inventario más reciente primero.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    private function companiesWithInventories($visible): Collection
    {
        $latest = $visible->selectRaw('company_id, MAX(id) AS last_id')->groupBy('company_id')->pluck('last_id', 'company_id');
        $names = Company::whereIn('id', $latest->keys())->pluck('name', 'id');

        return $latest->sortDesc()
            ->map(fn (int $lastId, int $companyId): array => ['id' => $companyId, 'name' => $names[$companyId] ?? '—'])
            ->values();
    }

    /**
     * Resumen general (inventarios por estado; al personal interno también empresas y usuarios).
     */
    private function globalKpis(User $user, $visible): array
    {
        $byStatus = $visible->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $kpis = [];

        if ($user->canSeeAllCompanies()) {
            $kpis[] = ['key' => 'companies', 'label' => 'Empresas activas', 'value' => Company::where('is_active', true)->count()];
            $kpis[] = ['key' => 'client_users', 'label' => 'Usuarios de clientes', 'value' => User::whereNotNull('company_id')->where('is_active', true)->count()];
        }

        $labels = [
            InventoryStatus::PENDIENTE->value => 'Inventarios sin iniciar',
            InventoryStatus::EN_PROCESO->value => 'Inventarios en proceso',
            InventoryStatus::PAUSADO->value => 'Inventarios en pausa',
            InventoryStatus::CERRADO->value => 'Inventarios finalizados',
        ];

        foreach (InventoryStatus::cases() as $status) {
            $kpis[] = ['key' => 'inventories_'.strtolower($status->value), 'label' => $labels[$status->value], 'value' => (int) ($byStatus[$status->value] ?? 0)];
        }

        return $kpis;
    }
}
