<?php

namespace App\Listeners;

use App\Events\AdminNotificationsUpdated;
use App\Models\AdminUser;
use Illuminate\Notifications\Events\NotificationSent;

class BroadcastAdminNotification
{
    public function handle(NotificationSent $event): void
    {
        if ($event->channel === 'database' && $event->notifiable instanceof AdminUser) {
            AdminNotificationsUpdated::dispatch($event->notifiable->id);
        }
    }
}
