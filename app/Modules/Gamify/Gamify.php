<?php

namespace App\Modules\Gamify;

use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\User;
use App\Models\UserBadge;
use App\Models\UserLevel;
use App\Models\XpEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gamifikasi (GAM-01..07). Modul ini hanya mendengarkan kejadian dari modul lain
 * (lihat Hooks); mematikannya tidak mengubah modul lain.
 *
 * - XP dari aktivitas bermakna, dibatasi per hari (total dan per jenis).
 * - Buku besar xp_events unik per sumber: aksi yang sama tidak memberi XP dua kali.
 * - Streak dihitung per hari sekolah: Sabtu dan Minggu tidak memutus rentetan.
 */
class Gamify
{
    private static ?bool $enabled = null;

    public static function flush(): void
    {
        self::$enabled = null;
    }

    public function enabled(): bool
    {
        return self::$enabled ??= (bool) (SchoolSetting::first()?->settings['gamify_enabled'] ?? true);
    }

    public function eligible(User $user): bool
    {
        return $user->role === User::ROLE_STUDENT && $this->enabled();
    }

    // ---------------------------------------------------------------- level

    /** @return array{level:int,title:string,into:int,span:int,next_at:int} */
    public function level(int $xp): array
    {
        $f = (int) config('gamify.level_factor');
        $level = (int) floor(sqrt(max(0, $xp) / $f)) + 1;
        $from = $f * ($level - 1) ** 2;
        $to = $f * $level ** 2;
        $titles = config('gamify.level_titles');

        return [
            'level' => $level,
            'title' => $titles[min(count($titles) - 1, intdiv($level - 1, 2))],
            'into' => $xp - $from,
            'span' => $to - $from,
            'next_at' => $to,
        ];
    }

    // ---------------------------------------------------------------- beri XP

    /**
     * Beri XP bila aturan, batas harian, dan keunikan sumber mengizinkan.
     *
     * @return int XP yang benar-benar diberikan (0 bila ditolak/dibatasi)
     */
    public function award(User $user, string $reason, string $sourceType, string $sourceId, ?int $points = null): int
    {
        if (! $this->eligible($user)) {
            return 0;
        }
        $points ??= (int) config("gamify.xp.{$reason}", 0);
        if ($points <= 0) {
            return 0;
        }

        return DB::transaction(function () use ($user, $reason, $sourceType, $sourceId, $points) {
            $level = $this->lockedLevel($user);
            $today = now()->startOfDay();

            $used = (int) XpEvent::where('user_id', $user->id)->where('created_at', '>=', $today)->sum('points');
            $allowed = min($points, (int) config('gamify.daily_cap') - $used);
            if ($cap = config("gamify.category_caps.{$reason}")) {
                $usedCat = (int) XpEvent::where('user_id', $user->id)->where('reason', $reason)->where('created_at', '>=', $today)->sum('points');
                $allowed = min($allowed, $cap - $usedCat);
            }
            if ($allowed <= 0) {
                return 0;
            }

            try {
                // savepoint: pelanggaran unik tidak boleh membatalkan transaksi luar
                DB::transaction(fn () => XpEvent::create(['user_id' => $user->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => $reason, 'points' => $allowed]));
            } catch (UniqueConstraintViolationException) {
                return 0;
            }

            $notices = $level->notices ?? [];
            $priorIds = array_column($notices, 'id');
            $notices[] = ['id' => (string) Str::uuid7(), 'type' => 'xp', 'points' => $allowed, 'label' => config("gamify.reasons.{$reason}", $reason)];

            $before = $level->level;
            $level->xp += $allowed;
            $level->level = $this->level($level->xp)['level'];
            if ($level->level > $before) {
                $notices[] = ['id' => (string) Str::uuid7(), 'type' => 'level', 'level' => $level->level, 'title' => $this->level($level->xp)['title']];
            }
            if ($this->touchStreak($level, now()) && $level->streak >= 2) {
                $notices[] = ['id' => (string) Str::uuid7(), 'type' => 'streak', 'days' => $level->streak];
            }

            $level->notices = $this->earnBadges($user, $level, $notices);
            $level->save();
            $this->bell($user, $level->notices, $priorIds);

            return $allowed;
        });
    }

    /** Catat kejadian tanpa XP (untuk lencana), mis. nilai 100 atau ide berbintang. */
    public function mark(User $user, string $reason, string $sourceType, string $sourceId): void
    {
        if (! $this->eligible($user)) {
            return;
        }

        DB::transaction(function () use ($user, $reason, $sourceType, $sourceId) {
            $level = $this->lockedLevel($user);
            try {
                DB::transaction(fn () => XpEvent::create(['user_id' => $user->id, 'source_type' => $sourceType, 'source_id' => $sourceId, 'reason' => $reason, 'points' => 0]));
            } catch (UniqueConstraintViolationException) {
                return;
            }
            $level->notices = $this->earnBadges($user, $level, $level->notices ?? []);
            $level->save();
        });
    }

    private function lockedLevel(User $user): UserLevel
    {
        DB::table('user_levels')->insertOrIgnore([
            'user_id' => $user->id, 'school_id' => $user->school_id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return UserLevel::where('user_id', $user->id)->lockForUpdate()->firstOrFail();
    }

    /** Rentetan hari: Sabtu/Minggu di antara dua hari aktif tidak memutus. @return bool true bila hari ini hari baru */
    private function touchStreak(UserLevel $level, CarbonInterface $now): bool
    {
        $today = $now->copy()->startOfDay();
        $last = $level->last_active_on?->copy()->startOfDay();

        if ($last && $last->equalTo($today)) {
            return false;
        }

        $continues = false;
        if ($last) {
            $continues = true;
            for ($d = $last->copy()->addDay(); $d->lt($today); $d->addDay()) {
                if (! $d->isWeekend()) {
                    $continues = false;
                    break;
                }
            }
        }

        $level->streak = $continues ? $level->streak + 1 : 1;
        $level->best_streak = max($level->best_streak, $level->streak);
        $level->last_active_on = $today->toDateString();

        return true;
    }

    /** Beri lencana yang syaratnya baru terpenuhi. Mengembalikan daftar pemberitahuan yang diperbarui. */
    /** Level dan lencana baru juga dicatat di lonceng (tanpa push; sudah ada toast). */
    private function bell(User $user, array $notices, array $priorIds): void
    {
        try {
            foreach ($notices as $n) {
                if (in_array($n['id'], $priorIds, true) || ! in_array($n['type'], ['level', 'badge'], true)) {
                    continue;
                }
                app(\App\Modules\Notify\Notify::class)->send(
                    $user, 'reward',
                    $n['type'] === 'level' ? "Naik ke level {$n['level']}: {$n['title']}" : "Lencana baru: {$n['name']} {$n['icon']}",
                    null, route('gamify.me', [], false), $n['type'].':'.($n['level'] ?? $n['badge']),
                );
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function earnBadges(User $user, UserLevel $level, array $notices): array
    {
        $have = UserBadge::where('user_id', $user->id)->pluck('badge')->all();
        $counts = XpEvent::where('user_id', $user->id)->groupBy('reason')->selectRaw('reason, count(*) as n')->pluck('n', 'reason');

        foreach (config('gamify.badges') as $key => $b) {
            if (in_array($key, $have, true)) {
                continue;
            }
            $ok = match (true) {
                isset($b['reason']) => (int) ($counts[$b['reason']] ?? 0) >= $b['count'],
                isset($b['streak']) => $level->streak >= $b['streak'],
                isset($b['level']) => $level->level >= $b['level'],
                default => false,
            };
            if ($ok) {
                UserBadge::create(['user_id' => $user->id, 'badge' => $key, 'earned_at' => now()]);
                $notices[] = ['id' => (string) Str::uuid7(), 'type' => 'badge', 'badge' => $key, 'name' => $b['name'], 'icon' => $b['icon']];
            }
        }

        return array_slice($notices, -20);
    }

    // ---------------------------------------------------------------- tampilan

    /** Ringkasan kecil untuk header dan beranda. */
    public function mini(User $user): array
    {
        $row = UserLevel::where('user_id', $user->id)->first();
        $xp = $row?->xp ?? 0;
        $lv = $this->level($xp);

        return [
            'xp' => $xp,
            'level' => $lv['level'],
            'title' => $lv['title'],
            'progress' => $lv['span'] ? round($lv['into'] / $lv['span'], 3) : 0,
            'into' => $lv['into'],
            'span' => $lv['span'],
            'streak' => $this->activeStreak($row),
            'notices' => $row?->notices ?? [],
        ];
    }

    /** Streak hanya dianggap hidup bila hari aktif terakhir masih bersambung sampai hari ini. */
    private function activeStreak(?UserLevel $row): int
    {
        if (! $row || ! $row->last_active_on) {
            return 0;
        }
        $probe = clone $row;
        $today = now()->startOfDay();
        $last = $row->last_active_on->copy()->startOfDay();
        if ($last->equalTo($today)) {
            return $row->streak;
        }
        for ($d = $last->copy()->addDay(); $d->lt($today); $d->addDay()) {
            if (! $d->isWeekend()) {
                return 0;
            }
        }

        return $probe->streak;
    }

    public function badges(User $user): array
    {
        $earned = UserBadge::where('user_id', $user->id)->pluck('earned_at', 'badge');

        return collect(config('gamify.badges'))->map(fn ($b, $key) => [
            'key' => $key, 'name' => $b['name'], 'icon' => $b['icon'], 'text' => $b['text'],
            'earned_at' => isset($earned[$key]) ? $earned[$key]->toIso8601String() : null,
        ])->values()->all();
    }

    public function history(User $user, int $limit = 25): array
    {
        return XpEvent::where('user_id', $user->id)->where('points', '>', 0)->orderByDesc('created_at')->limit($limit)->get()
            ->map(fn ($e) => ['label' => config("gamify.reasons.{$e->reason}", $e->reason), 'points' => $e->points, 'at' => $e->created_at->toIso8601String()])->all();
    }

    public function dismiss(User $user, array $ids): void
    {
        DB::transaction(function () use ($user, $ids) {
            $row = UserLevel::where('user_id', $user->id)->lockForUpdate()->first();
            if ($row) {
                $row->notices = array_values(array_filter($row->notices ?? [], fn ($n) => ! in_array($n['id'], $ids, true)));
                $row->save();
            }
        });
    }

    // ---------------------------------------------------------------- hadiah kosmetik (GAM-05, THM-04)

    /**
     * XP yang dipakai untuk membuka hadiah. Guru/admin, atau sekolah yang mematikan
     * gamifikasi, mendapat semuanya terbuka: tidak ada yang terkunci tanpa jalan membukanya.
     */
    public function unlockXp(User $user): int
    {
        if ($user->role !== User::ROLE_STUDENT || ! $this->enabled()) {
            return PHP_INT_MAX;
        }

        return (int) (UserLevel::where('user_id', $user->id)->value('xp') ?? 0);
    }

    /** @return array{avatars:list<array>,frames:list<array>} */
    public function catalog(User $user): array
    {
        $xp = $this->unlockXp($user);
        $map = fn (array $items, \Closure $extra) => collect($items)->map(fn ($v, $k) => ['key' => $k, 'name' => $v['name'], 'xp' => $v['xp'], 'locked' => $v['xp'] > $xp] + $extra($v))->values()->all();

        return [
            'avatars' => $map(config('gamify.avatars'), fn ($v) => ['emoji' => $v['emoji']]),
            'frames' => $map(config('gamify.frames'), fn ($v) => ['ring' => $v['ring']]),
        ];
    }

    /** Avatar dan bingkai yang dipakai; hadiah yang belum/tidak lagi terbuka diabaikan. */
    public function look(User $user, ?\App\Models\UserPreference $pref): array
    {
        $xp = $this->unlockXp($user);
        $avatar = config("gamify.avatars.{$pref?->avatar}");
        $frame = config("gamify.frames.{$pref?->frame}");

        return [
            'avatar' => $avatar && $avatar['xp'] <= $xp ? $avatar['emoji'] : null,
            'frame' => $frame && $frame['xp'] <= $xp ? $frame['ring'] : null,
        ];
    }

    // ---------------------------------------------------------------- papan peringkat (GAM-06)

    /** @return array{rows:list<array>,me:?array} */
    public function leaderboard(SchoolClass $class, string $period, ?User $viewer = null, int $limit = 20): array
    {
        $week = $period !== 'total';
        $start = now()->startOfWeek();

        $rows = DB::table('class_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('user_levels as l', 'l.user_id', '=', 'u.id')
            ->leftJoin('user_preferences as p', 'p.user_id', '=', 'u.id')
            ->leftJoin('xp_events as e', function ($j) use ($week, $start) {
                $j->on('e.user_id', '=', 'u.id');
                if ($week) {
                    $j->where('e.created_at', '>=', $start);
                }
            })
            ->where('m.class_id', $class->id)->where('u.role', User::ROLE_STUDENT)->whereNull('u.deleted_at')
            ->groupBy('u.id', 'u.name', 'l.xp', 'l.level', 'p.avatar', 'p.frame')
            ->selectRaw('u.id, u.name, p.avatar, p.frame, coalesce(l.level, 1) as level, '.($week ? 'coalesce(sum(e.points), 0)' : 'coalesce(l.xp, 0)').' as xp')
            ->orderByDesc('xp')->orderBy('u.name')
            ->get();

        // Peringkat dense: skor sama = peringkat sama; yang belum punya XP tidak diberi peringkat
        $rank = 0;
        $prev = null;
        $list = $rows->map(function ($r, $i) use (&$rank, &$prev) {
            if ($r->xp !== $prev) {
                $rank = $i + 1;
                $prev = $r->xp;
            }

            return ['user_id' => $r->id, 'name' => $r->name, 'level' => (int) $r->level, 'xp' => (int) $r->xp, 'rank' => (int) $r->xp > 0 ? $rank : null,
                'avatar' => config("gamify.avatars.{$r->avatar}.emoji"), 'frame' => config("gamify.frames.{$r->frame}.ring")];
        });

        return [
            'rows' => $list->take($limit)->values()->all(),
            'me' => $viewer ? $list->firstWhere('user_id', $viewer->id) : null,
            'members' => $list->count(),
        ];
    }
}
