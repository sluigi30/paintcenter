<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A driver is a fourth role, not a weaker admin.
     *
     * Everything that asks "is this person staff?" in this codebase does it by
     * naming the roles outright (User::admins(), Message::isVisibleTo,
     * ActivityLog::log), so adding a value here grants nothing on its own —
     * each of those sites decides for itself whether a driver belongs. That is
     * deliberate: a driver must not inherit admin reach by default.
     *
     * Same shape as the super_admin migration (2026_06_30_000001).
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'driver', 'customer'])
                ->default('customer')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['super_admin', 'admin', 'customer'])
                ->default('customer')
                ->change();
        });
    }
};
