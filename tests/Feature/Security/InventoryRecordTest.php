<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Companies\Models\Site;
use Modules\Inventories\Models\Inventory;
use Modules\Inventories\Models\InventoryScan;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Registro de la toma al estilo del APK "App Suministros": cantidad, estado, sede (ubicación),
 * pocket, detalle, observaciones y fotos; edición y borrado desde el equipo (sin señal).
 */
class InventoryRecordTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Inventory $inventory;

    private Site $planta;

    private User $luis;

    private array $headers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        Storage::fake('local');

        $company = $this->company();
        $this->planta = $company->sites()->create(['code' => 'PLANTA', 'name' => 'Planta']);
        $company->sites()->create(['code' => 'CDA', 'name' => 'CDA']);
        $company->sites()->create(['code' => 'OLD', 'name' => 'Cerrada', 'is_active' => false]);

        $this->inventory = Inventory::create(['company_id' => $company->id, 'code' => 'INV-1', 'name' => 'Toma', 'status' => 'EN_PROCESO']);
        $this->inventory->assets()->create(['codigo' => '12345', 'nombre' => 'GALLETAS', 'unidad_medida' => 'CJ']);

        $this->luis = $this->makeUser('INVENTARIADOR');
        $this->inventory->assignees()->attach($this->luis->id);
        $this->headers = $this->authHeaders($this->luis);
    }

    private function record(array $overrides = []): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'code' => '12345',
            'quantity' => 5,
            'condition' => 'BUENO',
            'site_id' => $this->planta->id,
            'location' => 'Planta',
            'pocket' => 'p-01',
            'detail' => 'Caja sellada',
            'observations' => 'Sin novedad',
            'scanned_at' => '2026-09-28T17:21:08Z',
            'updated_at' => '2026-09-28T17:21:08Z',
            ...$overrides,
        ];
    }

    private function push(array $records, ?array $headers = null)
    {
        return $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/batch", ['scans' => $records], $headers ?? $this->headers);
    }

    public function test_guarda_todos_los_datos_del_registro(): void
    {
        $this->push([$this->record()])->assertOk();

        $scan = InventoryScan::first();
        $this->assertSame(['5.00', 'BUENO', $this->planta->id, 'P-01', 'Caja sellada', 'Sin novedad'], [
            $scan->quantity, $scan->condition->value, $scan->site_id, $scan->pocket, $scan->detail, $scan->observations,
        ]);
    }

    public function test_el_mismo_producto_en_dos_sedes_suma_cantidades(): void
    {
        $cda = Site::where('code', 'CDA')->first();
        $this->push([$this->record(), $this->record(['quantity' => 3, 'site_id' => $cda->id, 'location' => 'CDA'])])->assertOk();

        $this->getJson("/api/v1/inventories/{$this->inventory->id}/take/summary", $this->headers)
            ->assertJsonPath('data.found', 1)          // un producto
            ->assertJsonPath('data.scans', 2)          // dos registros
            ->assertJsonPath('data.units', 8)          // 5 + 3 unidades
            ->assertJsonCount(2, 'data.by_site');
        $this->getJson('/api/v1/inventories', $this->headers)->assertJsonPath('data.0.found_count', 1);
    }

    public function test_una_sede_de_otra_empresa_no_se_asigna_pero_el_registro_se_guarda(): void
    {
        $ajena = $this->company(['code' => 'OTRA', 'name' => 'Otra'])->sites()->create(['code' => 'X', 'name' => 'Ajena']);

        $this->push([$this->record(['site_id' => $ajena->id, 'location' => 'Ajena'])])->assertOk();

        $scan = InventoryScan::first();
        $this->assertNull($scan->site_id);
        $this->assertSame('Ajena', $scan->location);
    }

    public function test_editar_desde_el_equipo_solo_aplica_la_edicion_mas_reciente_del_autor(): void
    {
        $record = $this->record();
        $this->push([$record])->assertOk();

        // Edición nueva: cambia cantidad y estado.
        $this->push([[...$record, 'quantity' => 7, 'condition' => 'MALO', 'updated_at' => '2026-09-28T18:00:00Z']])->assertOk();
        // Edición atrasada (llegó tarde por falta de señal): no pisa la anterior.
        $this->push([[...$record, 'quantity' => 1, 'updated_at' => '2026-09-28T17:30:00Z']])->assertOk();
        // Otro usuario con el mismo uuid: se ignora.
        $otro = $this->makeUser('INVENTARIADOR');
        $this->inventory->assignees()->attach($otro->id);
        $this->push([[...$record, 'quantity' => 99, 'updated_at' => '2026-09-28T19:00:00Z']], $this->authHeaders($otro))->assertOk();

        $scan = InventoryScan::first();
        $this->assertSame(['7.00', 'MALO'], [$scan->quantity, $scan->condition->value]);
        $this->assertSame(1, InventoryScan::count());
    }

    public function test_cambiar_el_codigo_al_editar_recalcula_el_resultado(): void
    {
        $record = $this->record();
        $this->push([$record])->assertOk();

        $this->push([[...$record, 'code' => 'zz-9', 'updated_at' => '2026-09-28T18:00:00Z']])
            ->assertJsonPath('data.accepted.0.outcome', 'SURPLUS');
        $this->assertNull(InventoryScan::first()->asset_id);
    }

    public function test_borrar_registros_desde_el_equipo_es_idempotente_y_solo_los_propios(): void
    {
        $mine = $this->record();
        $theirs = $this->record();
        $this->push([$mine])->assertOk();
        $otro = $this->makeUser('INVENTARIADOR');
        $this->inventory->assignees()->attach($otro->id);
        $this->push([$theirs], $this->authHeaders($otro))->assertOk();

        $url = "/api/v1/inventories/{$this->inventory->id}/scans/delete";
        $this->postJson($url, ['uuids' => [$mine['uuid'], $theirs['uuid']]], $this->headers)->assertOk();
        $this->postJson($url, ['uuids' => [$mine['uuid']]], $this->headers)->assertOk();   // reintento

        $this->assertSame([$theirs['uuid']], InventoryScan::pluck('client_uuid')->all());
    }

    public function test_sedes_para_el_equipo_solo_activas_de_la_empresa(): void
    {
        $this->getJson("/api/v1/inventories/{$this->inventory->id}/sites", $this->headers)
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'CDA');
    }

    public function test_fotos_se_suben_sin_duplicar_hasta_tres_y_se_ven_con_permiso(): void
    {
        $record = $this->record();
        $url = "/api/v1/inventories/{$this->inventory->id}/scans/{$record['uuid']}/photos";
        $photo = fn (?string $uuid = null) => ['uuid' => $uuid ?? (string) Str::uuid(), 'photo' => UploadedFile::fake()->image('foto.jpg', 800, 600)];

        // Antes de que llegue el registro, la foto espera.
        $this->post($url, $photo(), $this->headers)->assertStatus(409)->assertJsonPath('code', 'SCAN_NOT_SYNCED');

        $this->push([$record])->assertOk();
        $first = $photo();
        $id = $this->post($url, $first, $this->headers)->assertCreated()->json('data.id');
        $this->post($url, [...$photo($first['uuid'])], $this->headers)->assertOk();   // reintento: misma foto
        $this->post($url, $photo(), $this->headers)->assertCreated();
        $this->post($url, $photo(), $this->headers)->assertCreated();
        $this->post($url, $photo(), $this->headers)->assertUnprocessable()->assertJsonPath('code', 'PHOTO_LIMIT');

        $this->get("/api/v1/inventories/{$this->inventory->id}/photos/{$id}", $this->headers)->assertOk();
        $cliente = $this->makeUser('CLIENTE', [], $this->company(['code' => 'X2', 'name' => 'X2']));
        $this->get("/api/v1/inventories/{$this->inventory->id}/photos/{$id}", $this->authHeaders($cliente))->assertNotFound();

        // La lista de la web trae las fotos del registro.
        $this->getJson("/api/v1/inventories/{$this->inventory->id}/take/scans", $this->headers)->assertJsonCount(3, 'data.0.photos');
    }

    public function test_borrar_foto_y_registro_borra_los_archivos(): void
    {
        $record = $this->record();
        $this->push([$record])->assertOk();
        $url = "/api/v1/inventories/{$this->inventory->id}/scans/{$record['uuid']}/photos";
        $uuid = (string) Str::uuid();
        $this->post($url, ['uuid' => $uuid, 'photo' => UploadedFile::fake()->image('a.jpg')], $this->headers)->assertCreated();
        $second = (string) Str::uuid();
        $this->post($url, ['uuid' => $second, 'photo' => UploadedFile::fake()->image('b.jpg')], $this->headers)->assertCreated();
        $this->assertCount(2, Storage::disk('local')->allFiles());

        $this->deleteJson("{$url}/{$uuid}", [], $this->headers)->assertOk();
        $this->assertCount(1, Storage::disk('local')->allFiles());

        $this->postJson("/api/v1/inventories/{$this->inventory->id}/scans/delete", ['uuids' => [$record['uuid']]], $this->headers)->assertOk();
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_el_excel_trae_los_registros_con_las_columnas_del_apk_y_los_totales(): void
    {
        $cda = Site::where('code', 'CDA')->first();
        $this->push([$this->record(), $this->record(['quantity' => 3, 'site_id' => $cda->id, 'condition' => 'MALO']), $this->record(['code' => '99999', 'quantity' => 2])])->assertOk();

        $response = $this->get("/api/v1/inventories/{$this->inventory->id}/take/export", $this->authHeaders($this->makeUser('CONTABLE')))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'toma').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $book = IOFactory::load($path);

        $this->assertSame(['Resumen', 'Conciliación', 'Registros'], $book->getSheetNames());
        $records = $book->getSheetByName('Registros')->toArray();
        $this->assertSame(['ID', 'POCKET', 'INVENTARIADOR', 'FECHA_LECTURA', 'CODIGO_PRODUCTO', 'NOMBRE_PRODUCTO', 'UND_MEDIDA', 'CANTIDAD', 'DETALLE_DEL_BIEN', 'ESTADO_CONSERVACION', 'UBICACION_PRODUCTO', 'OBSERVACIONES', 'RESULTADO', 'FOTOS'], $records[0]);
        $this->assertEquals(['P-01', '28/09/2026 12:21:08', '12345', 'GALLETAS', 'CJ', 5, 'BUENO', 'Planta'], [$records[1][1], $records[1][3], $records[1][4], $records[1][5], $records[1][6], $records[1][7], $records[1][9], $records[1][10]]);

        // Conciliación: 5 en Planta + 3 en CDA = 8 contra 1 de la base (cantidad vacía = 1).
        $reconciliation = collect($book->getSheetByName('Conciliación')->toArray())->keyBy(0);
        $this->assertEquals(8, $reconciliation['12345'][5]);
        $this->assertSame('DIFERENCIA POSITIVA', $reconciliation['12345'][8]);
        $this->assertSame('SOBRANTE', $reconciliation['99999'][8]);

        // Los ceros se escriben como 0 (no como celda vacía).
        $this->assertSame('0', (string) $records[1][13]);   // FOTOS del primer registro
        $summary = collect($book->getSheetByName('Resumen')->toArray())->keyBy(0);
        $this->assertSame('0', (string) $summary['Productos faltantes'][1]);
        $this->assertSame('0', (string) $summary['Conciliado'][1]);
    }

    public function test_cambios_en_tiempo_real_traen_ediciones_y_eliminaciones_de_otros_equipos(): void
    {
        $a = $this->record();
        $b = $this->record(['quantity' => 2]);
        $this->push([$a, $b])->assertOk();
        $url = "/api/v1/inventories/{$this->inventory->id}/scans";

        $first = $this->getJson("{$url}?changed_since=2000-01-01T00:00:00Z", $this->headers)->assertOk();
        $this->assertCount(2, $first->json('data'));
        $since = $first->json('meta.server_time');

        $this->travel(5)->seconds();
        // Otro momento: el registro A se edita (5 → 3) y el B se anula en la web.
        $this->push([[...$a, 'quantity' => 3, 'updated_at' => now()->addMinute()->toISOString()]])->assertOk();
        $scanB = InventoryScan::where('client_uuid', $b['uuid'])->first();
        $this->deleteJson("/api/v1/inventories/{$this->inventory->id}/take/scans/{$scanB->id}", [], $this->authHeaders($this->makeUser('CONTABLE')))->assertOk();

        $changes = $this->getJson("{$url}?changed_since={$since}", $this->headers)->assertOk();
        $this->assertSame([$a['uuid']], array_column($changes->json('data'), 'uuid'));   // solo lo que cambió
        $this->assertEquals(3, $changes->json('data.0.quantity'));
        $this->assertSame([$b['uuid']], $changes->json('meta.deleted'));
    }

    public function test_un_reenvio_atrasado_no_revive_un_registro_anulado_en_la_web(): void
    {
        $record = $this->record();
        $this->push([$record])->assertOk();
        $scan = InventoryScan::first();
        $this->deleteJson("/api/v1/inventories/{$this->inventory->id}/take/scans/{$scan->id}", [], $this->authHeaders($this->makeUser('CONTABLE')))->assertOk();

        // El teléfono (sin señal) había editado el registro y lo reenvía después.
        $this->push([[...$record, 'quantity' => 9, 'updated_at' => now()->addMinute()->toISOString()]])
            ->assertOk()
            ->assertJsonPath('data.accepted.0.deleted', true);

        $this->assertSame(0, InventoryScan::count());
    }
}
