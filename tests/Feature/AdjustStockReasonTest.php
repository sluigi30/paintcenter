<?php

namespace Tests\Feature;

use App\Filament\Resources\InventoryResource\Pages\ListInventory;
use App\Models\Brand;
use App\Models\InventoryLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Adjust Stock records WHY, from a list.
 *
 * Free text gave a history nobody could read back — "damaged", "Damage",
 * "dmg 2 cans" and "" are four spellings of one thing. The presets are per
 * action, because a supplier delivery is not a reason to deduct.
 */
class AdjustStockReasonTest extends TestCase
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

    private function variant(): ProductVariant
    {
        $product = Product::create([
            'brand_id' => Brand::create(['brand_name' => 'Testbrand'])->id,
            'name'     => 'Testbrand Enamel',
        ]);

        return $product->variants()->create([
            'color_name'  => 'White',
            'size_volume' => '4L',
            'price'       => 1400,
            'stock'       => 10,
        ]);
    }

    private function adjust(ProductVariant $variant, array $data)
    {
        return Livewire::actingAs($this->admin())
            ->test(ListInventory::class)
            ->callTableAction('adjust_stock', $variant, $data);
    }

    public function test_a_preset_reason_is_what_lands_in_the_log(): void
    {
        $variant = $this->variant();

        $this->adjust($variant, [
            'action_type'   => 'restock',
            'quantity'      => 5,
            'reason_preset' => 'Supplier delivery',
        ])->assertHasNoTableActionErrors();

        $log = InventoryLog::latest('id')->firstOrFail();

        $this->assertSame('Supplier delivery', $log->notes);
        $this->assertSame(5, $log->quantity_changed);
        $this->assertSame(15, $variant->fresh()->stock);
    }

    /** "other" is a UI token; it must never reach the audit trail. */
    public function test_other_stores_the_typed_text_not_the_word_other(): void
    {
        $variant = $this->variant();

        $this->adjust($variant, [
            'action_type'   => 'deduct',
            'quantity'      => 2,
            'reason_preset' => 'other',
            'notes'         => 'Lid dented in transit, sent back to supplier',
        ])->assertHasNoTableActionErrors();

        $log = InventoryLog::latest('id')->firstOrFail();

        $this->assertSame('Lid dented in transit, sent back to supplier', $log->notes);
        $this->assertSame(-2, $log->quantity_changed);
    }

    public function test_a_reason_is_required(): void
    {
        $this->adjust($this->variant(), [
            'action_type' => 'restock',
            'quantity'    => 5,
        ])->assertHasTableActionErrors(['reason_preset']);

        $this->assertSame(0, InventoryLog::count());
    }

    /** Choosing "Other" and typing nothing is not a reason. */
    public function test_other_demands_the_text(): void
    {
        $this->adjust($this->variant(), [
            'action_type'   => 'adjustment',
            'quantity'      => 1,
            'reason_preset' => 'other',
            'notes'         => '',
        ])->assertHasTableActionErrors(['notes']);

        $this->assertSame(0, InventoryLog::count());
    }

    /** A restock reason must not be offerable on a deduction, and vice versa. */
    public function test_the_reasons_offered_belong_to_the_action(): void
    {
        $restock = InventoryLog::reasonOptions('restock');
        $deduct  = InventoryLog::reasonOptions('deduct');

        $this->assertArrayHasKey('Supplier delivery', $restock);
        $this->assertArrayNotHasKey('Supplier delivery', $deduct);

        $this->assertArrayHasKey('Damaged', $deduct);
        $this->assertArrayNotHasKey('Damaged', $restock);

        // The escape hatch is on every action, including an unknown one.
        foreach (['restock', 'deduct', 'adjustment', 'nonsense'] as $action) {
            $this->assertArrayHasKey('other', InventoryLog::reasonOptions($action));
        }
    }
}
