<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Converts inventory from product-level to variant-level (per volume).
 *
 * A product ("Anzahl Urethane Paint, red") now owns product_variants
 * ("1L", "4L", "16L"), each with its OWN price, stock, and low-stock
 * threshold. Cart, checkout, and the inventory audit trail all reference
 * the variant.
 *
 * Prototype reset: existing product/order data is cleared (per owner
 * decision, 2026-07-11) instead of being migrated — a single stock pool
 * cannot be honestly split across volumes. Users, brands, and categories
 * are preserved.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Prototype data wipe (children before parents) ──
        DB::table('sms_logs')->delete();
        DB::table('payments')->delete();
        DB::table('order_items')->delete();
        DB::table('inventory_logs')->delete();
        DB::table('cart_items')->delete();
        DB::table('orders')->delete();
        DB::table('products')->delete();

        // ── The variant table ──
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('size_volume', 30);            // "1L", "4L", "16L"
            $table->decimal('price', 10, 2);
            $table->integer('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(10);
            $table->boolean('is_archived')->default(false);
            $table->timestamps();

            $table->unique(['product_id', 'size_volume']);
        });

        // ── Products keep identity only; pricing/stock move to variants ──
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['size_volume', 'price', 'stock', 'low_stock_threshold']);
        });

        // ── Cart lines belong to a variant ──
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn('selected_size');
            $table->foreignId('product_variant_id')->after('product_id')
                ->constrained()->cascadeOnDelete();
        });

        // ── Order lines reference the variant + snapshot the size label
        //    (like unit_price: history must survive later variant edits) ──
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
            $table->string('size_volume', 30)->nullable()->after('product_variant_id');
        });

        // ── Audit trail records which variant moved ──
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn(['product_variant_id', 'size_volume']);
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropForeign(['product_variant_id']);
            $table->dropColumn('product_variant_id');
            $table->string('selected_size', 20)->nullable()->after('quantity');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('size_volume')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(10);
        });

        Schema::dropIfExists('product_variants');
    }
};
