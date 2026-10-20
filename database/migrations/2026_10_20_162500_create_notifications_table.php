<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * جدول إشعارات الموقع الداخلية (database channel) المستخدم من قبل
     * Laravel Notifiable لتخزين الإشعارات في قاعدة البيانات.
     *
     * Phase 7 · Step 4 يستخدم هذا الجدول لتخزين إشعارات الموقع الداخلية
     * (CampaignType::Push) عبر CampaignNotification::toDatabase().
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
