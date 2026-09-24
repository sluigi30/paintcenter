<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\TintColorResource\Pages\CreateTintColor;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TintColor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Phase 1 of the tint-recipe revamp (MIXING.md): mixing bases exist as
 * stocked products the catalogue never shows, colorant presets exist as
 * configuration rather than products, and a base can knows its volume as a
 * number so its colorant cap does not depend on parsing "4L".
 */
class TintMixSetupTest extends TestCase
{
    use RefreshDatabase;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brand = Brand::create(['brand_name' => 'Testbrand']);
    }

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name' => 'Admin',
            'email' => 'admin@example.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
        ]);
    }

    private function product(string $name, bool $base, string $size = '4L'): Product
    {
        $product = Product::create([
            'brand_id' => $this->brand->id,
            'name' => $name,
            'is_mixing_base' => $base,
        ]);
        $product->categories()->attach(Category::firstOrCreate(['category_name' => 'Latex Paint'])->id);

        $product->variants()->create([
            'color_name' => $base ? 'White Base' : 'Burnt Sienna',
            'hex_code' => $base ? '#FFFFFF' : '#8A3324',
            'size_volume' => $size,
            'price' => 1100,
            'stock' => 10,
            'low_stock_threshold' => 2,
        ]);

        return $product;
    }

    // ---------------------------------------------------------------
    // Bases never reach the catalogue
    // ---------------------------------------------------------------

    public function test_the_catalogue_lists_paint_but_not_mixing_bases(): void
    {
        $this->product('Finished Latex', false);
        $this->product('Tint Base', true);

        $names = collect($this->getJson('/api/products')->assertOk()->json('data'))->pluck('name');

        $this->assertSame(['Finished Latex'], $names->all());
    }

    public function test_a_brand_selling_only_bases_is_not_on_the_brand_grid(): void
    {
        $this->product('Tint Base', true);

        $this->getJson('/api/brands')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/brands/{$this->brand->id}/categories")->assertOk()->assertJsonCount(0);
    }

    public function test_a_mixing_base_has_no_product_page(): void
    {
        $base = $this->product('Tint Base', true);

        $this->getJson("/api/products/{$base->id}")->assertNotFound();
    }

    public function test_a_base_can_cannot_be_carted_unmixed(): void
    {
        $variant = $this->product('Tint Base', true)->variants()->first();

        Sanctum::actingAs(User::create([
            'first_name' => 'Test', 'last_name' => 'Customer', 'email' => 'c@example.test',
            'password' => bcrypt('password'), 'role' => 'customer',
        ]));

        $this->postJson('/api/cart/add', ['product_variant_id' => $variant->id, 'quantity' => 1])
            ->assertStatus(422);
    }

    // ---------------------------------------------------------------
    // Volume is a number, and the colorant cap follows it
    // ---------------------------------------------------------------

    public function test_volume_is_filled_from_the_size_when_left_blank(): void
    {
        $variant = $this->product('Tint Base', true, '4 L')->variants()->first();

        $this->assertSame(4.0, $variant->fresh()->volume_liters);
    }

    public function test_relabelling_the_size_re_derives_a_stale_volume(): void
    {
        $variant = $this->product('Tint Base', true, '1L')->variants()->first();

        $variant->update(['size_volume' => '4L']);

        $this->assertSame(4.0, $variant->fresh()->volume_liters,
            'A can re-labelled 4L must not keep capping its tint at one litre.');
    }

    public function test_an_explicit_volume_beats_the_size_string(): void
    {
        $variant = $this->product('Tint Base', true, '4L')->variants()->first();

        $variant->update(['size_volume' => '4L can', 'volume_liters' => 3.8]);

        $this->assertSame(3.8, $variant->fresh()->volume_liters);
    }

    public function test_a_size_with_no_number_keeps_the_volume_it_had(): void
    {
        $variant = $this->product('Tint Base', true, '1 gallon')->variants()->first();
        $this->assertSame(3.785, $variant->fresh()->volume_liters);

        // What the admin form does: resubmits the unchanged volume, so the
        // column is not dirty while the size is.
        $variant->update(['size_volume' => 'Gallon', 'volume_liters' => 3.785]);

        $this->assertSame(3.785, $variant->fresh()->volume_liters);
    }

    public function test_the_tint_cap_scales_with_the_can(): void
    {
        config(['paint.mix.default_max_tint_ml_per_liter' => 60.0]);
        $variant = $this->product('Tint Base', true, '4L')->variants()->first();

        $this->assertSame(240.0, $variant->maxTintMl());

        $variant->update(['max_tint_ml_per_liter' => 100]);
        $this->assertSame(400.0, $variant->fresh()->maxTintMl());
    }

    public function test_a_can_of_unknown_volume_has_no_cap(): void
    {
        $variant = $this->product('Tint Base', true, 'Set of 3')->variants()->first();

        $this->assertNull($variant->fresh()->volume_liters);
        $this->assertNull($variant->fresh()->maxTintMl());
    }

    // ---------------------------------------------------------------
    // Tint presets
    // ---------------------------------------------------------------

    public function test_amounts_are_checked_in_whole_steps_without_float_modulo(): void
    {
        $black = new TintColor(['step_ml' => 0.5]);
        $red = new TintColor(['step_ml' => 2]);

        $this->assertTrue($black->acceptsAmount(1.5));
        $this->assertTrue($black->acceptsAmount(0.5));
        $this->assertFalse($black->acceptsAmount(0.3));
        $this->assertFalse($black->acceptsAmount(0.55), 'Finer than a tenth of a ml is not a step.');

        $this->assertTrue($red->acceptsAmount(4));
        $this->assertFalse($red->acceptsAmount(3));
        $this->assertFalse($red->acceptsAmount(0));
        $this->assertFalse($red->acceptsAmount(-2));
    }

    public function test_the_admin_form_creates_a_preset_without_exposing_strength(): void
    {
        $this->actingAs($this->admin());

        TintColor::create(['name' => 'Existing', 'hex_code' => '#000000', 'sort_order' => 4]);

        Livewire::test(CreateTintColor::class)
            ->assertFormFieldDoesNotExist('tint_strength')
            ->fillForm(['name' => 'Oxide Red', 'hex_code' => '#9b3a2a', 'step_ml' => '2'])
            ->call('create')
            ->assertHasNoFormErrors();

        $tint = TintColor::where('name', 'Oxide Red')->firstOrFail();

        $this->assertSame('#9B3A2A', $tint->hex_code);
        $this->assertSame(2.0, $tint->step_ml);
        $this->assertSame(1.0, $tint->tint_strength, 'Calibration starts at 1.00, untouched by the form.');
        $this->assertSame(5, $tint->sort_order, 'A new preset joins the end of the list.');
    }

    public function test_the_product_form_saves_a_mixing_base_with_its_volume_and_cap(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Tint Base',
                'brand_id' => $this->brand->id,
                'categories' => [Category::create(['category_name' => 'Latex Paint'])->id],
                'is_mixing_base' => true,
                'variants' => [[
                    'base_type' => 'deep',
                    'size_volume' => '4L',
                    'volume_liters' => 4,
                    'max_tint_ml_per_liter' => 120,
                    'price' => 1220,
                    'stock' => 5,
                    'low_stock_threshold' => 2,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $variant = ProductVariant::firstOrFail();

        $this->assertTrue($variant->product->is_mixing_base);
        $this->assertSame(480.0, $variant->maxTintMl());
    }
}
