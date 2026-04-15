<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
   // database/migrations/xxxx_add_is_archived_to_products.php

        public function up(): void
        {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_archived')->default(false)->after('stock');
            });
        }

        public function down(): void
        {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('is_archived');
            });
        }
};
