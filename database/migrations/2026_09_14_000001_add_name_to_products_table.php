<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Products get a real NAME.
 *
 * Until now the product's name was its `description` — every list, alert and
 * order line printed the description and called it a name, which left no
 * field for an actual description. QA asked for a name on every product, so
 * `description` is demoted to what it says it is and `name` takes over as the
 * label.
 *
 * Backfilled from `description` (that IS the current name), falling back to
 * "Brand — Category" for the one product whose description is blank. NOT NULL
 * with a '' default rather than nullable: a nameless product would print as an
 * empty row everywhere, and the form requires one from here on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('name', 255)->default('')->after('brand_id');
        });

        foreach (DB::table('products')->get() as $product) {
            $name = trim((string) $product->description);

            if ($name === '') {
                $brand    = DB::table('brands')->where('id', $product->brand_id)->value('brand_name');
                $category = DB::table('categories')->where('id', $product->category_id)->value('category_name');
                $name     = trim(implode(' — ', array_filter([$brand, $category]))) ?: 'Product #' . $product->id;
            }

            // A description that was only ever a name is now a duplicate;
            // clear it so the admin can write a real one.
            DB::table('products')->where('id', $product->id)->update([
                'name'        => mb_substr($name, 0, 255),
                'description' => null,
            ]);
        }
    }

    public function down(): void
    {
        // Put the name back where it came from, so `description` is again the
        // label the rest of the old code expects.
        foreach (DB::table('products')->whereNull('description')->get() as $product) {
            DB::table('products')->where('id', $product->id)
                ->update(['description' => $product->name]);
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};
