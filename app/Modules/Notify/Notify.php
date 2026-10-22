<?php

namespace App\Modules\Notify;

use App\Core\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\AppNotification;
use App\Models\PushSubscription;
use App\Models\School;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Models\UserPreference;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pusat notifikasi. Semua notifikasi masuk lonceng; sebagian juga dikirim sebagai push
 * dengan aturan anti-banjir: dimatikan per jenis oleh pengguna, tidak dikirim saat
 * pengguna sedang membuka aplikasi (kuis live), ditahan pada jam tenang, dan dibatasi
 * per hari. Pesan manual admin melewati batas harian dan jam tenang.
 */
class Notify
{
    public function __construct(private WebPush $push) {}

    // ------------------------------------------------------------- pengaturan

    public function pushAvailable(): bool
    {
        return $this->push->configured() && (bool) (SchoolSetting::first()?->settings['push_enabled'] ?? true);
    }

    public static function kind(string $kind): array
    {
        return config("notify.kinds.$kind") ?? ['label' => $kind, 'push' => false, 'roles' => []];
    }

    /** Jenis yang boleh diatur pengguna (dimatikan/dinyalakan) sesuai perannya. */
    public static function togglableFor(User $user): array
    {
        return collect(config('notify.kinds'))
            ->filter(fn ($k) => $k['push'] && empty($k['fixed']) && in_array($user->role, $k['roles'], true))
            ->map(fn ($k, $key) => ['key' => $key, 'label' => $k['label']])->values()->all();
    }

    public static function quiet(?CarbonInterface $at = null): bool
    {
        $h = (int) ($at ?? now())->format('G');
        $from = (int) config('notify.quiet_from');
        $until = (int) config('notify.quiet_until');

        return $from > $until ? ($h >= $from || $h < $until) : ($h >= $from && $h < $until);
    }

    public static function markOnline(string $userId): void
    {
        Cache::put("presence:$userId", 1, 120);
    }

    public static function online(string $userId): bool
    {
        return Cache::has("presence:$userId");
    }

    // ------------------------------------------------------------- mengirim

    /**
     * Buat satu notifikasi. $dedupe mencegah duplikat (mis. tugas yang sama dipicu dua kali).
     * $force: lewati batas harian dan jam tenang (pesan manual admin).
     */
    public function send(User $user, string $kind, string $title, ?string $body = null, ?string $url = null, ?string $dedupe = null, bool $force = false): ?AppNotification
    {
        $n = $this->create($user, $kind, $title, $body, $url, $dedupe);
        if ($n) {
            $this->deliver($n, $user, $force);
        }

        return $n;
    }

    /** Notifikasi bertumpuk (komentar, suka, pengumpulan): satu baris belum-dibaca per kunci, dengan hitungan. */
    public function bump(User $user, string $kind, string $key, callable $make): AppNotification
    {
        $row = AppNotification::where('user_id', $user->id)->where('dedupe_key', $key)->whereNull('read_at')->first();
        $count = ($row?->count ?? 0) + 1;
        [$title, $body, $url] = $make($count) + [null, null, null];

        if ($row) {
            $row->update(['title' => $title, 'body' => $body, 'url' => $url, 'count' => $count, 'updated_at' => now()]);

            return $row;
        }

        // kunci lama yang sudah dibaca dilepas supaya aktivitas baru muncul lagi
        AppNotification::where('user_id', $user->id)->where('dedupe_key', $key)->whereNotNull('read_at')->update(['dedupe_key' => null]);

        return $this->create($user, $kind, $title, $body, $url, $key) ?? $row;
    }

    private function create(User $user, string $kind, string $title, ?string $body, ?string $url, ?string $dedupe): ?AppNotification
    {
        try {
            return DB::transaction(fn () => AppNotification::create([
                'user_id' => $user->id,
                'kind' => $kind,
                'title' => mb_substr($title, 0, 150),
                'body' => $body !== null ? mb_substr($body, 0, 300) : null,
                'url' => $url ? mb_substr($url, 0, 300) : null,
                'dedupe_key' => $dedupe,
            ]));
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /** Kirim ke banyak orang sekaligus; mengembalikan [jumlah penerima, jumlah yang dikirimi push]. */
    public function sendMany(iterable $users, string $kind, string $title, ?string $body = null, ?string $url = null, bool $force = false): array
    {
        $total = 0;
        $pushed = 0;
        foreach ($users as $user) {
            $n = $this->send($user, $kind, $title, $body, $url, null, $force);
            $total++;
            $pushed += $n?->push_state === 'sent' ? 1 : 0;
        }

        return [$total, $pushed];
    }

    // ------------------------------------------------------------- kebijakan push

    /** Alasan push tidak dikirim, atau 'send'. */
    public function policy(AppNotification $n, User $user, bool $force = false): string
    {
        $cfg = self::kind($n->kind);
        if (empty($cfg['push'])) {
            return 'none';
        }
        if (! $this->pushAvailable() || ! PushSubscription::where('user_id', $user->id)->exists()) {
            return 'off';
        }
        if (empty($cfg['fixed']) && in_array($n->kind, UserPreference::where('user_id', $user->id)->value('notify_off') ?? [], true)) {
            return 'off';
        }
        if ($force) {
            return 'send';
        }
        if ($n->kind === 'live_started' && self::online($user->id)) {
            return 'online';
        }
        if (self::quiet()) {
            return 'held';
        }
        if ($this->sentToday($user) >= (int) config('notify.daily_cap')) {
            return 'capped';
        }

        return 'send';
    }

    public function sentToday(User $user): int
    {
        return AppNotification::where('user_id', $user->id)->where('push_state', 'sent')
            ->where('pushed_at', '>=', now()->startOfDay())->count();
    }

    private function deliver(AppNotification $n, User $user, bool $force): void
    {
        $state = $this->policy($n, $user, $force);
        if ($state === 'send') {
            $this->transmit($user, [$n]);
        } else {
            $n->update(['push_state' => $state]);
        }
    }

    /** Kirim push untuk satu atau beberapa notifikasi (digabung jadi satu pesan bila lebih dari satu). */
    public function transmit(User $user, array $notes, ?string $title = null, ?string $body = null, ?string $url = null): void
    {
        $subs = PushSubscription::where('user_id', $user->id)->get(['id', 'endpoint', 'p256dh', 'auth'])->map->only(['id', 'endpoint', 'p256dh', 'auth'])->all();
        $now = now();
        $first = $notes[0];
        // Beberapa notifikasi yang digabung dihitung satu push (hanya yang pertama berstatus 'sent')
        foreach (array_values($notes) as $i => $n) {
            $n->update(['push_state' => $i === 0 ? 'sent' : 'merged', 'pushed_at' => $now]);
        }
        if (! $subs) {
            return;
        }

        $message = [
            'title' => $title ?? $first->title,
            'body' => $body ?? $first->body,
            'url' => $url ?? $first->url ?? route('notifications.index', [], false),
            'tag' => count($notes) > 1 ? 'digest' : $first->kind,
        ];
        $schoolId = $user->school_id;
        $run = function () use ($subs, $message, $schoolId) {
            @set_time_limit(120);
            $results = app(WebPush::class)->sendMany($subs, $message);
            $dead = array_keys(array_filter($results, fn ($r) => $r === 'gone'));
            $ok = array_keys(array_filter($results, fn ($r) => $r === 'ok'));
            if ($dead || $ok) {
                app(TenantContext::class)->run(School::findOrFail($schoolId), function () use ($dead, $ok) {
                    PushSubscription::whereIn('id', $dead)->delete();
                    PushSubscription::whereIn('id', $ok)->update(['last_ok_at' => now()]);
                });
            }
        };

        // Dalam permintaan web, kirim setelah respons selesai agar guru tidak menunggu layanan push
        app()->runningInConsole() ? $run() : dispatch($run)->afterResponse();
    }

    /** Lepas notifikasi yang ditahan jam tenang: satu push per orang. */
    public function releaseHeld(): int
    {
        if (self::quiet()) {
            return 0;
        }
        $sent = 0;
        $users = AppNotification::where('push_state', 'held')->where('created_at', '>=', now()->subDay())->whereNull('read_at')->pluck('user_id')->unique();
        foreach (User::whereIn('id', $users)->get() as $user) {
            $notes = AppNotification::where('user_id', $user->id)->where('push_state', 'held')->where('created_at', '>=', now()->subDay())->whereNull('read_at')->orderBy('created_at')->get()->all();
            if (! $notes || $this->sentToday($user) >= (int) config('notify.daily_cap')) {
                AppNotification::where('user_id', $user->id)->where('push_state', 'held')->update(['push_state' => 'capped']);

                continue;
            }
            count($notes) === 1
                ? $this->transmit($user, $notes)
                : $this->transmit($user, $notes, count($notes).' pemberitahuan baru', $notes[0]->title, route('notifications.index', [], false));
            $sent++;
        }
        AppNotification::where('push_state', 'held')->where('created_at', '<', now()->subDay())->update(['push_state' => 'capped']);

        return $sent;
    }

    /** Masalah sistem untuk admin sekolah; paling banyak sekali per hari per jenis. Tidak boleh melempar galat. */
    public static function alertAdmins(string $title, string $body, string $key): void
    {
        try {
            if (! app(TenantContext::class)->check()) {
                return;
            }
            $notify = app(self::class);
            foreach (User::where('role', User::ROLE_ADMIN)->where('status', 'active')->get() as $admin) {
                $notify->send($admin, 'system', $title, $body, route('admin.dashboard', [], false), "system:$key:".now()->toDateString());
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    // ------------------------------------------------------------- lonceng

    public function unread(User $user): int
    {
        return AppNotification::where('user_id', $user->id)->whereNull('read_at')->count();
    }

    public function recent(User $user, int $limit = 15, ?string $before = null): Collection
    {
        return AppNotification::where('user_id', $user->id)
            ->when($before, fn ($q) => $q->where('updated_at', '<', $before))
            ->orderByDesc('updated_at')->limit($limit)->get()
            ->map(fn (AppNotification $n) => $this->present($n));
    }

    public function present(AppNotification $n): array
    {
        return [
            'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'url' => $n->url,
            'count' => $n->count, 'read' => $n->read_at !== null, 'at' => $n->updated_at->toIso8601String(),
            'icon' => match ($n->kind) {
                'quiz_assigned', 'quiz_due', 'live_started' => '📝', 'grade_ready' => '📊', 'comment' => '💬', 'reaction' => '❤️',
                'submitted', 'digest', 'overdue' => '📥', 'reward' => '🏅', 'manual' => '📣', 'system' => '⚠️', default => '🔔',
            },
        ];
    }

    public function markRead(User $user, ?string $id = null): void
    {
        AppNotification::where('user_id', $user->id)->whereNull('read_at')
            ->when($id, fn ($q) => $q->where('id', $id))->update(['read_at' => now()]);
    }

    public function prune(): int
    {
        return AppNotification::where('created_at', '<', now()->subDays((int) config('notify.keep_days')))->delete();
    }

    // ------------------------------------------------------------- sasaran

    /**
     * Daftar penerima untuk pesan manual admin.
     *
     * @param  'user'|'teachers'|'students'|'class'|'all'  $audience
     */
    public function audience(string $audience, ?string $target = null): Collection
    {
        $q = User::where('status', 'active');

        return match ($audience) {
            'user' => $q->where('id', $target)->whereIn('role', [User::ROLE_TEACHER, User::ROLE_STUDENT])->get(),
            'teachers' => $q->where('role', User::ROLE_TEACHER)->get(),
            'students' => $q->where('role', User::ROLE_STUDENT)->get(),
            'class' => $q->where('role', User::ROLE_STUDENT)->whereIn('id', DB::table('class_members')->where('class_id', $target)->select('user_id'))->get(),
            'all' => $q->whereIn('role', [User::ROLE_TEACHER, User::ROLE_STUDENT])->get(),
            default => collect(),
        };
    }

    /** Siswa pada rombel tertentu (untuk kuis baru, pengingat, dan nilai). */
    public function classStudents(string $classId): Collection
    {
        return User::where('status', 'active')->where('role', User::ROLE_STUDENT)
            ->whereIn('id', DB::table('class_members')->where('class_id', $classId)->select('user_id'))->get();
    }

    public function activeYearId(): ?string
    {
        return AcademicYear::active()?->id;
    }
}
