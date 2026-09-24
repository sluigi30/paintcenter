<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the two mixing designs that tint recipes replaced (MIXING.md phase 5).
 *
 *   DISPENSER (CUSTOM_COLOR.md): products flagged is_custom_color, variants
 *   told apart by base_code P/M/D, a per-variant tint_fee. Premised on a
 *   tinting machine the shop never had.
 *
 *   PINT POURING (MIXING_PINTS.md): cart lines tied by mix_group, pint
 *   variants calibrated by product_variants.tint_strength.
 *
 * Nothing that describes an ORDER is touched: order_items keeps custom_hex,
 * tint_fee, mix_group, mix_role and mix_liters, so every historical order
 * still renders and still reports. What goes is the machinery for making NEW
 * ones — and the data is FOLDED into the new model before any column drops:
 *
 *   is_custom_color products  → is_mixing_base. They were always cans of
 *                                untinted base, which is exactly what a
 *                                mixing base is. (They appear on the bench once
 *                                the admin gives each can its base colour.)
 *   base_code P/M/D           → the colour name ("Pastel Base"), which is how
 *                                a mixing base names itself — and what keeps
 *                                the three cans distinct once base_code leaves
 *                                the identity key.
 *
 * Carts are not history. Old-style lines — a pint-mix group, or a dispenser
 * custom colour — are deleted: neither can be checked out through the new
 * rules, and the customer mixes again on the bench. Decided 2026-09-24.
 *
 * NOT REVERSIBLE for data: down() restores the columns, not what was in them.
 */
return new class extends Migration
{
    private const BASE_LABELS = ['P' => 'Pastel', 'M' => 'Medium', 'D' => 'Deep'];

    public function up(): void
    {
        // ---- Fold, before anything is dropped ------------------------------

        DB::table('products')->where('is_custom_color', true)->update(['is_mixing_base' => true]);

        DB::table('product_variants')->where('base_code', '!=', '')->orderBy('id')
            ->each(function ($v) {
                $label = self::BASE_LABELS[$v->base_code] ?? $v->base_code;
                $name = trim((string) $v->color_name);

                DB::table('product_variants')->where('id', $v->id)->update([
                    'color_name' => $name === '' ? "{$label} Base" : "{$name} — {$label} base",
                ]);
            });

        // ---- Clear carts of lines the new rules cannot check out -----------

        DB::table('cart_items')
            ->where(fn ($q) => $q->whereNotNull('mix_group')
                ->orWhere(fn ($q) => $q->whereNotNull('custom_hex')->whereNull('mix_recipe')))
            ->delete();

        // ---- Rebuild the identity key without base_code --------------------
        //
        // The unique index also backs the product_id foreign key, and MySQL
        // refuses to drop an index a constraint still needs (errno 1553) — the
        // trap 2026_09_14_000003 documents. A plain index holds the FK while
        // the unique is rebuilt under its own name.

        Schema::table('product_variants', fn (Blueprint $t) => $t->index('product_id', 'product_variants_fk_hold'));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropUnique('product_variants_identity_unique'));
        Schema::table('product_variants', fn (Blueprint $t) => $t->unique(
            ['product_id', 'color_code', 'color_name', 'size_volume'],
            'product_variants_identity_unique',
        ));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropIndex('product_variants_fk_hold'));

        // ---- Drop the retired columns --------------------------------------

        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn(['base_code', 'tint_fee', 'tint_strength']));
        Schema::table('products', fn (Blueprint $t) => $t->dropColumn('is_custom_color'));

        Schema::table('cart_items', function (Blueprint $t) {
            $t->dropIndex('cart_items_mix_group_index');
        });
        Schema::table('cart_items', fn (Blueprint $t) => $t->dropColumn(['mix_group', 'mix_role', 'mix_liters']));
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $t) {
            $t->char('mix_group', 36)->nullable()->index();
            $t->string('mix_role', 10)->nullable();
            $t->decimal('mix_liters', 8, 3)->nullable();
        });

        Schema::table('products', fn (Blueprint $t) => $t->boolean('is_custom_color')->default(false));

        Schema::table('product_variants', function (Blueprint $t) {
            $t->string('base_code', 1)->default('');
            $t->decimal('tint_fee', 10, 2)->default(0);
            $t->decimal('tint_strength', 3, 2)->default(1.00);
        });

        Schema::table('product_variants', fn (Blueprint $t) => $t->index('product_id', 'product_variants_fk_hold'));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropUnique('product_variants_identity_unique'));
        Schema::table('product_variants', fn (Blueprint $t) => $t->unique(
            ['product_id', 'color_code', 'color_name', 'size_volume', 'base_code'],
            'product_variants_identity_unique',
        ));
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropIndex('product_variants_fk_hold'));
    }
};
