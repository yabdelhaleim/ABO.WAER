<?php

namespace App\Models;

use Database\Factories\CustomerAddressFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerAddress extends Model
{
    /** @use HasFactory<CustomerAddressFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Governorate, $this>
     */
    public function governorate(): BelongsTo
    {
        return $this->belongsTo(Governorate::class);
    }

    /**
     * The address as a single line, for the address book and order summaries.
     * The Arabic governorate name is preferred when the shipping catalog
     * carries one.
     */
    public function getFormattedAddressAttribute(): string
    {
        $governorate = $this->governorate?->name_ar ?: $this->governorate?->name;

        return collect([
            $this->address,
            $this->area,
            $governorate,
        ])->filter()->implode(', ');
    }
}
