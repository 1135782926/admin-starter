<?php

use App\Models\AdminUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin.{adminId}', fn (AdminUser $admin, string $adminId): bool => (string) $admin->getKey() === $adminId);
