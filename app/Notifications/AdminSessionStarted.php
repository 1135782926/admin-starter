<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AdminSessionStarted extends Notification
{
    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array{title: string, message: string}
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'title' => '管理员账号有新登录',
            'message' => '你的管理员账号在另一个会话中完成了登录。',
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'admin.session-started';
    }
}
