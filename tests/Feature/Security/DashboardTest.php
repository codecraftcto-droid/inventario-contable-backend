<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryPocket;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Dashboard del inicio: siempre muestra un inventario; empresa e inventario por defecto = los más recientes.
 */
class DashboardTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
    }

    public function test_personal_interno_elige_empresa_y_luego_inventario_por_defecto_los_mas_recientes(): void
    {
        $antigua = $this->company(['name' => 'Antigua SAC']);
        $reciente = $this->company(['name' => 'Reciente SAC']);
        $a1 = Inventory::create(['company_id' => $antigua->id, 'code' => 'A-1', 'name' => 'Antiguo 1']);
        $r1 = Inventory::create(['company_id' => $reciente->id, 'code' => 'R-1', 'name' => 'Reciente 1']);
        $r2 = Inventory::create(['company_id' => $reciente->id, 'code' => 'R-2', 'name' => 'Reciente 2']);
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));

        $this->getJson('/api/v1/dashboard', $headers)
            ->assertOk()
            ->assertJsonPath('data.filters.companies.0.name', 'Reciente SAC')
            ->assertJsonPath('data.filters.companies.1.name', 'Antigua SAC')
            ->assertJsonPath('data.filters.company_id', $reciente->id)
            ->assertJsonPath('data.filters.inventories.0.id', $r2->id)
            ->assertJsonPath('data.filters.inventories.1.id', $r1->id)
            ->assertJsonPath('data.inventory.id', $r2->id);

        // Al cambiar de empresa se toma su inventario más reciente.
        $this->getJson("/api/v1/dashboard?company_id={$antigua->id}", $headers)
            ->assertJsonPath('data.filters.inventories.0.id', $a1->id)
            ->assertJsonCount(1, 'data.filters.inventories')
            ->assertJsonPath('data.inventory.code', 'A-1');

        $this->getJson("/api/v1/dashboard?company_id={$reciente->id}&inventory_id={$r1->id}", $headers)
            ->assertJsonPath('data.inventory.code', 'R-1');

        // Un inventario de otra empresa no se mezcla: se vuelve al más reciente de la empresa elegida.
        $this->getJson("/api/v1/dashboard?company_id={$reciente->id}&inventory_id={$a1->id}", $headers)
            ->assertJsonPath('data.inventory.id', $r2->id);
    }

    public function test_el_usuario_de_una_empresa_solo_ve_los_inventarios_de_su_empresa(): void
    {
        $mia = $this->company();
        $otra = $this->company();
        $m1 = Inventory::create(['company_id' => $mia->id, 'code' => 'M-1', 'name' => 'Mío 1']);
        $m2 = Inventory::create(['company_id' => $mia->id, 'code' => 'M-2', 'name' => 'Mío 2']);
        $ajeno = Inventory::create(['company_id' => $otra->id, 'code' => 'O-1', 'name' => 'Ajeno']);
        $headers = $this->authHeaders($this->makeUser('CLIENTE', company: $mia));

        $this->getJson("/api/v1/dashboard?company_id={$otra->id}&inventory_id={$ajeno->id}", $headers)
            ->assertOk()
            ->assertJsonCount(0, 'data.filters.companies')     // sin combo de empresas
            ->assertJsonPath('data.filters.company_id', $mia->id)
            ->assertJsonPath('data.filters.inventories.*.id', [$m2->id, $m1->id])
            ->assertJsonPath('data.inventory.id', $m2->id);
    }

    public function test_el_inventariador_solo_ve_los_inventarios_asignados(): void
    {
        $empresa = $this->company();
        $asignado = Inventory::create(['company_id' => $empresa->id, 'code' => 'I-1', 'name' => 'Asignado']);
        Inventory::create(['company_id' => $this->company()->id, 'code' => 'I-2', 'name' => 'No asignado']);
        $luis = $this->makeUser('INVENTARIADOR');
        $asignado->assignees()->attach($luis->id);

        $this->getJson('/api/v1/dashboard', $this->authHeaders($luis))
            ->assertJsonCount(1, 'data.filters.companies')
            ->assertJsonPath('data.filters.inventories.*.code', ['I-1'])
            ->assertJsonPath('data.inventory.code', 'I-1');
    }

    public function test_sin_inventarios_no_falla(): void
    {
        $this->getJson('/api/v1/dashboard', $this->authHeaders($this->makeUser('CONTABLE')))
            ->assertOk()
            ->assertJsonPath('data.inventory', null)
            ->assertJsonPath('data.stats', null);
    }

    public function test_indicadores_y_series_del_inventario(): void
    {
        $empresa = $this->company();
        $site = $empresa->sites()->create(['code' => 'PL', 'name' => 'Planta']);
        $inventory = Inventory::create(['company_id' => $empresa->id, 'code' => 'INV-1', 'name' => 'Toma', 'status' => 'EN_PROCESO']);
        $inventory->assets()->createMany([
            ['codigo' => 'A-1', 'nombre' => 'Laptop', 'cantidad' => 2],
            ['codigo' => 'A-2', 'nombre' => 'Silla', 'cantidad' => 5],
            ['codigo' => 'A-3', 'nombre' => 'Mesa', 'cantidad' => 1],
            ['codigo' => 'A-4', 'nombre' => 'Monitor'],
        ]);
        $pocket = InventoryPocket::create(['inventory_id' => $inventory->id, 'code' => 'P-01']);
        $user = $this->makeUser('CONTABLE');
        $headers = $this->authHeaders($user);

        $scan = fn (string $code, float $qty, string $when, ?string $condition = 'BUENO') => $this->postJson("/api/v1/inventories/{$inventory->id}/scans/batch", ['scans' => [[
            'uuid' => (string) Str::uuid(), 'code' => $code, 'quantity' => $qty, 'scanned_at' => $when,
            'condition' => $condition, 'site_id' => $site->id, 'pocket_id' => $pocket->id,
        ]]], $headers)->assertOk();

        $scan('A-1', 2, '2026-09-26T10:00:00-05:00');                 // conciliado
        $scan('A-2', 3, '2026-09-27T10:00:00-05:00', 'MALO');         // diferencia negativa (-2)
        $scan('A-3', 1, '2026-09-27T11:00:00-05:00');
        $scan('A-3', 1, '2026-09-27T12:00:00-05:00', 'REGULAR');      // diferencia positiva (+1)
        $scan('X-9', 4, '2026-09-27T12:30:00-05:00');                 // sobrante

        $stats = $this->getJson('/api/v1/dashboard', $headers)->assertOk()->json('data.stats');

        $this->assertSame(['products' => 4, 'units' => 9], array_map('intval', $stats['base']));
        $this->assertSame(5, $stats['records']);
        $this->assertEquals(11, $stats['units']);
        $this->assertSame(3, $stats['found_products']);
        $this->assertEquals(75, $stats['progress']);

        $reconciliation = collect($stats['reconciliation'])->pluck('products', 'status')->all();
        $this->assertSame(['CONCILIADO' => 1, 'FALTANTE' => 1, 'SOBRANTE' => 1, 'DIFERENCIA_POSITIVA' => 1, 'DIFERENCIA_NEGATIVA' => 1], $reconciliation);

        // Avance diario acumulado: día 1 → 1 de 4 productos; día 2 → 3 de 4.
        $this->assertSame(['2026-09-26', '2026-09-27'], array_column($stats['timeline'], 'date'));
        $this->assertSame([1, 4], array_column($stats['timeline'], 'records'));
        $this->assertSame([1, 2], array_column($stats['timeline'], 'new_products'));
        $this->assertEquals([25, 75], array_column($stats['timeline'], 'progress'));

        $this->assertSame([3, 1, 1], array_column($stats['by_condition'], 'records'));   // BUENO, REGULAR, MALO
        $this->assertSame('Planta', $stats['by_site'][0]['site']);
        $this->assertSame(5, $stats['by_user'][0]['records']);
        $this->assertSame(['PENDIENTE' => 0, 'EN_CONTEO' => 1, 'TERMINADO' => 0], $stats['pockets']['statuses']);

        // Mayores diferencias primero (sobrante +4, luego faltante -1 / negativa -2…)
        $this->assertSame('X-9', $stats['top_differences'][0]['code']);
        $this->assertNotContains('A-1', array_column($stats['top_differences'], 'code'));
    }
}
