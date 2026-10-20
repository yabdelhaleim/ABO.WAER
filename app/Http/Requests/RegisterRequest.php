<?php

namespace App\Http\Requests;

use App\Services\CustomerService;
use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $service = app(CustomerService::class);

        $phone = $service->normalizePhone($this->phone);

        $whatsapp = $this->filled('whatsapp') && ! $this->boolean('whatsapp_same')
            ? $service->normalizePhone($this->whatsapp)
            : $phone;

        $email = $this->filled('email') ? strtolower(trim((string) $this->input('email'))) : null;

        $this->merge([
            'name' => trim((string) $this->input('name')),
            'email' => $email,
            'phone' => $phone,
            'whatsapp' => $whatsapp,
        ]);
    }

    public function rules(): array
    {
        $egyptPhone = 'regex:/^01[0125][0-9]{8}$/';

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'string', 'email:rfc', 'max:191', 'unique:customers,email'],
            'phone' => ['required', $egyptPhone, 'unique:customers,phone'],
            'whatsapp' => ['nullable', $egyptPhone],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'accepts_promotions' => ['nullable', 'boolean'],
            'terms' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Egyptian mobile number (01xxxxxxxxx).',
            'whatsapp.regex' => 'Enter a valid Egyptian WhatsApp number (01xxxxxxxxx).',
            'phone.unique' => 'This phone number already has an account. Please log in.',
            'email.unique' => 'This email is already registered. Please log in.',
            'email.email' => 'Enter a valid email address.',
            'terms.accepted' => 'You must agree to the Terms of Service and Privacy Policy to continue.',
        ];
    }
}
