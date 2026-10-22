<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Domain kustom sekolah, mis. mading.smpn1wajak.sch.id */
class SchoolDomain extends Model
{
    use HasUuids;

    protected $fillable = ['school_id', 'host', 'is_primary'];

    protected $casts = ['is_primary' => 'boolean'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
