<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QuizAttempt extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = [
        'school_id', 'session_id', 'user_id', 'attempt_no', 'seed', 'question_order', 'started_at', 'deadline_at', 'submitted_at',
        'score', 'max_score', 'focus_lost',
    ];

    protected $casts = [
        'question_order' => 'array',
        'started_at' => 'datetime',
        'deadline_at' => 'datetime',
        'submitted_at' => 'datetime',
        'score' => 'float',
        'max_score' => 'integer',
        'focus_lost' => 'integer',
        'seed' => 'integer',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(QuizSession::class, 'session_id')->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(QuizAnswer::class, 'attempt_id');
    }

    public function isExpired(): bool
    {
        return $this->deadline_at && $this->deadline_at->lte(now());
    }

    /** Nilai 0-100. */
    public function grade(): ?int
    {
        return $this->max_score > 0 ? (int) round($this->score / $this->max_score * 100) : null;
    }
}
