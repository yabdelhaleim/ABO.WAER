<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class BannerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = [];

        if ($this->has('is_active')) {
            $data['is_active'] = $this->boolean('is_active');
        }

        if ($this->has('remove_image')) {
            $data['remove_image'] = $this->boolean('remove_image');
        }

        if ($this->has('remove_mobile_image')) {
            $data['remove_mobile_image'] = $this->boolean('remove_mobile_image');
        }

        if ($this->has('sort_order') && $this->input('sort_order') !== null) {
            $data['sort_order'] = (int) $this->input('sort_order');
        }

        // The admin screen has one button label, so the Arabic copy of
        // it is cleared on save. Leaving a stale Arabic label behind
        // would keep showing on the storefront after the admin edited
        // the button.
        if ($this->has('button_text')) {
            $data['button_text_ar'] = null;
        }

        if (! empty($data)) {
            $this->merge($data);
        }
    }

    public function rules(): array
    {
        $isUpdate = $this->isMethod('PUT') || $this->isMethod('PATCH');

        // An announcement has no picture, so it is the one placement
        // that may be created without an image.
        $placement = (string) $this->input('placement', 'hero');
        $imageRequired = ! $isUpdate && $placement !== 'announcement';

        return [
            'placement' => ['sometimes', Rule::in(['hero', 'promo', 'announcement'])],
            'title' => ['nullable', 'string', 'max:200'],
            'title_ar' => ['nullable', 'string', 'max:200'],
            'subtitle' => ['nullable', 'string', 'max:300'],
            'subtitle_ar' => ['nullable', 'string', 'max:300'],
            'button_text' => ['nullable', 'string', 'max:100'],
            'button_text_ar' => ['nullable', 'string', 'max:100'],
            'button_url' => ['nullable', 'string', 'max:500'],
            'alt' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'text_color' => ['sometimes', Rule::in(['light', 'dark'])],
            'text_position' => ['sometimes', Rule::in(['left', 'center', 'right'])],
            'overlay' => ['sometimes', Rule::in(['none', 'light', 'dark'])],
            'link_type' => ['sometimes', Rule::in(['none', 'category', 'product', 'url'])],
            'link_target' => ['nullable', 'string', 'max:255'],
            'link_url' => [
                'nullable',
                'string',
                'max:500',
                function (string $attribute, mixed $value, callable $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }

                    if (! str_starts_with($value, '/') && ! str_starts_with($value, 'https://')) {
                        $fail('The link must start with / or https://');
                    }
                },
            ],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            // Banner image — strict mime allow-list of real image formats
            // (no SVG, no executables renamed), capped at 2 MB so the
            // hero banner can be lazy-loaded on a phone connection.
            'image' => [
                $imageRequired ? 'required' : 'nullable',
                'image',
                'mimes:jpg,jpeg,png,webp',
                'max:2048',
            ],
            'mobile_image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_image' => ['sometimes', 'boolean'],
            'remove_mobile_image' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /**
     * The schedule window has to end after it starts. The rule is
     * written here rather than with after_or_equal because that rule
     * compares against "now" when the other end of the window is
     * empty, and a banner may have only one bound.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $starts = $this->input('starts_at');
            $ends = $this->input('ends_at');

            if (! $starts || ! $ends) {
                return;
            }

            if (strtotime((string) $ends) <= strtotime((string) $starts)) {
                $validator->errors()->add('ends_at', 'The end date must be after the start date.');
            }
        });
    }
}
