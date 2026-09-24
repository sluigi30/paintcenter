<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tint-recipe mixing — the revamp of MIXING.md's pint-pouring design.
 *
 * The customer buys ONE can of base and a recipe of colorant measured in ml.
 * Colorants are not products (no stock, no price of their own), so they get a
 * table of their own: tint_colors. Bases stay ordinary products — they have
 * sizes, prices and stock like any other can — flagged so the catalogue does
 * not list them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $t) {
            // Sold only through the mixing bench, never from the catalogue.
            $t->boolean('is_mixing_base')->default(false)->after('is_custom_color');
        });

        Schema::table('product_variants', function (Blueprint $t) {
            // Litres in one can, as a NUMBER. size_volume is free text ("1L",
            // "1 L", "1-liter") and the tint cap is computed from this, so it
            // must not depend on parsing whatever the admin typed.
            $t->decimal('volume_liters', 8, 3)->nullable()->after('size_volume');

            // How much colorant one litre of this base accepts before the
            // paint stops behaving like paint. Null = config default.
            $t->decimal('max_tint_ml_per_liter', 5, 1)->nullable()->after('tint_strength');
        });

        Schema::create('tint_colors', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->char('hex_code', 7);

            // The smallest amount the customer can add, in ml. An input
            // constraint only — the colour maths takes any value.
            $t->decimal('step_ml', 4, 1)->default(1.0);

            // Kubelka-Munk calibration. Deliberately NOT on the admin form:
            // it changes every preview, and "stronger" is not a setting.
            $t->decimal('tint_strength', 3, 2)->default(1.00);

            $t->unsignedInteger('sort_order')->default(0);

            // Out of colorant = archived; restocked = unarchived. Never deleted,
            // because placed orders name it.
            $t->boolean('is_archived')->default(false);
            $t->timestamps();
        });

        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // [{tint_color_id, name, hex, ml}] — ml per ONE can. A snapshot:
                // editing a preset later must not rewrite a placed order.
                $t->json('mix_recipe')->nullable()->after('mix_liters');
            });
        }

        $this->backfillVolumes();
    }

    /**
     * Fill volume_liters from the same parser the pint-mixing flow used, so
     * every existing can starts with a number the admin only has to check.
     * Unparseable sizes ("Set of 3") stay null, which is the honest answer.
     */
    private function backfillVolumes(): void
    {
        DB::table('product_variants')->select(['id', 'size_volume'])->orderBy('id')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $liters = (new \App\Models\ProductVariant)
                        ->forceFill(['size_volume' => $row->size_volume])
                        ->parsedLiters();

                    if ($liters !== null) {
                        DB::table('product_variants')->where('id', $row->id)
                            ->update(['volume_liters' => round($liters, 3)]);
                    }
                }
            });
    }

    public function down(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('mix_recipe'));
        }

        Schema::dropIfExists('tint_colors');

        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropColumn(['volume_liters', 'max_tint_ml_per_liter']);
        });

        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_mixing_base'));
    }
};
