<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Opción "Toma de inventario" en la web: avance, listados, anular lecturas y resultado en Excel.
 */
class InventoryTakeTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();

        $this->inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'Toma', 'status' => 'EN_PROCESO']);
        foreach (['A-1', 'A-2', 'A-3', 'A-4'] as $code) {
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

    private function url(string $path = ''): string
    {
        return "/api/v1/inventories/{$this->inventory->id}/take{$path}";
    }

    private function push(array $headers, array $codes): void
    {
        $scans = array_map(fn (string $code): array => [
            'uuid' => (string) Str::uuid(), 'code' => $code, 'location' => 'Oficina 101', 'scanned_at' => now()->toISOString(),
        ], $codes);

        $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/batch", ['scans' => $scans], $headers)->assertOk();
    }

    public function test_avance_de_la_toma(): void
    {
        $headers = $this->authHeaders($this->inventariador());
        $this->push($headers, ['A-1', 'A-2', 'A-1', 'ZZ-9']);

        $this->getJson($this->url('/summary'), $headers)
            ->assertOk()
            ->assertJsonPath('data.total', 4)
            ->assertJsonPath('data.found', 2)
            ->assertJsonPath('data.missing', 2)
            ->assertJsonPath('data.surplus', 1)
            ->assertJsonPath('data.duplicates', 0)
            ->assertJsonPath('data.scans', 4)
            ->assertJsonPath('data.units', 3)          // A-1 dos veces + A-2 (cantidad 1 cada uno)
            ->assertJsonPath('data.progress', 50)
            ->assertJsonPath('data.readers.0.total', 4);
    }

    public function test_listados_de_lecturas_y_faltantes_paginados(): void
    {
        $headers = $this->authHeaders($this->inventariador());
        $this->push($headers, ['A-1', 'A-2', 'ZZ-9']);

        $this->getJson($this->url('/scans?outcome=SURPLUS'), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'ZZ-9');

        $this->getJson($this->url('/scans?search=a-1'), $headers)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.description', 'Activo A-1');

        $this->getJson($this->url('/missing?per_page=1'), $headers)
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.code', 'A-3');
    }

    public function test_anular_un_registro_el_producto_sigue_encontrado_si_tiene_otro(): void
    {
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $this->push($headers, ['A-1', 'A-1']);
        [$found, $duplicate] = InventoryScan::orderBy('id')->get()->all();

        $this->deleteJson($this->url("/scans/{$found->id}"), [], $headers)->assertOk();

        // El producto sigue encontrado gracias al otro registro.
        $this->assertSame('FOUND', $duplicate->fresh()->outcome->value);
        $this->getJson($this->url('/summary'), $headers)->assertJsonPath('data.found', 1)->assertJsonPath('data.duplicates', 0);
    }

    public function test_anular_lecturas_requiere_permiso_e_inventario_abierto(): void
    {
        $this->push($this->authHeaders($this->inventariador()), ['A-1']);
        $scan = InventoryScan::first();

        // El inventariador lee, pero no anula.
        $this->deleteJson($this->url("/scans/{$scan->id}"), [], $this->authHeaders($this->inventariador()))->assertForbidden();

        $this->inventory->update(['status' => 'CERRADO']);
        $this->deleteJson($this->url("/scans/{$scan->id}"), [], $this->authHeaders($this->makeUser('CONTABLE')))->assertUnprocessable();
        $this->assertSame(1, InventoryScan::count());
    }

    public function test_lectura_de_otro_inventario_no_se_puede_anular(): void
    {
        $other = Inventory::create(['company_id' => $this->inventory->company_id, 'code' => 'INV-2', 'name' => 'Otro', 'status' => 'EN_PROCESO']);
        $scan = $other->scans()->create([
            'client_uuid' => (string) Str::uuid(), 'code' => 'X', 'outcome' => 'SURPLUS', 'scanned_at' => now(),
            'user_id' => $this->adminUser()->id,
        ]);

        $this->deleteJson($this->url("/scans/{$scan->id}"), [], $this->authHeaders($this->makeUser('CONTABLE')))->assertNotFound();
    }

    public function test_cliente_solo_ve_la_toma_de_su_empresa(): void
    {
        $client = $this->makeUser('CLIENTE', [], $this->company(['code' => 'OTRA', 'name' => 'Otra SAC']));

        $this->getJson($this->url('/summary'), $this->authHeaders($client))->assertNotFound();
    }

    public function test_la_hora_utc_del_equipo_se_guarda_en_hora_de_lima(): void
    {
        $headers = $this->authHeaders($this->inventariador());
        $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/batch", ['scans' => [
            ['uuid' => (string) Str::uuid(), 'code' => 'A-1', 'scanned_at' => '2026-09-26T21:30:00.000Z'],
        ]], $headers)->assertOk();

        $this->assertSame('2026-09-26 16:30:00', InventoryScan::first()->getRawOriginal('scanned_at'));
        $this->getJson($this->url('/scans'), $headers)->assertJsonPath('data.0.scanned_at', '2026-09-26T21:30:00.000000Z');
    }

    public function test_exporta_el_resultado_en_excel(): void
    {
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $this->push($headers, ['A-1', 'ZZ-9']);

        $response = $this->get($this->url('/export'), $headers)->assertOk();

        $this->assertStringContainsString('toma-INV-1.xlsx', $response->headers->get('content-disposition'));
    }
}
