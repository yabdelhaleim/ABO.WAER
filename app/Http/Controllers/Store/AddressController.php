<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Http\Requests\Store\AddressRequest;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Services\AddressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function __construct(protected AddressService $addresses) {}

    /**
     * The customer's address book, default address first.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'addresses' => $this->addresses->getAddressesForCustomer($this->customer($request)),
        ]);
    }

    /**
     * Add an address. A customer's first address becomes their default.
     */
    public function store(AddressRequest $request): JsonResponse
    {
        $address = $this->addresses->createAddress(
            $this->customer($request),
            $request->validated(),
        );

        return response()->json([
            'success' => true,
            'message' => 'Address added successfully.',
            'address' => $address,
        ], 201);
    }

    /**
     * Edit one of the customer's addresses.
     */
    public function update(AddressRequest $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwnedBy($request, $address);

        return response()->json([
            'success' => true,
            'message' => 'Address updated successfully.',
            'address' => $this->addresses->updateAddress($address, $request->validated()),
        ]);
    }

    /**
     * Delete one of the customer's addresses.
     */
    public function destroy(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwnedBy($request, $address);

        $this->addresses->deleteAddress($address);

        return response()->json([
            'success' => true,
            'message' => 'Address deleted successfully.',
        ]);
    }

    /**
     * Choose which address checkout pre-selects.
     */
    public function makeDefault(Request $request, CustomerAddress $address): JsonResponse
    {
        $this->assertOwnedBy($request, $address);

        return response()->json([
            'success' => true,
            'message' => 'Default address updated.',
            'address' => $this->addresses->setDefaultAddress($address),
        ]);
    }

    /**
     * Refuse to act on somebody else's address.
     *
     * 404 rather than 403: a 403 would confirm that the row exists, which is
     * itself information the caller is not entitled to.
     */
    protected function assertOwnedBy(Request $request, CustomerAddress $address): void
    {
        if ($address->customer_id !== $this->customer($request)?->id) {
            abort(404);
        }
    }

    /**
     * The signed-in customer. Guaranteed non-null by the `auth` middleware on
     * the route group.
     */
    protected function customer(Request $request): ?Customer
    {
        return $request->user();
    }
}
