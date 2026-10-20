<?php

namespace App\Models;

use App\Enums\CampaignAudience;
use App\Enums\CampaignStatus;
use App\Enums\CampaignType;
use Database\Factories\CampaignFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Campaign extends Model
{
    /** @use HasFactory<CampaignFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'type' => CampaignType::class,
            'audience' => CampaignAudience::class,
            'status' => CampaignStatus::class,
            'scheduled_for' => 'datetime',
            'sent_at' => 'datetime',
            'sent_count' => 'integer',
            'opened_count' => 'integer',
            'clicked_count' => 'integer',
        ];
    }

    /**
     * حساب نسبة الفتح بدقة مع تجنب القسمة على صفر.
     */
    public function openRate(): float
    {
        if ($this->sent_count <= 0) {
            return 0.0;
        }

        return round(($this->opened_count / $this->sent_count) * 100, 2);
    }

    /**
     * حساب نسبة النقر بدقة مع تجنب القسمة على صفر.
     */
    public function clickRate(): float
    {
        if ($this->sent_count <= 0) {
            return 0.0;
        }

        return round(($this->clicked_count / $this->sent_count) * 100, 2);
    }

    /**
     * تحويل الحملة إلى الشكل الذي تستهلكه واجهة campaigns.js
     * في لوحة الأدمن (id ثابت كـ c-XXX لتطابق الترقيم الحالي).
     *
     * @return array<string, mixed>
     */
    public function toAdminPayload(): array
    {
        $schedule = null;

        if ($this->scheduled_for) {
            $schedule = [
                'date' => $this->scheduled_for->format('Y-m-d'),
                'time' => $this->scheduled_for->format('H:i'),
            ];
        }

        $metrics = $this->sent_count > 0
            ? [
                'sent' => $this->sent_count,
                'opened' => (int) round($this->openRate()),
                'clicked' => (int) round($this->clickRate()),
            ]
            : [
                'sent' => null,
                'opened' => null,
                'clicked' => null,
            ];

        return [
            'id' => 'c-'.str_pad((string) $this->id, 3, '0', STR_PAD_LEFT),
            'name' => $this->title,
            'preview' => $this->preview,
            'type' => $this->type->value,
            'audience' => $this->audience->label(),
            // The raw stored segment. `audience` above is the display label the
            // table renders; the slide-over needs the value to re-select the
            // matching card when the admin reopens a saved campaign.
            'audience_value' => $this->audience->value,
            'status' => $this->status->value,
            'schedule' => $schedule,
            'sent' => $metrics['sent'],
            'opened' => $metrics['opened'],
            'clicked' => $metrics['clicked'],
        ];
    }

    /**
     * استعلام للحملات المجدولة المستحقة للإرسال (لـ Cron).
     *
     * @param  Builder<Campaign>  $query
     * @return Builder<Campaign>
     */
    public function scopeScheduledForSending(Builder $query): Builder
    {
        return $query->where('status', CampaignStatus::Scheduled->value)
            ->whereNotNull('scheduled_for')
            ->where('scheduled_for', '<=', now());
    }
}
