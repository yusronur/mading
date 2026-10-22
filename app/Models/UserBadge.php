<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class UserBadge extends Model
{
    use BelongsToSchool, HasUuids;

    public $timestamps = false;

    protected $fillable = ['school_id', 'user_id', 'badge', 'earned_at'];

    protected $casts = ['earned_at' => 'datetime'];
}
