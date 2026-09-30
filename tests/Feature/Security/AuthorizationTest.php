<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Module;
use Modules\Inventories\Models\Inventory;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
    }

    public function test_endpoint_sin_el_permiso_requerido_responde_403(): void
    {
        $inventariador = $this->makeUser('INVENTARIADOR');   // INV:VER + INV_TOMA: VER, ESCANEAR, SINCRONIZAR
        $headers = $this->authHeaders($inventariador);

        $this->getJson('/api/v1/inventories', $headers)->assertOk();
        $this->postJson('/api/v1/inventories', [], $headers)->assertStatus(403)->assertJsonPath('required', ['INV:CREAR']);
        $this->getJson('/api/v1/security/users', $headers)->assertStatus(403);
    }

    public function test_superusuario_pasa_todos_los_permisos(): void
    {
        $headers = $this->authHeaders($this->adminUser());

        $this->getJson('/api/v1/security/users', $headers)->assertOk();
        $this->getJson('/api/v1/core/modules', $headers)->assertOk();
        $this->getJson('/api/v1/security/access-logs', $headers)->assertOk();
    }

    public function test_menu_web_incluye_padre_aunque_el_permiso_este_solo_en_el_hijo(): void
    {
        $role = Role::create(['name' => 'SOPORTE', 'guard_name' => 'api']);
        $role->givePermissionTo('SEG_USU:VER');
        $user = $this->makeUser('SOPORTE');

        $menu = $this->login($user)->assertOk()->json('data.menu');

        $this->assertSame(['SEG'], array_column($menu, 'code'));
        $this->assertSame(['SEG_USU'], array_column($menu[0]['children'], 'code'));
        $this->assertSame(['VER'], $menu[0]['children'][0]['actions']);
        $this->assertSame([], $menu[0]['actions']);
    }

    public function test_menu_se_filtra_por_plataforma(): void
    {
        $admin = $this->adminUser();

        $web = array_column($this->login($admin)->json('data.menu'), 'code');
        $movil = array_column($this->login($admin, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-1'])->json('data.menu'), 'code');

        $this->assertSame(['DASH', 'EMP', 'INV', 'SEG'], $web);
        $this->assertSame(['INV'], $movil);   // Solo módulos MOVIL o AMBOS
    }

    public function test_inventariador_tiene_acceso_web_y_movil(): void
    {
        $inventariador = $this->makeUser('INVENTARIADOR');

        $web = $this->login($inventariador)->assertOk()->json('data.menu');
        $movil = $this->login($inventariador, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-1'])->assertOk()->json('data.menu');

        $this->assertSame(['DASH', 'INV'], array_column($web, 'code'));
        $this->assertSame(['INV'], array_column($movil, 'code'));
        $this->assertSame([], $movil[0]['children']);   // Las opciones no van en el menú

        $permissions = $this->login($inventariador)->json('data.permissions');
        $this->assertContains('INV_TOMA:ESCANEAR', $permissions);
        $this->assertNotContains('INV_BASE:VER', $permissions);
    }

    public function test_cada_opcion_del_inventario_exige_su_propio_permiso(): void
    {
        $inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'A']);
        $base = "/api/v1/inventories/{$inventory->id}/accounting-base";
        $row = ['codigo' => 'A1', 'nombre' => 'PC', 'unidad_medida' => 'UND'];

        // INVENTARIADOR: no ve la base contable.
        $headers = $this->authHeaders($this->makeUser('INVENTARIADOR'));
        $this->getJson($base, $headers)->assertStatus(403)->assertJsonPath('required', ['INV_BASE:VER']);

        // CLIENTE: ve la base de su empresa, pero no la carga ni la modifica.
        $headers = $this->authHeaders($this->makeUser('CLIENTE', company: $inventory->company));
        $this->getJson($base, $headers)->assertOk();
        $this->postJson($base, $row, $headers)->assertStatus(403);
        $this->getJson('/api/v1/inventories/accounting-base-template', $headers)->assertStatus(403);

        // CONTABLE: todo.
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $this->postJson($base, $row, $headers)->assertCreated();
        $this->get('/api/v1/inventories/accounting-base-template', $headers)->assertOk()->assertDownload('plantilla-base-contable.xlsx');
        $this->get("{$base}/export", $headers)->assertOk()->assertDownload('base-contable-INV-1.xlsx');
    }

    public function test_matriz_muestra_las_opciones_como_filas_hijas_de_inventarios(): void
    {
        $matrix = $this->getJson('/api/v1/core/permissions', $this->authHeaders($this->adminUser()))->assertOk()->json('data');

        $inv = collect($matrix['modules'])->firstWhere('code', 'INV');
        $this->assertSame(['INV_BASE', 'INV_TOMA', 'INV_ASIG', 'INV_POCK'], array_column($inv['children'], 'code'));
        $this->assertFalse($inv['children'][0]['is_menu']);
        $this->assertArrayHasKey('IMPORTAR', $inv['children'][0]['permissions']);
    }

    public function test_menu_oculta_modulos_inactivos_y_sus_submodulos(): void
    {
        Module::where('code', 'SEG')->update(['is_active' => false]);

        $menu = array_column($this->login($this->adminUser())->json('data.menu'), 'code');

        $this->assertNotContains('SEG', $menu);
    }

    public function test_permisos_vienen_en_formato_modulo_accion(): void
    {
        $permissions = $this->login($this->makeUser('INVENTARIADOR'))->json('data.permissions');

        $this->assertSame(['DASH:VER', 'INV:VER', 'INV_TOMA:ESCANEAR', 'INV_TOMA:SINCRONIZAR', 'INV_TOMA:VER'], $permissions);
    }

    public function test_rol_inactivo_no_otorga_permisos(): void
    {
        Role::where('name', 'CONTABLE')->update(['is_active' => false]);
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));

        $this->getJson('/api/v1/inventories', $headers)->assertStatus(403);
    }

    public function test_usuario_cliente_solo_ve_inventarios_de_su_empresa(): void
    {
        $empresaA = $this->company();
        $empresaB = $this->company();
        $propio = Inventory::create(['company_id' => $empresaA->id, 'code' => 'INV-1', 'name' => 'Inventario A']);
        $ajeno = Inventory::create(['company_id' => $empresaB->id, 'code' => 'INV-1', 'name' => 'Inventario B']);

        $headers = $this->authHeaders($this->makeUser('CLIENTE', company: $empresaA));

        $this->getJson('/api/v1/inventories', $headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $propio->id);

        $this->getJson("/api/v1/inventories/{$ajeno->id}", $headers)->assertStatus(404);
        $this->getJson("/api/v1/inventories/{$ajeno->id}/accounting-base", $headers)->assertStatus(404);
        $this->getJson('/api/v1/companies', $headers)->assertStatus(403);   // CLIENTE no tiene EMP:VER
    }

    public function test_personal_interno_ve_inventarios_de_todas_las_empresas(): void
    {
        Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'A']);
        Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'B']);

        $this->getJson('/api/v1/inventories', $this->authHeaders($this->makeUser('CONTABLE')))
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
    }

    public function test_inventario_cerrado_no_admite_cargas(): void
    {
        $inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'A']);
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));

        $this->postJson("/api/v1/inventories/{$inventory->id}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'CERRADO');
        $this->postJson("/api/v1/inventories/{$inventory->id}/accounting-base", ['codigo' => 'A1', 'nombre' => 'PC'], $headers)
            ->assertStatus(422);
    }

    public function test_mismo_codigo_de_activo_en_inventarios_distintos(): void
    {
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));
        $a = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'A']);
        $b = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'B']);
        $asset = ['codigo' => 'A-001', 'nombre' => 'Laptop', 'unidad_medida' => 'UND'];

        $this->postJson("/api/v1/inventories/{$a->id}/accounting-base", $asset, $headers)->assertCreated();
        $this->postJson("/api/v1/inventories/{$b->id}/accounting-base", $asset, $headers)->assertCreated();
        $this->postJson("/api/v1/inventories/{$a->id}/accounting-base", $asset, $headers)->assertStatus(422)->assertJsonValidationErrors('codigo');
    }

    public function test_permiso_inexistente_niega_el_acceso(): void
    {
        Permission::where('name', 'INV:VER')->delete();

        $this->getJson('/api/v1/inventories', $this->authHeaders($this->makeUser('CONTABLE')))->assertStatus(403);
    }
}
