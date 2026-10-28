<?php

namespace Tests\Feature\Admin;

use App\Models\Admin;
use App\Models\Color;
use App\Models\Product;
use App\Models\Size;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class ColorSizeTest extends TestCase
{
    use RefreshDatabase;

    protected Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = Admin::create([
            'name' => 'Catalog Admin',
            'email' => 'catalog@store.com',
            'password' => 'password',
            'is_active' => true,
        ]);
    }

    public function test_unauthenticated_user_cannot_access_colors_sizes(): void
    {
        $response = $this->get('/admin/colors-sizes');

        $response->assertRedirect(route('admin.login'));
    }

    public function test_admin_can_view_colors_sizes_page(): void
    {
        $color = Color::create(['name' => 'Black', 'hex_code' => '#000000']);
        Size::create(['name' => 'M', 'group' => 'tops']);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/colors-sizes');

        $response->assertOk();
        $response->assertSee('Black');
        $response->assertSee('M');
        $response->assertViewHas('windowColorsJson');
        $response->assertViewHas('windowSizeGroupsJson');
    }

    public function test_admin_can_create_color(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/colors', [
            'name' => 'Olive',
            'name_ar' => 'زيتوني',
            'hex_code' => '#5C6A48',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('color.name', 'Olive');
        $response->assertJsonPath('color.hex', '#5C6A48');
        $this->assertDatabaseHas('colors', [
            'name' => 'Olive',
            'hex_code' => '#5C6A48',
            'is_active' => 1,
        ]);
    }

    public function test_color_hex_code_validation(): void
    {
        // Hex that is not a # followed by 3 or 6 hex digits.
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/colors', [
            'name' => 'Bad Color',
            'hex_code' => 'not-a-color',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('hex_code');
    }

    public function test_color_accepts_short_hex_code(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/colors', [
            'name' => 'Short Hex',
            'hex_code' => '#abc',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('colors', [
            'name' => 'Short Hex',
            'hex_code' => '#abc',
        ]);
    }

    public function test_admin_can_update_color(): void
    {
        $color = Color::create(['name' => 'Red', 'hex_code' => '#FF0000']);

        $response = $this->actingAs($this->admin, 'admin')->putJson("/admin/colors/{$color->id}", [
            'name' => 'Crimson',
            'hex_code' => '#DC143C',
            'is_active' => true,
        ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('color.name', 'Crimson');
        $this->assertDatabaseHas('colors', [
            'id' => $color->id,
            'name' => 'Crimson',
            'hex_code' => '#DC143C',
        ]);
    }

    public function test_admin_can_delete_color(): void
    {
        $color = Color::create(['name' => 'Teal', 'hex_code' => '#008080']);

        $response = $this->actingAs($this->admin, 'admin')->deleteJson("/admin/colors/{$color->id}");

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('colors', ['id' => $color->id]);
    }

    public function test_admin_can_create_size(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/sizes', [
            'name' => '32',
            'group' => 'bottoms',
            'is_active' => true,
        ]);

        $response->assertStatus(201);
        $response->assertJson(['success' => true]);
        $response->assertJsonPath('size.name', '32');
        $response->assertJsonPath('size.group', 'bottoms');
        $this->assertDatabaseHas('sizes', [
            'name' => '32',
            'group' => 'bottoms',
        ]);
    }

    public function test_size_requires_valid_group(): void
    {
        $response = $this->actingAs($this->admin, 'admin')->postJson('/admin/sizes', [
            'name' => 'XL',
            'group' => 'socks',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('group');
    }

    public function test_admin_can_update_size(): void
    {
        $size = Size::create(['name' => 'L', 'group' => 'tops']);

        $response = $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/sizes/{$size->id}", [
                'name' => 'L',
                'group' => 'tops',
                'is_active' => false,
            ]);

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseHas('sizes', [
            'id' => $size->id,
            'is_active' => 0,
        ]);
    }

    public function test_admin_can_delete_size(): void
    {
        $size = Size::create(['name' => 'L', 'group' => 'tops']);

        $response = $this->actingAs($this->admin, 'admin')->deleteJson("/admin/sizes/{$size->id}");

        $response->assertOk();
        $response->assertJson(['success' => true]);
        $this->assertDatabaseMissing('sizes', ['id' => $size->id]);
    }

    public function test_deleting_color_cascades_to_products_and_variants(): void
    {
        $color = Color::create(['name' => 'Maroon', 'hex_code' => '#800000']);
        $size = Size::create(['name' => 'M', 'group' => 'tops']);
        $product = Product::create([
            'name' => 'Sweater',
            'slug' => Str::slug('Sweater '.Str::random(4)),
        ]);
        $product->colors()->attach($color->id);
        $product->variants()->create([
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => 'SWEATER-C'.$color->id.'-S'.$size->id,
            'price' => 100,
            'cost' => 50,
            'quantity' => 10,
        ]);

        $this->actingAs($this->admin, 'admin')->deleteJson("/admin/colors/{$color->id}");

        $this->assertDatabaseMissing('colors', ['id' => $color->id]);
        $this->assertDatabaseMissing('product_colors', ['color_id' => $color->id]);
        $this->assertDatabaseMissing('variants', ['color_id' => $color->id]);
    }

    public function test_admin_can_switch_a_color_on_and_off(): void
    {
        $color = Color::create(['name' => 'Olive', 'hex_code' => '#5C6A48', 'is_active' => true]);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/colors/{$color->id}/toggle")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('color.active', false)
            ->assertJsonPath('color.id', (string) $color->id);

        $this->assertFalse($color->fresh()->is_active);

        $this->actingAs($this->admin, 'admin')
            ->patchJson("/admin/colors/{$color->id}/toggle")
            ->assertOk()
            ->assertJsonPath('color.active', true);

        $this->assertTrue($color->fresh()->is_active);
    }

    public function test_admin_can_reorder_the_colors(): void
    {
        $olive = Color::create(['name' => 'Olive', 'hex_code' => '#5C6A48', 'sort_order' => 0]);
        $black = Color::create(['name' => 'Black', 'hex_code' => '#0D0F11', 'sort_order' => 1]);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/colors/reorder', ['colors' => [$black->id, $olive->id]])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $black->fresh()->sort_order);
        $this->assertSame(1, $olive->fresh()->sort_order);
    }

    public function test_reordering_the_colors_rejects_an_unknown_color(): void
    {
        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/colors/reorder', ['colors' => [9999]])
            ->assertStatus(422)
            ->assertJsonValidationErrors('colors.0');
    }

    public function test_admin_can_reorder_the_sizes_of_a_group(): void
    {
        $small = Size::create(['name' => 'S', 'group' => 'tops', 'sort_order' => 0]);
        $medium = Size::create(['name' => 'M', 'group' => 'tops', 'sort_order' => 1]);

        $this->actingAs($this->admin, 'admin')
            ->postJson('/admin/sizes/reorder', ['sizes' => [$medium->id, $small->id]])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame(0, $medium->fresh()->sort_order);
        $this->assertSame(1, $small->fresh()->sort_order);
    }

    public function test_admin_can_hide_a_whole_size_group(): void
    {
        $small = Size::create(['name' => 'S', 'group' => 'tops', 'is_active' => true]);
        $medium = Size::create(['name' => 'M', 'group' => 'tops', 'is_active' => true]);
        $waist = Size::create(['name' => '32', 'group' => 'bottoms', 'is_active' => true]);

        $this->actingAs($this->admin, 'admin')
            ->patchJson('/admin/sizes/group/tops/toggle', ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('updated', 2);

        $this->assertFalse($small->fresh()->is_active);
        $this->assertFalse($medium->fresh()->is_active);

        // The other groups are left alone.
        $this->assertTrue($waist->fresh()->is_active);
    }

    public function test_the_colors_and_sizes_page_carries_the_product_counts(): void
    {
        $color = Color::create(['name' => 'Olive', 'hex_code' => '#5C6A48']);
        $size = Size::create(['name' => 'M', 'group' => 'tops']);
        $product = Product::create([
            'name' => 'Sweater',
            'slug' => Str::slug('Sweater '.Str::random(4)),
        ]);
        $product->colors()->attach($color->id);
        $product->variants()->create([
            'color_id' => $color->id,
            'size_id' => $size->id,
            'sku' => 'SWEATER-C'.$color->id.'-S'.$size->id,
            'price' => 100,
            'cost' => 50,
            'quantity' => 10,
        ]);

        $response = $this->actingAs($this->admin, 'admin')->get('/admin/colors-sizes');

        $response->assertOk();
        $response->assertSee('"products":1', false);
    }
}
