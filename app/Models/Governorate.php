<?php

namespace App\Models;

use Database\Factories\GovernorateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Governorate extends Model
{
    /** @use HasFactory<GovernorateFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'fee' => 'float',
            'time_from' => 'integer',
            'time_to' => 'integer',
            'allows_cod' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ShippingZone, $this>
     */
    public function zone(): BelongsTo
    {
        return $this->belongsTo(ShippingZone::class, 'shipping_zone_id');
    }

    /**
     * The fee actually applied to this governorate — its own when set,
     * otherwise the fee inherited from its zone.
     */
    public function getEffectiveFeeAttribute(): float
    {
        return $this->fee !== null ? (float) $this->fee : (float) ($this->zone?->fee ?? 0);
    }
}
