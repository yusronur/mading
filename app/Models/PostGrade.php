<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Nilai rubrik satu kartu (Tahap 18). */
class PostGrade extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = ['school_id', 'board_id', 'post_id', 'student_id', 'grader_id', 'scores', 'total', 'max', 'grade', 'feedback'];

    protected $casts = ['scores' => 'array', 'total' => 'float', 'max' => 'float', 'grade' => 'integer'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function grader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'grader_id');
    }

    public function toCard(): array
    {
        return [
            'scores' => $this->scores, 'total' => $this->total, 'max' => $this->max, 'grade' => $this->grade,
            'feedback' => $this->feedback, 'grader' => $this->grader?->name, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
