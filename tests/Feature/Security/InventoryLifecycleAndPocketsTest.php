<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryPocket;
use Modules\Inventories\Models\InventoryScan;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Botones del inventario (iniciar, pausar, reanudar, finalizar) y pockets asignados a usuarios.
 */
class InventoryLifecycleAndPocketsTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $inventory;

    private array $contable;

    private User $luis;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        $this->inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'Toma']);
        $this->inventory->assets()->create(['codigo' => 'A-1', 'nombre' => 'Laptop', 'cantidad' => 2]);
        $this->contable = $this->authHeaders($this->makeUser('CONTABLE'));
        $this->luis = $this->makeUser('INVENTARIADOR', ['first_name' => 'Luis']);
    }

    private function url(string $path): string
    {
        return "/api/v1/inventories/{$this->inventory->id}{$path}";
    }

    private function push(array $headers, array $overrides = [])
    {
        return $this->postJson($this->url('/scans/batch'), ['scans' => [[
            'uuid' => (string) Str::uuid(), 'code' => 'A-1', 'quantity' => 1, 'scanned_at' => now()->toISOString(), ...$overrides,
        ]]], $headers);
    }

    // ---------------------------------------------------------------- Ciclo

    public function test_ciclo_iniciar_pausar_reanudar_finalizar_con_historial(): void
    {
        $this->postJson($this->url('/start'), [], $this->contable)->assertOk()->assertJsonPath('data.status', 'EN_PROCESO')->assertJsonPath('data.status_label', 'En proceso');
        $this->postJson($this->url('/pause'), [], $this->contable)->assertOk()->assertJsonPath('data.status', 'PAUSADO');
        $this->postJson($this->url('/resume'), [], $this->contable)->assertOk()->assertJsonPath('data.status', 'EN_PROCESO');
        $this->postJson($this->url('/close'), [], $this->contable)->assertOk()->assertJsonPath('data.status_label', 'Finalizado');

        $this->assertSame(
            ['FINALIZAR', 'REANUDAR', 'PAUSAR', 'INICIAR'],
            array_column($this->getJson($this->url('/history'), $this->contable)->json('data'), 'action'),
        );
        $this->assertNotNull($this->inventory->fresh()->started_at);
    }

    public function test_transiciones_invalidas_se_rechazan_con_mensaje(): void
    {
        $this->postJson($this->url('/pause'), [], $this->contable)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status' => 'No se puede pausar un inventario pendiente.']);
        $this->postJson($this->url('/resume'), [], $this->contable)->assertUnprocessable();

        $this->postJson($this->url('/start'), [], $this->contable)->assertOk();
        $this->postJson($this->url('/start'), [], $this->contable)->assertUnprocessable();
    }

    public function test_el_inventariador_no_controla_el_ciclo(): void
    {
        $this->inventory->assignees()->attach($this->luis->id);
        $this->postJson($this->url('/start'), [], $this->authHeaders($this->luis))->assertForbidden();
    }

    public function test_sin_iniciar_o_en_pausa_no_se_registra_pero_el_equipo_conserva_sus_registros(): void
    {
        $this->push($this->contable)->assertStatus(409)->assertJsonPath('code', 'INVENTORY_NOT_STARTED');

        $this->postJson($this->url('/start'), [], $this->contable)->assertOk();
        $this->push($this->contable)->assertOk();

        $this->postJson($this->url('/pause'), [], $this->contable)->assertOk();
        $this->push($this->contable)->assertStatus(409)->assertJsonPath('code', 'INVENTORY_PAUSED');

        // El mismo registro, reenviado al reanudar, entra.
        $this->postJson($this->url('/resume'), [], $this->contable)->assertOk();
        $this->push($this->contable)->assertOk();
        $this->assertSame(2, InventoryScan::count());
    }

    // --------------------------------------------------------------- Pockets

    public function test_crear_pockets_uno_a_uno_y_en_bloque(): void
    {
        $site = $this->inventory->company->sites()->create(['code' => 'PL', 'name' => 'Planta']);

        $this->postJson($this->url('/pockets'), ['code' => ' p-01 ', 'name' => 'Rack A', 'site_id' => $site->id, 'user_ids' => [$this->luis->id]], $this->contable)
            ->assertCreated()
            ->assertJsonPath('data.code', 'P-01')
            ->assertJsonPath('data.site.name', 'Planta')
            ->assertJsonPath('data.users.0.full_name', $this->luis->full_name);
        // Asignar el pocket lo asigna al inventario (si no, no lo vería).
        $this->assertTrue($this->inventory->isAssignedTo($this->luis));

        $this->postJson($this->url('/pockets'), ['code' => 'P-01'], $this->contable)->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->postJson($this->url('/pockets/bulk'), ['prefix' => 'P-', 'from' => 1, 'to' => 5], $this->contable)
            ->assertCreated()
            ->assertJsonPath('data.created', 4);   // P-01 ya existía
        $this->getJson($this->url('/pockets?per_page=50'), $this->contable)->assertJsonPath('meta.total', 5);
    }

    public function test_el_telefono_ve_solo_sus_pockets_y_el_registro_queda_enlazado(): void
    {
        $this->postJson($this->url('/start'), [], $this->contable)->assertOk();
        $mine = InventoryPocket::create(['inventory_id' => $this->inventory->id, 'code' => 'P-01']);
        InventoryPocket::create(['inventory_id' => $this->inventory->id, 'code' => 'P-02']);
        $this->putJson($this->url("/pockets/{$mine->id}"), ['code' => 'P-01', 'user_ids' => [$this->luis->id]], $this->contable)->assertOk();
        $luis = $this->authHeaders($this->luis);

        $this->getJson($this->url('/pockets/mine'), $luis)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.code', 'P-01')
            ->assertJsonPath('meta.inventory_has_pockets', true);
        // El supervisor / contable ve todos.
        $this->getJson($this->url('/pockets/mine'), $this->contable)->assertJsonCount(2, 'data');

        $this->push($luis, ['pocket_id' => $mine->id, 'pocket' => 'otro'])->assertOk();

        $scan = InventoryScan::first();
        $this->assertSame([$mine->id, 'P-01'], [$scan->pocket_id, $scan->pocket]);
        $this->assertSame('EN_CONTEO', $mine->fresh()->status->value);
        $this->getJson($this->url('/pockets?search=P-01'), $this->contable)
            ->assertJsonPath('data.0.records', 1)
            ->assertJsonPath('data.0.units', 1);
    }

    public function test_terminar_y_reabrir_pocket(): void
    {
        $pocket = InventoryPocket::create(['inventory_id' => $this->inventory->id, 'code' => 'P-01']);
        $pocket->users()->attach($this->luis->id);
        $this->inventory->assignees()->attach($this->luis->id);
        $otro = $this->makeUser('INVENTARIADOR');
        $this->inventory->assignees()->attach($otro->id);

        $this->postJson($this->url("/pockets/{$pocket->id}/finish"), [], $this->authHeaders($otro))->assertForbidden();
        $this->postJson($this->url("/pockets/{$pocket->id}/finish"), [], $this->authHeaders($this->luis))->assertOk()->assertJsonPath('data.status', 'TERMINADO');

        $this->postJson($this->url("/pockets/{$pocket->id}/reopen"), [], $this->contable)->assertOk()->assertJsonPath('data.status', 'PENDIENTE');
    }

    public function test_un_pocket_con_registros_no_se_elimina(): void
    {
        $this->postJson($this->url('/start'), [], $this->contable)->assertOk();
        $pocket = InventoryPocket::create(['inventory_id' => $this->inventory->id, 'code' => 'P-01']);
        $this->push($this->contable, ['pocket_id' => $pocket->id])->assertOk();

        $this->deleteJson($this->url("/pockets/{$pocket->id}"), [], $this->contable)->assertUnprocessable();
    }

    public function test_el_dashboard_cuenta_los_inventarios_en_pausa(): void
    {
        $this->postJson($this->url('/start'), [], $this->contable)->assertOk();
        $this->postJson($this->url('/pause'), [], $this->contable)->assertOk();

        $kpis = collect($this->getJson('/api/v1/dashboard', $this->contable)->assertOk()->json('data.kpis'))->keyBy('key');
        $this->assertSame(1, $kpis['inventories_pausado']['value']);
        $this->assertSame('Inventarios en pausa', $kpis['inventories_pausado']['label']);
    }
}
