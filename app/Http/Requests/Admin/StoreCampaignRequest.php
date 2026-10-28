<?php

namespace App\Http\Requests\Admin;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

class StoreCampaignRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preview' => ['nullable', 'string', 'max:255'],
            'message' => ['required', 'string'],
            'type' => ['required', Rule::enum(CampaignType::class)],
            'audience' => ['required', Rule::enum(CampaignAudience::class)],
            'status' => ['sometimes', Rule::enum(CampaignStatus::class)],
            'scheduled_for' => ['nullable', 'date', 'after:now'],
            'coupon' => ['nullable', 'string', 'max:255'],
            'deeplink' => ['nullable', 'string', 'max:255'],
        ];
    }
}
