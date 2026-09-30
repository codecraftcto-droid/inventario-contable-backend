<?php

namespace Modules\Companies\Models;

use App\Models\Concerns\TracksAuthor;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Inventories\Models\Inventory;

class Company extends Model
{
    use TracksAuthor;

    protected $fillable = [
        'code',
        'name',
        'document_type',
        'document_number',
        'email',
        'phone',
        'address',
        'max_users',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'max_users' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Usuarios de esta empresa (tipo CLIENTE).
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * Sedes (locales) de la empresa.
     */
    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(Inventory::class);
    }

    /**
     * ¿Se le puede crear un usuario más según su contrato? Solo cuentan los usuarios activos.
     */
    public function canAddUser(): bool
    {
        return $this->max_users === null || $this->users()->where('is_active', true)->count() < $this->max_users;
    }
}
