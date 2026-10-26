<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the contact form on the /contact page.
 *
 * `topic` is the browser-side select (about / shipping / returns /
 * order). The server keeps it as the message subject so the admin
 * inbox can triage without a second mapping step.
 */
class ContactMessageRequest extends FormRequest
{
    /**
     * The contact form is public; guests may send a message.
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
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'string', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'topic' => ['nullable', 'string', 'max:150'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Please enter your name.',
            'email.required' => 'Please enter your email.',
            'email.email' => 'Please enter a valid email address.',
            'message.required' => 'Please write your message.',
            'message.min' => 'Message must be at least 10 characters.',
        ];
    }
}
