<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Near ABC Store, green gate" — directions, not an address.
     *
     * Its own column rather than more text crammed into shipping_address,
     * because the two are used differently: the address is handed to a map
     * search, the landmark is read by a human standing in the street. Feeding
     * "green gate" to a geocoder only makes the query worse, which is why the
     * Navigate link is built from the address alone.
     *
     * For this store it is also the more useful of the two. The postal code for
     * Pilar is 2101 and tells a driver nothing; a landmark is how a provincial
     * PH delivery is actually found. See DELIVERY_ROLE.md 4b.
     *
     * On `users` as well as `orders` so it is remembered between orders, the
     * same way the address now is — a customer who once wrote good directions
     * should never have to write them twice.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_landmark')->nullable()->after('shipping_address');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('landmark')->nullable()->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_landmark');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('landmark');
        });
    }
};
