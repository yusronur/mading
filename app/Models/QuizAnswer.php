<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use BelongsToSchool, HasUuids;

    public $timestamps = false;

    protected $fillable = ['school_id', 'attempt_id', 'question_id', 'response', 'is_correct', 'points', 'answered_at'];

    protected $casts = ['response' => 'array', 'is_correct' => 'boolean', 'points' => 'float', 'answered_at' => 'datetime'];
}
