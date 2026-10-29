<?php

namespace App\Http\Requests\Admin;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class UpdateCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string|In>>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subject' => ['sometimes', 'required', 'string', 'max:255'],
            'preview' => ['sometimes', 'nullable', 'string', 'max:255'],
            'message' => ['sometimes', 'required', 'string'],
            'type' => ['sometimes', 'required', Rule::enum(CampaignType::class)],
            'audience' => ['sometimes', 'required', Rule::enum(CampaignAudience::class)],
            'status' => ['sometimes', Rule::enum(CampaignStatus::class)],
            'scheduled_for' => ['sometimes', 'nullable', 'date', 'after:now'],
            'coupon' => ['sometimes', 'nullable', 'string', 'max:255'],
            'deeplink' => ['sometimes', 'nullable', 'string', 'max:255'],
            'image' => ['sometimes', 'nullable', 'file', 'image', 'max:5120'],
            'remove_image' => ['sometimes', 'nullable', 'boolean'],
        ];
    }
}
