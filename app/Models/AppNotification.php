<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AppNotification extends Model
{
    use BelongsToSchool, HasUuids;

    protected $table = 'app_notifications';

    public const UPDATED_AT = 'updated_at';

    protected $fillable = ['school_id', 'user_id', 'kind', 'title', 'body', 'url', 'dedupe_key', 'count', 'push_state', 'pushed_at', 'read_at'];

    protected $casts = ['pushed_at' => 'datetime', 'read_at' => 'datetime'];
}
