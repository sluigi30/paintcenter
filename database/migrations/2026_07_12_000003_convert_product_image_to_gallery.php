<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Single product image → image gallery (JSON array, ordered; first
 * entry is the cover). Existing images are carried over as one-item
 * galleries. The model exposes a computed `image` (= first of gallery)
 * so all existing consumers keep working.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->json('images')->nullable()->after('hex_code');
        });

        // Carry over existing single images as one-item galleries
        DB::table('products')->whereNotNull('image')->where('image', '!=', '')
            ->eachById(function ($product) {
                DB::table('products')->where('id', $product->id)
                    ->update(['images' => json_encode([$product->image])]);
            });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('image')->nullable();
        });

        DB::table('products')->whereNotNull('images')->eachById(function ($product) {
            $images = json_decode($product->images, true) ?: [];
            DB::table('products')->where('id', $product->id)
                ->update(['image' => $images[0] ?? null]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('images');
        });
    }
};
