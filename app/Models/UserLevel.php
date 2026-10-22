<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;

/** Ringkasan XP, level, dan streak per siswa (cache dari buku besar xp_events). */
class UserLevel extends Model
{
    use BelongsToSchool;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'school_id', 'xp', 'level', 'streak', 'best_streak', 'last_active_on', 'notices'];

    protected $casts = ['xp' => 'integer', 'level' => 'integer', 'streak' => 'integer', 'best_streak' => 'integer', 'notices' => 'array', 'last_active_on' => 'date'];
}
