<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\TintColor;
use App\Models\User;
use App\Services\ColorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * A mixing base is chosen by TYPE, and the type decides its preview colour,
 * how strongly colorant shows in it, and its default capacity.
 *
 * The point of the type over a hand-typed hex: a deep base makes the SAME 20 ml
 * of colorant show far deeper than a white one, and no hex can say that. The
 * tint_response carries it into the prediction the customer sees and the
 * recipe the solver proposes. See MIXING.md, "Base types".
 */
class BaseTypeTest extends TestCase
{
    use RefreshDatabase;

    private Product $bases;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bases = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Boysen'])->id,
            'name' => 'Tint Base',
            'is_mixing_base' => true,
        ]);
        $this->bases->categories()->attach(Category::create(['category_name' => 'Latex'])->id);
    }

    private function can(string $type, string $size = '1L'): ProductVariant
    {
        return $this->bases->variants()->create([
            'base_type' => $type, 'size_volume' => $size, 'price' => 300, 'stock' => 10,
        ]);
    }

    public function test_the_type_sets_the_colour_and_the_name_whatever_was_typed(): void
    {
        $deep = $this->bases->variants()->create([
            'base_type' => 'deep', 'size_volume' => '1L', 'price' => 300, 'stock' => 10,
            'hex_code' => '#FFFFFF', 'color_name' => 'Anything',
        ]);

        $this->assertSame(config('paint.mix.base_types.deep.hex'), $deep->fresh()->hex_code);
        $this->assertSame('Deep Base', $deep->fresh()->color_name);
    }

    public function test_the_same_recipe_shows_deeper_in_a_deep_base(): void
    {
        $red = TintColor::create(['name' => 'Oxide Red', 'hex_code' => '#9B3A2A', 'step_ml' => 2]);
        $recipe = [['tint_color_id' => $red->id, 'name' => 'Oxide Red', 'hex' => '#9B3A2A', 'ml' => 20]];
        $tints = TintColor::all()->keyBy('id');

        $inWhite = \App\Services\TintRecipe::predict($this->can('white'), $recipe, $tints);
        $inDeep = \App\Services\TintRecipe::predict($this->can('deep'), $recipe, $tints);

        $this->assertLessThan(
            ColorService::hexToLab($inWhite)['l'],
            ColorService::hexToLab($inDeep)['l'],
            'Deep base: less white pigment, so the colorant shows darker.',
        );
        $this->assertGreaterThan(
            ColorService::chroma(ColorService::hexToLab($inWhite)),
            ColorService::chroma(ColorService::hexToLab($inDeep)),
            '... and richer — not merely greyer, which is all a darker hex could do.',
        );
    }

    public function test_the_cap_comes_from_the_type_unless_the_can_says_otherwise(): void
    {
        $deep = $this->can('deep', '4L');
        $this->assertSame(4 * config('paint.mix.base_types.deep.max_tint_ml_per_liter'), $deep->maxTintMl());

        $deep->update(['max_tint_ml_per_liter' => 90]);
        $this->assertSame(360.0, $deep->fresh()->maxTintMl());
    }

    public function test_the_bench_is_told_each_bases_response(): void
    {
        $this->can('white');
        $this->can('deep', '4L');

        $variants = collect($this->getJson('/api/mix/bases')->assertOk()->json('bases.0.variants'))->keyBy('base_type');

        $this->assertEquals(1.0, $variants['white']['tint_response']);
        $this->assertEquals(config('paint.mix.base_types.deep.tint_response'), $variants['deep']['tint_response']);
    }

    public function test_a_product_that_stops_being_a_base_stops_typing_its_cans(): void
    {
        $can = $this->can('deep');

        $this->bases->update(['is_mixing_base' => false]);

        $this->assertNull($can->fresh()->base_type);

        // And its colour is the admin's again.
        $can->fresh()->update(['hex_code' => '#123456']);
        $this->assertSame('#123456', $can->fresh()->hex_code);
    }

    public function test_the_admin_picks_a_type_and_types_no_colour(): void
    {
        $this->actingAs(User::create([
            'first_name' => 'A', 'last_name' => 'Dmin', 'email' => 'a@example.test',
            'password' => bcrypt('password'), 'role' => 'admin',
        ]));

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name' => 'Davies Tint Base',
                'brand_id' => $this->bases->brand_id,
                'categories' => [Category::first()->id],
                'is_mixing_base' => true,
                'variants' => [
                    ['base_type' => 'white', 'size_volume' => '4L', 'volume_liters' => 4, 'price' => 1100, 'stock' => 5, 'low_stock_threshold' => 2],
                    ['base_type' => 'deep',  'size_volume' => '4L', 'volume_liters' => 4, 'price' => 1220, 'stock' => 5, 'low_stock_threshold' => 2],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $cans = Product::where('name', 'Davies Tint Base')->firstOrFail()->variants;

        $this->assertSame(['White Base', 'Deep Base'], $cans->pluck('color_name')->all(),
            'Two 4L cans of different types are two cans, not a duplicate.');
        $this->assertSame(config('paint.mix.base_types.deep.hex'), $cans[1]->hex_code);
    }

    public function test_existing_bases_are_typed_from_their_names(): void
    {
        $make = fn (string $name) => $this->bases->variants()->create([
            'color_name' => $name, 'hex_code' => '#FFFFFF', 'size_volume' => '1L', 'price' => 1, 'stock' => 1,
        ]);

        $deep = $make('Deep Base');
        $pastel = $make('Pastel Base');
        $odd = $make('Base No. 3');

        $migration = require database_path('migrations/2026_09_25_000001_add_base_type_to_product_variants.php');
        $migration->down();
        $migration->up();

        $this->assertSame('deep', DB::table('product_variants')->where('id', $deep->id)->value('base_type'));
        $this->assertSame('pastel', DB::table('product_variants')->where('id', $pastel->id)->value('base_type'));
        $this->assertSame('white', DB::table('product_variants')->where('id', $odd->id)->value('base_type'),
            'An unreadable name reads as white — under-stating colour, never over-stating it.');
        $this->assertSame(config('paint.mix.base_types.deep.hex'), DB::table('product_variants')->where('id', $deep->id)->value('hex_code'));
    }
}
