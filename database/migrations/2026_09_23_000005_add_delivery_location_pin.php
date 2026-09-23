<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where the delivery actually is, when the customer can tell us.
     *
     * A map SEARCH is only ever as good as the string it is given, and
     * "Brgy. Poblacion, Pilar" is a polygon, not a doorstep — a real address
     * typed at checkout still put a driver in the wrong part of the barangay.
     * Coordinates fix that without any map library anywhere: the Navigate link
     * simply targets `lat,lng` instead of the address, and Google Maps routes
     * to it exactly as before.
     *
     * Nullable throughout, and that is the design, not an omission. The pin is
     * optional: a customer may decline the permission, and one ordering for a
     * job site or for a parent SHOULD decline, because their own location is
     * not the delivery's. Every one of those orders falls back to the text
     * address, which is how every order has been placed until now.
     *
     * `location_accuracy` is stored because a fix taken indoors can come back
     * as a ±500 m cell-tower estimate, barely better than the town centre. It
     * is shown on every surface, so a vague pin is never mistaken for a precise
     * one — the same rule that stopped us geocoding "Pilar, Bataan".
     *
     * NOTE what accuracy does NOT tell you: how precisely the PHONE was
     * located, never whether the phone was at the delivery address. A ±8 m pin
     * on the wrong building passes every check. That is the confirmation step's
     * job, not this column's. See DELIVERY_ROLE.md Phase 5.
     *
     * decimal(10,7): 7 decimal places is roughly a centimetre, far beyond what
     * any consumer GPS reports, and avoids the float rounding that makes two
     * "identical" coordinates compare unequal.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('delivery_landmark');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $table->unsignedInteger('location_accuracy')->nullable()->after('delivery_lng');
            $table->timestamp('location_pinned_at')->nullable()->after('location_accuracy');
        });

        // Mirrored so a pin is remembered between orders, like the address now
        // is. Without it a customer re-pins on every single order and stops
        // bothering — exactly the trap the address was in until it started
        // being saved back at checkout.
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('delivery_lat', 10, 7)->nullable()->after('landmark');
            $table->decimal('delivery_lng', 10, 7)->nullable()->after('delivery_lat');
            $table->unsignedInteger('location_accuracy')->nullable()->after('delivery_lng');
            $table->timestamp('location_pinned_at')->nullable()->after('location_accuracy');
        });
    }

    public function down(): void
    {
        foreach (['orders', 'users'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn([
                    'delivery_lat',
                    'delivery_lng',
                    'location_accuracy',
                    'location_pinned_at',
                ]);
            });
        }
    }
};
