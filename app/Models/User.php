<?php

namespace App\Models;

use App\Enums\UserType;
use App\Models\Concerns\TracksAuthor;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Modules\Companies\Models\Company;
use Modules\Core\Models\Module;
use Modules\Security\Models\AccessLog;
use Modules\Security\Models\UserSession;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TracksAuthor;

    /** Guard de spatie/laravel-permission: los permisos se validan sobre la API (JWT). */
    protected string $guard_name = 'api';

    /** @var Collection<int, Permission>|null Caché por petición de los permisos efectivos. */
    private ?Collection $effectivePermissionsCache = null;

    protected $fillable = [
        'company_id',
        'username',
        'email',
        'password',
        'document_number',
        'first_name',
        'last_name',
        'phone',
        'is_active',
        'is_root_admin',
        'must_change_password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $appends = [
        'full_name',
        'user_type',
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_root_admin' => 'boolean',
            'must_change_password' => 'boolean',
            'failed_login_attempts' => 'integer',
            'locked_until' => 'datetime',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    protected function fullName(): Attribute
    {
        return Attribute::get(fn (): string => trim("{$this->first_name} {$this->last_name}"));
    }

    /**
     * Tipo deducido de la empresa: con empresa = CLIENTE, sin empresa = INTERNO.
     */
    protected function userType(): Attribute
    {
        return Attribute::get(fn (): UserType => $this->company_id ? UserType::CLIENTE : UserType::INTERNO);
    }

    /**
     * Empresa cliente a la que pertenece. Null = personal interno.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isClientUser(): bool
    {
        return $this->company_id !== null;
    }

    /**
     * Personal interno (sin empresa): ve la información de todas las empresas.
     */
    public function canSeeAllCompanies(): bool
    {
        return $this->company_id === null;
    }

    /**
     * Restringe una consulta con columna company_id a lo que este usuario puede ver:
     * personal interno ve todo, un usuario cliente solo lo de su empresa.
     */
    public function restrictToCompany(Builder $query, string $column = 'company_id'): Builder
    {
        return $this->canSeeAllCompanies() ? $query : $query->where($column, $this->company_id);
    }

    /**
     * Permisos denegados explícitamente a este usuario, aunque su rol se los otorgue.
     */
    public function deniedPermissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'denied_permissions');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(AccessLog::class);
    }

    public function isRootAdmin(): bool
    {
        return $this->is_root_admin === true;
    }

    /**
     * Bloqueo temporal por intentos fallidos todavía vigente.
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Permisos por rol (solo roles activos) + directos, menos los denegados explícitamente al usuario.
     * Un usuario cliente nunca recibe permisos de módulos de administración, aunque su rol los tenga.
     * El superusuario raíz tiene todos los permisos.
     *
     * @return Collection<int, Permission>
     */
    public function getEffectivePermissions(): Collection
    {
        if ($this->effectivePermissionsCache !== null) {
            return $this->effectivePermissionsCache;
        }

        if ($this->isRootAdmin()) {
            return $this->effectivePermissionsCache = Permission::query()->where('guard_name', $this->guard_name)->get();
        }

        $this->loadMissing(['roles.permissions', 'permissions', 'deniedPermissions']);
        $deniedIds = $this->deniedPermissions->pluck('id')->all();
        $blockedModuleIds = $this->isClientUser() ? Module::internalOnlyIds() : [];

        return $this->effectivePermissionsCache = $this->roles
            ->where('is_active', true)
            ->flatMap(fn (Role $role) => $role->permissions)
            ->merge($this->permissions)
            ->unique('id')
            ->reject(fn (Permission $permission) => in_array($permission->id, $deniedIds, true)
                || in_array($permission->module_id, $blockedModuleIds, true))
            ->values();
    }

    /**
     * Nombres de permisos efectivos en formato MODULO:ACCION.
     *
     * @return string[]
     */
    public function permissionNames(): array
    {
        return $this->getEffectivePermissions()->pluck('name')->sort()->values()->all();
    }

    public function hasPermissionTo($permission, $guardName = null): bool
    {
        $permissionName = $permission instanceof Permission ? $permission->name : (string) $permission;

        return $this->getEffectivePermissions()->contains(fn (Permission $p) => $p->name === $permissionName);
    }

    /**
     * Limpia la caché de permisos (tras cambiar roles o permisos del usuario).
     */
    public function flushPermissionCache(): void
    {
        $this->effectivePermissionsCache = null;
        $this->unsetRelation('roles');
        $this->unsetRelation('permissions');
        $this->unsetRelation('deniedPermissions');
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Los claims de sesión (sid, plt) se agregan al emitir el token en AuthService.
     */
    public function getJWTCustomClaims(): array
    {
        return [];
    }
}
