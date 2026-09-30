<?php

namespace Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Companies\Http\Requests\SiteRequest;
use Modules\Companies\Models\Company;
use Modules\Companies\Models\Site;

/**
 * Sedes de una empresa (opción "Sedes" de cada fila del listado de empresas).
 */
class SiteController extends Controller
{
    public function index(Request $request, Company $company): JsonResponse
    {
        $sites = $company->sites()
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('code', 'like', $term)
                    ->orWhere('name', 'like', $term)
                    ->orWhere('address', 'like', $term));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'name', 'is_active', 'created_at'], 'name'))
            ->paginate($this->perPage($request));

        return $this->paginated($sites, fn (Site $site): array => $this->transform($site));
    }

    public function store(SiteRequest $request, Company $company): JsonResponse
    {
        $site = $company->sites()->create($request->validated());

        return response()->json(['message' => 'Sede creada correctamente.', 'data' => $this->transform($site)], 201);
    }

    public function update(SiteRequest $request, Company $company, Site $site): JsonResponse
    {
        $site->update($request->validated());

        return response()->json(['message' => 'Sede actualizada correctamente.', 'data' => $this->transform($site)]);
    }

    public function destroy(Company $company, Site $site): JsonResponse
    {
        $site->delete();

        return response()->json(['message' => 'Sede eliminada correctamente.']);
    }

    private function transform(Site $site): array
    {
        return [
            'id' => $site->id,
            'code' => $site->code,
            'name' => $site->name,
            'address' => $site->address,
            'is_active' => $site->is_active,
            'created_at' => $site->created_at?->toISOString(),
        ];
    }
}
