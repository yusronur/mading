<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Rombel. Nama kelas "Class" dipakai PHP, jadi model ini SchoolClass. */
class SchoolClass extends Model
{
    use BelongsToSchool, HasUuids;

    protected $table = 'classes';

    protected $fillable = ['school_id', 'academic_year_id', 'name', 'grade', 'homeroom_teacher_id'];

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function homeroomTeacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'homeroom_teacher_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'class_members', 'class_id', 'user_id')
            ->withPivot('school_id')
            ->withTimestamps();
    }

    /** Tebak tingkat dari nama rombel: "7A" -> 7, "VIII-B" -> 8. */
    public static function guessGrade(string $name): ?int
    {
        $n = strtoupper(trim($name));

        if (preg_match('/^(\d{1,2})/', $n, $m)) {
            return (int) $m[1];
        }

        foreach (['XII' => 12, 'XI' => 11, 'IX' => 9, 'X' => 10, 'VIII' => 8, 'VII' => 7, 'VI' => 6, 'V' => 5, 'IV' => 4, 'III' => 3, 'II' => 2, 'I' => 1] as $roman => $grade) {
            if (preg_match('/^'.$roman.'(?![IVX])/', $n)) {
                return $grade;
            }
        }

        return null;
    }
}
