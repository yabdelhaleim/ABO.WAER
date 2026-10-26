<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the newsletter sign-up form.
 *
 * The form is reachable from the home page footer, so the endpoint is
 * anonymous. `email` is the only field; `source` is filled in by the
 * controller from the referrer so the store knows which page produced
 * the subscription.
 */
class NewsletterRequest extends FormRequest
{
    /**
     * Subscribing is open to anyone; the store is not gated behind auth.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:150'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Enter your email address to join the list.',
            'email.email' => 'That does not look like a valid email address.',
        ];
    }
}
