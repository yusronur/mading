<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class PushBroadcast extends Model
{
    use BelongsToSchool, HasUuids;

    protected $table = 'push_broadcasts';

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'sender_id', 'audience', 'target', 'title', 'body', 'url', 'recipients', 'with_push'];

    public function sender()
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
