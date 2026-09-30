<?php

namespace Modules\Inventories\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Se valida la unicidad con el mismo formato con el que se guarda (mayúsculas, sin espacios).
        if (is_string($this->input('codigo'))) {
            $this->merge(['codigo' => mb_strtoupper(trim($this->input('codigo')))]);
        }
    }

    public function rules(): array
    {
        $inventoryId = $this->route('inventory')->id;
        $assetId = $this->route('asset')?->id;

        return [
            // El código es único dentro del inventario (otras empresas pueden repetirlo).
            'codigo' => ['required', 'string', 'max:50', Rule::unique('assets', 'codigo')->where('inventory_id', $inventoryId)->ignore($assetId)],
            'nombre' => ['nullable', 'string', 'max:255'],
            'unidad_medida' => ['nullable', 'string', 'max:50'],
            'cantidad' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'valor' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ];
    }

    public function attributes(): array
    {
        return ['codigo' => 'código de producto', 'nombre' => 'nombre de producto', 'unidad_medida' => 'unidad de medida', 'cantidad' => 'cantidad', 'valor' => 'valor'];
    }
}
