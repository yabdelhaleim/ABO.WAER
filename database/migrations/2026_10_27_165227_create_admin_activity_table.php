<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('admin_activity', function (Blueprint $table) {
            $table->id();

            // The admin who did it. Null when the row is written by a
            // console command or a queued job with no session behind it.
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();

            // What happened, in `subject.action` form: "product.archived",
            // "admin.created", "customer.blocked".
            $table->string('action');

            // The row the action was performed on, when there is one.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            // Anything else worth remembering: the reason a customer was
            // blocked, the ids a bulk action touched, the old and the new
            // value of a field.
            $table->json('meta')->nullable();

            $table->string('ip')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['admin_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('admin_activity');
    }
};
