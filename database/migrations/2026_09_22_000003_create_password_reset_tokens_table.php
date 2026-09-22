<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel ships this table inside its default users migration. Ours was
 * rewritten early on (role, first_name/last_name, phone, address) and the
 * reset table went with it, so password recovery has simply never existed.
 * Restoring it verbatim — the broker in config/auth.php expects these exact
 * three columns, keyed on email.
 *
 * Admin invitations deliberately do NOT use this table; see the
 * `admin_invites` migration for why the two are kept apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
