<?php

namespace App\Models;

use Database\Factories\MemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['username', 'nickname', 'member_group_id', 'avatar', 'email', 'mobile', 'password', 'gender', 'birthday', 'signature', 'status'])]
#[Hidden(['password'])]
class Member extends Model
{
    /** @use HasFactory<MemberFactory> */
    use HasFactory;

    protected $attributes = [
        'gender' => 'unknown',
        'status' => true,
    ];

    /** @return BelongsTo<MemberGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(MemberGroup::class, 'member_group_id');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'birthday' => 'date',
            'status' => 'boolean',
        ];
    }
}
