<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Colour becomes a VARIANT AXIS, alongside size and base.
 *
 * Until now each colour of a paint line was its own product row, so "Boysen
 * Latex Colors" appeared in the catalogue once per shade and a customer
 * scrolled past forty near-identical cards. QA asked for one product whose
 * colours are picked after tapping it — which is exactly what already happens
 * for sizes, and for bases since 2026-09-01. So colour joins them on the
 * variant instead of getting a hierarchy of its own: a variant is now one
 * (colour × size × base) can, and stock is counted where it actually sits.
 *
 * `color_code` and `color_name` are NOT NULL DEFAULT '' for the same reason
 * `base_code` is (see 2026_09_01_000001): MySQL treats NULLs in a unique index
 * as distinct, so a nullable colour would let two "4L" rows of the same
 * uncoded shade through and silently lose the duplicate protection.
 *
 * BOTH are in the key, not just the code. Plenty of shades ship with a name
 * and no manufacturer code ("White", "Off-White"); keying on the code alone
 * would collapse them into one row. The pair is the colour's identity.
 *
 * `hex_code` stays a screen preview and is nullable — it identifies nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->string('color_code', 40)->default('')->after('product_id');
            $table->string('color_name', 100)->default('')->after('color_code');
            $table->string('hex_code', 7)->nullable()->after('color_name');
        });

        // Every existing variant inherits its parent product's colour — the
        // product WAS the colour, so this is lossless. Merging the resulting
        // one-colour products into real multi-colour lines is an admin job,
        // not something a migration can guess.
        foreach (DB::table('products')->get() as $product) {
            DB::table('product_variants')
                ->where('product_id', $product->id)
                ->update([
                    'color_code' => (string) ($product->color_code ?? ''),
                    'color_name' => (string) ($product->color_name ?? ''),
                    'hex_code'   => $product->hex_code,
                ]);
        }

        // Add the replacement BEFORE dropping the old unique: MySQL uses that
        // index to support the product_id foreign key and refuses to drop an
        // index a constraint still needs (errno 1553). The new key is also
        // leftmost-prefixed on product_id, so it covers the FK in between.
        // Named explicitly because the generated name would be 74 characters
        // and MySQL caps identifiers at 64.
        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(
                ['product_id', 'color_code', 'color_name', 'size_volume', 'base_code'],
                'product_variants_identity_unique'
            );
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'size_volume', 'base_code']);
        });

        // The product no longer has a colour of its own.
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['color_code', 'color_name', 'hex_code']);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('color_code', 40)->nullable()->after('description');
            $table->string('color_name', 100)->nullable()->after('color_code');
            $table->string('hex_code', 7)->nullable()->after('color_name');
        });

        // A product can hold one colour, so a multi-colour line collapses to
        // its first variant's shade — the very limitation this migration lifted.
        foreach (DB::table('products')->get() as $product) {
            $first = DB::table('product_variants')
                ->where('product_id', $product->id)
                ->orderBy('id')
                ->first();

            if ($first) {
                DB::table('products')->where('id', $product->id)->update([
                    'color_code' => $first->color_code ?: null,
                    'color_name' => $first->color_name ?: null,
                    'hex_code'   => $first->hex_code,
                ]);
            }
        }

        Schema::table('product_variants', function (Blueprint $table) {
            $table->unique(['product_id', 'size_volume', 'base_code']);
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropUnique('product_variants_identity_unique');
        });

        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropColumn(['color_code', 'color_name', 'hex_code']);
        });
    }
};
