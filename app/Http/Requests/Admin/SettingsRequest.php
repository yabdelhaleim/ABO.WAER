<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The settings the admin screen sends.
 *
 * The screen sends every section at once, because the operator edits
 * the whole page and saves it in one go. The rules below are the
 * server's answer to what a setting may hold — the browser's own
 * checks are a convenience, this is the one that counts.
 */
class SettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // An empty address is not an invalid address. The screen sends
        // "" for a field the admin cleared, and the email rule would
        // otherwise refuse it.
        if ($this->input('store.email') === '') {
            $this->merge(['store.email' => null]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'store' => ['required', 'array'],
            'store.name' => ['required', 'string', 'max:120'],
            'store.phone' => ['nullable', 'string', 'max:40'],
            'store.whatsapp' => ['nullable', 'string', 'max:40'],
            'store.email' => ['nullable', 'email', 'max:160'],
            'store.address' => ['nullable', 'string', 'max:255'],
            'store.logo' => ['nullable', 'string', 'max:255'],
            'store.favicon' => ['nullable', 'string', 'max:255'],
            'store.social' => ['sometimes', 'array'],
            'store.social.instagram' => ['nullable', 'string', 'max:160'],
            'store.social.facebook' => ['nullable', 'string', 'max:160'],
            'store.social.tiktok' => ['nullable', 'string', 'max:160'],
            'store.maintenance' => ['required', 'boolean'],

            'orders' => ['required', 'array'],
            'orders.minimum' => ['nullable', 'numeric', 'min:0'],
            'orders.autoCancelHours' => ['nullable', 'integer', 'min:1', 'max:720'],
            'orders.guestCheckout' => ['required', 'boolean'],
            'orders.prefix' => ['required', 'string', 'max:10'],

            'inventory' => ['required', 'array'],
            'inventory.lowStockThreshold' => ['nullable', 'integer', 'min:0'],
            'inventory.hideOutOfStock' => ['required', 'boolean'],

            'reasons' => ['sometimes', 'array', 'max:100'],
            'reasons.*.id' => ['nullable'],
            'reasons.*.en' => ['nullable', 'string', 'max:120'],
            'reasons.*.ar' => ['nullable', 'string', 'max:120'],
            'reasons.*.active' => ['sometimes', 'boolean'],

            'receipts' => ['required', 'array'],
            'receipts.showLogo' => ['required', 'boolean'],
            'receipts.headerText' => ['nullable', 'string', 'max:40'],
            'receipts.footerNote' => ['nullable', 'string', 'max:160'],
            'receipts.fields' => ['sometimes', 'array'],
            'receipts.fields.*' => ['boolean'],
            'receipts.paper' => ['required', Rule::in(['a4', 'thermal'])],

            'notifications' => ['required', 'array'],
            'notifications.newOrder' => ['required', 'boolean'],
            'notifications.lowStock' => ['required', 'boolean'],
            'notifications.returnRequest' => ['required', 'boolean'],
            'notifications.shipmentFailed' => ['required', 'boolean'],
            'notifications.couponExpiring' => ['required', 'boolean'],
            'notifications.browserSound' => ['required', 'boolean'],
        ];
    }
}
