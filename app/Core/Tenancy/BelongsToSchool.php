<?php

namespace App\Core\Tenancy;

use App\Models\School;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Wajib dipakai oleh setiap model bisnis (lihat Blueprint Bab 9.3).
 *
 * - Menambahkan global scope school_id.
 * - Mengisi school_id otomatis dari sekolah aktif saat membuat data.
 * - Menolak menyimpan data ke sekolah lain.
 */
trait BelongsToSchool
{
    public static function bootBelongsToSchool(): void
    {
        static::addGlobalScope(new SchoolScope);

        static::creating(function ($model) {
            $context = app(TenantContext::class);

            if (empty($model->school_id)) {
                if (! $context->check()) {
                    throw new LogicException('Tidak ada sekolah aktif saat membuat '.class_basename($model).'.');
                }

                $model->school_id = $context->id();
            }

            if ($context->check() && ! $context->scopeBypassed() && $model->school_id !== $context->id()) {
                throw new LogicException('Tidak boleh membuat data untuk sekolah lain.');
            }
        });
    }

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }
}
