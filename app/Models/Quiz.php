<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Quiz extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    /** Pengaturan bawaan (QZ-08, QZ-09). */
    public const DEFAULTS = [
        'shuffle_questions' => true,
        'shuffle_options' => true,
        'duration_minutes' => 30,     // menit per percobaan, wajib (tenggat hanya membatasi kapan boleh mulai)
        'attempts' => 1,              // 0 = tanpa batas
        'scoring' => 'best',          // best | last
        'review' => 'after_submit',   // after_submit | after_close | never
        'show_score' => true,
        'kkm' => 75,
    ];

    protected $fillable = ['school_id', 'owner_id', 'title', 'description', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function settings(): array
    {
        $s = array_merge(self::DEFAULTS, array_intersect_key($this->settings ?? [], self::DEFAULTS));
        $s['duration_minutes'] ??= self::DEFAULTS['duration_minutes']; // kuis lama tanpa durasi

        return $s;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(Question::class, 'quiz_questions')
            ->withPivot(['id', 'position', 'points', 'school_id'])
            ->withTimestamps()
            ->orderByPivot('position');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(QuizSession::class);
    }
}
