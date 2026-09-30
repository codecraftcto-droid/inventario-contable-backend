<?php

namespace Modules\Security\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('name')) {
            $this->merge(['name' => mb_strtoupper(trim((string) $this->input('name')))]);
        }
    }

    public function rules(): array
    {
        $roleId = $this->route('role')?->id;

        return [
            'name' => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_ ]+$/u', Rule::unique('roles', 'name')->where('guard_name', 'api')->ignore($roleId)],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'permission_ids' => ['sometimes', 'array'],
            'permission_ids.*' => ['integer', 'distinct', 'exists:permissions,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'nombre',
            'permission_ids' => 'permisos',
        ];
    }

    public function messages(): array
    {
        return [
            'name.regex' => 'El nombre solo puede tener letras, números, espacios y guion bajo.',
        ];
    }
}
