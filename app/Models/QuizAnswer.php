<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use BelongsToSchool, HasUuids;

    public $timestamps = false;

    protected $fillable = ['school_id', 'attempt_id', 'question_id', 'response', 'is_correct', 'points', 'live_score', 'elapsed_ms', 'answered_at'];

    protected $casts = ['response' => 'array', 'is_correct' => 'boolean', 'points' => 'float', 'live_score' => 'integer', 'elapsed_ms' => 'integer', 'answered_at' => 'datetime'];
}
