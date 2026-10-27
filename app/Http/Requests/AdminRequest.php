<?php

namespace App\Http\Requests;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and editing a back-office account.
 *
 * The same request serves both: the presence of the `admin` route
 * parameter is what tells the rules apart, so the create form has to
 * carry a password while the edit form may leave it alone.
 */
class AdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Fold the address down before it is validated.
     *
     * The back office stores its addresses in lower case, so that two
     * spellings of the same address can never become two accounts. The
     * uniqueness rule below therefore compares like with like.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => mb_strtolower(trim($email))]);
        }
    }

    public function rules(): array
    {
        /** @var Admin|null $admin */
        $admin = $this->route('admin');
        $adminId = $admin instanceof Admin ? $admin->id : null;

        return [
            'name' => ['required', 'string', 'max:100'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('admins', 'email')->ignore($adminId),
            ],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'password' => [$adminId === null ? 'required' : 'nullable', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'email.unique' => 'Another admin already uses this address.',
            'role.required' => 'Pick a role for this admin.',
            'role.enum' => 'That role does not exist.',
            'password.confirmed' => 'The two passwords do not match.',
        ];
    }
}
