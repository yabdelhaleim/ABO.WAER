<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class TestWebPushNotification extends Notification
{
    public function __construct(
        public string $title = 'ABO Store · إشعار تجريبي',
        public string $body = 'الإشعارات تعمل بنجاح على جهازك! 🎉',
        public ?string $url = null,
    ) {
        $this->url = $url ?? url('/');
    }

    /**
     * @return array<int, string|class-string>
     */
    public function via(mixed $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(mixed $notifiable, mixed $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->title)
            ->icon(asset('images/abo-logo.svg'))
            ->badge(asset('images/abo-logo.svg'))
            ->body($this->body)
            ->action('Open Store', 'open_store')
            ->data(['url' => $this->url])
            ->tag('test-notification-'.time())
            ->vibrate([200, 100, 200]);
    }
}
