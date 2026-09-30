<?php

return [
    'name' => 'Inventories',

    // Fotos de la toma: disco de Laravel donde se guardan ('local' en desarrollo; en
    // producción puede ser 's3' u otro sin cambiar código) y tamaño máximo por foto (KB).
    // La app ya las envía reducidas (~1600 px, calidad 70 ≈ 200-400 KB).
    'photos_disk' => env('INVENTORY_PHOTOS_DISK', 'local'),
    'photo_max_kb' => (int) env('INVENTORY_PHOTO_MAX_KB', 5120),
];
