<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->string('role')->default('owner');
            $table->timestamp('last_login_at')->nullable();
        });

        // Every admin that exists today predates the role column, and the
        // column defaults to `owner` for exactly that reason: an admin who
        // was running the panel before the upgrade must not wake up with
        // fewer rights than they had. The backfill is explicit so the
        // intent survives a later change of the default.
        DB::table('admins')->update(['role' => 'owner']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('admins', function (Blueprint $table) {
            $table->dropColumn(['role', 'last_login_at']);
        });
    }
};
