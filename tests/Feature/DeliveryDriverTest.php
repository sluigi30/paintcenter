<?php

namespace Tests\Feature;

use App\Filament\Driver\Resources\DeliveryResource;
use App\Filament\Driver\Resources\DeliveryResource\Pages\ListDeliveries;
use App\Models\ActivityLog;
use App\Models\Message;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\DeliveryService;
use App\Services\OrderCancellationService;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The driver role: what it can reach, what it cannot, and what it must record.
 *
 * Most of what is asserted here fails SILENTLY if it regresses — a driver who
 * never appears in an admin query, a reset email that is dropped, an action
 * that writes no audit row. None of those throw, so none of them would show up
 * in a smoke test that only checks a page loads.
 */
class DeliveryDriverTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role, array $attributes = []): User
    {
        return User::create(array_merge([
            'first_name' => ucfirst($role),
            'last_name'  => 'Person',
            'email'      => $role . uniqid() . '@example.test',
            'password'   => bcrypt('password'),
            'role'       => $role,
        ], $attributes));
    }

    private function order(?User $driver = null, string $status = 'processing', string $method = 'gcash'): Order
    {
        $order = Order::create([
            'user_id'      => $this->user('customer', ['phone' => null])->id,
            'order_date'   => now(),
            'order_type'   => 'delivery',
            'status'       => $status,
            'total_amount' => 4200,
            'driver_id'    => $driver?->id,
            'assigned_at'  => $driver ? now() : null,
            // A driver only ever handles an order they have collected.
            'picked_up_at' => $status === 'shipped' ? now() : null,
        ]);

        Payment::create([
            'order_id'       => $order->id,
            'payment_method' => $method,
            'payment_status' => 'pending',
        ]);

        return $order->refresh();
    }

    // -------------------------------------------------------
    // Panel access
    // -------------------------------------------------------

    public function test_a_driver_may_enter_the_driver_panel_and_not_the_admin_one(): void
    {
        $driver = $this->user('driver');

        $this->assertTrue($driver->canAccessPanel(Filament::getPanel('driver')));
        $this->assertFalse($driver->canAccessPanel(Filament::getPanel('admin')));
    }

    public function test_an_admin_may_not_enter_the_driver_panel(): void
    {
        // Not an oversight: an admin who needs to intervene does it from the
        // Orders table, where the override actions live.
        foreach (['admin', 'super_admin'] as $role) {
            $staff = $this->user($role);

            $this->assertTrue($staff->canAccessPanel(Filament::getPanel('admin')));
            $this->assertFalse($staff->canAccessPanel(Filament::getPanel('driver')));
        }
    }

    public function test_a_customer_and_an_archived_driver_reach_neither_panel(): void
    {
        $customer = $this->user('customer');
        $archived = $this->user('driver', ['is_archived' => true]);

        foreach ([$customer, $archived] as $user) {
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('driver')));
            $this->assertFalse($user->canAccessPanel(Filament::getPanel('admin')));
        }
    }

    // -------------------------------------------------------
    // Signing in
    // -------------------------------------------------------

    private function attemptLogin(string $panel, string $email, string $password)
    {
        Filament::setCurrentPanel($panel);

        // The panel's CONFIGURED login page, not Filament\Auth\Pages\Login —
        // testing the base class silently exercises stock Filament and passes
        // while the override under test never runs.
        return Livewire::test(\App\Filament\Auth\Login::class)
            ->fillForm(['email' => $email, 'password' => $password])
            ->call('authenticate');
    }

    public function test_a_driver_signs_in_at_the_driver_panel(): void
    {
        $this->user('driver', ['email' => 'mark@example.test', 'password' => 'Sup3rSecret!23']);

        $this->attemptLogin('driver', 'mark@example.test', 'Sup3rSecret!23')
            ->assertHasNoErrors();

        $this->assertSame('driver', auth()->user()?->role);
    }

    public function test_a_driver_at_the_admin_login_is_told_where_to_go(): void
    {
        // Filament checks canAccessPanel() as part of the attempt and reports
        // the failure with the SAME message it uses for a bad password. A
        // driver with a perfectly good password was told it did not match,
        // which reads as a password they mistyped when they set it.
        $this->user('driver', ['email' => 'mark@example.test', 'password' => 'Sup3rSecret!23']);

        $component = $this->attemptLogin('admin', 'mark@example.test', 'Sup3rSecret!23');

        $this->assertFalse(auth()->check());
        $this->assertStringContainsString(
            '/driver/login',
            implode(' ', $component->errors()->all()),
        );
    }

    public function test_a_genuinely_wrong_password_still_says_nothing_useful(): void
    {
        // The helpful branches must stay behind a correct password, or the
        // login page becomes a way to ask which addresses are drivers.
        $this->user('driver', ['email' => 'mark@example.test', 'password' => 'Sup3rSecret!23']);

        $component = $this->attemptLogin('admin', 'mark@example.test', 'wrong-password');

        $errors = implode(' ', $component->errors()->all());

        $this->assertStringNotContainsString('/driver', $errors);
        $this->assertStringContainsString('do not match', $errors);
    }

    // -------------------------------------------------------
    // Scoping — a driver sees their own work and nothing else
    // -------------------------------------------------------

    public function test_a_driver_only_lists_their_own_deliveries(): void
    {
        $mine   = $this->user('driver');
        $theirs = $this->user('driver');

        $own   = $this->order($mine);
        $other = $this->order($theirs);

        // A real request gets this from the panel's middleware. Without it
        // Filament builds row-action URLs against the DEFAULT panel and the
        // table dies looking for filament.admin.resources.deliveries.view.
        Filament::setCurrentPanel('driver');

        Livewire::actingAs($mine)
            ->test(ListDeliveries::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$other]);
    }

    public function test_the_delivery_detail_screen_renders(): void
    {
        // A page that only ever loads in a browser is a page whose infolist can
        // break on a null relation and nobody finds out until a driver is
        // standing on a doorstep.
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        Filament::setCurrentPanel('driver');

        Livewire::actingAs($driver)
            ->test(\App\Filament\Driver\Resources\DeliveryResource\Pages\ViewDelivery::class, [
                'record' => $order->getKey(),
            ])
            ->assertSuccessful();
    }

    public function test_opening_another_drivers_order_is_a_404_not_a_403(): void
    {
        // Order ids are a plain auto-increment, so a 403 on a row that exists
        // is a difference anyone can measure by counting. Same rule as
        // MessageAttachmentController.
        $mine   = $this->user('driver');
        $theirs = $this->order($this->user('driver'));

        $this->actingAs($mine);

        $this->expectException(ModelNotFoundException::class);

        DeliveryResource::getEloquentQuery()->findOrFail($theirs->id);
    }

    public function test_a_pickup_order_never_appears_on_a_drivers_list(): void
    {
        $driver = $this->user('driver');

        $pickup = $this->order($driver);
        $pickup->update(['order_type' => 'pickup']);

        $this->actingAs($driver);

        $this->assertEmpty(DeliveryResource::getEloquentQuery()->pluck('id'));
    }

    // -------------------------------------------------------
    // A driver is not an admin
    // -------------------------------------------------------

    public function test_a_driver_is_never_counted_as_an_admin(): void
    {
        // User::admins() feeds the stock alerts, the new-order bell and
        // activeAdmin(). A driver leaking into it would make them the sender
        // of every automated order message.
        $driver = $this->user('driver');

        $this->assertNotContains($driver->id, User::admins()->pluck('id')->all());

        $admin = $this->user('admin');
        $this->assertSame($admin->id, User::activeAdmin()?->id);
    }

    public function test_a_driver_cannot_read_a_customer_thread(): void
    {
        $customer = $this->user('customer');
        $admin    = $this->user('admin');
        $driver   = $this->user('driver');

        $message = Message::create([
            'sender_id'   => $customer->id,
            'receiver_id' => $admin->id,
            'content'     => 'Is the white in stock?',
            'timestamp'   => now(),
            'is_read'     => false,
        ]);

        $this->assertTrue($message->isVisibleTo($admin));
        $this->assertFalse($message->isVisibleTo($driver));
    }

    public function test_a_driver_gets_a_password_reset_but_a_customer_still_does_not(): void
    {
        \Illuminate\Support\Facades\Notification::fake();

        $this->user('driver')->sendPasswordResetNotification('token');
        $this->user('customer')->sendPasswordResetNotification('token');
        $this->user('driver', ['is_archived' => true])->sendPasswordResetNotification('token');

        \Illuminate\Support\Facades\Notification::assertSentTimes(
            \Illuminate\Auth\Notifications\ResetPassword::class,
            1,
        );
    }

    // -------------------------------------------------------
    // Assignment
    // -------------------------------------------------------

    public function test_assigning_records_the_driver_and_writes_an_audit_entry(): void
    {
        $admin  = $this->user('admin');
        $driver = $this->user('driver');
        $order  = $this->order();

        $this->actingAs($admin);
        DeliveryService::assign($order, $driver, $admin);

        $this->assertSame($driver->id, $order->fresh()->driver_id);
        $this->assertNotNull($order->fresh()->assigned_at);

        // The automatic TracksActivity diff records `driver_id: null → 7`,
        // which is unreadable exactly when someone is working out who had the
        // order — so assign() writes a named entry as well.
        $this->assertTrue(
            ActivityLog::where('event', 'order.assigned')->exists(),
        );
    }

    public function test_a_pickup_cannot_be_assigned_to_a_driver(): void
    {
        $admin = $this->user('admin');
        $order = $this->order();
        $order->update(['order_type' => 'pickup']);

        $this->actingAs($admin);

        $this->expectException(DomainException::class);

        DeliveryService::assign($order->fresh(), $this->user('driver'), $admin);
    }

    public function test_reassigning_an_order_already_out_brings_it_back_to_the_shop(): void
    {
        // The previous driver is told to return the items, so the order has to
        // stop being "out for delivery" — otherwise the new driver gets it in
        // their Out for Delivery tab, offering only "Delivered", for goods
        // sitting on a shelf at the shop.
        $this->user('admin');
        $admin  = $this->user('super_admin');
        $first  = $this->user('driver');
        $second = $this->user('driver');

        $order = $this->order($first, 'shipped');
        $order->update(['failed_attempts' => 2, 'delivery_note' => 'Nobody home']);

        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');
        DeliveryService::assign($order->fresh(), $second, $admin);

        $fresh = $order->fresh();

        $this->assertSame($second->id, $fresh->driver_id);
        $this->assertSame('processing', $fresh->status);
        $this->assertSame(0, $fresh->failed_attempts);
        $this->assertNull($fresh->picked_up_at);
        // Kept — the most useful thing the next driver can know before setting off.
        $this->assertSame('Nobody home', $fresh->delivery_note);
    }

    public function test_an_exhausted_delivery_can_be_cancelled_but_an_ordinary_shipped_one_cannot(): void
    {
        // A shipped order is normally uncancellable because nobody here knows
        // where the goods are. An exhausted one is the case where we do: the
        // driver tried, failed, and was told to bring it back.
        $driver = $this->user('driver');

        $ordinary = $this->order($driver, 'shipped');
        $this->assertFalse(OrderCancellationService::canCancel($ordinary));

        $exhausted = $this->order($driver, 'shipped');
        $exhausted->update(['failed_attempts' => DeliveryService::MAX_ATTEMPTS]);
        $this->assertTrue(OrderCancellationService::canCancel($exhausted->fresh()));

        // The customer's own rule must NOT widen with it — they cannot see the
        // counter and the goods are not with them.
        $this->assertFalse(OrderCancellationService::canCustomerCancel($exhausted->fresh()));
    }

    public function test_cancelling_an_exhausted_delivery_returns_the_stock(): void
    {
        $this->user('admin');
        $admin  = $this->user('super_admin');
        $driver = $this->user('driver');

        $order = $this->order($driver, 'shipped');
        $order->update(['failed_attempts' => DeliveryService::MAX_ATTEMPTS]);

        $this->actingAs($admin);
        OrderCancellationService::cancel($order->fresh(), 'Undeliverable after 3 attempts', $admin->id);

        $fresh = $order->fresh();

        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame('Undeliverable after 3 attempts', $fresh->cancellation_reason);

        // NOT 'refunded'. The driver never collected — there is no money to
        // send back, and saying otherwise tells the store it returned cash it
        // never received.
        $this->assertSame('pending', $fresh->payment->payment_status);
    }

    public function test_only_money_that_came_in_is_refunded_on_cancellation(): void
    {
        $this->user('admin');
        $admin  = $this->user('super_admin');
        $driver = $this->user('driver');

        // Collected COD, then cancelled by the store afterwards.
        $paid = $this->order($driver, 'processing', 'cod');
        $paid->payment->update(['payment_status' => 'paid']);

        $this->actingAs($admin);
        OrderCancellationService::cancel($paid->fresh(), 'Store error', $admin->id);

        $this->assertSame('refunded', $paid->fresh()->payment->payment_status);
    }

    // -------------------------------------------------------
    // The delivery leg
    // -------------------------------------------------------

    public function test_a_driver_walks_an_order_from_processing_to_completed(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver);

        $this->actingAs($driver);

        $this->assertTrue(DeliveryService::pickUp($order, $driver)->succeeded());
        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->picked_up_at);

        $this->assertTrue(DeliveryService::deliver($order->fresh(), $driver)->succeeded());
        $this->assertSame('completed', $order->fresh()->status);
        $this->assertNotNull($order->fresh()->delivered_at);
    }

    public function test_a_driver_cannot_touch_an_order_that_is_not_theirs(): void
    {
        $order = $this->order($this->user('driver'));
        $other = $this->user('driver');

        $this->actingAs($other);

        $this->expectException(DomainException::class);

        DeliveryService::pickUp($order, $other);
    }

    public function test_a_drivers_delivery_is_written_to_the_activity_log(): void
    {
        // ActivityLog::log() refused anyone who was not an admin, which meant
        // the actions most worth attributing — who took an order out, who
        // handed it over — were recorded nowhere at all.
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver);

        $this->assertTrue(
            ActivityLog::where('event', 'order.delivered')
                ->where('user_id', $driver->id)
                ->exists(),
        );
    }

    // -------------------------------------------------------
    // COD
    // -------------------------------------------------------

    public function test_a_cod_order_cannot_be_delivered_without_confirming_the_cash(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        $this->actingAs($driver);

        try {
            DeliveryService::deliver($order, $driver, cashCollected: false);
            $this->fail('A COD delivery was completed without confirming collection.');
        } catch (DomainException) {
            // Nothing may have moved — not the status, not the payment.
            $this->assertSame('shipped', $order->fresh()->status);
            $this->assertSame('pending', $order->payment->fresh()->payment_status);
        }
    }

    public function test_confirming_the_cash_marks_the_payment_paid(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'cod');

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver, cashCollected: true);

        $this->assertSame('completed', $order->fresh()->status);
        $this->assertSame('paid', $order->payment->fresh()->payment_status);
        // Stamped separately from payment_status, which cannot say WHICH
        // driver took the money.
        $this->assertNotNull($order->fresh()->cash_collected_at);
    }

    public function test_a_prepaid_order_needs_no_cash_step(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped', 'gcash');

        $this->actingAs($driver);
        DeliveryService::deliver($order, $driver);

        $this->assertSame('completed', $order->fresh()->status);
        // An online payment is not the driver's to collect or to change.
        $this->assertSame('pending', $order->payment->fresh()->payment_status);
        $this->assertNull($order->fresh()->cash_collected_at);
    }

    // -------------------------------------------------------
    // Failed attempts
    // -------------------------------------------------------

    public function test_a_failed_attempt_changes_no_status_and_keeps_the_pickup_time(): void
    {
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');
        $pickedUpAt = $order->picked_up_at;

        $this->actingAs($driver);
        DeliveryService::recordFailedAttempt($order, $driver, 'Nobody home');

        $fresh = $order->fresh();

        $this->assertSame('shipped', $fresh->status);
        $this->assertSame(1, $fresh->failed_attempts);
        $this->assertSame('Nobody home', $fresh->delivery_note);
        // The goods DID leave the store. Clearing this would lose track of how
        // long stock has been off the shelf.
        $this->assertEquals($pickedUpAt->timestamp, $fresh->picked_up_at->timestamp);
    }

    public function test_the_customer_is_told_about_a_failed_attempt(): void
    {
        // Their tracker goes on saying "on its way", so silence reads as a van
        // that simply never came.
        $this->user('admin');   // someone for the store to speak through
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        $this->actingAs($driver);
        Filament::setCurrentPanel('driver');
        DeliveryService::recordFailedAttempt($order, $driver, 'Nobody home');

        $this->assertTrue(
            Message::where('receiver_id', $order->user_id)
                ->where('kind', Message::KIND_ORDER_UPDATE)
                ->where('content', 'like', '%Nobody home%')
                ->exists(),
        );
    }

    public function test_attempts_stop_at_the_cap_and_alert_the_admins(): void
    {
        $admin  = $this->user('admin');
        $driver = $this->user('driver');
        $order  = $this->order($driver, 'shipped');

        $this->actingAs($driver);

        // The driver presses this button from THEIR panel, and the alert it
        // raises links into the ADMIN one. Resource::getUrl() resolves against
        // the current request's panel, so without this line the test builds an
        // admin URL by default and passes while the real thing 500s on
        // "Route [filament.driver.resources.orders.edit] not defined".
        Filament::setCurrentPanel('driver');

        for ($i = 0; $i < DeliveryService::MAX_ATTEMPTS; $i++) {
            DeliveryService::recordFailedAttempt($order->fresh(), $driver, 'Nobody home');
        }

        $this->assertTrue(DeliveryService::attemptsExhausted($order->fresh()));
        $this->assertSame(1, $admin->notifications()->count());

        // The final message promises the customer the store will be in touch,
        // so the store has to have been told FIRST — the alert is raised before
        // the message is posted, and a failure there aborts before the promise
        // is made. Both must exist together.
        $this->assertTrue(
            Message::where('receiver_id', $order->user_id)
                ->where('content', 'like', '%someone from the store will contact you%')
                ->exists(),
        );

        // A fourth would leave the order going round forever with nobody
        // deciding anything about it.
        $this->expectException(DomainException::class);
        DeliveryService::recordFailedAttempt($order->fresh(), $driver, 'Nobody home');
    }
}
