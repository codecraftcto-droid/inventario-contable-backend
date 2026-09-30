<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Companies\Models\Company;
use Modules\Companies\Models\Site;
use Tests\Concerns\InteractsWithSecurity;
use Tests\TestCase;

/**
 * Opción "Sedes" de cada empresa.
 */
class CompanySitesTest extends TestCase
{
    use InteractsWithSecurity, RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSecurity();
        $this->company = $this->company();
    }

    private function url(?Company $company = null, string $path = ''): string
    {
        return '/api/v1/companies/'.($company ?? $this->company)->id.'/sites'.$path;
    }

    public function test_crud_de_sedes(): void
    {
        $headers = $this->authHeaders($this->adminUser());

        $id = $this->postJson($this->url(), ['code' => ' sede-01 ', 'name' => 'Sede Central', 'address' => 'Av. Arequipa 123'], $headers)
            ->assertCreated()
            ->assertJsonPath('data.code', 'SEDE-01')
            ->assertJsonPath('data.is_active', true)
            ->json('data.id');

        $this->putJson($this->url(path: "/{$id}"), ['code' => 'SEDE-01', 'name' => 'Sede Central Lima', 'is_active' => false], $headers)
            ->assertOk()
            ->assertJsonPath('data.name', 'Sede Central Lima')
            ->assertJsonPath('data.is_active', false);

        $this->getJson($this->url().'?search=lima', $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/companies', $headers)->assertJsonPath('data.0.sites_count', 1);

        $this->deleteJson($this->url(path: "/{$id}"), [], $headers)->assertOk();
        $this->assertSame(0, Site::count());
    }

    public function test_el_codigo_es_unico_dentro_de_la_empresa_pero_se_repite_entre_empresas(): void
    {
        $headers = $this->authHeaders($this->adminUser());
        $other = $this->company(['code' => 'OTRA', 'name' => 'Otra SAC']);

        $this->postJson($this->url(), ['code' => 'SEDE-01', 'name' => 'Central'], $headers)->assertCreated();
        $this->postJson($this->url(), ['code' => 'sede-01', 'name' => 'Duplicada'], $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code' => 'La empresa ya tiene una sede con ese código.']);
        $this->postJson($this->url($other), ['code' => 'SEDE-01', 'name' => 'Central'], $headers)->assertCreated();
    }

    public function test_no_se_puede_tocar_la_sede_desde_otra_empresa(): void
    {
        $headers = $this->authHeaders($this->adminUser());
        $other = $this->company(['code' => 'OTRA', 'name' => 'Otra SAC']);
        $site = $other->sites()->create(['code' => 'S1', 'name' => 'Sede']);

        $this->putJson($this->url(path: "/{$site->id}"), ['code' => 'S1', 'name' => 'Cambio'], $headers)->assertNotFound();
        $this->deleteJson($this->url(path: "/{$site->id}"), [], $headers)->assertNotFound();
    }

    public function test_contable_ve_las_sedes_pero_no_las_modifica(): void
    {
        $this->company->sites()->create(['code' => 'S1', 'name' => 'Sede']);
        $headers = $this->authHeaders($this->makeUser('CONTABLE'));

        $this->getJson($this->url(), $headers)->assertOk()->assertJsonPath('meta.total', 1);
        $this->postJson($this->url(), ['code' => 'S2', 'name' => 'Otra'], $headers)->assertForbidden();
    }

    public function test_un_usuario_cliente_no_accede_a_sedes(): void
    {
        // Empresas (y sus opciones) es un módulo solo para el personal interno.
        $client = $this->makeUser('CLIENTE', [], $this->company);

        $this->getJson($this->url(), $this->authHeaders($client))->assertForbidden();
    }

    public function test_al_eliminar_la_empresa_se_eliminan_sus_sedes(): void
    {
        $this->company->sites()->create(['code' => 'S1', 'name' => 'Sede']);

        $this->deleteJson("/api/v1/companies/{$this->company->id}", [], $this->authHeaders($this->adminUser()))->assertOk();
        $this->assertSame(0, Site::count());
    }
}
