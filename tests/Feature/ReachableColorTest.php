<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/colors/reachable — the one authority on "can the shop make this?".
 *
 * The rule this file exists to protect: **whatever the solver proposes, the
 * cart must accept**. A recommendation the buy endpoint then refuses is worse
 * than no recommendation, because the customer has already approved the
 * colour. That is asserted end to end rather than by reading both sets of
 * rules and hoping they agree.
 *
 * See REACHABILITY.md.
 */
class ReachableColorTest extends TestCase
{
    use RefreshDatabase;

    private Product $product;

    private ProductVariant $whiteBase;

    private ProductVariant $redPint;

    private ProductVariant $bluePint;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name' => 'Testbrand Latex',
        ]);
        $this->product->categories()->attach(Category::create(['category_name' => 'Latex'])->id);

        $this->whiteBase = $this->variant('White', '4L', '#FFFFFF');
        $this->redPint = $this->variant('Red', 'Pint', '#CC2222');
        $this->bluePint = $this->variant('Blue', 'Pint', '#2244AA');
    }

    private function variant(string $name, string $size, ?string $hex, int $stock = 20): ProductVariant
    {
        return ProductVariant::create([
            'product_id' => $this->product->id,
            'color_name' => $name,
            'color_code' => '',
            'hex_code' => $hex,
            'size_volume' => $size,
            'price' => 100,
            'stock' => $stock,
        ]);
    }

    private function ask(array $hexes)
    {
        return $this->getJson('/api/colors/reachable?'.http_build_query(['hex' => $hexes]));
    }

    // -------------------------------------------------------
    // Shape
    // -------------------------------------------------------

    public function test_it_returns_a_recipe_and_a_distance_for_each_target(): void
    {
        $response = $this->ask(['#ED7878', '#FFFFFF'])->assertOk();

        $response->assertJsonStructure([
            'as_of',
            'results' => [
                ['target', 'best' => [
                    'hex', 'delta_e', 'band',
                    'recipe' => ['base' => ['product_variant_id', 'count'], 'tints'],
                    'total_liters', 'total_price',
                ]],
            ],
        ]);

        $this->assertSame('#ED7878', $response->json('results.0.target'));
        $this->assertSame('#FFFFFF', $response->json('results.1.target'));
    }

    /** A colour the shelf already holds needs no tint at all, and must come
     *  back in the band that says so rather than being "improved" with a pint
     *  nobody needs. */
    public function test_a_stocked_colour_resolves_to_itself(): void
    {
        $best = $this->ask(['#FFFFFF'])->assertOk()->json('results.0.best');

        $this->assertSame('#FFFFFF', $best['hex']);
        $this->assertSame('match', $best['band']);
        $this->assertEqualsWithDelta(0.0, $best['delta_e'], 1e-9);
        $this->assertSame([], $best['recipe']['tints']);
        $this->assertSame($this->whiteBase->id, $best['recipe']['base']['product_variant_id']);

        // Nothing is poured, so this is not a mix and must not be sold as one.
        $this->assertSame('stocked', $best['kind']);
    }

    /**
     * The bench has to open on a proposed recipe without a second round trip,
     * so every can a recipe names comes back with what a mix line needs. The
     * alternative — looking the ids up through the paginated product list — is
     * why this exists: the variant might simply not be in the page fetched.
     */
    public function test_it_returns_the_cans_its_recipes_name(): void
    {
        $response = $this->ask(['#ED7878'])->assertOk();

        $recipe = $response->json('results.0.best.recipe');
        $ingredients = $response->json('ingredients');

        $ids = array_merge(
            [$recipe['base']['product_variant_id']],
            array_column($recipe['tints'], 'product_variant_id'),
        );

        foreach ($ids as $id) {
            $this->assertArrayHasKey((string) $id, $ingredients, "Recipe names variant {$id} but did not describe it.");
        }

        $base = $ingredients[(string) $recipe['base']['product_variant_id']];

        foreach (['product_id', 'name', 'color_name', 'hex_code', 'size_volume',
            'price', 'stock', 'tint_strength', 'category_ids'] as $key) {
            $this->assertArrayHasKey($key, $base);
        }
    }

    /** Public on purpose — choosing a colour precedes any intent to buy. */
    public function test_it_needs_no_login(): void
    {
        $this->ask(['#ED7878'])->assertOk();
    }

    // -------------------------------------------------------
    // The rule that matters: the cart must accept what is proposed
    // -------------------------------------------------------

    /**
     * Every answer must be buyable down the path it names.
     *
     * This is the assertion that caught `kind`: the solver can legitimately
     * answer "nothing to mix, the shop stocks this", and MixController rightly
     * refuses a recipe with no tints. Posting every answer to /cart/mix failed
     * here first rather than in a customer's hands.
     */
    public function test_every_proposed_recipe_is_accepted_by_the_cart(): void
    {
        $customer = User::create([
            'first_name' => 'Test',
            'last_name' => 'Customer',
            'email' => 'reach@example.test',
            'password' => bcrypt('password'),
            'role' => 'customer',
            'phone' => null,
        ]);

        $targets = ['#ED7878', '#8CAFCC', '#6E6E6E', '#C9A227'];

        foreach ($this->ask($targets)->assertOk()->json('results') as $result) {
            $this->assertNotNull($result['best'], "Nothing proposed for {$result['target']}.");

            $best = $result['best'];

            if ($best['kind'] === 'mix') {
                $this->actingAs($customer)
                    ->postJson('/api/cart/mix', $best['recipe'])
                    ->assertSuccessful();

                continue;
            }

            $this->actingAs($customer)
                ->postJson('/api/cart/add', [
                    'product_variant_id' => $best['recipe']['base']['product_variant_id'],
                    'quantity' => $best['recipe']['base']['count'],
                ])
                ->assertSuccessful();
        }
    }

    // -------------------------------------------------------
    // What the shelf actually holds
    // -------------------------------------------------------

    public function test_it_never_proposes_an_archived_or_out_of_stock_can(): void
    {
        $this->redPint->update(['stock' => 0]);
        $this->bluePint->update(['is_archived' => true]);

        $best = $this->ask(['#ED7878'])->assertOk()->json('results.0.best');

        $this->assertSame([], $best['recipe']['tints'], 'Proposed a tint that is gone.');
        $this->assertSame($this->whiteBase->id, $best['recipe']['base']['product_variant_id']);
    }

    public function test_it_answers_honestly_when_nothing_can_be_poured(): void
    {
        ProductVariant::query()->update(['stock' => 0]);

        $this->assertNull($this->ask(['#ED7878'])->assertOk()->json('results.0.best'));
    }

    /**
     * A cached answer must die when the shelf behind it moves. Without the
     * stock fingerprint in the key, the endpoint keeps recommending pints that
     * were sold ten minutes ago — and the cart then refuses the recipe.
     */
    public function test_a_stock_change_invalidates_the_cached_answer(): void
    {
        $first = $this->ask(['#ED7878'])->assertOk()->json('results.0.best');
        $this->assertNotSame([], $first['recipe']['tints']);

        $this->redPint->update(['stock' => 0]);
        $this->bluePint->update(['stock' => 0]);

        $second = $this->ask(['#ED7878'])->assertOk()->json('results.0.best');

        $this->assertSame([], $second['recipe']['tints'], 'Served a stale recipe after the pints sold out.');
    }

    // -------------------------------------------------------
    // Input
    // -------------------------------------------------------

    public function test_it_requires_at_least_one_colour(): void
    {
        $this->getJson('/api/colors/reachable')->assertStatus(422);
    }

    /** Each target is a search over the shelf, not a lookup — so the batch is
     *  capped rather than left open. */
    public function test_it_refuses_more_than_eight_colours(): void
    {
        $this->ask(array_fill(0, 9, '#ED7878'))->assertStatus(422);
        $this->ask(array_fill(0, 8, '#ED7878'))->assertOk();
    }

    public function test_it_names_the_colour_it_cannot_read(): void
    {
        $this->ask(['#ED7878', 'nope'])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'We cannot read the colour "nope".']);

        // Anything longer is refused by validation before it gets that far,
        // which is why max:9 is there — a hex is 7 characters.
        $this->ask(['chartreuse'])->assertStatus(422);
    }

    public function test_it_accepts_the_shorthand_and_bare_forms(): void
    {
        $response = $this->ask(['fff', '#ED7878'])->assertOk();

        $this->assertSame('#FFFFFF', $response->json('results.0.target'));
    }
}
