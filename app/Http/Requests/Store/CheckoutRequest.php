<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for the COD checkout submission.
 *
 * `governorate` accepts either an id or a name so the form keeps working
 * whether the browser posts the <select> value (currently the name) or
 * an id (the next iteration of the form). The service resolves either
 * shape against the governorates table.
 */
class CheckoutRequest extends FormRequest
{
    /**
     * Checkout is open to guests and signed-in customers alike; the
     * controller decides whether the eventual order is attached to
     * a customer.
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
            'phone' => ['required', 'string', 'regex:/^01[0125][0-9]{8}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'governorate' => ['required', 'string', 'max:150'],
            'area' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'string', 'in:cod'],
            // Optional payload from the browser when the cart lives
            // only in localStorage (a visitor who never signed in).
            'cart_json' => ['nullable', 'string'],
            // كود الكوبون الاختياري — يُطبَّق على الإجمالي عند إنشاء الأوردر
            'coupon_code' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.regex' => 'Enter a valid Egyptian mobile number (01xxxxxxxxx).',
        ];
    }
}
