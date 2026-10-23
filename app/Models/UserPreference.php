<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    use BelongsToSchool;

    protected $primaryKey = 'user_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['user_id', 'school_id', 'theme_id', 'color_mode', 'reduce_motion', 'font_scale', 'avatar', 'frame', 'notify_off', 'quick_comments'];

    protected $casts = ['reduce_motion' => 'boolean', 'font_scale' => 'integer', 'notify_off' => 'array', 'quick_comments' => 'array'];

    public function theme(): BelongsTo
    {
        return $this->belongsTo(Theme::class);
    }
}
