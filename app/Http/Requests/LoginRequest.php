<?php

namespace App\Http\Requests;

use App\Services\CustomerService;
use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * The original identifier field name submitted by the user
     * (used to keep validation error keys consistent with the
     * field the user actually filled in).
     */
    protected string $submittedField = 'identifier';

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $service = app(CustomerService::class);

        if ($this->has('identifier')) {
            $identifier = trim((string) $this->input('identifier'));
            $this->submittedField = 'identifier';
        } else {
            $identifier = trim((string) $this->input('phone', ''));
            $this->submittedField = 'phone';
        }

        $this->merge([
            'identifier' => $identifier,
            'phone' => $this->looksLikePhone($identifier)
                ? $service->normalizePhone($identifier)
                : null,
            'email' => $this->looksLikeEmail($identifier) ? strtolower($identifier) : null,
        ]);
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string'],
            'password' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'identifier.required' => 'Enter your email or phone number to continue.',
        ];
    }

    public function submittedField(): string
    {
        return $this->submittedField;
    }

    protected function looksLikePhone(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        return (bool) preg_match('/[+\d]/', $value);
    }

    protected function looksLikeEmail(string $value): bool
    {
        return (bool) filter_var($value, FILTER_VALIDATE_EMAIL);
    }
}
