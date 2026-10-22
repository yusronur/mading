<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Post extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    /** Mikrodetik: penanda versi sinkron (ADR-014) peka terhadap perubahan beruntun. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = [
        'school_id', 'board_id', 'section_id', 'author_id', 'is_anonymous', 'title', 'body', 'link', 'color',
        'status', 'moderation_reason', 'position', 'is_pinned', 'edited_at',
    ];

    protected $casts = [
        'is_anonymous' => 'boolean',
        'is_pinned' => 'boolean',
        'position' => 'float',
        'link' => 'array',
        'edited_at' => 'datetime',
    ];

    public const COLORS = ['red', 'orange', 'yellow', 'green', 'teal', 'blue', 'purple', 'pink'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(Attachment::class, 'attachable')->orderBy('created_at');
    }

    public function comments(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function reactions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Reaction::class);
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(BoardSection::class, 'section_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
