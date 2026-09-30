<?php

namespace Modules\Inventories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class InventoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $inventory = $this->route('inventory');
        $companyId = $this->input('company_id', $inventory?->company_id);

        return [
            // La empresa no se cambia una vez creado el inventario.
            'company_id' => $inventory ? ['prohibited'] : ['required', 'integer', Rule::exists('companies', 'id')->where('is_active', true)],
            'code' => ['required', 'string', 'max:30', Rule::unique('inventories', 'code')->where('company_id', $companyId)->ignore($inventory?->id)],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'company_id' => 'empresa',
            'code' => 'código',
            'name' => 'nombre',
            'start_date' => 'fecha de inicio',
            'end_date' => 'fecha de fin',
        ];
    }

    public function messages(): array
    {
        return [
            'company_id.prohibited' => 'La empresa de un inventario no se puede cambiar.',
            'code.unique' => 'La empresa ya tiene un inventario con ese código.',
        ];
    }
}
