<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A mixing base is chosen by TYPE — white, pastel, medium, deep, accent — and
 * the type fixes its preview colour, how strongly colorant shows in it, and
 * its default colorant capacity (config('paint.mix.base_types')). It replaces
 * a free hex, which cannot express that a deep base makes the same 20 ml of
 * colorant show far deeper. See MIXING.md, "Base types".
 *
 * Existing mixing-base cans are typed from their colour name ("Deep Base" →
 * deep); a name that says nothing recognisable becomes white, which is the
 * safe reading — it under-states colour rather than over-states it — and the
 * admin should check those. Their hex and name are then set from the type,
 * unless that would make two cans of one product and size identical, in which
 * case the original name is kept so the identity key still holds.
 */
return new class extends Migration
{
    private const MATCH = [
        'accent' => ['accent', 'clear', 'ultra', 'neutral'],
        'deep'   => ['deep', 'dark', 'tint base d'],
        'medium' => ['medium', 'mid'],
        'pastel' => ['pastel', 'light'],
        'white'  => ['white'],
    ];

    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $t) {
            $t->string('base_type', 20)->nullable()->after('volume_liters');
        });

        $types = config('paint.mix.base_types');

        $variants = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('products.is_mixing_base', true)
            ->select('product_variants.id', 'product_variants.product_id', 'product_variants.color_name', 'product_variants.size_volume', 'product_variants.color_code')
            ->orderBy('product_variants.id')
            ->get();

        foreach ($variants as $v) {
            $type = self::typeFor($v->color_name);
            $label = $types[$type]['label'];

            // Renaming to the type label must not collide with a sibling can.
            $taken = DB::table('product_variants')
                ->where('product_id', $v->product_id)
                ->where('size_volume', $v->size_volume)
                ->where('color_code', $v->color_code)
                ->where('color_name', $label)
                ->where('id', '!=', $v->id)
                ->exists();

            DB::table('product_variants')->where('id', $v->id)->update([
                'base_type' => $type,
                'hex_code' => $types[$type]['hex'],
                'color_name' => $taken ? $v->color_name : $label,
            ]);
        }
    }

    private static function typeFor(?string $name): string
    {
        $name = strtolower((string) $name);

        foreach (self::MATCH as $type => $words) {
            foreach ($words as $word) {
                if (str_contains($name, $word)) {
                    return $type;
                }
            }
        }

        return 'white';
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $t) => $t->dropColumn('base_type'));
    }
};
