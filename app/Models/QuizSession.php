<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuizSession extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    protected $fillable = [
        'school_id', 'quiz_id', 'host_id', 'class_id', 'mode', 'code', 'title', 'opens_at', 'closes_at', 'closed_at', 'settings', 'questions', 'live',
    ];

    protected $casts = [
        'opens_at' => 'datetime',
        'closes_at' => 'datetime',
        'closed_at' => 'datetime',
        'settings' => 'array',
        'questions' => 'array',
        'live' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(fn (QuizSession $s) => $s->code ??= static::generateCode());
    }

    /** Kode unik bersama kode papan, supaya satu kotak "Masukkan kode" cukup. */
    public static function generateCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
            }
        } while (static::withTrashed()->where('code', $code)->exists() || Board::withTrashed()->where('code', $code)->exists());

        return $code;
    }

    public function quiz(): BelongsTo
    {
        return $this->belongsTo(Quiz::class)->withTrashed();
    }

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class, 'class_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(QuizAttempt::class, 'session_id');
    }

    public function isLive(): bool
    {
        return $this->mode === 'live';
    }

    public function isOpen(): bool
    {
        $now = now();

        return ! $this->closed_at
            && (! $this->opens_at || $this->opens_at->lte($now))
            && (! $this->closes_at || $this->closes_at->gt($now));
    }

    public function isClosed(): bool
    {
        return $this->closed_at || ($this->closes_at && $this->closes_at->lte(now()));
    }

    public function status(): string
    {
        return $this->isClosed() ? 'closed' : ($this->isOpen() ? 'open' : 'scheduled');
    }

    public function maxScore(): int
    {
        return (int) array_sum(array_column($this->questions, 'points'));
    }

    /** @return array<string, array> soal snapshot per id */
    public function questionMap(): array
    {
        return collect($this->questions)->keyBy('id')->all();
    }
}
