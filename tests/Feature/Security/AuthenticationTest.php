<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Security\Models\AccessLog;
use Modules\Security\Models\UserSession;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
    }

    public function test_login_devuelve_tokens_permisos_y_menu(): void
    {
        $user = $this->makeUser('CONTABLE');

        $response = $this->login($user)->assertOk();

        $response->assertJsonStructure(['data' => [
            'access_token', 'refresh_token', 'expires_in', 'refresh_expires_at',
            'user' => ['id', 'username', 'user_type', 'must_change_password'],
            'permissions', 'menu',
        ]]);
        $this->assertContains('INV_BASE:IMPORTAR', $response->json('data.permissions'));
        $this->assertSame('WEB', $response->json('data.platform'));
        $this->assertSame(1, AccessLog::where('event', 'LOGIN_OK')->where('user_id', $user->id)->count());
    }

    public function test_se_puede_ingresar_con_numero_de_documento_o_correo(): void
    {
        $user = $this->makeUser('CONTABLE', ['document_number' => '45678912', 'email' => 'maria@empresa.com']);

        $this->login('45678912')->assertOk()->assertJsonPath('data.user.id', $user->id);
        $this->login('maria@empresa.com')->assertOk();
        $this->login('  MARIA@Empresa.com ')->assertOk();   // Sin distinguir mayúsculas ni espacios
        $this->login('45678912', 'incorrecta')->assertStatus(401);
    }

    public function test_credenciales_invalidas_se_registran_como_login_fallido(): void
    {
        $user = $this->makeUser('CONTABLE');

        $this->login($user, 'incorrecta')->assertStatus(401);
        $this->login('no-existe')->assertStatus(401);

        $this->assertSame(1, $user->fresh()->failed_login_attempts);
        $this->assertSame(2, AccessLog::where('event', 'LOGIN_FALLIDO')->count());
    }

    public function test_bloqueo_temporal_tras_intentos_fallidos(): void
    {
        $user = $this->makeUser('CONTABLE');   // phpunit.xml: 3 intentos

        $this->login($user, 'mala1')->assertStatus(401);
        $this->login($user, 'mala2')->assertStatus(401);
        $this->login($user, 'mala3')->assertStatus(423);

        $this->assertTrue($user->fresh()->isLocked());
        $this->assertSame(1, AccessLog::where('event', 'BLOQUEO')->where('user_id', $user->id)->count());

        // Aun con la contraseña correcta sigue bloqueado.
        $this->login($user)->assertStatus(423);

        // Pasado el tiempo de bloqueo, puede ingresar y se reinician los intentos.
        $this->travel(16)->minutes();
        $this->login($user)->assertOk();
        $this->assertSame(0, $user->fresh()->failed_login_attempts);
    }

    public function test_usuario_inactivo_no_puede_ingresar(): void
    {
        $user = $this->makeUser('CONTABLE', ['is_active' => false]);

        $this->login($user)->assertStatus(403);
    }

    public function test_cambio_de_clave_obligatorio_en_primer_ingreso(): void
    {
        $user = $this->makeUser('CONTABLE', ['must_change_password' => true]);
        $headers = $this->authHeaders($user);

        // Bloquea el resto del sistema...
        $this->getJson('/api/v1/inventories', $headers)
            ->assertStatus(403)
            ->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');

        // ...pero permite ver su perfil y cambiar la clave.
        $this->getJson('/api/v1/security/auth/me', $headers)->assertOk()->assertJsonPath('data.user.must_change_password', true);

        $this->postJson('/api/v1/security/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'NuevaClave456',
            'password_confirmation' => 'NuevaClave456',
        ], $headers)->assertOk();

        $this->getJson('/api/v1/inventories', $headers)->assertOk();
        $this->assertSame(1, AccessLog::where('event', 'CAMBIO_CLAVE')->where('user_id', $user->id)->count());
    }

    public function test_cambio_de_clave_valida_politica_y_clave_actual(): void
    {
        $user = $this->makeUser('CONTABLE');
        $headers = $this->authHeaders($user);

        $this->postJson('/api/v1/security/auth/change-password', [
            'current_password' => 'otra',
            'password' => 'corta',
            'password_confirmation' => 'corta',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['current_password', 'password']);
    }

    public function test_cambio_de_clave_cierra_las_otras_sesiones(): void
    {
        $user = $this->makeUser('CONTABLE');
        $celular = $this->authHeaders($user, ['platform' => 'MOVIL', 'device_id' => 'CEL-1']);
        $web = $this->authHeaders($user);

        $this->postJson('/api/v1/security/auth/change-password', [
            'current_password' => self::PASSWORD,
            'password' => 'NuevaClave456',
            'password_confirmation' => 'NuevaClave456',
        ], $web)->assertOk();

        $this->getJson('/api/v1/inventories', $celular)->assertStatus(401);
        $this->getJson('/api/v1/inventories', $web)->assertOk();
    }

    public function test_refresh_rota_el_token_y_el_anterior_deja_de_servir(): void
    {
        $user = $this->makeUser('CONTABLE');
        $refreshToken = $this->login($user)->json('data.refresh_token');

        $newRefreshToken = $this->postJson('/api/v1/security/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertOk()
            ->assertJsonStructure(['data' => ['access_token', 'refresh_token', 'permissions', 'menu']])
            ->json('data.refresh_token');

        $this->assertNotSame($refreshToken, $newRefreshToken);

        $this->postJson('/api/v1/security/auth/refresh', ['refresh_token' => $refreshToken])->assertStatus(401);
    }

    public function test_refresh_desde_otro_dispositivo_revoca_la_sesion(): void
    {
        $user = $this->makeUser('INVENTARIADOR');
        $refreshToken = $this->login($user, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-1'])->json('data.refresh_token');

        $this->postJson('/api/v1/security/auth/refresh', ['refresh_token' => $refreshToken, 'device_id' => 'CEL-2'])->assertStatus(401);

        $this->assertSame(UserSession::REVOKED_INVALID_REFRESH, UserSession::where('user_id', $user->id)->value('revoke_reason'));
    }

    public function test_logout_revoca_la_sesion_y_el_access_token(): void
    {
        $user = $this->makeUser('CONTABLE');
        $login = $this->login($user)->json('data');
        $headers = ['Authorization' => "Bearer {$login['access_token']}"];

        $this->postJson('/api/v1/security/auth/logout', [], $headers)->assertOk();

        $this->getJson('/api/v1/inventories', $headers)->assertStatus(401);

        $this->postJson('/api/v1/security/auth/refresh', ['refresh_token' => $login['refresh_token']])->assertStatus(401);
        $this->assertSame(1, AccessLog::where('event', 'LOGOUT')->count());
    }

    public function test_login_movil_exige_dispositivo_y_reemplaza_la_sesion_previa_del_equipo(): void
    {
        $user = $this->makeUser('INVENTARIADOR');

        $this->login($user, extra: ['platform' => 'MOVIL'])->assertStatus(422)->assertJsonValidationErrors('device_id');

        $this->login($user, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-1', 'device_name' => 'Samsung A15'])->assertOk();
        $this->login($user, extra: ['platform' => 'MOVIL', 'device_id' => 'CEL-1'])->assertOk();

        $this->assertSame(1, UserSession::where('user_id', $user->id)->active()->count());
        $this->assertSame('Samsung A15', UserSession::where('user_id', $user->id)->oldest('id')->value('device_name'));
    }

    public function test_sin_token_responde_401_en_json(): void
    {
        $this->getJson('/api/v1/inventories')->assertStatus(401)->assertJsonPath('code', 'UNAUTHENTICATED');
        $this->get('/api/v1/inventories')->assertStatus(401);
    }

    public function test_admin_inicial_debe_cambiar_su_clave(): void
    {
        $admin = User::where('username', 'admin')->first();

        $this->assertTrue($admin->must_change_password);
        $this->assertTrue($admin->isRootAdmin());
    }
}
