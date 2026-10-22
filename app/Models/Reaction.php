<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Reaction extends Model
{
    use BelongsToSchool, HasUuids;

    public const KINDS = [
        'none' => 'Tanpa reaksi',
        'like' => 'Suka ❤️',
        'star' => 'Bintang 1-5 ⭐',
        'vote' => 'Vote naik/turun 👍👎',
        'grade' => 'Nilai angka dari guru (0-100)',
    ];

    /** Mikrodetik: penanda versi sinkron (ADR-014) peka terhadap perubahan beruntun. */
    protected $dateFormat = 'Y-m-d H:i:s.uP';

    protected $fillable = ['school_id', 'post_id', 'user_id', 'kind', 'value'];

    protected $casts = ['value' => 'integer'];
}
