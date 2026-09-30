<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Asignación de inventariadores: el personal sin INV:VER_TODOS solo ve lo asignado.
 */
class InventoryAssignmentTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $assigned;

    private Inventory $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();

        $company = $this->company();
        $this->assigned = Inventory::create(['company_id' => $company->id, 'code' => 'INV-1', 'name' => 'Asignado']);
        $this->other = Inventory::create(['company_id' => $company->id, 'code' => 'INV-2', 'name' => 'Otro']);
    }

    private function url(Inventory $inventory, string $path = ''): string
    {
        return "/api/v1/inventories/{$inventory->id}/assignees{$path}";
    }

    public function test_el_inventariador_solo_ve_los_inventarios_asignados(): void
    {
        $inventariador = $this->makeUser('INVENTARIADOR');
        $headers = $this->authHeaders($inventariador);

        // Sin asignación no ve ninguno (ni en la web ni en la app).
        $this->getJson('/api/v1/inventories?only_open=1', $headers)->assertOk()->assertJsonPath('meta.total', 0);

        $this->assigned->assignees()->attach($inventariador->id);

        $this->getJson('/api/v1/inventories?only_open=1', $headers)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'INV-1');
        $this->getJson("/api/v1/inventories/{$this->assigned->id}", $headers)->assertOk();
    }

    public function test_sin_asignacion_no_puede_enviar_lecturas_y_la_app_recibe_un_codigo(): void
    {
        $headers = $this->authHeaders($this->makeUser('INVENTARIADOR'));

        $this->postJson("/api/v1/inventories/{$this->other->id}/scans/batch", ['scans' => [
            ['uuid' => (string) Str::uuid(), 'code' => 'A-1', 'scanned_at' => now()->toISOString()],
        ]], $headers)
            ->assertForbidden()
            ->assertJsonPath('code', 'INVENTORY_NOT_ASSIGNED');

        $this->getJson("/api/v1/inventories/{$this->other->id}/scan-base", $headers)->assertForbidden();
    }

    public function test_administrador_y_contable_ven_todos_sin_asignacion(): void
    {
        foreach ([$this->adminUser(), $this->makeUser('CONTABLE')] as $user) {
            $this->getJson('/api/v1/inventories', $this->authHeaders($user))->assertJsonPath('meta.total', 2);
        }
    }

    public function test_el_cliente_sigue_viendo_los_de_su_empresa_sin_asignacion(): void
    {
        $client = $this->makeUser('CLIENTE', [], $this->assigned->company);

        $this->getJson('/api/v1/inventories', $this->authHeaders($client))->assertJsonPath('meta.total', 2);
    }

    public function test_asignar_listar_y_quitar(): void
    {
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $luis = $this->makeUser('INVENTARIADOR', ['first_name' => 'Luis', 'last_name' => 'Huamán']);
        $ana = $this->makeUser('INVENTARIADOR', ['first_name' => 'Ana', 'last_name' => 'Rojas']);

        $this->getJson($this->url($this->assigned, '/candidates?search=luis'), $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.assigned', false);

        $this->postJson($this->url($this->assigned), ['user_ids' => [$luis->id, $ana->id]], $headers)->assertOk();
        // Reasignar no duplica.
        $this->postJson($this->url($this->assigned), ['user_ids' => [$luis->id]], $headers)->assertOk();

        $this->getJson($this->url($this->assigned), $headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.full_name', 'Ana Rojas')
            ->assertJsonPath('data.0.roles.0', 'INVENTARIADOR');
        $this->getJson('/api/v1/inventories?search=INV-1', $headers)->assertJsonPath('data.0.assignees_count', 2);

        $this->deleteJson($this->url($this->assigned, "/{$ana->id}"), [], $headers)->assertOk();
        $this->assertSame([$luis->id], $this->assigned->assignees()->pluck('users.id')->all());
    }

    public function test_solo_se_asigna_personal_interno_activo_que_escanea(): void
    {
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $client = $this->makeUser('INVENTARIADOR', [], $this->assigned->company);   // usuario de la empresa
        $inactive = $this->makeUser('INVENTARIADOR', ['is_active' => false]);
        $noScan = $this->makeUser('CLIENTE');                                      // interno sin permiso de escanear

        foreach ([$client, $inactive, $noScan] as $user) {
            $this->postJson($this->url($this->assigned), ['user_ids' => [$user->id]], $headers)
                ->assertUnprocessable()
                ->assertJsonValidationErrors('user_ids');
        }
        $this->assertSame(0, $this->assigned->assignees()->count());
    }

    public function test_asignar_requiere_permiso_e_inventario_abierto(): void
    {
        $inventariador = $this->makeUser('INVENTARIADOR');
        $this->assigned->assignees()->attach($inventariador->id);

        $this->postJson($this->url($this->assigned), ['user_ids' => [$inventariador->id]], $this->authHeaders($inventariador))->assertForbidden();

        $this->assigned->update(['status' => 'CERRADO']);
        $this->postJson($this->url($this->assigned), ['user_ids' => [$inventariador->id]], $this->authHeaders($this->makeUser('CONTABLE')))
            ->assertUnprocessable();
    }
}
