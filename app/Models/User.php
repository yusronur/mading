<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use BelongsToSchool, HasFactory, HasUuids, SoftDeletes;

    public const ROLE_SUPER_ADMIN = 'super_admin';

    public const ROLE_ADMIN = 'school_admin';

    public const ROLE_TEACHER = 'teacher';

    public const ROLE_STUDENT = 'student';

    /** Tamu kuis publik: akun sementara tanpa sandi, hanya bisa mengerjakan satu kuis. */
    public const ROLE_GUEST = 'guest';

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Admin Sekolah',
        self::ROLE_TEACHER => 'Guru',
        self::ROLE_STUDENT => 'Siswa',
        self::ROLE_GUEST => 'Tamu',
    ];

    protected $fillable = [
        'school_id', 'username', 'name', 'email', 'password', 'role', 'gender',
        'birth_date', 'avatar', 'profile', 'status', 'must_change_password', 'last_login_at',
    ];

    protected $hidden = ['password', 'initial_password', 'qr_token', 'qr_token_hash', 'remember_token'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'initial_password' => 'encrypted',
            'qr_token' => 'encrypted',
            'qr_token_created_at' => 'datetime',
            'birth_date' => 'date',
            'avatar' => 'array',
            'profile' => 'array',
            'must_change_password' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isTeacher(): bool
    {
        return $this->role === self::ROLE_TEACHER;
    }

    public function isGuest(): bool
    {
        return $this->role === self::ROLE_GUEST;
    }

    public function isStudent(): bool
    {
        return $this->role === self::ROLE_STUDENT;
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    public function classes(): BelongsToMany
    {
        return $this->belongsToMany(SchoolClass::class, 'class_members', 'user_id', 'class_id')
            ->withPivot('school_id')
            ->withTimestamps();
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    /** Rombel siswa pada tahun ajaran aktif. */
    public function currentClass(): ?SchoolClass
    {
        $year = AcademicYear::active();

        return $year ? $this->classes()->where('academic_year_id', $year->id)->first() : null;
    }

    public function dashboardRoute(): string
    {
        return match ($this->role) {
            self::ROLE_ADMIN => 'admin.dashboard',
            self::ROLE_TEACHER => 'teacher.dashboard',
            self::ROLE_GUEST => 'home',
            default => 'student.dashboard',
        };
    }

    /** Rombel tahun ajaran aktif yang diampu sebagai wali kelas. */
    public function homeroomClassIds(): array
    {
        $year = AcademicYear::active();

        return $year ? SchoolClass::where('academic_year_id', $year->id)->where('homeroom_teacher_id', $this->id)->pluck('id')->all() : [];
    }
}
