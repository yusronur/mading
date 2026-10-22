<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Feature flag per sekolah (ADM-06). */
class SchoolFeature extends Model
{
    use BelongsToSchool;

    public const DEFAULTS = [
        'gamification' => true,
        'live_quiz' => true,
        'guest_access' => false,
        'ai' => false,
    ];

    protected $primaryKey = 'feature_key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['school_id', 'feature_key', 'enabled', 'config'];

    protected $casts = ['enabled' => 'boolean', 'config' => 'array'];
}
