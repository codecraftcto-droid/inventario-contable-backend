<?php

namespace Modules\Security\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Security\Http\Requests\UserRequest;
use Modules\Security\Models\UserSession;
use Modules\Security\Services\UserService;

class UserController extends Controller
{
    public function __construct(private readonly UserService $users) {}

    public function index(Request $request): JsonResponse
    {
        $users = User::query()
            ->with(['roles:id,name', 'company:id,code,name'])
            ->withCount(['sessions as active_sessions_count' => fn ($query) => $query->active()])
            ->when($request->filled('search'), function ($query) use ($request): void {
                $term = "%{$request->string('search')}%";
                $query->where(fn ($q) => $q
                    ->where('username', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('first_name', 'like', $term)
                    ->orWhere('last_name', 'like', $term)
                    ->orWhere('document_number', 'like', $term));
            })
            // user_type=INTERNO (sin empresa) | CLIENTE (con empresa)
            ->when($request->input('user_type') === 'INTERNO', fn ($query) => $query->whereNull('company_id'))
            ->when($request->input('user_type') === 'CLIENTE', fn ($query) => $query->whereNotNull('company_id'))
            ->when($request->filled('company_id'), fn ($query) => $query->where('company_id', $request->integer('company_id')))
            ->when($request->filled('role_id'), fn ($query) => $query->whereHas('roles', fn ($q) => $q->whereKey($request->integer('role_id'))))
            ->when($request->has('is_active'), fn ($query) => $query->where('is_active', $request->boolean('is_active')))
            ->tap(fn ($query) => $this->applySort($query, $request, ['username', 'email', 'document_number', 'first_name', 'last_name', 'is_active', 'last_login_at', 'created_at'], 'first_name'))
            ->paginate($this->perPage($request));

        return $this->paginated($users, fn (User $user): array => $this->transform($user));
    }

    public function store(UserRequest $request): JsonResponse
    {
        [$user, $temporaryPassword] = $this->users->create($request->validated());

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'data' => [
                ...$this->transform($user->fresh(['roles', 'company'])),
                // Solo se muestra una vez para entregarla al usuario.
                'temporary_password' => $temporaryPassword,
            ],
        ], 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json([
            'data' => $this->transform($user->load(['roles:id,name', 'company:id,code,name'])),
        ]);
    }

    public function update(UserRequest $request, User $user): JsonResponse
    {
        $user = $this->users->update($user, $request->validated(), $request->user());

        return response()->json([
            'message' => 'Usuario actualizado correctamente.',
            'data' => $this->transform($user->fresh(['roles', 'company'])),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->users->delete($user, $request->user());

        return response()->json(['message' => 'Usuario eliminado correctamente.']);
    }

    /**
     * Activar / desactivar. Al desactivar se cierran todas sus sesiones.
     */
    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);

        $user = $this->users->setActive($user, $data['is_active'], $request->user());

        return response()->json([
            'message' => $user->is_active ? 'Usuario activado.' : 'Usuario desactivado y sus sesiones cerradas.',
            'data' => $this->transform($user->fresh(['roles', 'company'])),
        ]);
    }

    public function unlock(User $user): JsonResponse
    {
        $user = $this->users->unlock($user);

        return response()->json([
            'message' => 'Usuario desbloqueado.',
            'data' => $this->transform($user->fresh(['roles', 'company'])),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $temporaryPassword = $this->users->resetPassword($request, $user, $request->user());

        return response()->json([
            'message' => 'Contraseña reseteada. El usuario deberá cambiarla en su próximo ingreso.',
            'data' => ['temporary_password' => $temporaryPassword],
        ]);
    }

    public function syncRoles(Request $request, User $user): JsonResponse
    {
        $data = $request->validate([
            'role_ids' => ['present', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ]);

        $this->users->syncRoles($user, $data['role_ids']);

        return response()->json([
            'message' => 'Roles actualizados correctamente.',
            'data' => $this->transform($user->fresh(['roles', 'company'])),
        ]);
    }

    private function transform(User $user): array
    {
        return [
            'id' => $user->id,
            'user_type' => $user->user_type->value,
            'company' => $user->company ? [
                'id' => $user->company->id,
                'code' => $user->company->code,
                'name' => $user->company->name,
            ] : null,
            'username' => $user->username,
            'email' => $user->email,
            'document_number' => $user->document_number,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'is_active' => $user->is_active,
            'is_root_admin' => $user->isRootAdmin(),
            'is_locked' => $user->isLocked(),
            'locked_until' => $user->locked_until?->toISOString(),
            'must_change_password' => $user->must_change_password,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'active_sessions_count' => $user->active_sessions_count ?? UserSession::query()->active()->where('user_id', $user->id)->count(),
            'roles' => $user->roles->map(fn ($role): array => ['id' => $role->id, 'name' => $role->name])->values()->all(),
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
