<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'actor_id', 'actor_username', 'actor_nickname', 'attempted_username',
    'module', 'action', 'permission_name', 'route_name', 'method', 'path',
    'target_type', 'target_id', 'target_label', 'status_code', 'success',
    'ip_address', 'user_agent', 'duration_ms', 'changes', 'related_changes', 'created_at',
])]
class AdminLog extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'actor_id' => 'integer',
            'target_id' => 'integer',
            'status_code' => 'integer',
            'success' => 'boolean',
            'duration_ms' => 'integer',
            'changes' => 'array',
            'related_changes' => 'array',
        ];
    }
}
