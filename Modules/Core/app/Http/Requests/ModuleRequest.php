<?php

namespace Modules\Core\Http\Requests;

use App\Enums\Platform;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class ModuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('code')) {
            $this->merge(['code' => mb_strtoupper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        $moduleId = $this->route('module')?->id;

        return [
            'parent_id' => ['nullable', 'integer', 'exists:modules,id'],
            'code' => ['required', 'string', 'max:30', 'regex:/^[A-Z][A-Z0-9_]*$/', Rule::unique('modules', 'code')->ignore($moduleId)],
            'name' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
            'icon' => ['nullable', 'string', 'max:100'],
            'path' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'platform' => ['required', new Enum(Platform::class)],
            'is_menu' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'action_ids' => ['sometimes', 'array'],
            'action_ids.*' => ['integer', 'distinct', 'exists:actions,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'parent_id' => 'módulo padre',
            'code' => 'código',
            'name' => 'nombre',
            'path' => 'ruta',
            'sort_order' => 'orden',
            'platform' => 'plataforma',
            'action_ids' => 'acciones',
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'El código debe estar en mayúsculas y solo usar letras, números y guion bajo (ej. INV_TOM).',
        ];
    }
}
