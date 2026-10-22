<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PushSubscription extends Model
{
    use BelongsToSchool, HasUuids;

    protected $table = 'push_subscriptions';

    protected $fillable = ['school_id', 'user_id', 'endpoint', 'endpoint_hash', 'p256dh', 'auth', 'agent', 'last_ok_at'];

    protected $casts = ['last_ok_at' => 'datetime'];
}
