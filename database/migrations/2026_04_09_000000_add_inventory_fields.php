<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add low_stock_threshold to products table
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('low_stock_threshold')->default(10)->after('stock');
        });

        // Add notes and admin_id to inventory_logs table
        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete()->after('product_id');
            $table->string('notes')->nullable()->after('quantity_changed');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('low_stock_threshold');
        });

        Schema::table('inventory_logs', function (Blueprint $table) {
            $table->dropForeign(['admin_id']);
            $table->dropColumn(['admin_id', 'notes']);
        });
    }
};