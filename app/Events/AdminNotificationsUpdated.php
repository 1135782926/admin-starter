<?php

namespace App\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldRescue;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

class AdminNotificationsUpdated implements ShouldBroadcast, ShouldDispatchAfterCommit, ShouldRescue
{
    use Dispatchable;

    public function __construct(private readonly int $adminId) {}

    public function broadcastWhen(): bool
    {
        return config('broadcasting.default') === 'reverb';
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('admin.'.$this->adminId);
    }

    public function broadcastAs(): string
    {
        return 'admin.notifications.updated';
    }
}
