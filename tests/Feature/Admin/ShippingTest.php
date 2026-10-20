<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Governorate;
use App\Models\ShippingSetting;
use App\Models\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShippingTest extends TestCase
{
    use RefreshDatabase;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Admin::create([
            'name' => 'Shipping Admin',
            'email' => 'shipping@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    private function actingAsAdmin(): static
    {
        return $this->actingAs($this->admin, 'admin');
    }

    public function test_admin_can_view_shipping_page(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Delta Zone', 'fee' => 60]);
        Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'name' => 'Dakahlia',
            'fee' => 55,
        ]);

        $response = $this->actingAsAdmin()->get(route('admin.shipping.index'));

        $response->assertOk()->assertViewIs('admin.shipping.index');

        // The zone tree and global settings are serialised for shipping.js.
        $response->assertSee('__SHIPPING_ZONES__', false);
        $response->assertSee('__SHIPPING_SETTINGS__', false);
        $response->assertSee('Delta Zone', false);
        $response->assertSee('Dakahlia', false);
    }

    public function test_admin_can_update_global_shipping_settings(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.settings.update'), [
                'free_shipping_enabled' => true,
                'free_shipping_threshold' => 1500,
                'cod_fee' => 20,
                'default_fee' => 60,
                'note_en' => 'Fast delivery note',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shipping_settings', ['key' => 'free_shipping_threshold', 'value' => '1500']);
        $this->assertDatabaseHas('shipping_settings', ['key' => 'free_shipping_enabled', 'value' => '1']);
        $this->assertDatabaseHas('shipping_settings', ['key' => 'note_en', 'value' => 'Fast delivery note']);

        $this->assertSame('1500', ShippingSetting::get('free_shipping_threshold'));
        $this->assertSame('20', ShippingSetting::get('cod_fee'));
    }

    public function test_global_shipping_settings_reject_negative_fees(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.settings.update'), [
                'cod_fee' => -5,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cod_fee');
    }

    public function test_admin_can_create_shipping_zone(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.zones.store'), [
                'name' => 'Delta Zone',
                'name_ar' => 'منطقة الدلتا',
                'fee' => 50.00,
                'time_from' => 2,
                'time_to' => 4,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shipping_zones', [
            'name' => 'Delta Zone',
            'name_ar' => 'منطقة الدلتا',
            'is_active' => true,
        ]);
    }

    public function test_created_shipping_zone_gets_the_next_sort_order(): void
    {
        ShippingZone::factory()->create(['sort_order' => 7]);

        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.zones.store'), [
                'name' => 'Last Zone',
                'fee' => 30,
                'time_from' => 1,
                'time_to' => 2,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('shipping_zones', ['name' => 'Last Zone', 'sort_order' => 8]);
    }

    public function test_shipping_zone_requires_name_fee_and_valid_delivery_window(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.zones.store'), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'fee', 'time_from', 'time_to']);

        // time_to must not be earlier than time_from.
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.zones.store'), [
                'name' => 'Backwards Zone',
                'fee' => 30,
                'time_from' => 5,
                'time_to' => 2,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('time_to');
    }

    public function test_admin_can_update_shipping_zone(): void
    {
        $zone = ShippingZone::factory()->create(['name' => 'Old Zone']);

        $this->actingAsAdmin()
            ->putJson(route('admin.shipping.zones.update', $zone), [
                'name' => 'New Zone',
                'fee' => 70.00,
                'time_from' => 3,
                'time_to' => 5,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('shipping_zones', [
            'id' => $zone->id,
            'name' => 'New Zone',
            'fee' => 70,
            'is_active' => false,
        ]);
    }

    public function test_admin_can_delete_shipping_zone_with_its_governorates(): void
    {
        $zone = ShippingZone::factory()->create();
        Governorate::factory()->count(2)->create(['shipping_zone_id' => $zone->id]);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.shipping.zones.destroy', $zone))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('shipping_zones', ['id' => $zone->id]);
        $this->assertDatabaseCount('governorates', 0);
    }

    public function test_admin_can_create_governorate(): void
    {
        $zone = ShippingZone::factory()->create();

        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.governorates.store'), [
                'shipping_zone_id' => $zone->id,
                'name' => 'Mansoura',
                'name_ar' => 'المنصورة',
                'fee' => 55.00,
                'allows_cod' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('governorates', [
            'name' => 'Mansoura',
            'shipping_zone_id' => $zone->id,
            'fee' => 55,
            'allows_cod' => true,
        ]);
    }

    public function test_governorate_requires_a_valid_shipping_zone(): void
    {
        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.governorates.store'), [
                'shipping_zone_id' => 9999,
                'name' => 'Nowhere',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shipping_zone_id');
    }

    public function test_governorate_defaults_to_inheriting_the_zone_fee(): void
    {
        $zone = ShippingZone::factory()->create(['fee' => 80]);
        $governorate = Governorate::factory()->create([
            'shipping_zone_id' => $zone->id,
            'fee' => null,
        ]);

        $this->assertNull($governorate->fresh()->fee);
        $this->assertSame(80.0, $governorate->fresh()->effective_fee);

        $governorate->update(['fee' => 25]);
        $this->assertSame(25.0, $governorate->fresh()->effective_fee);
    }

    public function test_created_governorate_gets_the_next_sort_order_within_its_zone(): void
    {
        $zone = ShippingZone::factory()->create();
        Governorate::factory()->create(['shipping_zone_id' => $zone->id, 'sort_order' => 4]);

        $this->actingAsAdmin()
            ->postJson(route('admin.shipping.governorates.store'), [
                'shipping_zone_id' => $zone->id,
                'name' => 'Tanta',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('governorates', ['name' => 'Tanta', 'sort_order' => 5]);
    }

    public function test_admin_can_update_governorate(): void
    {
        $governorate = Governorate::factory()->create(['name' => 'Old Governorate']);

        $this->actingAsAdmin()
            ->putJson(route('admin.shipping.governorates.update', $governorate), [
                'shipping_zone_id' => $governorate->shipping_zone_id,
                'name' => 'New Governorate',
                'allows_cod' => false,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('governorates', [
            'id' => $governorate->id,
            'name' => 'New Governorate',
            'allows_cod' => false,
        ]);
    }

    public function test_admin_can_delete_governorate(): void
    {
        $governorate = Governorate::factory()->create();

        $this->actingAsAdmin()
            ->deleteJson(route('admin.shipping.governorates.destroy', $governorate))
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('governorates', ['id' => $governorate->id]);
    }

    public function test_deleting_a_governorate_leaves_its_zone_intact(): void
    {
        $zone = ShippingZone::factory()->create();
        $governorate = Governorate::factory()->create(['shipping_zone_id' => $zone->id]);

        $this->actingAsAdmin()
            ->deleteJson(route('admin.shipping.governorates.destroy', $governorate))
            ->assertOk();

        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
    }

    public function test_guest_cannot_access_shipping(): void
    {
        $this->get(route('admin.shipping.index'))->assertRedirect(route('admin.login'));
    }

    public function test_guest_cannot_mutate_shipping(): void
    {
        $zone = ShippingZone::factory()->create();
        $governorate = Governorate::factory()->create();

        $this->postJson(route('admin.shipping.zones.store'), [
            'name' => 'Nope', 'fee' => 10, 'time_from' => 1, 'time_to' => 2,
        ])->assertRedirect(route('admin.login'));

        $this->putJson(route('admin.shipping.zones.update', $zone), [
            'name' => 'Nope', 'fee' => 10, 'time_from' => 1, 'time_to' => 2,
        ])->assertRedirect(route('admin.login'));

        $this->deleteJson(route('admin.shipping.zones.destroy', $zone))
            ->assertRedirect(route('admin.login'));

        $this->postJson(route('admin.shipping.governorates.store'), [
            'shipping_zone_id' => $zone->id, 'name' => 'Nope',
        ])->assertRedirect(route('admin.login'));

        $this->putJson(route('admin.shipping.governorates.update', $governorate), [
            'shipping_zone_id' => $zone->id, 'name' => 'Nope',
        ])->assertRedirect(route('admin.login'));

        $this->deleteJson(route('admin.shipping.governorates.destroy', $governorate))
            ->assertRedirect(route('admin.login'));

        $this->postJson(route('admin.shipping.settings.update'), ['cod_fee' => 5])
            ->assertRedirect(route('admin.login'));

        $this->assertDatabaseHas('shipping_zones', ['id' => $zone->id]);
        $this->assertDatabaseHas('governorates', ['id' => $governorate->id]);
    }
}
