<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BoardSection extends Model
{
    use BelongsToSchool, HasUuids;

    /** Mikrodetik: penanda versi sinkron (ADR-014) peka terhadap perubahan beruntun. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['school_id', 'board_id', 'title', 'position'];

    protected $casts = ['position' => 'float'];

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'section_id');
    }
}
