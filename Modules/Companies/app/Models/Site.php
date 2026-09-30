<?php

namespace Modules\Companies\Models;

use App\Models\Concerns\TracksAuthor;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Sede (local) de una empresa cliente.
 */
class Site extends Model
{
    use TracksAuthor;

    protected $fillable = [
        'company_id',
        'code',
        'name',
        'address',
        'is_active',
    ];

    protected $attributes = [
        'is_active' => true,
    ];

    protected function casts(): array
    {
        return [
            'company_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Código en mayúsculas y sin espacios alrededor, para que no se dupliquen "sede1" y "SEDE1 ".
     */
    protected function code(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : mb_strtoupper(trim($value)));
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
