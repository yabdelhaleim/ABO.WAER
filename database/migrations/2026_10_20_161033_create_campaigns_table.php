<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            // العنوان الداخلي الذي يستخدمه الأدمن لتعريف الحملة
            $table->string('title');
            // سطر الموضوع للإيميل أو عنوان الإشعار
            $table->string('subject');
            // نص مختصر للمعاينة متطابق مع `preview` في campaigns.js
            $table->text('preview');
            // نص الرسالة أو المحتوى الكامل
            $table->text('message');
            // القناة: email, push, sms
            $table->string('type');
            // الشريحة المستهدفة: all, vip, cart_abandoners, inactive_30_days
            $table->string('audience');
            // الحالة: draft, scheduled, active, ended
            $table->string('status')->default('draft');
            // تاريخ ووقت الجدولة
            $table->timestamp('scheduled_for')->nullable();
            // تاريخ ووقت الإرسال الفعلي
            $table->timestamp('sent_at')->nullable();
            // مؤشرات التفاعل الافتراضية
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->timestamps();

            // فهرس مركب لتسريع استعلامات الـ Cron على الحملات المستحقة
            $table->index(['status', 'scheduled_for'], 'campaigns_status_scheduled_for_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
