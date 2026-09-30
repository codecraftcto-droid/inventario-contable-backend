<?php

namespace Modules\Companies\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Companies\Http\Requests\CompanyRequest;
use Modules\Companies\Models\Company;

/**
 * Empresas cliente: solo su registro y el número de usuarios permitido por contrato.
 * Sus usuarios se crean en Seguridad > Usuarios (tipo CLIENTE).
 */
class CompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $companies = Company::query()
            ->withCount([
                'users as active_users_count' => fn ($query) => $query->where('is_active', true),
                'inventories',
                'sites',
            ])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q->where('name', 'like', $term)
                    ->orWhere('code', 'like', $term)
                    ->orWhere('document_number', 'like', $term));
            })
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->tap(fn ($query) => $request->user()->restrictToCompany($query, 'id'))   // Un usuario cliente solo ve su empresa
            ->tap(fn ($query) => $this->applySort($query, $request, ['code', 'name', 'document_number', 'max_users', 'is_active', 'created_at'], 'name'))
            ->paginate($this->perPage($request));

        return $this->paginated($companies, fn (Company $company): array => $this->transform($company));
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json(['data' => $this->transform($this->withCounts($company))]);
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());

        return response()->json([
            'message' => 'Empresa creada correctamente.',
            'data' => $this->transform($this->withCounts($company)),
        ], 201);
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $maxUsers = $request->validated('max_users');
        $activeUsers = $company->users()->where('is_active', true)->count();

        if ($maxUsers !== null && $maxUsers < $activeUsers) {
            throw ValidationException::withMessages([
                'max_users' => "La empresa tiene {$activeUsers} usuario(s) activo(s). Desactiva algunos antes de reducir el límite a {$maxUsers}.",
            ]);
        }

        $company->update($request->validated());

        return response()->json([
            'message' => 'Empresa actualizada correctamente.',
            'data' => $this->transform($this->withCounts($company)),
        ]);
    }

    public function destroy(Company $company): JsonResponse
    {
        if ($company->users()->exists() || $company->inventories()->exists()) {
            throw ValidationException::withMessages([
                'company' => 'La empresa tiene usuarios o inventarios registrados. Desactívala en lugar de eliminarla.',
            ]);
        }

        $company->delete();

        return response()->json(['message' => 'Empresa eliminada correctamente.']);
    }

    private function withCounts(Company $company): Company
    {
        return $company->loadCount([
            'users as active_users_count' => fn ($query) => $query->where('is_active', true),
            'inventories',
            'sites',
        ]);
    }

    private function transform(Company $company): array
    {
        return [
            'id' => $company->id,
            'code' => $company->code,
            'name' => $company->name,
            'document_type' => $company->document_type,
            'document_number' => $company->document_number,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'max_users' => $company->max_users,
            'active_users_count' => $company->active_users_count,
            'can_add_user' => $company->max_users === null || $company->active_users_count < $company->max_users,
            'inventories_count' => $company->inventories_count,
            'sites_count' => $company->sites_count,
            'is_active' => $company->is_active,
            'created_at' => $company->created_at?->toISOString(),
        ];
    }
}
