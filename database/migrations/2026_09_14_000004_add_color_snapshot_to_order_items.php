<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Order lines snapshot the colour they were bought in.
 *
 * Now that colour lives on the variant, an order line that only carried
 * `size_volume` would render as "Boysen Latex Colors (4L)" with no shade at
 * all — and re-reading the colour off the variant is exactly what the
 * existing size_volume/unit_price snapshots refuse to do: the variant can be
 * recoloured, renamed or archived, and a two-month-old order must still show
 * what was actually handed over.
 *
 * Nullable here, unlike on the variant: no unique index depends on these, and
 * nothing distinguishes "" from "no colour" on a line that has already been
 * sold. Custom-colour lines leave them null and keep using custom_hex.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('color_code', 40)->nullable()->after('product_variant_id');
            $table->string('color_name', 100)->nullable()->after('color_code');
            $table->string('hex_code', 7)->nullable()->after('color_name');
        });

        // Existing lines take the colour their variant carries today — the
        // closest thing to the truth available after the fact.
        foreach (DB::table('product_variants')->get() as $variant) {
            DB::table('order_items')
                ->where('product_variant_id', $variant->id)
                ->update([
                    'color_code' => $variant->color_code ?: null,
                    'color_name' => $variant->color_name ?: null,
                    'hex_code'   => $variant->hex_code,
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['color_code', 'color_name', 'hex_code']);
        });
    }
};
