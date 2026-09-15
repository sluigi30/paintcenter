<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The order the shades and sizes are listed in.
 *
 * Variants used to come back in insertion order by accident — nothing asked
 * for an order, and the database happened to return rows by primary key. The
 * identity unique index added a moment ago (product_id, color_code, ...) is
 * leftmost-prefixed on product_id, so the planner now answers "this product's
 * variants" from that index instead and hands them back sorted by COLOUR CODE.
 * An uncoded shade ('') therefore jumped to the front of every colour picker.
 *
 * Rather than depend on a different accident, the order becomes a column the
 * admin controls by dragging rows in the product form. Shade cards have an
 * order the customer recognises, and "1L, 4L, 16L" reads better than "16L, 1L,
 * 4L" — neither is something alphabetical sorting would arrive at.
 *
 * Backfilled from the existing ids so today's arrangement survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('product_id');
        });

        foreach (DB::table('product_variants')->distinct()->pluck('product_id') as $productId) {
            $position = 0;

            foreach (DB::table('product_variants')->where('product_id', $productId)->orderBy('id')->pluck('id') as $id) {
                DB::table('product_variants')->where('id', $id)->update(['sort_order' => ++$position]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};
