<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Custom colour, part 1 of 2 — the sellable side.
 *
 * A custom colour is not a product and has no stock of its own; it is a
 * service applied to a can of BASE paint at the counter. So the thing with
 * inventory is the base variant, and the colour rides on the order line
 * (part 2). See CUSTOM_COLOR.md in the mobile repo.
 *
 * `products.is_custom_color` marks a product whose colour the customer
 * chooses. Its variants are real cans of untinted base on the shelf.
 *
 * `product_variants.base_code` makes base a SECOND VARIANT AXIS alongside
 * size, so one "Permacoat — Custom Colour" product owns 4L-P / 4L-M / 4L-D
 * rather than there being three separate base products. The customer picks
 * a colour and a size; ColorService resolves the base and the word "base"
 * never reaches the app.
 *
 * base_code is NOT NULL DEFAULT '' rather than nullable ON PURPOSE.
 * MySQL treats NULLs in a unique index as distinct from each other, so
 * unique(product_id, size_volume, base_code) with NULL base codes would
 * happily accept two "4L" rows on a ready-mixed product — silently losing
 * the duplicate-size protection the old two-column unique gave us.
 * '' means "this product has no base distinction" and compares normally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_custom_color')->default(false)->after('hex_code');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            // '' = not a base / no base distinction. 'P' pastel, 'M' medium, 'D' deep.
            $table->string('base_code', 2)->default('')->after('size_volume');

            // Colorant cost scales with can size and base depth, not with price —
            // so the fee belongs on the variant, which is already (size x base).
            $table->decimal('tint_fee', 10, 2)->default(0)->after('price');
        });

        // Add the replacement BEFORE dropping the old one, in two separate
        // statements. MySQL uses the (product_id, size_volume) unique as the
        // supporting index for the product_id foreign key and refuses to drop
        // an index a constraint still depends on (errno 1553). The new unique
        // is also leftmost-prefixed on product_id, so it covers the FK for the
        // moment in between and the old index becomes free to go.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'size_volume', 'base_code']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_volume']);
        });
    }

    public function down(): void
    {
        // Same constraint in reverse — restore the two-column unique before
        // dropping the three-column one.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'size_volume']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_volume', 'base_code']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['base_code', 'tint_fee']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_custom_color');
        });
    }
};
