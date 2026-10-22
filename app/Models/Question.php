<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Question extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    public const TYPES = [
        'mc' => 'Pilihan ganda',
        'mcm' => 'Pilihan ganda kompleks',
        'tf' => 'Benar / salah',
        'short' => 'Isian singkat',
        'match' => 'Menjodohkan',
    ];

    public const DIFFICULTIES = [1 => 'Mudah', 2 => 'Sedang', 3 => 'Sulit'];

    protected $fillable = [
        'school_id', 'bank_id', 'author_id', 'type', 'prompt', 'explanation', 'data', 'tags', 'difficulty', 'points', 'position',
    ];

    protected $casts = [
        'data' => 'array',
        'tags' => 'array',
        'difficulty' => 'integer',
        'points' => 'integer',
        'position' => 'integer',
    ];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'bank_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('created_at');
    }
}
