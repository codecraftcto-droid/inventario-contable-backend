<?php

namespace Modules\Security\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Alta y edición de usuarios. La contraseña solo se recibe al crear; si se omite
 * se genera una temporal. Para cambiarla después se usa "resetear contraseña".
 */
class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'email' => $this->filled('email') ? mb_strtolower(trim((string) $this->input('email'))) : $this->input('email'),
            'document_number' => $this->filled('document_number') ? mb_strtoupper(trim((string) $this->input('document_number'))) : $this->input('document_number'),
        ]);
    }

    public function rules(): array
    {
        $userId = $this->route('user')?->id;
        $creating = $userId === null;

        return [
            // Con empresa = usuario cliente; sin empresa = personal interno.
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
            'username' => ['required', 'string', 'min:3', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($userId)],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'document_number' => ['required', 'string', 'min:6', 'max:20', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('users', 'document_number')->ignore($userId)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'phone' => ['nullable', 'string', 'max:20'],
            'is_active' => ['sometimes', 'boolean'],
            'password' => $creating
                ? ['nullable', 'string', Password::min(8)->mixedCase()->numbers()]
                : ['prohibited'],
            'role_ids' => ['sometimes', 'array'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'username' => 'usuario',
            'document_number' => 'número de documento',
            'first_name' => 'nombres',
            'last_name' => 'apellidos',
            'role_ids' => 'roles',
        ];
    }

    public function messages(): array
    {
        return [
            'document_number.regex' => 'El número de documento solo puede tener letras, números y guion.',
            'password.prohibited' => 'Para cambiar la contraseña usa la opción "Resetear contraseña".',
        ];
    }
}
