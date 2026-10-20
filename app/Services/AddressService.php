<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class AddressService
{
    /**
     * Every address the customer owns, default first, with the shipping
     * details needed to price and label a parcel.
     *
     * @return Collection<int, CustomerAddress>
     */
    public function getAddressesForCustomer(Customer $customer): Collection
    {
        return $customer->addresses()->with('governorate.zone')->get();
    }

    /**
     * Add an address for a customer.
     *
     * The customer's first address is always the default one, otherwise
     * checkout would have nothing pre-selected.
     *
     * @param  array<string, mixed>  $data
     */
    public function createAddress(Customer $customer, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($customer, $data) {
            $isDefault = ! $customer->addresses()->exists() || ! empty($data['is_default']);

            if ($isDefault) {
                $this->clearDefaultExcept($customer, null);
            }

            $data['customer_id'] = $customer->id;
            $data['is_default'] = $isDefault;

            return CustomerAddress::create($data)->load('governorate.zone');
        });
    }

    /**
     * Update an address the customer already owns.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateAddress(CustomerAddress $address, array $data): CustomerAddress
    {
        return DB::transaction(function () use ($address, $data) {
            if (! empty($data['is_default']) && ! $address->is_default) {
                $this->clearDefaultExcept($address->customer, $address->id);
            }

            $address->update($data);

            return $address->fresh(['governorate.zone']);
        });
    }

    /**
     * Delete an address, promoting the most recent remaining one when the
     * address that goes away was the default.
     */
    public function deleteAddress(CustomerAddress $address): void
    {
        DB::transaction(function () use ($address) {
            $customer = $address->customer;
            $wasDefault = $address->is_default;

            $address->delete();

            if ($wasDefault) {
                $customer->addresses()->first()?->update(['is_default' => true]);
            }
        });
    }

    /**
     * Promote one address and demote every other address of the same
     * customer, so exactly one default is ever flagged.
     */
    public function setDefaultAddress(CustomerAddress $address): CustomerAddress
    {
        return DB::transaction(function () use ($address) {
            $this->clearDefaultExcept($address->customer, $address->id);

            $address->update(['is_default' => true]);

            return $address->fresh();
        });
    }

    /**
     * Demote every default address belonging to this customer, optionally
     * sparing one row id.
     *
     * Queried off the model rather than through `addresses()`, whose display
     * ordering has no meaning on an update.
     */
    protected function clearDefaultExcept(Customer $customer, ?int $exceptId): void
    {
        CustomerAddress::where('customer_id', $customer->id)
            ->where('is_default', true)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->update(['is_default' => false]);
    }
}
