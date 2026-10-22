<?php

namespace App\Modules\Notify\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\AuditLog;
use App\Models\PushBroadcast;
use App\Models\PushSubscription;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Notify\Notify;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Admin mengirim pesan manual (lonceng + push) ke guru dan/atau siswa. */
class BroadcastController extends Controller
{
    public function __construct(private Notify $notify) {}

    public function index(): Response
    {
        $year = AcademicYear::active();

        return Inertia::render('Admin/Push', [
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get(['id', 'name']) : [],
            'available' => $this->notify->pushAvailable(),
            'reach' => [
                'teachers' => $this->reach('teacher'),
                'students' => $this->reach('student'),
            ],
            'history' => PushBroadcast::with('sender:id,name')->latest('created_at')->limit(15)->get()->map(fn ($b) => [
                'id' => $b->id, 'title' => $b->title, 'audience' => $b->audience, 'target' => $b->target,
                'recipients' => $b->recipients, 'with_push' => $b->with_push, 'sender' => $b->sender?->name, 'at' => $b->created_at->toIso8601String(),
            ]),
        ]);
    }

    /** Pencarian satu pengguna sebagai sasaran. */
    public function search(Request $request): JsonResponse
    {
        $d = $request->validate(['role' => ['required', 'in:teacher,student'], 'q' => ['required', 'string', 'min:2', 'max:60']]);
        $users = User::where('role', $d['role'])->where('status', 'active')
            ->where(fn ($w) => $w->where('name', 'ilike', "%{$d['q']}%")->orWhere('username', 'ilike', "%{$d['q']}%"))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'username']);

        return response()->json($users);
    }

    public function preview(Request $request): JsonResponse
    {
        [$audience, $target] = $this->target($request);
        $users = $this->notify->audience($audience, $target);

        return response()->json($this->summary($users));
    }

    public function store(Request $request, TenantContext $tenant): JsonResponse
    {
        [$audience, $target] = $this->target($request);
        $d = $request->validate([
            'title' => ['required', 'string', 'max:100'],
            'body' => ['nullable', 'string', 'max:300'],
            'url' => ['nullable', 'string', 'max:300', 'regex:#^/[A-Za-z0-9/_\-\.?=&%\#]*$#'],
        ], ['url.regex' => 'Tautan harus berupa alamat di dalam aplikasi, contoh: /siswa.'], ['title' => 'judul', 'body' => 'isi pesan']);

        $users = $this->notify->audience($audience, $target);
        if ($users->isEmpty()) {
            return response()->json(['message' => 'Tidak ada penerima untuk pilihan ini.'], 422);
        }

        [$total, $pushed] = $this->notify->sendMany($users, 'manual', $d['title'], $d['body'] ?? null, $d['url'] ?? null, true);

        PushBroadcast::create([
            'sender_id' => $request->user()->id, 'audience' => $audience, 'target' => $this->label($audience, $target),
            'title' => $d['title'], 'body' => $d['body'] ?? null, 'url' => $d['url'] ?? null, 'recipients' => $total, 'with_push' => $pushed,
        ]);
        AuditLog::record('push.broadcast', null, ['audience' => $audience, 'recipients' => $total, 'title' => $d['title']]);

        return response()->json(['recipients' => $total, 'with_push' => $pushed]);
    }

    private function target(Request $request): array
    {
        $d = $request->validate([
            'audience' => ['required', Rule::in(['user', 'teachers', 'students', 'class', 'all'])],
            'target' => [Rule::requiredIf(fn () => in_array($request->input('audience'), ['user', 'class'], true)), 'nullable', 'uuid'],
        ]);
        if ($d['audience'] === 'class') {
            abort_unless(SchoolClass::whereKey($d['target'])->exists(), 422, 'Rombel tidak ditemukan.');
        }

        return [$d['audience'], $d['target'] ?? null];
    }

    private function summary($users): array
    {
        $ids = $users->pluck('id');

        return [
            'recipients' => $users->count(),
            'with_push' => $ids->isEmpty() ? 0 : PushSubscription::whereIn('user_id', $ids)->distinct('user_id')->count('user_id'),
        ];
    }

    private function reach(string $role): array
    {
        $users = User::where('role', $role)->where('status', 'active');

        return [
            'total' => (clone $users)->count(),
            'with_push' => PushSubscription::whereIn('user_id', (clone $users)->select('id'))->distinct('user_id')->count('user_id'),
        ];
    }

    private function label(string $audience, ?string $target): string
    {
        return match ($audience) {
            'user' => User::find($target)?->name ?? 'Satu pengguna',
            'teachers' => 'Semua guru',
            'students' => 'Semua siswa',
            'class' => 'Rombel '.(SchoolClass::find($target)?->name ?? ''),
            default => 'Semua guru dan siswa',
        };
    }
}
