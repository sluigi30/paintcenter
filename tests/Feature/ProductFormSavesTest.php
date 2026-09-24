<?php

namespace Tests\Feature;

use App\Filament\Resources\ProductResource\Pages\CreateProduct;
use App\Filament\Resources\ProductResource\Pages\EditProduct;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The product form actually writes what the new shape needs.
 *
 * Rendering the form proves nothing about saving it: the categories
 * multi-select writes a pivot, the variants repeater writes colour columns it
 * did not have before, and Save is supposed to keep the form open. Each of
 * those fails in a way a GET request cannot see.
 */
class ProductFormSavesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'first_name' => 'Test',
            'last_name'  => 'Admin',
            'email'      => 'admin@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'admin',
        ]);
    }

    public function test_creating_a_product_saves_its_categories_and_its_shades(): void
    {
        $this->actingAs($this->admin());

        $brand  = Brand::create(['brand_name' => 'Testbrand']);
        $enamel = Category::create(['category_name' => 'Enamel Paint']);
        $wood   = Category::create(['category_name' => 'Wood Coating']);

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name'        => 'Testbrand Enamel',
                'brand_id'    => $brand->id,
                'categories'  => [$enamel->id, $wood->id],
                'description' => 'Gloss enamel for wood and metal.',
                'variants'    => [
                    [
                        'color_name'          => 'Burnt Sienna',
                        // A Unicode non-breaking hyphen, as pasted from a
                        // shade card — normalized on the way in.
                        'color_code'          => "B\u{2011}1408",
                        'hex_code'            => '#8A3324',
                        'size_volume'         => '4L',
                        'price'               => 1400,
                        'stock'               => 12,
                        'low_stock_threshold' => 5,
                    ],
                    [
                        // Named, uncoded — the case that must neither collapse
                        // into the row above nor be rejected as a duplicate.
                        'color_name'          => 'White',
                        'color_code'          => '',
                        'hex_code'            => '#FFFFFF',
                        'size_volume'         => '4L',
                        'price'               => 1300,
                        'stock'               => 30,
                        'low_stock_threshold' => 5,
                    ],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $product = Product::firstOrFail();

        $this->assertSame('Testbrand Enamel', $product->name);
        $this->assertEqualsCanonicalizing(
            ['Enamel Paint', 'Wood Coating'],
            $product->categories->pluck('category_name')->all(),
        );

        $this->assertSame(
            ['Burnt Sienna (B-1408)', 'White'],
            array_column($product->colors, 'label'),
            'Both shades must survive, with the pasted Unicode dash normalized.',
        );
    }

    public function test_the_same_shade_cannot_be_listed_twice_in_one_size(): void
    {
        $this->actingAs($this->admin());

        $row = [
            'color_name'          => 'Burnt Sienna',
            'color_code'          => 'B-1408',
            'size_volume'         => '4L',
            'price'               => 1400,
            'stock'               => 1,
            'low_stock_threshold' => 5,
        ];

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name'       => 'Testbrand Enamel',
                'brand_id'   => Brand::create(['brand_name' => 'Testbrand'])->id,
                'categories' => [Category::create(['category_name' => 'Enamel Paint'])->id],
                'variants'   => [$row, $row],
            ])
            ->call('create')
            ->assertHasFormErrors(['variants']);

        $this->assertSame(0, Product::count());
    }

    public function test_a_product_cannot_be_saved_without_a_name(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name'       => '',
                'brand_id'   => Brand::create(['brand_name' => 'Testbrand'])->id,
                'categories' => [Category::create(['category_name' => 'Enamel Paint'])->id],
                'variants'   => [[
                    'size_volume'         => '4L',
                    'price'               => 1400,
                    'stock'               => 1,
                    'low_stock_threshold' => 5,
                ]],
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);
    }

    /** Save closes the form: creating lands back on the product table. */
    public function test_saving_a_new_product_returns_to_the_table(): void
    {
        $this->actingAs($this->admin());

        Livewire::test(CreateProduct::class)
            ->fillForm([
                'name'       => 'Testbrand Enamel',
                'brand_id'   => Brand::create(['brand_name' => 'Testbrand'])->id,
                'categories' => [Category::create(['category_name' => 'Enamel Paint'])->id],
                'variants'   => [[
                    'color_name'          => 'White',
                    'color_code'          => '',
                    'size_volume'         => '4L',
                    'price'               => 1400,
                    'stock'               => 1,
                    'low_stock_threshold' => 5,
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/products');
    }

    public function test_saving_an_edit_returns_to_the_table(): void
    {
        $this->actingAs($this->admin());

        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'     => 'Testbrand Enamel',
        ]);
        $product->categories()->attach(Category::create(['category_name' => 'Enamel Paint'])->id);
        $product->variants()->create([
            'color_name'  => 'White',
            'size_volume' => '4L',
            'price'       => 1400,
            'stock'       => 5,
        ]);

        Livewire::test(EditProduct::class, ['record' => $product->getKey()])
            ->fillForm(['name' => 'Testbrand Enamel Plus'])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertRedirect('/admin/products');

        $this->assertSame('Testbrand Enamel Plus', $product->fresh()->name);
    }
}
