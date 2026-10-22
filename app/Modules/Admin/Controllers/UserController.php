<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Admin\InitialPasswords;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'role' => ['nullable', 'in:student,teacher,school_admin'],
            'class_id' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', 'in:active,inactive,alumni'],
        ]);

        $year = AcademicYear::active();

        $users = User::query()->where('role', '!=', User::ROLE_GUEST)
            ->when($filters['role'] ?? null, fn ($q, $role) => $q->where('role', $role))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('username', 'ilike', "%{$term}%"));
            })
            ->when($filters['class_id'] ?? null, fn ($q, $classId) => $q->whereHas('classes', fn ($c) => $c->where('classes.id', $classId)))
            ->with(['classes' => fn ($q) => $year ? $q->where('academic_year_id', $year->id)->select('classes.id', 'classes.name') : $q->whereRaw('1=0')])
            ->orderBy('role')->orderBy('name')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'username' => $u->username,
                'role' => $u->role,
                'role_label' => User::ROLE_LABELS[$u->role] ?? $u->role,
                'gender' => $u->gender,
                'email' => $u->email,
                'birth_date' => $u->birth_date?->toDateString(),
                'status' => $u->status,
                'class' => $u->classes->first()?->name,
                'class_id' => $u->classes->first()?->id,
                'must_change_password' => $u->must_change_password,
                'has_initial_password' => $u->must_change_password && $u->initial_password !== null,
                'last_login_at' => $u->last_login_at?->toIso8601String(),
            ]);

        return Inertia::render('Admin/Users', [
            'users' => $users,
            'filters' => $filters,
            'credential' => $this->credentialFromSession($request, $year),
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    /** Tambah guru atau siswa satu per satu (tanpa impor). Sandi awal dibuat otomatis. */
    public function store(Request $request, InitialPasswords $passwords): RedirectResponse
    {
        $year = AcademicYear::active();
        $role = $request->input('role');

        $data = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_STUDENT, User::ROLE_TEACHER])],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'class_id' => [
                $role === User::ROLE_STUDENT && $year ? 'required' : 'nullable', 'uuid',
                Rule::exists('classes', 'id')->where('school_id', $request->user()->school_id)->where('academic_year_id', $year?->id),
            ],
        ], [
            'username.regex' => 'Username hanya boleh huruf, angka, titik, garis bawah, dan strip (tanpa spasi).',
            'class_id.required' => 'Pilih rombel siswa.',
        ], ['username' => 'NISN/username', 'name' => 'nama', 'class_id' => 'rombel']);

        $username = trim($data['username']);
        if (User::withTrashed()->whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            return back()->withErrors(['username' => 'NISN/username ini sudah dipakai (termasuk akun yang dihapus).'])->withInput();
        }

        $user = DB::transaction(function () use ($data, $username, $passwords) {
            $user = new User([
                'username' => $username,
                'name' => trim(strip_tags($data['name'])),
                'role' => $data['role'],
                'gender' => $data['gender'] ?? null,
                'status' => 'active',
            ]);
            $user->password = 'x'; // diganti sandi awal di bawah
            $user->save();
            $passwords->issue($user);

            if (! empty($data['class_id'])) {
                if ($data['role'] === User::ROLE_STUDENT) {
                    DB::table('class_members')->insert([
                        'class_id' => $data['class_id'], 'user_id' => $user->id, 'school_id' => $user->school_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                } else {
                    SchoolClass::whereKey($data['class_id'])->update(['homeroom_teacher_id' => $user->id]);
                }
            }

            return $user;
        });

        AuditLog::record('user.created', $user, ['username' => $user->username, 'role' => $user->role]);

        return back()->with('credential_for', $user->id)->with('success', "Akun {$user->name} dibuat. Catat atau cetak sandi awalnya.");
    }

    /** Ubah identitas pengguna: nama, username/NISN, email, jenis kelamin, tanggal lahir, rombel siswa. */
    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->role === User::ROLE_GUEST, 404);
        $year = AcademicYear::active();

        $data = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/'],
            'name' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'gender' => ['nullable', Rule::in(['L', 'P'])],
            'birth_date' => ['nullable', 'date', 'before:tomorrow', 'after:1930-01-01'],
            'class_id' => [
                'nullable', 'uuid',
                Rule::exists('classes', 'id')->where('school_id', $user->school_id)->where('academic_year_id', $year?->id),
            ],
        ], [
            'username.regex' => 'Username hanya boleh huruf, angka, titik, garis bawah, dan strip (tanpa spasi).',
            'email.email' => 'Format email tidak valid.',
        ], ['username' => 'NISN/username', 'name' => 'nama', 'email' => 'email', 'birth_date' => 'tanggal lahir', 'class_id' => 'rombel']);

        $username = trim($data['username']);
        if (User::withTrashed()->whereKeyNot($user->id)->whereRaw('lower(username) = ?', [mb_strtolower($username)])->exists()) {
            return back()->withErrors(['username' => 'NISN/username ini sudah dipakai akun lain (termasuk akun yang dihapus).']);
        }

        $before = $user->only(['username', 'name', 'email', 'gender', 'birth_date']);

        DB::transaction(function () use ($user, $data, $username, $year) {
            $user->forceFill([
                'username' => $username,
                'name' => trim(strip_tags($data['name'])),
                'email' => ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null,
                'gender' => $data['gender'] ?? null,
                'birth_date' => $data['birth_date'] ?? null,
            ])->save();

            // Pindah rombel (siswa, tahun ajaran aktif)
            if ($user->role === User::ROLE_STUDENT && $year && array_key_exists('class_id', $data) && $data['class_id']) {
                $current = DB::table('class_members as m')->join('classes as c', 'c.id', '=', 'm.class_id')
                    ->where('m.user_id', $user->id)->where('c.academic_year_id', $year->id)->pluck('m.class_id')->all();
                if ($current !== [$data['class_id']]) {
                    DB::table('class_members')->where('user_id', $user->id)->whereIn('class_id', $current)->delete();
                    DB::table('class_members')->insert([
                        'class_id' => $data['class_id'], 'user_id' => $user->id, 'school_id' => $user->school_id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });

        AuditLog::record('user.updated', $user, ['username' => $user->username, 'changed' => array_keys(array_diff_assoc(
            array_map('strval', $user->only(['username', 'name', 'email', 'gender', 'birth_date'])),
            array_map('strval', $before)
        ))]);

        return back()->with('success', "Data {$user->name} disimpan.");
    }

    public function resetPassword(Request $request, User $user, InitialPasswords $passwords): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Gunakan menu ganti sandi untuk akun sendiri.');

        $passwords->issue($user);

        AuditLog::record('user.password_reset', $user, ['username' => $user->username]);

        // Hanya id yang disimpan di sesi; sandi dibaca dari kolom terenkripsi
        return back()->with('credential_for', $user->id);
    }

    public function updateStatus(Request $request, User $user): RedirectResponse
    {
        abort_if($user->id === $request->user()->id, 422, 'Tidak dapat mengubah status akun sendiri.');

        $data = $request->validate(['status' => ['required', 'in:active,inactive']]);
        $user->update($data);

        AuditLog::record('user.status_changed', $user, ['status' => $data['status']]);

        return back()->with('success', "Status {$user->name} diubah menjadi ".($data['status'] === 'active' ? 'aktif' : 'nonaktif').'.');
    }

    /** Sandi awal yang baru dibuat; tetap tampil di dialog sampai ditutup. */
    private function credentialFromSession(Request $request, ?AcademicYear $year): ?array
    {
        $id = $request->session()->get('credential_for');
        $user = $id ? User::find($id) : null;

        if (! $user || ! $user->must_change_password || $user->initial_password === null) {
            return null;
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role_label' => User::ROLE_LABELS[$user->role] ?? $user->role,
            'class' => $year ? $user->classes()->where('academic_year_id', $year->id)->value('classes.name') : null,
            'password' => $user->initial_password,
        ];
    }
}
