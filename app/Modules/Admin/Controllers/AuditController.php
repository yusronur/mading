<?php

namespace App\Modules\Admin\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Log aktivitas penting untuk admin sekolah (ADM-08). */
class AuditController extends Controller
{
    public const LABELS = [
        'auth.lockout' => 'Akun dikunci sementara (salah sandi berulang)',
        'auth.qr_login' => 'Masuk dengan kartu QR',
        'user.password_reset' => 'Reset sandi',
        'user.status_changed' => 'Status akun diubah',
        'credentials.generated' => 'Sandi awal dibuat',
        'credentials.printed' => 'Kartu akun dicetak',
        'credentials.qr_generated' => 'Kode QR dibuat',
        'credentials.qr_revoked' => 'Kode QR dicabut',
        'import.students' => 'Impor siswa',
        'import.teachers' => 'Impor guru',
        'academic_year.created' => 'Tahun ajaran dibuat',
        'academic_year.activated' => 'Tahun ajaran diaktifkan',
        'school.settings_updated' => 'Pengaturan sekolah diubah',
        'board.duplicated' => 'Papan diduplikat',
        'board.deleted' => 'Papan dihapus',
        'board.archived' => 'Papan diarsipkan/dipulihkan',
        'board.collaborator_added' => 'Kolaborator ditambahkan',
        'board.collaborator_removed' => 'Kolaborator dilepas',
        'post.moderated' => 'Kartu dimoderasi',
    ];

    public const GROUPS = [
        'auth' => 'Masuk',
        'user' => 'Akun',
        'credentials' => 'Kartu akun',
        'import' => 'Impor',
        'board' => 'Papan',
        'post' => 'Moderasi',
        'school' => 'Sekolah',
        'academic_year' => 'Tahun ajaran',
    ];

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'group' => ['nullable', 'in:'.implode(',', array_keys(self::GROUPS))],
            'q' => ['nullable', 'string', 'max:60'],
        ]);

        $logs = AuditLog::query()
            ->when($filters['group'] ?? null, fn ($q, $g) => $q->where('action', 'like', $g.'.%'))
            ->when($filters['q'] ?? null, function ($q, $term) {
                $ids = User::where('name', 'ilike', "%{$term}%")->orWhere('username', 'ilike', "%{$term}%")->limit(200)->pluck('id');
                $q->where(fn ($w) => $w->whereIn('actor_id', $ids)->orWhereIn('target_id', $ids)->orWhereRaw('meta::text ilike ?', ["%{$term}%"]));
            })
            ->latest('created_at')
            ->paginate(30)
            ->withQueryString();

        $people = User::withTrashed()->whereIn('id', $logs->pluck('actor_id')->merge($logs->where('target_type', 'User')->pluck('target_id'))->filter()->unique())
            ->get(['id', 'name', 'username'])->keyBy('id');

        $logs->through(fn (AuditLog $l) => [
            'id' => $l->id,
            'action' => $l->action,
            'label' => self::LABELS[$l->action] ?? $l->action,
            'actor' => $l->actor_id ? ($people[$l->actor_id]->name ?? '–') : 'Sistem',
            'target' => $l->target_type === 'User' ? ($people[$l->target_id]->name ?? null) : null,
            'meta' => $l->meta,
            'ip' => $l->ip,
            'created_at' => $l->created_at?->toIso8601String(),
        ]);

        return Inertia::render('Admin/AuditLog', [
            'logs' => $logs,
            'filters' => $filters,
            'groups' => self::GROUPS,
        ]);
    }
}
