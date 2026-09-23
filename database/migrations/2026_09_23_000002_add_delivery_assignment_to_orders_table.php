<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The delivery leg of an order, carried on the order itself.
     *
     * No `deliveries` table: `orders` already IS the thing being delivered, and
     * the split that matters is driver_id = who owns it NOW versus
     * activity_logs = what happened historically. Order uses TracksActivity, so
     * a reassignment is already diffed into the audit trail for free — a
     * history table would only restate it.
     *
     * Note there is no `failed_delivery` status and no status column change.
     * A failed attempt is a fact about the trip, not a new place in the
     * journey: adding one would ripple into Order::STATUS_FLOW_BY_TYPE, the
     * mobile app's mirrored FLOW, OrderObserver's SMS, the customer tracker and
     * the reports. See DELIVERY_ROLE.md.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Who currently owns the delivery. Nullable because an order is
            // placed long before anyone is assigned to carry it, and because a
            // pickup is never assigned at all.
            $table->foreignId('driver_id')->nullable()->after('shipping_address')
                ->constrained('users')->nullOnDelete();

            // Mirrors the cancelled_by / cancelled_at pair above: who did it
            // and when, so the admin side can be answered for.
            $table->foreignId('assigned_by')->nullable()->after('driver_id')
                ->constrained('users')->nullOnDelete();

            $table->timestamp('assigned_at')->nullable()->after('assigned_by');

            // Distinct from the status timestamps: an admin can advance an
            // order by hand (the override path), and that is not the same fact
            // as a driver reporting the goods physically left with them.
            $table->timestamp('picked_up_at')->nullable()->after('assigned_at');
            $table->timestamp('delivered_at')->nullable()->after('picked_up_at');

            // The driver's own words — "left with guard", "nobody home". Also
            // holds the reason for the most recent failed attempt.
            $table->text('delivery_note')->nullable()->after('delivered_at');

            // Capped at DeliveryService::MAX_ATTEMPTS. Without a ceiling an
            // undeliverable order sits `shipped` forever and falls off every
            // work list; at the cap the decision passes back to the store.
            $table->unsignedTinyInteger('failed_attempts')->default(0)->after('delivery_note');

            // COD only. payments.payment_status alone cannot say WHICH driver
            // is holding the cash, which is the whole point of reconciling it.
            $table->timestamp('cash_collected_at')->nullable()->after('failed_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('driver_id');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn([
                'assigned_at',
                'picked_up_at',
                'delivered_at',
                'delivery_note',
                'failed_attempts',
                'cash_collected_at',
            ]);
        });
    }
};
