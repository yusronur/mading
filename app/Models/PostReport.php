<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PostReport extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = ['school_id', 'post_id', 'user_id', 'reason', 'resolved_at'];

    protected $casts = ['resolved_at' => 'datetime'];
}
