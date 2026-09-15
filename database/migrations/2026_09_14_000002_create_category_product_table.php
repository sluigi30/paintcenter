<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A product belongs to MANY categories.
 *
 * "Nippon Paint Enamel" is legitimately both Enamel Paint and Wood Coating,
 * and a single `category_id` forced the admin to pick one and lose the other
 * — so the product never showed up under the category half the customers
 * browse by.
 *
 * `products.category_id` is DROPPED rather than kept as a "primary" category.
 * Two sources of truth for the same fact is how the pivot and the column
 * drift apart: the API would filter on one and the admin table display the
 * other. Everything now reads the pivot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();

            // Keeps a double-tick in the admin multi-select from writing the
            // same pair twice, which would double every products_count.
            $table->unique(['product_id', 'category_id']);
        });

        $rows = DB::table('products')
            ->whereNotNull('category_id')
            ->get(['id', 'category_id'])
            ->map(fn ($p) => ['product_id' => $p->id, 'category_id' => $p->category_id])
            ->all();

        if ($rows !== []) {
            DB::table('category_product')->insert($rows);
        }

        Schema::table('products', function (Blueprint $table) {
            // dropForeign BY COLUMN, not by name, and on every driver. SQLite
            // cannot drop a constraint in place, so naming the column is what
            // makes Laravel rebuild the table; without it SQLite's plain
            // DROP COLUMN leaves the foreign key clause behind and the next
            // statement fails with "unknown column category_id in foreign key
            // definition".
            $table->dropForeign(['category_id']);
            $table->dropColumn('category_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        // Only the first category survives the trip back — the column can
        // hold one, which is exactly the limitation this migration removed.
        foreach (DB::table('category_product')->orderBy('id')->get() as $row) {
            DB::table('products')->where('id', $row->product_id)
                ->whereNull('category_id')
                ->update(['category_id' => $row->category_id]);
        }

        Schema::dropIfExists('category_product');
    }
};
