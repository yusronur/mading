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
                'status' => $u->status,
                'class' => $u->classes->first()?->name,
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
