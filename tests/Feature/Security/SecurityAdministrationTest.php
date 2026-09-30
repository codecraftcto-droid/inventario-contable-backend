<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\Action;
use Modules\Core\Models\Module;
use Modules\Security\Models\UserSession;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

class SecurityAdministrationTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        $this->headers = $this->authHeaders($this->adminUser());
    }

    private function userPayload(array $overrides = []): array
    {
        return [
            'username' => 'jperez',
            'email' => 'jperez@example.com',
            'document_number' => '45678912',
            'first_name' => 'Juan',
            'last_name' => 'Pérez',
            'role_ids' => [Role::where('name', 'CONTABLE')->value('id')],
            ...$overrides,
        ];
    }

    public function test_crear_usuario_genera_clave_temporal_y_obliga_a_cambiarla(): void
    {
        $response = $this->postJson('/api/v1/security/users', $this->userPayload(), $this->headers)->assertCreated();

        $password = $response->json('data.temporary_password');
        $this->assertNotEmpty($password);
        $this->assertTrue($response->json('data.must_change_password'));

        $this->login('jperez', $password)->assertOk()->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_el_numero_de_documento_es_obligatorio_y_unico(): void
    {
        $this->postJson('/api/v1/security/users', $this->userPayload(['document_number' => null]), $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors('document_number');

        $this->postJson('/api/v1/security/users', $this->userPayload(['document_number' => '00000000']), $this->headers)   // Es el del admin
            ->assertStatus(422)->assertJsonValidationErrors('document_number');
    }

    public function test_el_tipo_de_usuario_se_deduce_de_la_empresa(): void
    {
        $company = $this->company();

        $this->postJson('/api/v1/security/users', $this->userPayload(), $this->headers)
            ->assertCreated()->assertJsonPath('data.user_type', 'INTERNO')->assertJsonPath('data.company', null);

        $this->postJson('/api/v1/security/users', $this->userPayload([
            'username' => 'cliente1', 'email' => 'cliente1@example.com', 'document_number' => '11111111', 'company_id' => $company->id,
            'role_ids' => [Role::where('name', 'CLIENTE')->value('id')],
        ]), $this->headers)->assertCreated()->assertJsonPath('data.user_type', 'CLIENTE')->assertJsonPath('data.company.id', $company->id);

        $this->getJson('/api/v1/security/users?user_type=CLIENTE', $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_usuario_de_empresa_respeta_limite_del_contrato(): void
    {
        $company = $this->company(['max_users' => 1]);
        $clientRole = Role::where('name', 'CLIENTE')->value('id');

        $this->postJson('/api/v1/security/users', $this->userPayload([
            'company_id' => $company->id, 'role_ids' => [$clientRole],
        ]), $this->headers)->assertCreated();

        $this->postJson('/api/v1/security/users', $this->userPayload([
            'company_id' => $company->id, 'role_ids' => [$clientRole],
            'username' => 'otro', 'email' => 'otro@example.com', 'document_number' => '22222222',
        ]), $this->headers)->assertStatus(422)->assertJsonValidationErrors('company_id');

        // Un usuario inactivo no ocupa cupo.
        $this->postJson('/api/v1/security/users', $this->userPayload([
            'company_id' => $company->id, 'role_ids' => [$clientRole],
            'username' => 'otro', 'email' => 'otro@example.com', 'document_number' => '22222222', 'is_active' => false,
        ]), $this->headers)->assertCreated();
    }

    public function test_no_se_asigna_rol_con_permisos_de_administracion_a_usuario_de_empresa(): void
    {
        $this->postJson('/api/v1/security/users', $this->userPayload([
            'company_id' => $this->company()->id,
            'role_ids' => [Role::where('name', 'ADMIN')->value('id')],
        ]), $this->headers)->assertStatus(422)->assertJsonValidationErrors('role_ids');

        // CONTABLE incluye EMP:VER (módulo de administración).
        $this->postJson('/api/v1/security/users', $this->userPayload([
            'company_id' => $this->company()->id,
            'role_ids' => [Role::where('name', 'CONTABLE')->value('id')],
        ]), $this->headers)->assertStatus(422)->assertJsonValidationErrors('role_ids');
    }

    public function test_usuario_de_empresa_nunca_recibe_permisos_de_administracion(): void
    {
        // Aunque por error su rol tenga permisos de Seguridad, se filtran.
        Role::where('name', 'CLIENTE')->first()->givePermissionTo('SEG_USU:VER');
        $user = $this->makeUser('CLIENTE', company: $this->company());

        $permissions = $this->login($user)->assertOk()->json('data.permissions');

        $this->assertNotContains('SEG_USU:VER', $permissions);
        $this->assertContains('INV:VER', $permissions);
    }

    public function test_desactivar_usuario_cierra_sus_sesiones(): void
    {
        $user = $this->makeUser('CONTABLE');
        $userHeaders = $this->authHeaders($user);

        $this->patchJson("/api/v1/security/users/{$user->id}/status", ['is_active' => false], $this->headers)->assertOk();

        $this->getJson('/api/v1/inventories', $userHeaders)->assertStatus(401);
        $this->assertSame(0, UserSession::where('user_id', $user->id)->active()->count());
    }

    public function test_desbloquear_y_resetear_clave(): void
    {
        $user = $this->makeUser('CONTABLE', ['locked_until' => now()->addHour(), 'failed_login_attempts' => 2]);

        $this->postJson("/api/v1/security/users/{$user->id}/unlock", [], $this->headers)->assertOk()->assertJsonPath('data.is_locked', false);

        $temporary = $this->postJson("/api/v1/security/users/{$user->id}/reset-password", [], $this->headers)->assertOk()->json('data.temporary_password');

        $this->login($user)->assertStatus(401);
        $this->login($user, $temporary)->assertOk()->assertJsonPath('data.user.must_change_password', true);
    }

    public function test_superusuario_no_se_puede_desactivar_ni_eliminar(): void
    {
        $admin = User::where('username', 'admin')->first();

        $this->patchJson("/api/v1/security/users/{$admin->id}/status", ['is_active' => false], $this->headers)->assertStatus(422);
        $this->deleteJson("/api/v1/security/users/{$admin->id}", [], $this->headers)->assertStatus(422);
    }

    public function test_revocar_sesion_de_un_dispositivo_perdido(): void
    {
        $user = $this->makeUser('INVENTARIADOR');
        $celular = $this->login($user, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-PERDIDO'])->json('data');
        $web = $this->authHeaders($user);

        $this->postJson("/api/v1/security/users/{$user->id}/sessions/revoke", ['device_id' => 'CEL-PERDIDO'], $this->headers)
            ->assertOk()->assertJsonPath('data.revoked', 1);

        $this->getJson('/api/v1/inventories', ['Authorization' => "Bearer {$celular['access_token']}"])->assertStatus(401);
        $this->postJson('/api/v1/security/auth/refresh', ['refresh_token' => $celular['refresh_token']])->assertStatus(401);
        $this->getJson('/api/v1/inventories', $web)->assertOk();   // La sesión web sigue viva
    }

    public function test_listado_de_sesiones_y_log_de_accesos(): void
    {
        $this->makeUser('CONTABLE', ['username' => 'maria']);
        $this->login('maria', 'mala');

        $this->getJson('/api/v1/security/sessions', $this->headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/security/access-logs?event=LOGIN_FALLIDO', $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.login', 'maria');
    }

    public function test_rol_de_sistema_no_se_elimina_y_rol_con_usuarios_tampoco(): void
    {
        $admin = Role::where('name', 'ADMIN')->first();
        $this->deleteJson("/api/v1/security/roles/{$admin->id}", [], $this->headers)->assertStatus(422);

        $this->makeUser('CONTABLE');
        $contable = Role::where('name', 'CONTABLE')->first();
        $this->deleteJson("/api/v1/security/roles/{$contable->id}", [], $this->headers)->assertStatus(422);
    }

    public function test_matriz_de_permisos_del_rol_y_asignacion(): void
    {
        $role = $this->postJson('/api/v1/security/roles', ['name' => 'auditor'], $this->headers)
            ->assertCreated()->assertJsonPath('data.name', 'AUDITOR')->json('data');

        $matrix = $this->getJson("/api/v1/security/roles/{$role['id']}/permissions", $this->headers)->assertOk()->json('data');
        $this->assertContains('ESCANEAR', array_column($matrix['actions'], 'code'));

        $inv = collect($matrix['modules'])->firstWhere('code', 'INV');
        $this->assertFalse($inv['permissions']['VER']['granted']);
        $this->assertArrayNotHasKey('VER', collect($matrix['modules'])->firstWhere('code', 'SEG')['permissions']);   // Agrupador sin acciones

        $this->putJson("/api/v1/security/roles/{$role['id']}/permissions", [
            'permission_ids' => [$inv['permissions']['VER']['permission_id'], $inv['permissions']['EXPORTAR']['permission_id']],
        ], $this->headers)->assertOk()->assertJsonPath('data.permissions_count', 2);
    }

    public function test_rol_asignado_a_usuarios_de_empresa_no_recibe_permisos_de_administracion(): void
    {
        $role = Role::where('name', 'CLIENTE')->first();
        $this->makeUser('CLIENTE', company: $this->company());

        $this->putJson("/api/v1/security/roles/{$role->id}/permissions", [
            'permission_ids' => [Permission::where('name', 'SEG_USU:VER')->value('id')],
        ], $this->headers)->assertStatus(422)->assertJsonValidationErrors('permission_ids');
    }

    public function test_crud_de_modulos_con_jerarquia_y_acciones(): void
    {
        $inv = Module::where('code', 'INV')->first();
        $actions = Action::whereIn('code', ['VER', 'ESCANEAR'])->pluck('id')->all();

        $child = $this->postJson('/api/v1/core/modules', [
            'parent_id' => $inv->id, 'code' => 'inv_rep', 'name' => 'Reportes', 'platform' => 'WEB', 'action_ids' => $actions,
        ], $this->headers)->assertCreated()->json('data');

        $this->assertTrue(Permission::where('name', 'INV_REP:ESCANEAR')->exists());

        // Cambiar el código renombra sus permisos.
        $this->putJson("/api/v1/core/modules/{$child['id']}", [
            'parent_id' => $inv->id, 'code' => 'INV_RPT', 'name' => 'Reportes', 'platform' => 'WEB',
        ], $this->headers)->assertOk();
        $this->assertTrue(Permission::where('name', 'INV_RPT:VER')->exists());

        // No se permite un ciclo en la jerarquía.
        $this->putJson("/api/v1/core/modules/{$inv->id}", [
            'parent_id' => $child['id'], 'code' => 'INV', 'name' => 'Inventarios', 'platform' => 'AMBOS',
        ], $this->headers)->assertStatus(422)->assertJsonValidationErrors('parent_id');

        $tree = $this->getJson('/api/v1/core/modules', $this->headers)->assertOk()->json('data');
        $invNode = collect($tree)->firstWhere('code', 'INV');
        $this->assertSame('INV_RPT', $invNode['children'][0]['code']);

        $this->deleteJson("/api/v1/core/modules/{$inv->id}", [], $this->headers)->assertStatus(422);   // Base del sistema
        $this->deleteJson("/api/v1/core/modules/{$child['id']}", [], $this->headers)->assertOk();
        $this->assertFalse(Permission::where('name', 'INV_RPT:VER')->exists());
    }

    public function test_habilitar_acciones_de_un_modulo_desde_permisos(): void
    {
        $emp = Module::where('code', 'EMP')->first();
        $ids = Action::whereIn('code', ['VER', 'CREAR'])->pluck('id')->all();

        $this->putJson("/api/v1/core/modules/{$emp->id}/actions", ['action_ids' => $ids], $this->headers)->assertOk();

        $this->assertSame(['EMP:CREAR', 'EMP:VER'], Permission::where('module_id', $emp->id)->orderBy('name')->pluck('name')->all());

        // Un permiso nuevo se asigna solo al rol ADMIN.
        $this->putJson("/api/v1/core/modules/{$emp->id}/actions", ['action_ids' => [...$ids, Action::where('code', 'IMPORTAR')->value('id')]], $this->headers)->assertOk();
        $this->assertTrue(Role::where('name', 'ADMIN')->first()->hasPermissionTo('EMP:IMPORTAR'));
    }

    public function test_empresa_no_reduce_limite_por_debajo_de_sus_usuarios_activos(): void
    {
        $company = $this->company(['max_users' => 5]);
        $this->makeUser('CLIENTE', company: $company);
        $this->makeUser('CLIENTE', company: $company);

        $this->putJson("/api/v1/companies/{$company->id}", ['code' => $company->code, 'name' => $company->name, 'max_users' => 1], $this->headers)
            ->assertStatus(422)->assertJsonValidationErrors('max_users');

        $this->getJson("/api/v1/companies/{$company->id}", $this->headers)->assertOk()->assertJsonPath('data.active_users_count', 2);
    }
}
