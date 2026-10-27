<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Password reset tokens for the admin back office.
 *
 * The table backs the `admins` password broker configured in config/auth.php.
 * Laravel's DatabaseTokenRepository keeps one row per address: the email is
 * the primary key, the token holds the hashed reset token, and created_at is
 * what the repository reads to decide whether that token has expired — 30
 * minutes, per the broker's `expire` setting.
 *
 * The table is deliberately kept apart from the store front's
 * `password_reset_tokens`. Both key their rows by email alone, so a single
 * shared table would let a token issued for an admin be redeemed on a
 * customer account that happens to use the same address.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_password_reset_tokens', function (Blueprint $table) {
            // The address the reset link was sent to, and the lookup key.
            $table->string('email')->primary();
            // The reset token, already hashed by the broker's hasher.
            $table->string('token');
            // When the token was issued — the broker reads it to expire it.
            $table->timestamp('created_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_password_reset_tokens');
    }
};
