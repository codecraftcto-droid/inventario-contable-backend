<?php

namespace Modules\Inventories\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * Foto de un registro de la toma (hasta 3 por registro). El archivo vive en el disco
 * configurado en inventories.photos_disk; se sirve solo a través de la API (con permisos).
 */
class InventoryScanPhoto extends Model
{
    public const MAX_PER_SCAN = 3;

    protected $fillable = ['inventory_scan_id', 'client_uuid', 'disk', 'path', 'size'];

    protected static function booted(): void
    {
        // Al borrar la foto (o el registro, vía deleteFiles) también se borra el archivo.
        static::deleted(fn (self $photo) => Storage::disk($photo->disk)->delete($photo->path));
    }

    public function scan(): BelongsTo
    {
        return $this->belongsTo(InventoryScan::class, 'inventory_scan_id');
    }
}
