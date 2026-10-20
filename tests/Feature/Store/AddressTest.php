<?php

namespace Tests\Feature\Store;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Governorate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * HTTP surface of the customer address book: /account/addresses (index,
 * store, update, destroy, default).
 *
 * Two invariants are under test throughout — a customer only ever reaches
 * their own addresses, and they only ever have exactly one default.
 */
class AddressTest extends TestCase
{
    use RefreshDatabase;

    protected Customer $customer;

    protected Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customer = Customer::factory()->create();
        $this->governorate = Governorate::factory()->create([
            'name' => 'Cairo',
            'name_ar' => 'القاهرة',
        ]);
    }

    // ─── Reading the address book ───────────────────────────────

    public function test_customer_can_list_their_addresses(): void
    {
        CustomerAddress::factory()
            ->for($this->customer)
            ->for($this->governorate)
            ->create(['area' => 'Dokki']);

        $this->actingAs($this->customer)
            ->getJson(route('addresses.index'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('addresses.0.area', 'Dokki')
            ->assertJsonPath('addresses.0.governorate.name', 'Cairo');
    }

    public function test_address_list_puts_the_default_first(): void
    {
        $newest = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();
        $default = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->getJson(route('addresses.index'))
            ->assertOk()
            ->assertJsonPath('addresses.0.id', $default->id)
            ->assertJsonPath('addresses.1.id', $newest->id);
    }

    public function test_address_list_omits_another_customers_addresses(): void
    {
        CustomerAddress::factory()->count(2)->for($this->customer)->for($this->governorate)->create();
        CustomerAddress::factory()->for(Customer::factory())->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->getJson(route('addresses.index'))
            ->assertOk()
            ->assertJsonCount(2, 'addresses');
    }

    public function test_guests_cannot_read_the_address_book(): void
    {
        $this->getJson(route('addresses.index'))->assertUnauthorized();
    }

    // ─── Adding ─────────────────────────────────────────────────

    public function test_first_address_created_becomes_default_automatically(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['area' => 'Dokki']))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('address.is_default', true)
            ->assertJsonPath('address.customer_id', $this->customer->id);

        $this->assertDatabaseHas('customer_addresses', [
            'customer_id' => $this->customer->id,
            'area' => 'Dokki',
            'is_default' => true,
        ]);
    }

    public function test_a_later_address_does_not_steal_the_default(): void
    {
        $first = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['area' => 'Nasr City']))
            ->assertCreated()
            ->assertJsonPath('address.is_default', false);

        $this->assertTrue($first->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_asking_for_the_default_demotes_the_previous_one(): void
    {
        $previous = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['area' => 'Maadi', 'is_default' => true]))
            ->assertCreated()
            ->assertJsonPath('address.is_default', true);

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_a_new_address_stores_the_notes(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['notes' => 'Ring the bell twice']))
            ->assertCreated()
            ->assertJsonPath('address.notes', 'Ring the bell twice');

        $this->assertDatabaseHas('customer_addresses', ['notes' => 'Ring the bell twice']);
    }

    public function test_address_is_never_attached_to_another_customer(): void
    {
        $other = Customer::factory()->create();

        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['customer_id' => $other->id]))
            ->assertCreated()
            ->assertJsonPath('address.customer_id', $this->customer->id);

        $this->assertDatabaseMissing('customer_addresses', ['customer_id' => $other->id]);
    }

    public function test_adding_an_address_requires_the_checkout_fields(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['governorate_id', 'name', 'phone', 'area', 'address']);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    public function test_adding_an_address_rejects_an_unknown_governorate(): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['governorate_id' => 999999]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['governorate_id']);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidPhoneNumbers(): array
    {
        return [
            'prefix not issued in Egypt' => ['01312345678'],
            'wrong length' => ['0101234567'],
            'not a mobile' => ['02112345678'],
            'contains letters' => ['0101234567a'],
        ];
    }

    #[DataProvider('invalidPhoneNumbers')]
    public function test_adding_an_address_rejects_an_invalid_phone(string $phone): void
    {
        $this->actingAs($this->customer)
            ->postJson(route('addresses.store'), $this->payload(['phone' => $phone]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);

        $this->assertDatabaseCount('customer_addresses', 0);
    }

    // ─── Editing ────────────────────────────────────────────────

    public function test_customer_can_update_their_address(): void
    {
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create([
            'area' => 'Old Area',
        ]);

        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload(['area' => 'New Area']))
            ->assertOk()
            ->assertJsonPath('address.area', 'New Area');

        $this->assertDatabaseHas('customer_addresses', [
            'id' => $address->id,
            'area' => 'New Area',
        ]);
    }

    public function test_editing_an_address_leaves_its_default_flag_alone(): void
    {
        $address = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();

        // is_default is absent from the payload; the edit must not be read as
        // "make this one not the default".
        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload(['area' => 'New Area']))
            ->assertOk();

        $this->assertTrue($address->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_editing_can_promote_the_address_to_default(): void
    {
        $previous = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload(['is_default' => true]))
            ->assertOk()
            ->assertJsonPath('address.is_default', true);

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_editing_an_address_cannot_reassign_its_owner(): void
    {
        $other = Customer::factory()->create();
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload(['customer_id' => $other->id]))
            ->assertOk();

        $this->assertSame($this->customer->id, $address->fresh()->customer_id);
    }

    public function test_editing_validates_the_payload(): void
    {
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create([
            'area' => 'Untouched',
        ]);

        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload(['area' => '']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area']);

        $this->assertSame('Untouched', $address->fresh()->area);
    }

    // ─── Deleting ───────────────────────────────────────────────

    public function test_customer_can_delete_an_address(): void
    {
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->deleteJson(route('addresses.destroy', $address))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('customer_addresses', ['id' => $address->id]);
    }

    public function test_deleting_the_default_address_promotes_the_newest_of_the_rest(): void
    {
        $default = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();
        $older = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();
        $newer = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->deleteJson(route('addresses.destroy', $default))
            ->assertOk();

        $this->assertDatabaseMissing('customer_addresses', ['id' => $default->id]);
        $this->assertTrue($newer->fresh()->is_default);
        $this->assertFalse($older->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_deleting_the_only_address_leaves_no_default_behind(): void
    {
        $address = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->deleteJson(route('addresses.destroy', $address))
            ->assertOk();

        $this->assertSame(0, CustomerAddress::where('customer_id', $this->customer->id)->count());
    }

    // ─── Choosing the default ───────────────────────────────────

    public function test_customer_can_make_an_address_the_default(): void
    {
        $previous = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->patchJson(route('addresses.default', $address))
            ->assertOk()
            ->assertJsonPath('address.is_default', true);

        $this->assertFalse($previous->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    public function test_making_the_already_default_address_the_default_is_harmless(): void
    {
        $address = CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();
        CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->patchJson(route('addresses.default', $address))
            ->assertOk();

        $this->assertTrue($address->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    // ─── Ownership ──────────────────────────────────────────────

    public function test_customer_cannot_read_another_customers_address(): void
    {
        $address = CustomerAddress::factory()->for(Customer::factory())->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->putJson(route('addresses.update', $address), $this->payload())
            ->assertNotFound();

        $this->actingAs($this->customer)
            ->deleteJson(route('addresses.destroy', $address))
            ->assertNotFound();

        $this->actingAs($this->customer)
            ->patchJson(route('addresses.default', $address))
            ->assertNotFound();

        $this->assertDatabaseHas('customer_addresses', ['id' => $address->id]);
    }

    public function test_setting_a_default_never_touches_another_customers_addresses(): void
    {
        $other = Customer::factory()->create();
        $otherDefault = CustomerAddress::factory()->default()->for($other)->for($this->governorate)->create();

        CustomerAddress::factory()->default()->for($this->customer)->for($this->governorate)->create();
        $mine = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create();

        $this->actingAs($this->customer)
            ->patchJson(route('addresses.default', $mine))
            ->assertOk();

        $this->assertTrue($otherDefault->fresh()->is_default);
        $this->assertTrue($mine->fresh()->is_default);
        $this->assertSingleDefault($this->customer);
    }

    // ─── Presentation ───────────────────────────────────────────

    public function test_formatted_address_prefers_the_arabic_governorate_name(): void
    {
        $address = CustomerAddress::factory()->for($this->customer)->for($this->governorate)->create([
            'address' => '15 Tahrir St, 3rd floor',
            'area' => 'Dokki',
        ]);

        $this->assertSame(
            '15 Tahrir St, 3rd floor, Dokki, القاهرة',
            $address->formatted_address,
        );
    }

    public function test_formatted_address_falls_back_to_the_english_governorate_name(): void
    {
        $governorate = Governorate::factory()->create(['name' => 'Giza', 'name_ar' => null]);

        $address = CustomerAddress::factory()->for($this->customer)->for($governorate)->create([
            'address' => '10 Nasr St',
            'area' => 'Nasr City',
        ]);

        $this->assertSame('10 Nasr St, Nasr City, Giza', $address->formatted_address);
    }

    // ─── Helpers ────────────────────────────────────────────────

    /**
     * A valid store payload, overridden per test.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function payload(array $overrides = []): array
    {
        return array_merge([
            'governorate_id' => $this->governorate->id,
            'name' => 'Ahmed Ali',
            'phone' => '01012345678',
            'area' => 'Dokki',
            'address' => '15 Tahrir St, 3rd floor',
        ], $overrides);
    }

    /**
     * The invariant every write path is responsible for.
     */
    protected function assertSingleDefault(Customer $customer): void
    {
        $this->assertSame(
            1,
            CustomerAddress::where('customer_id', $customer->id)->where('is_default', true)->count(),
            'Expected exactly one default address.',
        );
    }
}
