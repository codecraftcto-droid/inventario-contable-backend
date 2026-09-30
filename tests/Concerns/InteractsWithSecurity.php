<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Modules\Companies\Models\Company;

/**
 * Helpers para pruebas: siembra módulos/roles y hace login real contra la API.
 */
trait InteractsWithSecurity
{
    protected const PASSWORD = 'Secreto123';

    /**
     * En producción cada petición es un proceso nuevo; en las pruebas el guard y el
     * parser JWT sobreviven entre peticiones y recordarían el token anterior.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        Auth::forgetGuards();

        if ($this->app->bound('tymon.jwt')) {
            $this->app->make('tymon.jwt')->unsetToken();
        }

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function seedSecurity(): void
    {
        $this->seed(DatabaseSeeder::class);
    }

    protected function makeUser(string|array $roles = [], array $attributes = [], ?Company $company = null): User
    {
        $factory = User::factory();

        if ($company) {
            $factory = $factory->forCompany($company);
        }

        $user = $factory->create(['password' => self::PASSWORD, ...$attributes]);
        $user->syncRoles(Role::whereIn('name', (array) $roles)->get());

        return $user;
    }

    protected function login(User|string $user, string $password = self::PASSWORD, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/security/auth/login', [
            'login' => $user instanceof User ? $user->username : $user,
            'password' => $password,
            ...$extra,
        ]);
    }

    /**
     * Inicia sesión y devuelve los headers con el Bearer token.
     *
     * @return array<string, string>
     */
    protected function authHeaders(User $user, array $extra = []): array
    {
        $token = $this->login($user, extra: $extra)->assertOk()->json('data.access_token');

        return ['Authorization' => "Bearer {$token}"];
    }

    protected function adminUser(): User
    {
        $admin = User::where('username', 'admin')->firstOrFail();
        $admin->forceFill(['password' => self::PASSWORD, 'must_change_password' => false])->save();

        return $admin;
    }

    protected function company(array $attributes = []): Company
    {
        static $sequence = 0;
        $sequence++;

        return Company::create(['code' => "E{$sequence}", 'name' => "Empresa {$sequence}", ...$attributes]);
    }
}
