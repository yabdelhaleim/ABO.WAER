<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddressRequest extends FormRequest
{
    /**
     * The `auth` middleware on the route group already refuses guests, so
     * this is a backstop rather than the primary gate.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        // These arrive as real booleans and integers from a JSON body, but a
        // form-encoded payload would hand over "1" and the strings above.
        if ($this->has('governorate_id')) {
            $data['governorate_id'] = (int) $this->input('governorate_id');
        }

        // Only when the client actually sent it: is_default is a `sometimes`
        // rule, and synthesising a false here would silently strip the flag
        // from an existing default address on every unrelated edit.
        if ($this->has('is_default')) {
            $data['is_default'] = $this->boolean('is_default');
        }

        if ($data !== []) {
            $this->merge($data);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $egyptPhone = 'regex:/^01[0125][0-9]{8}$/';

        return [
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', $egyptPhone],
            'area' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Egyptian mobile number (01xxxxxxxxx).',
            'governorate_id.exists' => 'The selected governorate is not available.',
        ];
    }
}
