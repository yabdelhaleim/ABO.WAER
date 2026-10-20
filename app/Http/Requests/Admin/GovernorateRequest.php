<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class GovernorateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        foreach (['allows_cod', 'is_active'] as $flag) {
            if ($this->has($flag)) {
                $data[$flag] = $this->boolean($flag);
            }
        }

        if ($this->has('sort_order') && $this->input('sort_order') !== null) {
            $data['sort_order'] = (int) $this->input('sort_order');
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'shipping_zone_id' => ['required', 'exists:shipping_zones,id'],
            'name' => ['required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'time_from' => ['nullable', 'integer', 'min:1'],
            'time_to' => ['nullable', 'integer', 'gte:time_from'],
            'allows_cod' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
