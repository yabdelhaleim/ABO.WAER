<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShippingZoneRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
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
        // A new zone needs the whole row; an update may carry the one
        // field that changed — the switch on a row, for instance.
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        return [
            'name' => [$isUpdate ? 'sometimes' : 'required', 'string', 'max:150'],
            'name_ar' => ['nullable', 'string', 'max:150'],
            'fee' => [$isUpdate ? 'sometimes' : 'required', 'numeric', 'min:0'],
            'time_from' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'min:1', 'max:30'],
            'time_to' => [$isUpdate ? 'sometimes' : 'required', 'integer', 'gte:time_from', 'max:60'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
