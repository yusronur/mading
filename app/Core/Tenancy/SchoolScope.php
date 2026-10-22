<?php

namespace App\Core\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope: setiap query model bisnis otomatis dibatasi ke sekolah aktif.
 * Tanpa sekolah aktif, query tidak mengembalikan apa pun (aman secara bawaan).
 */
class SchoolScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if ($context->scopeBypassed()) {
            return;
        }

        $schoolId = $context->id();

        if ($schoolId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('school_id'), $schoolId);
    }
}
