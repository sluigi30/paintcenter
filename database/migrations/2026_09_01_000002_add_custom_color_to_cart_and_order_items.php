<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom colour, part 2 of 2 — the line side.
 *
 * The chosen colour is an attribute of an ordinary cart/order line, which is
 * what keeps custom colour inside the existing cart, checkout, order and
 * messaging code instead of spawning a parallel "custom orders" system.
 *
 * `custom_hex IS NOT NULL` is the "this line is custom" test everywhere.
 *
 * On order_items the colour is SNAPSHOTTED, for the same reason
 * order_items.size_volume and unit_price already are: the variant behind a
 * line can be renamed, re-priced or archived, and a two-month-old order must
 * still show what was actually bought.
 *
 * tint_fee is copied onto the line rather than read back from the variant so
 * a later price-list edit cannot rewrite the history of what was charged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('custom_hex', 7)->nullable()->after('product_variant_id');
            $table->string('custom_color_name', 60)->nullable()->after('custom_hex');
            $table->decimal('tint_fee', 10, 2)->default(0)->after('custom_color_name');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->string('custom_hex', 7)->nullable()->after('size_volume');
            $table->string('custom_color_name', 60)->nullable()->after('custom_hex');
            $table->decimal('tint_fee', 10, 2)->default(0)->after('custom_color_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['custom_hex', 'custom_color_name', 'tint_fee']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['custom_hex', 'custom_color_name', 'tint_fee']);
        });
    }
};
