<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

class InventoryScanTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();

        $this->inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'Toma', 'status' => 'EN_PROCESO']);
        foreach (['A-1', 'a-2 ', 'A-3'] as $code) {
            $this->inventory->assets()->create(['codigo' => $code, 'nombre' => "Activo $code", 'unidad_medida' => 'UND']);
        }
    }

    /**
     * Inventariador asignado al inventario de la prueba (sin asignación no lo vería).
     */
    private function inventariador(): \App\Models\User
    {
        $user = $this->makeUser('INVENTARIADOR');
        $this->inventory->assignees()->attach($user->id);

        return $user;
    }

    private function scan(string $code, ?string $uuid = null): array
    {
        return ['uuid' => $uuid ?? (string) Str::uuid(), 'code' => $code, 'location' => 'Almacén', 'scanned_at' => now()->toISOString()];
    }

    private function push(array $headers, array $scans)
    {
        return $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/batch", ['scans' => $scans], $headers);
    }

    public function test_el_servidor_clasifica_en_base_o_sobrante_y_el_mismo_codigo_se_puede_registrar_varias_veces(): void
    {
        $headers = $this->authHeaders($this->inventariador());

        $outcomes = $this->push($headers, [$this->scan('a-1'), $this->scan('A-1'), $this->scan('A-2'), $this->scan('ZZ-9'), $this->scan('zz-9')])
            ->assertOk()
            ->json('data.accepted.*.outcome');

        // Códigos normalizados (mayúsculas / espacios). Cada registro cuenta (p. ej. el mismo
        // producto en dos sedes): ya no hay "repetidos".
        $this->assertSame(['FOUND', 'FOUND', 'FOUND', 'SURPLUS', 'SURPLUS'], $outcomes);
        $this->assertSame('EN_PROCESO', $this->inventory->fresh()->status->value);
    }

    public function test_reenviar_el_mismo_lote_no_duplica_lecturas(): void
    {
        // Escenario: el servidor guardó el lote pero la respuesta no llegó al celular (se cortó la red).
        $headers = $this->authHeaders($this->inventariador());
        $batch = [$this->scan('A-1'), $this->scan('A-3')];

        $first = $this->push($headers, $batch)->assertOk()->json('data.accepted');
        $retry = $this->push($headers, $batch)->assertOk()->json('data.accepted');

        $this->assertSame($first, $retry);
        $this->assertSame(2, InventoryScan::count());
    }

    public function test_dos_equipos_registran_el_mismo_producto_se_cuenta_un_producto_encontrado(): void
    {
        $celular1 = $this->authHeaders($this->inventariador(), ['platform' => 'MOVIL', 'device_id' => 'CEL-1']);
        $celular2 = $this->authHeaders($this->inventariador(), ['platform' => 'MOVIL', 'device_id' => 'CEL-2']);

        $this->push($celular1, [$this->scan('A-1')])->assertJsonPath('data.accepted.0.outcome', 'FOUND');
        $this->push($celular2, [$this->scan('A-1')])->assertJsonPath('data.accepted.0.outcome', 'FOUND');

        $this->assertSame(['CEL-1', 'CEL-2'], InventoryScan::orderBy('id')->pluck('device_id')->all());
        $this->getJson('/api/v1/inventories', $celular1)->assertJsonPath('data.0.found_count', 1);
    }

    public function test_inventario_cerrado_rechaza_lecturas(): void
    {
        $this->inventory->update(['status' => 'CERRADO']);

        $this->push($this->authHeaders($this->inventariador()), [$this->scan('A-1')])
            ->assertStatus(409)
            ->assertJsonPath('code', 'INVENTORY_CLOSED');
    }

    public function test_descarga_de_la_base_contable_por_cursor(): void
    {
        $headers = $this->authHeaders($this->inventariador());
        $url = "/api/v1/inventories/{$this->inventory->id}/scan-base";

        $first = $this->getJson("$url?limit=2", $headers)->assertOk()->assertJsonPath('meta.has_more', true)->assertJsonPath('meta.total', 3);
        $this->assertSame(['A-1', 'A-2'], $first->json('data.*.code'));

        $next = $this->getJson("$url?limit=2&after_id={$first->json('meta.next_after_id')}", $headers)->assertOk();
        $this->assertSame(['A-3'], $next->json('data.*.code'));
        $this->assertFalse($next->json('meta.has_more'));
    }

    public function test_descarga_las_lecturas_de_otros_equipos(): void
    {
        $headers = $this->authHeaders($this->inventariador());
        $this->push($headers, [$this->scan('A-1'), $this->scan('X-1')]);

        $this->getJson("/api/v1/inventories/{$this->inventory->id}/scans?after_id=0", $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.outcome', 'FOUND');
    }

    public function test_permisos_y_aislamiento_por_empresa(): void
    {
        // CLIENTE ve su inventario pero no puede registrar lecturas.
        $cliente = $this->authHeaders($this->makeUser('CLIENTE', company: $this->inventory->company));
        $this->push($cliente, [$this->scan('A-1')])->assertStatus(403);

        // Usuario de otra empresa: el inventario "no existe".
        $otro = $this->authHeaders($this->makeUser('CLIENTE', company: $this->company()));
        $this->getJson("/api/v1/inventories/{$this->inventory->id}/scans", $otro)->assertStatus(404);
    }

    public function test_lote_invalido_se_rechaza_completo(): void
    {
        $this->push($this->authHeaders($this->inventariador()), [['uuid' => 'no-es-uuid', 'code' => '', 'scanned_at' => 'x']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['scans.0.uuid', 'scans.0.code', 'scans.0.scanned_at']);
    }
}
