<?php

namespace App\Http\Requests\Store;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class CancelOrderRequest extends FormRequest
{
    /**
     * The `auth` middleware on the route group already refuses guests, so
     * this is a backstop rather than the primary gate. Ownership of the
     * order itself is checked in the controller, which answers 404 rather
     * than 403 so one customer cannot probe for another's orders.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cancellation_reason_id' => ['nullable', 'integer', 'exists:cancellation_reasons,id'],
            'cancellation_note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cancellation_reason_id.exists' => 'The selected cancellation reason is not available.',
        ];
    }
}
