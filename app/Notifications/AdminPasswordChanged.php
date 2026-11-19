<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

class AdminPasswordChanged extends Notification
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
            'title' => '登录密码已修改',
            'message' => '你的管理员账号已修改登录密码，原有会话已退出。',
        ];
    }

    public function databaseType(object $notifiable): string
    {
        return 'admin.password-changed';
    }
}
