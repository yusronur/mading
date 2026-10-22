<?php

namespace App\Models;

use App\Core\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Paket token tema. school_id NULL = tema bawaan platform (dipakai semua
 * sekolah); terisi = tema kustom sekolah.
 */
class Theme extends Model
{
    use HasUuids;

    protected $fillable = ['school_id', 'key', 'name', 'tokens', 'is_builtin', 'xp_required', 'sort'];

    protected $casts = ['tokens' => 'array', 'is_builtin' => 'boolean'];

    protected static function booted(): void
    {
        static::addGlobalScope('available', function (Builder $q) {
            $id = app(TenantContext::class)->id();
            $q->where(fn ($w) => $w->whereNull('themes.school_id')
                ->when($id, fn ($w2) => $w2->orWhere('themes.school_id', $id)));
        });
    }
}
