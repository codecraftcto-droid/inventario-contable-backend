<?php

namespace Modules\Security\Http\Requests;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'login' => ['required', 'string', 'max:150'],         // número de documento, correo o usuario
            'password' => ['required', 'string', 'max:255'],
            'platform' => ['sometimes', Rule::in(Platform::clientValues())],
            'device_id' => ['required_if:platform,MOVIL', 'nullable', 'string', 'max:100'],
            'device_name' => ['nullable', 'string', 'max:150'],
        ];
    }

    public function attributes(): array
    {
        return [
            'login' => 'documento o correo',
            'device_id' => 'identificador del dispositivo',
        ];
    }
}
