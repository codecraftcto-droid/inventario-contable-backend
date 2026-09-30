<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Conciliación de la toma contra la base contable (CANTIDAD de la base vs. lo registrado):
 * conciliado, faltante, sobrante, diferencia positiva y diferencia negativa.
 */
class InventoryReconciliationTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $inventory;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        $this->inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'Toma', 'status' => 'EN_PROCESO']);
        $this->headers = $this->authHeaders($this->makeUser('CONTABLE'));
    }

    private function upload(string $rows, string $header = "CODIGO PRODUCTO,NOMBRE PRODUCTO,UNIDAD MEDIDA,CANTIDAD,VALOR\n")
    {
        return $this->post("/api/v1/inventories/{$this->inventory->id}/accounting-base/import", [
            'file' => UploadedFile::fake()->createWithContent('base.csv', $header.$rows),
        ], $this->headers);
    }

    private function register(string $code, float $quantity): void
    {
        $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/batch", ['scans' => [[
            'uuid' => (string) Str::uuid(), 'code' => $code, 'quantity' => $quantity, 'scanned_at' => now()->toISOString(),
        ]]], $this->headers)->assertOk();
    }

    private function statuses(): array
    {
        return collect($this->getJson("/api/v1/inventories/{$this->inventory->id}/take/reconciliation?per_page=100", $this->headers)->assertOk()->json('data'))
            ->mapWithKeys(fn ($row) => [$row['code'] => [$row['status'], $row['expected'], $row['counted'], $row['difference']]])
            ->all();
    }

    public function test_los_cinco_estados_de_la_conciliacion(): void
    {
        $this->upload("A-1,Laptop,UND,5,3500\nA-2,Silla,UND,5,200\nA-3,Mesa,UND,5,300\nA-4,Monitor,UND,2,800\n")->assertOk();

        $this->register('A-1', 3);
        $this->register('A-1', 2);   // 3 + 2 = 5 (dos registros, p. ej. dos sedes)
        $this->register('A-2', 6);   // base 5
        $this->register('A-3', 4);   // base 5
        $this->register('ZZ-9', 1);  // no está en la base
        // A-4 sin registros

        $this->assertEquals([
            'A-1' => ['CONCILIADO', 5, 5, 0],
            'A-2' => ['DIFERENCIA_POSITIVA', 5, 6, 1],
            'A-3' => ['DIFERENCIA_NEGATIVA', 5, 4, -1],
            'A-4' => ['FALTANTE', 2, 0, -2],
            'ZZ-9' => ['SOBRANTE', 0, 1, 1],
        ], $this->statuses());

        $this->getJson("/api/v1/inventories/{$this->inventory->id}/take/summary", $this->headers)
            ->assertJsonPath('data.reconciliation.statuses', [
                'CONCILIADO' => 1, 'FALTANTE' => 1, 'SOBRANTE' => 1, 'DIFERENCIA_POSITIVA' => 1, 'DIFERENCIA_NEGATIVA' => 1,
            ])
            ->assertJsonPath('data.reconciliation.expected_units', 17)
            ->assertJsonPath('data.reconciliation.counted_units', 16);

        $this->getJson("/api/v1/inventories/{$this->inventory->id}/take/reconciliation?status=DIFERENCIA_NEGATIVA", $this->headers)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'A-3');
    }

    public function test_cantidad_vacia_en_la_base_cuenta_como_1(): void
    {
        $this->upload("A-1,Laptop,UND,,\n")->assertOk();
        $this->register('A-1', 1);

        $this->assertSame('CONCILIADO', $this->statuses()['A-1'][0]);
    }

    public function test_carga_cantidad_y_valor_y_reporta_valores_no_numericos(): void
    {
        $response = $this->upload("A-1,Laptop,UND,\"1,5\",3500.50\nA-2,Silla,UND,muchas,10\n")
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failures_count', 1);

        $this->assertStringContainsString('cantidad', $response->json('data.failures.0.errors.0'));
        $asset = $this->inventory->assets()->first();
        $this->assertSame(['1.50', '3500.50'], [$asset->cantidad, $asset->valor]);
    }

    public function test_un_archivo_sin_cantidad_ni_valor_no_borra_los_ya_cargados(): void
    {
        $this->upload("A-1,Laptop,UND,5,3500\n")->assertOk();
        $this->upload("A-1,Laptop Dell,UND\n", "CODIGO PRODUCTO,NOMBRE PRODUCTO,UNIDAD MEDIDA\n")->assertOk();

        $asset = $this->inventory->assets()->first();
        $this->assertSame(['Laptop Dell', '5.00', '3500.00'], [$asset->nombre, $asset->cantidad, $asset->valor]);
    }

    public function test_un_sobrante_que_luego_se_agrega_a_la_base_pasa_a_conciliarse(): void
    {
        $this->upload("A-1,Laptop,UND,1,\n")->assertOk();
        $this->register('B-7', 2);
        $this->assertSame('SOBRANTE', $this->statuses()['B-7'][0]);

        // El contador agrega el producto a la base (nueva carga del Excel)
        $this->upload("B-7,Proyector,UND,2,\n")->assertOk();

        $this->assertSame(['CONCILIADO', 2, 2, 0], $this->statuses()['B-7']);
        $this->assertSame('FOUND', InventoryScan::where('code', 'B-7')->first()->outcome->value);
    }

    public function test_quitar_un_producto_de_la_base_deja_sus_registros_como_sobrantes(): void
    {
        $this->upload("A-1,Laptop,UND,1,\n")->assertOk();
        $this->register('A-1', 1);
        $asset = $this->inventory->assets()->first();

        $this->deleteJson("/api/v1/inventories/{$this->inventory->id}/accounting-base/{$asset->id}", [], $this->headers)->assertOk();

        $this->assertSame(['SOBRANTE', 0, 1, 1], $this->statuses()['A-1']);
    }

    public function test_el_excel_trae_la_hoja_de_conciliacion(): void
    {
        $this->upload("A-1,Laptop,UND,5,3500\nA-2,Silla,UND,2,\n")->assertOk();
        $this->register('A-1', 4);

        $response = $this->get("/api/v1/inventories/{$this->inventory->id}/take/export", $this->headers)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'conc').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Resumen', 'Conciliación', 'Registros'], $book->getSheetNames());
        $rows = $book->getSheetByName('Conciliación')->toArray();
        $this->assertSame(['CODIGO_PRODUCTO', 'NOMBRE_PRODUCTO', 'UND_MEDIDA', 'VALOR', 'CANTIDAD_BASE', 'CANTIDAD_REGISTRADA', 'DIFERENCIA', 'REGISTROS', 'ESTADO'], $rows[0]);
        // Primero lo que requiere atención: el faltante, luego la diferencia negativa.
        $this->assertEquals(['A-2', 'Silla', 'UND', null, 2, 0, -2, 0, 'FALTANTE'], $rows[1]);
        $this->assertEquals(['A-1', 'Laptop', 'UND', 3500, 5, 4, -1, 1, 'DIFERENCIA NEGATIVA'], $rows[2]);
    }
}
