<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Permission\Models\Role;

#[Fillable(['name', 'guard_name', 'parent_id', 'display_name', 'status', 'remark'])]
class AdminRole extends Role
{
    protected $attributes = ['status' => true];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('id');
    }
}
