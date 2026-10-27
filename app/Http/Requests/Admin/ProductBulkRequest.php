<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for the bulk actions of the admin catalog.
 *
 * The admin list used to apply its bulk actions to an in-memory array
 * of sample products and report success without touching the database.
 * The request that drives the real endpoint carries the ids the
 * operator selected and the action they picked.
 */
class ProductBulkRequest extends FormRequest
{
    /**
     * The actions the bulk bar may ask for.
     *
     * `activate` and `archive` flip the is_archived flag, `category`
     * moves the products to another category, and `delete` removes them.
     */
    public const ACTIONS = ['activate', 'archive', 'category', 'delete'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'exists:products,id'],
            'category_id' => [
                'required_if:action,category',
                'nullable',
                'integer',
                'exists:categories,id',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'action.required' => 'Pick a bulk action.',
            'action.in' => 'That bulk action is not supported.',
            'ids.required' => 'Select at least one product.',
            'ids.*.exists' => 'One of the selected products no longer exists.',
            'category_id.required_if' => 'Pick the category to move the products to.',
            'category_id.exists' => 'The selected category does not exist.',
        ];
    }
}
