<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\EditOrder;
use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\Message;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Status moves one step at a time, along the flow for THAT order type.
 *
 * A dropdown of every status let a delivery be set to ready_for_pickup. The
 * app's tracker is per-type, could not place that status in the delivery flow,
 * and fell back to showing the order as still "Order Placed" — the store saw a
 * ready order, the customer saw an untouched one.
 */
class OrderStatusFlowTest extends TestCase
{
    use RefreshDatabase;

    /** Memoised: every button press acts as an admin, and emails are unique. */
    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@example.test'],
            [
                'first_name' => 'Test',
                'last_name'  => 'Admin',
                'password'   => bcrypt('password'),
                'role'       => 'admin',
            ],
        );
    }

    private function order(string $type, string $status = 'pending'): Order
    {
        $customer = User::create([
            'first_name' => 'Juan',
            'last_name'  => 'Dela Cruz',
            'email'      => 'customer' . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => 'customer',
            'phone'      => null,   // keeps SmsService out of the path
        ]);

        return Order::create([
            'user_id'      => $customer->id,
            'order_date'   => now(),
            'order_type'   => $type,
            'status'       => $status,
            'total_amount' => 1400,
        ]);
    }

    private function press(string $action, Order $order)
    {
        return Livewire::actingAs($this->admin())
            ->test(ListOrders::class)
            ->callTableAction($action, $order);
    }

    // -------------------------------------------------------
    // The flows themselves
    // -------------------------------------------------------

    public function test_a_delivery_walks_its_own_flow_to_the_end(): void
    {
        $order = $this->order('delivery');

        foreach (['processing', 'shipped', 'completed'] as $expected) {
            $this->press('advanceStatus', $order);
            $this->assertSame($expected, $order->fresh()->status);
            $order = $order->fresh();
        }

        $this->assertNull($order->nextStatus(), 'Completed is the end of the line.');
    }

    public function test_a_pickup_walks_a_different_flow(): void
    {
        $order = $this->order('pickup');

        foreach (['processing', 'ready_for_pickup', 'completed'] as $expected) {
            $this->press('advanceStatus', $order);
            $this->assertSame($expected, $order->fresh()->status);
            $order = $order->fresh();
        }
    }

    /** The bug this replaces: a delivery could never be "ready for pickup". */
    public function test_a_delivery_is_never_offered_a_pickup_status(): void
    {
        $delivery = $this->order('delivery', 'processing');
        $pickup   = $this->order('pickup', 'processing');

        $this->assertSame('shipped', $delivery->nextStatus());
        $this->assertSame('ready_for_pickup', $pickup->nextStatus());

        $this->assertNotContains('ready_for_pickup', $delivery->statusFlow());
        $this->assertNotContains('shipped', $pickup->statusFlow());
    }

    // -------------------------------------------------------
    // Moving back
    // -------------------------------------------------------

    public function test_move_back_undoes_one_step(): void
    {
        $order = $this->order('delivery', 'shipped');

        $this->press('revertStatus', $order);

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_there_is_nothing_before_pending(): void
    {
        $this->assertNull($this->order('delivery', 'pending')->previousStatus());
        $this->assertNull($this->order('pickup', 'pending')->previousStatus());
    }

    // -------------------------------------------------------
    // Edges
    // -------------------------------------------------------

    /** Cancelled is an exit, not a step; neither button applies. */
    public function test_a_cancelled_order_cannot_be_advanced_or_reverted(): void
    {
        $order = $this->order('delivery', 'cancelled');

        $this->assertNull($order->nextStatus());
        $this->assertNull($order->previousStatus());
    }

    /**
     * Orders placed before the flow was enforced can sit on a status their own
     * type does not use. Refusing to guess is better than guessing wrong.
     */
    public function test_an_order_stranded_off_its_flow_offers_no_next_step(): void
    {
        $stranded = $this->order('delivery', 'ready_for_pickup');

        $this->assertNull($stranded->nextStatus());
        $this->assertNull($stranded->previousStatus());
    }

    /**
     * An order is a financial record. order_items and payments cascade on
     * delete, nothing returns the stock, and the Reports page would quietly
     * report different revenue for a month that had already been read. The way
     * out of an order that should not have happened is to cancel it.
     */
    public function test_an_order_cannot_be_deleted_from_the_edit_page(): void
    {
        $order = $this->order('delivery');

        Livewire::actingAs($this->admin())
            ->test(EditOrder::class, ['record' => $order->getKey()])
            ->assertOk()
            ->assertActionDoesNotExist('delete');

        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    /** Every step still tells the customer — that is what the press is for. */
    public function test_advancing_messages_the_customer(): void
    {
        $this->admin();   // the thread is sent from the store's account
        $order = $this->order('delivery');

        $before = Message::where('receiver_id', $order->user_id)->count();

        $this->press('advanceStatus', $order);

        $this->assertGreaterThan(
            $before,
            Message::where('receiver_id', $order->user_id)->count(),
        );
    }
}
