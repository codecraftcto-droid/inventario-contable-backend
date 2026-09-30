<?php

namespace App\Models;

use App\Models\Concerns\TracksAuthor;
use Spatie\Permission\Models\Role as SpatieRole;

class Role extends SpatieRole
{
    use TracksAuthor;

    public const ADMIN = 'ADMIN';

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Los roles de sistema no se pueden eliminar.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_system;
    }
}
