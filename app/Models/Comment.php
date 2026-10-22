<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Comment extends Model
{
    use BelongsToSchool, HasUuids, SoftDeletes;

    /** Mikrodetik: penanda versi sinkron (ADR-014) peka terhadap perubahan beruntun. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['school_id', 'post_id', 'parent_id', 'author_id', 'body', 'is_anonymous', 'status', 'moderation_reason'];

    protected $casts = ['is_anonymous' => 'boolean'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
