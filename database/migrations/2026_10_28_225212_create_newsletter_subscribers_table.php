<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The newsletter form on the home page used to show "YOU'RE IN ✓"
     * without recording anything: the shopper was told they had
     * subscribed and no address was kept. This table is where the
     * address goes.
     *
     * One row per address — the email is unique, so the same person
     * cannot be counted twice. `status` carries the subscription state
     * rather than deleting the row, so a person who unsubscribes and
     * subscribes again keeps one history.
     */
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();

            // The address, stored the way it was typed but compared
            // case-insensitively (see the model's mutator).
            $table->string('email')->unique();

            // Where the subscription came from — the storefront today,
            // the admin import tomorrow.
            $table->string('source')->default('storefront');

            // 'subscribed' | 'unsubscribed'.
            $table->string('status')->default('subscribed')->index();

            $table->timestamp('subscribed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
    }
};
