<?php

namespace Modules\Security\Services;

use App\Enums\AccessEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Companies\Models\Company;
use Modules\Core\Models\Module;
use Modules\Security\Models\UserSession;

/**
 * Reglas de negocio de usuarios:
 *  - Si tiene empresa es un usuario cliente: solo ve su empresa, cuenta para el límite
 *    de usuarios de su contrato y no puede recibir roles con permisos de administración.
 *  - Si no tiene empresa es personal interno.
 *  - El superusuario raíz no se puede desactivar ni eliminar; nadie se desactiva a sí mismo.
 */
class UserService
{
    public function __construct(
        private readonly SessionService $sessions,
        private readonly AccessLogService $accessLog,
    ) {}

    /**
     * @return array{0: User, 1: string|null} usuario creado y contraseña temporal (si se generó)
     */
    public function create(array $data): array
    {
        $temporaryPassword = empty($data['password']) ? $this->temporaryPassword() : null;

        $user = DB::transaction(function () use ($data, $temporaryPassword): User {
            $isActive = $data['is_active'] ?? true;
            $this->ensureCompanyHasRoom($data['company_id'] ?? null, $isActive);

            $user = new User([
                ...collect($data)->except(['role_ids', 'password'])->all(),
                'is_active' => $isActive,
                'must_change_password' => true,       // Siempre cambia la clave en su primer ingreso
            ]);
            $user->password = $temporaryPassword ?? $data['password'];
            $user->save();

            $this->syncRoles($user, $data['role_ids'] ?? []);

            return $user;
        });

        return [$user, $temporaryPassword];
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor): User {
            $companyId = $data['company_id'] ?? null;
            $isActive = $data['is_active'] ?? $user->is_active;

            if ($user->isRootAdmin() && ($companyId !== null || ! $isActive)) {
                throw ValidationException::withMessages(['company_id' => 'El superusuario debe ser personal interno y estar activo.']);
            }

            $this->ensureNotSelfDeactivation($user, $actor, $isActive);

            // Ocupa un cupo nuevo si entra a otra empresa o si se reactiva.
            $takesNewSeat = $companyId !== null && ((int) $companyId !== (int) $user->company_id || ($isActive && ! $user->is_active));

            if ($takesNewSeat) {
                $this->ensureCompanyHasRoom($companyId, $isActive);
            }

            $user->fill([
                ...collect($data)->except(['role_ids', 'password'])->all(),
                'company_id' => $companyId,
                'is_active' => $isActive,
            ])->save();

            // Si pasó a ser usuario de una empresa, sus roles actuales también deben ser válidos.
            $this->syncRoles($user, $data['role_ids'] ?? $user->roles()->pluck('roles.id')->all());

            if (! $isActive) {
                $this->sessions->revokeForUser($user, UserSession::REVOKED_USER_DISABLED, $actor);
            }

            return $user;
        });
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        if ($active === $user->is_active) {
            return $user;
        }

        if (! $active) {
            if ($user->isRootAdmin()) {
                throw ValidationException::withMessages(['is_active' => 'El superusuario no se puede desactivar.']);
            }

            $this->ensureNotSelfDeactivation($user, $actor, false);
        } else {
            $this->ensureCompanyHasRoom($user->company_id, true);
        }

        DB::transaction(function () use ($user, $active, $actor): void {
            $user->forceFill(['is_active' => $active])->save();

            if (! $active) {
                $this->sessions->revokeForUser($user, UserSession::REVOKED_USER_DISABLED, $actor);
            }
        });

        return $user;
    }

    public function unlock(User $user): User
    {
        $user->forceFill(['failed_login_attempts' => 0, 'locked_until' => null])->save();

        return $user;
    }

    /**
     * Genera una contraseña temporal, obliga a cambiarla y cierra todas las sesiones.
     */
    public function resetPassword(Request $request, User $user, User $actor): string
    {
        $temporaryPassword = $this->temporaryPassword();

        DB::transaction(function () use ($request, $user, $actor, $temporaryPassword): void {
            $user->forceFill([
                'password' => $temporaryPassword,
                'must_change_password' => true,
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            $this->sessions->revokeForUser($user, UserSession::REVOKED_PASSWORD_RESET, $actor);

            $this->accessLog->record($request, AccessEvent::CAMBIO_CLAVE, $user, detail: "Reseteo de contraseña por {$actor->username}");
        });

        return $temporaryPassword;
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->isRootAdmin()) {
            throw ValidationException::withMessages(['user' => 'El superusuario no se puede eliminar.']);
        }

        if ($user->is($actor)) {
            throw ValidationException::withMessages(['user' => 'No puedes eliminar tu propio usuario.']);
        }

        $user->delete();
    }

    /**
     * @param  int[]  $roleIds
     */
    public function syncRoles(User $user, array $roleIds): void
    {
        if ($user->isClientUser()) {
            $this->ensureNoAdministrationRoles($roleIds);
        }

        if ($user->isRootAdmin() && ! Role::whereIn('id', $roleIds)->where('name', Role::ADMIN)->exists()) {
            throw ValidationException::withMessages(['role_ids' => 'El superusuario debe conservar el rol ADMIN.']);
        }

        $user->syncRoles(Role::whereIn('id', $roleIds)->get());
        $user->flushPermissionCache();
    }

    /**
     * Un usuario de empresa no puede recibir roles que den permisos de administración
     * (Seguridad, Empresas...). Ver config security.internal_only_modules.
     *
     * @param  int[]  $roleIds
     */
    private function ensureNoAdministrationRoles(array $roleIds): void
    {
        $adminRoles = Role::query()
            ->whereIn('id', $roleIds)
            ->whereHas('permissions', fn ($query) => $query->whereIn('module_id', Module::internalOnlyIds()))
            ->pluck('name');

        if ($adminRoles->isNotEmpty()) {
            throw ValidationException::withMessages([
                'role_ids' => "Los roles {$adminRoles->implode(', ')} tienen permisos de administración y no se pueden asignar a un usuario de empresa.",
            ]);
        }
    }

    /**
     * Límite de usuarios activos de la empresa según su contrato (companies.max_users).
     */
    private function ensureCompanyHasRoom(?int $companyId, bool $isActive): void
    {
        if (! $companyId || ! $isActive) {
            return;
        }

        $company = Company::query()->lockForUpdate()->findOrFail($companyId);

        if (! $company->is_active) {
            throw ValidationException::withMessages(['company_id' => "La empresa {$company->name} está desactivada."]);
        }

        if (! $company->canAddUser()) {
            throw ValidationException::withMessages([
                'company_id' => "La empresa {$company->name} ya tiene {$company->max_users} usuario(s) activo(s), el máximo de su contrato.",
            ]);
        }
    }

    private function ensureNotSelfDeactivation(User $user, User $actor, bool $isActive): void
    {
        if (! $isActive && $user->is($actor)) {
            throw ValidationException::withMessages(['is_active' => 'No puedes desactivar tu propio usuario.']);
        }
    }

    /**
     * Contraseña temporal que cumple la política (mayúsculas, minúsculas y números).
     */
    private function temporaryPassword(): string
    {
        $upper = chr(random_int(ord('A'), ord('Z')));
        $lower = chr(random_int(ord('a'), ord('z')));

        return str_shuffle($upper.$lower.random_int(10, 99).Str::random(6));
    }
}
