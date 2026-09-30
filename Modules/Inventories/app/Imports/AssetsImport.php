<?php

namespace Modules\Inventories\Imports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Modules\Inventories\Models\Asset;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Services\ScanRelinker;

/**
 * Carga la base contable de un inventario desde el Excel con los encabezados
 * CODIGO PRODUCTO | NOMBRE PRODUCTO | UNIDAD MEDIDA | CANTIDAD | VALOR.
 *
 * - El código es obligatorio y único dentro del inventario; el resto es opcional
 *   (CANTIDAD vacía = 1 para la conciliación). Un archivo sin las columnas CANTIDAD / VALOR
 *   (formato anterior) no borra los valores ya cargados.
 * - Los registros de la toma se vuelven a enlazar con la base (ver ScanRelinker).
 * - Volver a subir el archivo ACTUALIZA los códigos que ya existen y CREA los nuevos
 *   (los que no vienen en el archivo se conservan).
 * - Pensado para archivos grandes: lee en bloques y guarda cada bloque con un solo UPSERT.
 */
class AssetsImport implements ToCollection, WithChunkReading, WithHeadingRow
{
    private const CHUNK_SIZE = 1000;

    /**
     * Encabezados aceptados (ya normalizados por la librería: minúsculas, sin tildes, "_").
     * Se aceptan variantes comunes para no rechazar un archivo por "UNIDAD DE MEDIDA".
     */
    private const COLUMNS = [
        'codigo' => ['codigo_producto', 'codigo_de_producto', 'codigo'],
        'nombre' => ['nombre_producto', 'nombre_de_producto', 'nombre'],
        'unidad_medida' => ['unidad_medida', 'unidad_de_medida', 'unidad'],
        'cantidad' => ['cantidad', 'cantidad_base', 'cant'],
        'valor' => ['valor', 'valor_unitario', 'valor_contable', 'precio'],
    ];

    /** Columnas opcionales que vinieron en el archivo (solo esas se actualizan). */
    private array $present = [];

    private int $created = 0;

    private int $updated = 0;

    /** @var array<int, array{row: int, errors: string[]}> */
    private array $failures = [];

    /** Código => fila donde apareció por primera vez (para detectar repetidos en el archivo). */
    private array $seen = [];

    /** Fila del Excel donde empieza el bloque actual (1 = encabezados). */
    private int $nextRow = 2;

    public function __construct(private readonly Inventory $inventory) {}

    public function collection(Collection $rows): void
    {
        if ($this->nextRow === 2 && $rows->isNotEmpty()) {
            $headings = $rows->first()->keys()->all();
            $this->ensureHeadings($headings);
            foreach (['cantidad', 'valor'] as $field) {
                if (array_intersect(self::COLUMNS[$field], $headings) !== []) {
                    $this->present[] = $field;
                }
            }
        }

        $now = now();
        $userId = Auth::id();
        $valid = [];

        foreach ($rows as $row) {
            $rowNumber = $this->nextRow++;
            $data = [
                'codigo' => mb_strtoupper(trim((string) $this->value($row, 'codigo'))),
                'nombre' => $this->nullable($this->value($row, 'nombre')),
                'unidad_medida' => $this->nullable($this->value($row, 'unidad_medida')),
                ...array_map(fn (string $field) => $this->number($this->value($row, $field)), array_combine($this->present, $this->present)),
            ];

            // Filas totalmente vacías (típicas al final del Excel) se ignoran.
            if ($data['codigo'] === '' && $data['nombre'] === null && $data['unidad_medida'] === null && ($data['cantidad'] ?? null) === null && ($data['valor'] ?? null) === null) {
                continue;
            }

            $validator = Validator::make($data, $this->rules(), [], $this->attributes());

            if ($validator->fails()) {
                $this->failures[] = ['row' => $rowNumber, 'errors' => $validator->errors()->all()];

                continue;
            }

            if (isset($this->seen[$data['codigo']])) {
                $this->failures[] = [
                    'row' => $rowNumber,
                    'errors' => ["El código {$data['codigo']} está repetido en el archivo (ya está en la fila {$this->seen[$data['codigo']]})."],
                ];

                continue;
            }
            $this->seen[$data['codigo']] = $rowNumber;

            $valid[] = [
                'inventory_id' => $this->inventory->id,
                ...$data,
                'created_by' => $userId,
                'updated_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($valid === []) {
            return;
        }

        // Cuántos ya existían (se actualizan) y cuántos son nuevos (se crean).
        $existing = Asset::query()
            ->where('inventory_id', $this->inventory->id)
            ->whereIn('codigo', array_column($valid, 'codigo'))
            ->count();

        Asset::upsert($valid, ['inventory_id', 'codigo'], ['nombre', 'unidad_medida', ...$this->present, 'updated_by', 'updated_at']);

        // Registros de la toma hechos antes de esta carga (p. ej. un sobrante que ahora sí está en la base).
        app(ScanRelinker::class)->relink($this->inventory, array_column($valid, 'codigo'));

        $this->updated += $existing;
        $this->created += count($valid) - $existing;
    }

    public function chunkSize(): int
    {
        return self::CHUNK_SIZE;
    }

    public function rules(): array
    {
        return [
            'codigo' => ['required', 'string', 'max:50'],
            'nombre' => ['nullable', 'string', 'max:255'],
            'unidad_medida' => ['nullable', 'string', 'max:50'],
            'cantidad' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function attributes(): array
    {
        return [
            'codigo' => 'código de producto',
            'nombre' => 'nombre de producto',
            'unidad_medida' => 'unidad de medida',
            'cantidad' => 'cantidad',
            'valor' => 'valor',
        ];
    }

    public function createdCount(): int
    {
        return $this->created;
    }

    public function updatedCount(): int
    {
        return $this->updated;
    }

    public function importedCount(): int
    {
        return $this->created + $this->updated;
    }

    /**
     * @return array<int, array{row: int, errors: string[]}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Un archivo con otros encabezados (p. ej. el formato anterior) se rechaza completo
     * con un mensaje claro, en lugar de marcar cada fila como error.
     *
     * @param  array<int, string|int>  $headings
     */
    private function ensureHeadings(array $headings): void
    {
        if (array_intersect(self::COLUMNS['codigo'], $headings) === []) {
            throw ValidationException::withMessages([
                'file' => 'El archivo no tiene la columna CODIGO PRODUCTO. Descarga el formato y usa sus encabezados: CODIGO PRODUCTO, NOMBRE PRODUCTO y UNIDAD MEDIDA.',
            ]);
        }
    }

    private function value(Collection $row, string $field): mixed
    {
        foreach (self::COLUMNS[$field] as $heading) {
            if ($row->has($heading)) {
                return $row->get($heading);
            }
        }

        return null;
    }

    /**
     * Número tal como viene del Excel; texto con coma decimal ("1,5") también se acepta.
     * Si no es numérico se deja tal cual para que la validación lo informe por fila.
     */
    private function number(mixed $value): mixed
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (is_string($value)) {
            $normalized = str_replace([' ', ','], ['', '.'], trim($value));

            return is_numeric($normalized) ? (float) $normalized : trim($value);
        }

        return $value;
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
