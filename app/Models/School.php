<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Tenant. Tabel platform, tanpa global scope sekolah. */
class School extends Model
{
    use HasUuids;

    protected $fillable = ['slug', 'name', 'npsn', 'timezone', 'status', 'storage_quota_mb'];

    public function domains(): HasMany
    {
        return $this->hasMany(SchoolDomain::class);
    }

    public function setting(): HasOne
    {
        return $this->hasOne(SchoolSetting::class);
    }
}
