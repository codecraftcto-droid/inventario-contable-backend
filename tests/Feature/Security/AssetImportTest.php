<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Inventories\Models\Inventory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Carga de la base contable: CODIGO PRODUCTO (obligatorio, único) | NOMBRE PRODUCTO | UNIDAD MEDIDA.
 */
class AssetImportTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private const HEADER = "CODIGO PRODUCTO,NOMBRE PRODUCTO,UNIDAD MEDIDA\n";

    private Inventory $inventory;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        $this->inventory = Inventory::create(['company_id' => $this->company()->id, 'code' => 'INV-1', 'name' => 'A']);
        $this->headers = $this->authHeaders($this->makeUser('CONTABLE'));
    }

    private function upload(UploadedFile $file)
    {
        return $this->post("/api/v1/inventories/{$this->inventory->id}/accounting-base/import", ['file' => $file], $this->headers);
    }

    private function csv(string $rows): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('base.csv', self::HEADER.$rows);
    }

    private function product(string $code): ?array
    {
        return $this->inventory->assets()->where('codigo', $code)->first(['codigo', 'nombre', 'unidad_medida'])?->toArray();
    }

    public function test_primera_carga_crea_los_productos_y_nombre_y_unidad_son_opcionales(): void
    {
        $this->upload($this->csv("P-001,Laptop Dell,UND\np-002,,\nP-003,Papel bond,\n"))
            ->assertOk()
            ->assertJsonPath('data.created', 3)
            ->assertJsonPath('data.updated', 0)
            ->assertJsonPath('data.failures_count', 0);

        $this->assertSame(['codigo' => 'P-002', 'nombre' => null, 'unidad_medida' => null], $this->product('P-002'));
        // Cargar la base no inicia la toma (eso lo hace el botón "Iniciar").
        $this->assertSame('PENDIENTE', $this->inventory->fresh()->status->value);
    }

    public function test_volver_a_subir_actualiza_los_existentes_y_crea_los_nuevos(): void
    {
        $this->upload($this->csv("P-001,Laptop,UND\nP-002,Silla,UND\n"))->assertOk();

        $this->upload($this->csv("P-001,Laptop Dell Latitude,CAJA\nP-004,Monitor,UND\n"))
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('message', 'Carga completada: 1 producto(s) nuevo(s) y 1 actualizado(s).');

        $this->assertSame(['codigo' => 'P-001', 'nombre' => 'Laptop Dell Latitude', 'unidad_medida' => 'CAJA'], $this->product('P-001'));
        // Lo que no vino en el segundo archivo se conserva.
        $this->assertNotNull($this->product('P-002'));
        $this->assertSame(3, $this->inventory->assets()->count());
    }

    public function test_el_valor_queda_en_null_y_la_carga_no_lo_borra_si_ya_tenia(): void
    {
        $this->upload($this->csv("P-001,Laptop,UND\n"))->assertOk();
        $this->assertNull($this->inventory->assets()->where('codigo', 'P-001')->value('valor'));

        // Si un producto ya tenía valor (registrado a mano), volver a subir el Excel no lo borra.
        $this->inventory->assets()->where('codigo', 'P-001')->update(['valor' => 3500]);
        $this->upload($this->csv("P-001,Laptop Dell,UND\n"))->assertOk()->assertJsonPath('data.updated', 1);

        $asset = $this->inventory->assets()->where('codigo', 'P-001')->first();
        $this->assertSame('Laptop Dell', $asset->nombre);
        $this->assertSame('3500.00', $asset->valor);
    }

    public function test_codigo_repetido_en_el_archivo_y_codigo_vacio_se_reportan_por_fila(): void
    {
        $response = $this->upload($this->csv("P-001,Primero,UND\n,Sin codigo,UND\np-001 ,Repetido,UND\n"))
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.failures_count', 2);

        $failures = collect($response->json('data.failures'))->keyBy('row');
        $this->assertStringContainsString('código de producto', $failures[3]['errors'][0]);
        $this->assertSame('El código P-001 está repetido en el archivo (ya está en la fila 2).', $failures[4]['errors'][0]);
        $this->assertSame('Primero', $this->product('P-001')['nombre']);   // gana la primera aparición
    }

    public function test_archivo_con_otros_encabezados_se_rechaza_completo(): void
    {
        $old = UploadedFile::fake()->createWithContent('base.csv', "codigo_activo,descripcion,valor\nA-001,Laptop,3500\n");

        $this->upload($old)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'CODIGO PRODUCTO']);
        $this->assertSame(0, $this->inventory->assets()->count());
    }

    public function test_excel_real_con_tildes_y_codigo_con_ceros_a_la_izquierda(): void
    {
        $sheet = (new Spreadsheet)->getActiveSheet();
        $sheet->fromArray([['CÓDIGO PRODUCTO', 'NOMBRE PRODUCTO', 'UNIDAD DE MEDIDA'], ['000123', 'Tóner HP', 'UND']]);
        $sheet->getCell('A2')->setValueExplicit('000123', 'str');
        $path = tempnam(sys_get_temp_dir(), 'base').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        $this->upload(new UploadedFile($path, 'base.xlsx', null, null, true))->assertOk()->assertJsonPath('data.created', 1);

        $this->assertSame(['codigo' => '000123', 'nombre' => 'Tóner HP', 'unidad_medida' => 'UND'], $this->product('000123'));
    }

    public function test_el_formato_descargado_trae_los_encabezados_y_se_puede_volver_a_subir(): void
    {
        $response = $this->get('/api/v1/inventories/accounting-base-template', $this->headers)->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $response->streamedContent());

        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $sheet = $book->getActiveSheet();
        $this->assertSame(['CODIGO PRODUCTO', 'NOMBRE PRODUCTO', 'UNIDAD MEDIDA'], $sheet->rangeToArray('A1:C1')[0]);
        // Toda la columna del código es texto (también las filas que se llenen después).
        $columnStyle = $book->getCellXfByIndex($sheet->getColumnDimension('A')->getXfIndex());
        $this->assertSame('@', $columnStyle->getNumberFormat()->getFormatCode());

        $sheet->fromArray([['P-9', 'Escritorio', 'UND']], null, 'A2');
        (new Xlsx($sheet->getParent()))->save($path);
        $this->upload(new UploadedFile($path, 'base.xlsx', null, null, true))->assertOk()->assertJsonPath('data.created', 1);
    }

    public function test_registro_manual_no_duplica_el_codigo_aunque_cambien_mayusculas(): void
    {
        $url = "/api/v1/inventories/{$this->inventory->id}/accounting-base";
        $this->postJson($url, ['codigo' => 'P-1'], $this->headers)->assertCreated()->assertJsonPath('data.nombre', null);

        $this->postJson($url, ['codigo' => ' p-1 ', 'nombre' => 'Otro'], $this->headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('codigo');
    }
}
