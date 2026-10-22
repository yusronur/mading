<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SchoolSetting extends Model
{
    use BelongsToSchool;

    protected $primaryKey = 'school_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['school_id', 'app_display_name', 'logo_path', 'accent_color', 'default_theme_id', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function defaultTheme(): BelongsTo
    {
        return $this->belongsTo(Theme::class, 'default_theme_id');
    }
}
