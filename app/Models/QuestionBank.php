<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class QuestionBank extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    protected $fillable = ['school_id', 'owner_id', 'name', 'subject', 'grade', 'description', 'is_public'];

    protected $casts = ['grade' => 'integer', 'is_public' => 'boolean'];

    /** Bank milik pengguna, atau bank publik guru lain (hanya baca) di sekolah yang sama. */
    public function scopeVisibleTo(\Illuminate\Database\Eloquent\Builder $query, User $user): void
    {
        $query->where(fn ($w) => $w->where('owner_id', $user->id)->orWhere('is_public', true));
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function questions(): HasMany
    {
        return $this->hasMany(Question::class, 'bank_id');
    }
}
