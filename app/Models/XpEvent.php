<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Buku besar XP (GAM-01). */
class XpEvent extends Model
{
    use BelongsToSchool, HasUuids;

    public const UPDATED_AT = null;

    protected $fillable = ['school_id', 'user_id', 'source_type', 'source_id', 'reason', 'points'];

    protected $casts = ['points' => 'integer'];
}
