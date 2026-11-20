<?php

namespace App\Models;

use Database\Factories\MemberGroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name'])]
class MemberGroup extends Model
{
    /** @use HasFactory<MemberGroupFactory> */
    use HasFactory;
}
