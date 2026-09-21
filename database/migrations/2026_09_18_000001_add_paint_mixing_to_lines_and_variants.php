<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer-mixed colours.
 *
 * A mix is several ordinary lines — one base can plus the pint cans poured
 * into it — tied together by `mix_group`. The resulting colour rides on the
 * EXISTING custom_hex / custom_color_name columns rather than new ones, so a
 * mix inherits everything already built for custom tinting: the custom badge,
 * the swatch on eight screens, the mixing sheet, and (the important one) the
 * narrowed cancellation window, since mixed paint cannot be un-mixed.
 *
 * See MIXING.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                // One uuid per mix. Server-generated, never accepted from the
                // client: honouring a supplied group would let a request post
                // lines into somebody else's mix.
                $t->char('mix_group', 36)->nullable()->after('custom_color_name');

                // 'base' | 'tint'. Null on an ordinary, un-mixed line.
                $t->string('mix_role', 10)->nullable()->after('mix_group');

                // Litres in ONE can of this variant, NOT the line total.
                // The line contributes quantity * mix_liters. Snapshotted
                // because size_volume is free text and a later re-spec must
                // not silently restate the recipe's proportions.
                $t->decimal('mix_liters', 8, 3)->nullable()->after('mix_role');

                // Grouping a cart or an order is the only query this serves.
                $t->index('mix_group');
            });
        }

        Schema::table('product_variants', function (Blueprint $t) {
            // Calibration for the mix prediction. Single-constant Kubelka-Munk
            // ignores that white SCATTERS while dark pigment ABSORBS, so it
            // over-predicts how far a dark tint pulls a mix. Rather than
            // pretend the model is right, this lets the counter correct it per
            // colourant against real mixes — the same approach config/paint.php
            // takes to the tinting thresholds.
            $t->decimal('tint_strength', 3, 2)->default(1.00)->after('tint_fee');
        });
    }

    public function down(): void
    {
        foreach (['cart_items', 'order_items'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->dropIndex($table . '_mix_group_index');
                $t->dropColumn(['mix_group', 'mix_role', 'mix_liters']);
            });
        }

        Schema::table('product_variants', function (Blueprint $t) {
            $t->dropColumn('tint_strength');
        });
    }
};
