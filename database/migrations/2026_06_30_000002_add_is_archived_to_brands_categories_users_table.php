<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('brand_name');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('category_name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });
    }
};
