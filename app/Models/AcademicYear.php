<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AcademicYear extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = ['school_id', 'name', 'starts_on', 'ends_on', 'active_semester', 'is_active'];

    protected $casts = ['starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];

    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function classes(): HasMany
    {
        return $this->hasMany(SchoolClass::class);
    }

    /** Usulan nama tahun ajaran untuk tanggal tertentu, mis. 2026/2027. */
    public static function suggestName(?\DateTimeInterface $date = null): array
    {
        $date ??= now();
        $y = (int) $date->format('Y');
        $start = (int) $date->format('n') >= 7 ? $y : $y - 1;

        return [
            'name' => $start.'/'.($start + 1),
            'starts_on' => $start.'-07-01',
            'ends_on' => ($start + 1).'-06-30',
            'active_semester' => (int) $date->format('n') >= 7 ? 1 : 2,
        ];
    }
}
