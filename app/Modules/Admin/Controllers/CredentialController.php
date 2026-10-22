<?php

namespace App\Modules\Admin\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Admin\InitialPasswords;
use App\Modules\Admin\QrLogin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kartu akun siswa: sandi awal dan kode QR login, per rombel / per siswa,
 * bisa dicetak (AUTH-03, AUTH-04, ADM-04, ADM-09).
 *
 * Admin: semua rombel. Guru: hanya rombel yang ia ampu sebagai wali kelas.
 */
class CredentialController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate(['class_id' => ['nullable', 'uuid'], 'role' => ['nullable', 'in:student,teacher']]);
        $user = $request->user();
        $role = $this->role($request);
        $allowed = $this->allowedClassIds($user);
        $classId = $role === User::ROLE_TEACHER ? null : ($data['class_id'] ?? ($allowed === null ? null : ($allowed[0] ?? null)));
        $this->authorizeClass($user, $classId);

        $base = fn (): Builder => $this->students($user, $classId, $role);

        $students = $classId || $role === User::ROLE_TEACHER
            ? $base()->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'state' => $this->state($u),
                'has_qr' => $u->qr_token_hash !== null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ])
            : [];

        $year = AcademicYear::active();

        return Inertia::render('Admin/Credentials/Index', [
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)
                ->when($allowed !== null, fn ($q) => $q->whereIn('id', $allowed))
                ->orderBy('grade')->orderBy('name')->get(['id', 'name']) : [],
            'classId' => $classId,
            'role' => $role,
            'canAll' => $allowed === null,
            'students' => $students,
            'stats' => [
                'total' => $base()->count(),
                'changed' => $base()->where('must_change_password', false)->count(),
                'ready' => $base()->where('must_change_password', true)->whereNotNull('initial_password')->count(),
                'missing' => $base()->where('must_change_password', true)->whereNull('initial_password')->count(),
                'qr' => $base()->whereNotNull('qr_token_hash')->count(),
            ],
        ]);
    }

    /** Sandi awal untuk siswa yang belum pernah mengganti sandi. */
    public function generate(Request $request, InitialPasswords $passwords): RedirectResponse
    {
        $data = $this->scope($request);

        $query = $this->students($request->user(), $data['class_id'] ?? null, $this->role($request))->where('must_change_password', true);

        if (! empty($data['user_id'])) {
            $query->whereKey($data['user_id']);
        } elseif (! ($data['regenerate'] ?? false)) {
            $query->whereNull('initial_password');
        }

        $count = $passwords->issueMany($query->get());

        AuditLog::record('credentials.generated', null, [
            'count' => $count, 'class_id' => $data['class_id'] ?? null, 'regenerate' => (bool) ($data['regenerate'] ?? false),
        ]);

        return back()->with($count ? 'success' : 'error', $count
            ? "Sandi awal dibuat untuk {$count} ".$this->noun($request).'. Klik Cetak kartu untuk membagikannya.'
            : 'Tidak ada '.$this->noun($request).' yang perlu dibuatkan sandi awal.');
    }

    /** Reset sandi siswa yang lupa (oleh wali kelas atau admin, AUTH-04). */
    public function reset(Request $request, InitialPasswords $passwords): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'uuid'], 'class_id' => ['nullable', 'uuid']]);
        $this->authorizeClass($request->user(), $data['class_id'] ?? null);

        $student = $this->students($request->user(), $data['class_id'] ?? null, $this->role($request))->whereKey($data['user_id'])->firstOrFail();
        $passwords->issue($student);

        AuditLog::record('user.password_reset', $student, ['username' => $student->username]);

        return back()->with('success', "Sandi {$student->name} di-reset. Cetak kartunya untuk dibagikan; sandi wajib diganti saat masuk.");
    }

    /** Buat atau cabut kode QR login (AUTH-03). */
    public function qr(Request $request, QrLogin $qr): RedirectResponse
    {
        abort_if($this->role($request) === User::ROLE_TEACHER, 422, 'Kode QR hanya untuk siswa.');
        $data = $this->scope($request, ['action' => ['required', 'in:generate,regenerate,revoke']]);
        $query = $this->students($request->user(), $data['class_id'] ?? null);

        if (! empty($data['user_id'])) {
            $query->whereKey($data['user_id']);
        } elseif ($data['action'] === 'generate') {
            $query->whereNull('qr_token_hash');
        } elseif ($data['action'] === 'revoke') {
            $query->whereNotNull('qr_token_hash');
        }

        @set_time_limit(300);
        $count = 0;
        foreach ($query->get() as $student) {
            $data['action'] === 'revoke' ? $qr->revoke($student) : $qr->issue($student);
            $count++;
        }

        AuditLog::record($data['action'] === 'revoke' ? 'credentials.qr_revoked' : 'credentials.qr_generated', null, [
            'count' => $count, 'class_id' => $data['class_id'] ?? null, 'user_id' => $data['user_id'] ?? null,
        ]);

        if (! $count) {
            return back()->with('error', $data['action'] === 'revoke' ? 'Tidak ada kode QR yang perlu dicabut.' : 'Semua siswa sudah punya kode QR.');
        }

        return back()->with('success', $data['action'] === 'revoke'
            ? "Kode QR {$count} siswa dicabut. Kartu lama tidak bisa dipakai lagi."
            : "Kode QR dibuat untuk {$count} siswa".($data['action'] === 'regenerate' ? '; kartu lama tidak berlaku lagi.' : '.'));
    }

    public function print(Request $request): Response
    {
        $data = $request->validate([
            'class_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'all' => ['nullable', 'boolean'],
            'role' => ['nullable', 'in:student,teacher'],
        ]);
        $user = $request->user();
        $role = $this->role($request);

        abort_unless(! empty($data['class_id']) || ! empty($data['user_id']) || ! empty($data['all']), 422, 'Pilih rombel atau siswa.');
        abort_if(! empty($data['all']) && $this->allowedClassIds($user) !== null && $role !== User::ROLE_TEACHER, 403);
        $this->authorizeClass($user, $data['class_id'] ?? null);

        $year = AcademicYear::active();

        if ($role === User::ROLE_TEACHER) {
            $query = $this->students($user, null, $role)->when(! empty($data['user_id']), fn ($q) => $q->whereKey($data['user_id']));
        } elseif (! empty($data['user_id']) && $user->isAdmin()) {
            // Admin juga bisa mencetak kartu guru (sandi awal)
            $query = User::query()->whereKey($data['user_id'])->whereIn('role', [User::ROLE_STUDENT, User::ROLE_TEACHER]);
        } else {
            $query = $this->students($user, $data['class_id'] ?? null)
                ->when(! empty($data['user_id']), fn ($q) => $q->whereKey($data['user_id']));
        }

        $printable = fn (Builder $q) => $q->where(fn ($w) => $w->where(fn ($x) => $x->where('role', User::ROLE_STUDENT)->whereNotNull('qr_token_hash'))
            ->orWhere(fn ($x) => $x->where('must_change_password', true)->whereNotNull('initial_password')));

        $missing = (clone $query)->whereNot(fn ($q) => $printable($q))->count();

        $users = $printable($query)
            ->with(['classes' => fn ($q) => $year ? $q->where('academic_year_id', $year->id)->select('classes.id', 'classes.name') : $q->whereRaw('1=0')])
            ->get();

        $cards = $users->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'username' => $u->username,
            'class' => $u->classes->first()?->name,
            'role_label' => User::ROLE_LABELS[$u->role] ?? $u->role,
            'password' => $u->must_change_password ? $u->initial_password : null,
            'qr_url' => $u->qr_token && $u->role === User::ROLE_STUDENT ? QrLogin::url($u->qr_token) : null,
        ])->sortBy([['class', 'asc'], ['name', 'asc']])->values();

        AuditLog::record('credentials.printed', null, ['count' => $cards->count(), 'class_id' => $data['class_id'] ?? null, 'user_id' => $data['user_id'] ?? null]);

        return Inertia::render('Admin/Credentials/Print', [
            'cards' => $cards,
            'missing' => $missing,
            'loginUrl' => url('/masuk'),
            'schoolName' => app(TenantContext::class)->school()?->name ?? 'Sekolah',
            'title' => $role === User::ROLE_TEACHER ? 'Guru' : (! empty($data['class_id']) ? SchoolClass::whereKey($data['class_id'])->value('name') : null),
        ]);
    }

    /** null = semua rombel (admin); array = rombel perwalian guru. */
    private function allowedClassIds(User $user): ?array
    {
        if ($user->isAdmin()) {
            return null;
        }

        $ids = $user->homeroomClassIds();
        abort_if($ids === [], 403, 'Menu ini untuk wali kelas.');

        return $ids;
    }

    private function authorizeClass(User $user, ?string $classId): void
    {
        $allowed = $this->allowedClassIds($user);

        if ($allowed !== null) {
            abort_unless($classId && in_array($classId, $allowed, true), 403);
        }
    }

    private function scope(Request $request, array $extra = []): array
    {
        $data = $request->validate([
            'class_id' => ['nullable', 'uuid'],
            'user_id' => ['nullable', 'uuid'],
            'regenerate' => ['boolean'],
            'role' => ['nullable', 'in:student,teacher'],
        ] + $extra);
        $this->authorizeClass($request->user(), $data['class_id'] ?? null);

        return $data;
    }

    /** Peran yang dipilih; guru hanya boleh dikelola admin. */
    private function role(Request $request): string
    {
        $role = $request->input('role') === User::ROLE_TEACHER ? User::ROLE_TEACHER : User::ROLE_STUDENT;
        abort_if($role === User::ROLE_TEACHER && ! $request->user()->isAdmin(), 403);

        return $role;
    }

    private function noun(Request $request): string
    {
        return $this->role($request) === User::ROLE_TEACHER ? 'guru' : 'siswa';
    }

    private function students(User $actor, ?string $classId, string $role = User::ROLE_STUDENT): Builder
    {
        if ($role === User::ROLE_TEACHER) {
            abort_unless($actor->isAdmin(), 403);

            return User::query()->where('role', User::ROLE_TEACHER)->where('status', 'active');
        }

        $year = AcademicYear::active();
        $allowed = $this->allowedClassIds($actor);
        $classIds = $classId ? [$classId] : $allowed;

        return User::query()
            ->where('role', User::ROLE_STUDENT)
            ->where('status', 'active')
            ->when($classIds !== null, fn (Builder $q) => $q->whereHas('classes', fn ($c) => $c->whereIn('classes.id', $classIds)
                ->when($year, fn ($w) => $w->where('academic_year_id', $year->id))));
    }

    private function state(User $u): string
    {
        if (! $u->must_change_password) {
            return 'changed';
        }

        return $u->initial_password !== null ? 'ready' : 'missing';
    }
}
