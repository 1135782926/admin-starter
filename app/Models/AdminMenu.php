<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['parent_id', 'type', 'title', 'name', 'permission_id', 'open_type', 'path', 'component', 'url', 'icon', 'sort', 'keep_alive', 'status', 'remark'])]
class AdminMenu extends Model
{
    protected function casts(): array
    {
        return ['status' => 'boolean', 'keep_alive' => 'boolean', 'sort' => 'integer'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('id');
    }

    public function permission(): BelongsTo
    {
        return $this->belongsTo(AdminPermission::class);
    }
}
