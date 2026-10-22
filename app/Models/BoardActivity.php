<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Aktivitas live di papan: timer, poll, word cloud, spin wheel. */
class BoardActivity extends Model
{
    use BelongsToSchool, HasUuids;

    public const TYPES = ['timer' => 'Timer', 'poll' => 'Poll', 'cloud' => 'Word cloud', 'wheel' => 'Spin wheel'];

    protected $fillable = ['school_id', 'board_id', 'created_by', 'type', 'status', 'title', 'config', 'state'];

    protected $casts = ['config' => 'array', 'state' => 'array'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ActivityResponse::class, 'activity_id');
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }
}
