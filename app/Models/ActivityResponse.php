<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityResponse extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'activity_id', 'user_id', 'value'];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(BoardActivity::class, 'activity_id');
    }
}
