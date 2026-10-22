<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportJob extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = [
        'school_id', 'user_id', 'type', 'status', 'original_name', 'total_rows', 'valid_rows',
        'created_count', 'updated_count', 'skipped_count', 'rows', 'credentials', 'error_message', 'finished_at',
    ];

    protected $casts = [
        'rows' => 'array',
        'credentials' => 'encrypted:array',
        'finished_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
