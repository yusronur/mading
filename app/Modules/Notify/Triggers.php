<?php

namespace App\Modules\Notify;

use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Collection;

/** Notifikasi terjadwal dan yang dipicu aksi guru. Idempoten lewat dedupe_key. */
class Triggers
{
    public function __construct(private Notify $notify) {}

    /** Kuis/tugas baru ke rombel: satu pesan per siswa walau beberapa tugas dibuat sekaligus. */
    public function quizAssigned(iterable $sessions): int
    {
        $byUser = [];
        foreach ($sessions as $s) {
            if (! $s->class_id) {
                continue;
            }
            foreach ($this->notify->classStudents($s->class_id) as $u) {
                $byUser[$u->id]['user'] = $u;
                $byUser[$u->id]['s'][$s->id] = $s;
            }
        }
        foreach ($byUser as $row) {
            $list = array_values($row['s']);
            $first = $list[0];
            $opens = $first->opens_at && $first->opens_at->isFuture() ? ' (dibuka '.$first->opens_at->translatedFormat('j M H:i').')' : '';
            $this->notify->send(
                $row['user'], 'quiz_assigned',
                count($list) === 1 ? "Kuis baru: {$first->title}" : count($list).' kuis baru untukmu',
                count($list) === 1 ? 'Tenggat '.$first->closes_at->translatedFormat('j M H:i').$opens : null,
                route('student.dashboard', [], false),
                'assigned:'.$first->id,
            );
        }

        return count($byUser);
    }

    /** Kuis live: beri tahu siswa rombel yang dipilih guru (push hanya bila sedang tidak online). */
    public function liveStarted(QuizSession $s, array $classIds): int
    {
        $n = 0;
        foreach (array_unique($classIds) as $classId) {
            foreach ($this->notify->classStudents($classId) as $u) {
                $this->notify->send($u, 'live_started', "Kuis live dimulai: {$s->title}", 'Masukkan kode '.$s->code.' untuk bergabung.', route('live.play', $s->id, false), 'live:'.$s->id);
                $n++;
            }
        }

        return $n;
    }

    /** H-1 pagi: tugas yang berakhir besok dan belum dikumpulkan. */
    public function dueTomorrow(): int
    {
        $from = now()->addDay()->startOfDay();
        $sessions = QuizSession::where('mode', 'self')->whereNotNull('class_id')->whereNull('closed_at')
            ->whereBetween('closes_at', [$from, $from->copy()->endOfDay()])
            ->where(fn ($q) => $q->whereNull('opens_at')->orWhere('opens_at', '<=', now()))->get();

        $byUser = [];
        foreach ($sessions as $s) {
            $done = QuizAttempt::where('session_id', $s->id)->whereNotNull('submitted_at')->pluck('user_id')->all();
            foreach ($this->notify->classStudents($s->class_id) as $u) {
                if (! in_array($u->id, $done, true)) {
                    $byUser[$u->id]['user'] = $u;
                    $byUser[$u->id]['s'][] = $s;
                }
            }
        }
        foreach ($byUser as $row) {
            $list = $row['s'];
            $this->notify->send(
                $row['user'], 'quiz_due',
                count($list) === 1 ? "Besok tenggat: {$list[0]->title}" : count($list).' tugas berakhir besok',
                count($list) === 1 ? 'Berakhir '.$list[0]->closes_at->translatedFormat('j M H:i').'.' : null,
                route('student.dashboard', [], false),
                'due:'.$from->toDateString(),
            );
        }

        return count($byUser);
    }

    /** Tugas yang baru ditutup: nilai untuk siswa yang mengumpulkan, dan rekap untuk guru. Sekali per tugas. */
    public function closed(): int
    {
        $sessions = QuizSession::where('mode', 'self')->whereNotNull('class_id')
            ->whereBetween('closes_at', [now()->subDays(2), now()])->get()
            ->reject(fn ($s) => ! empty($s->settings['closed_notified']));

        $byUser = [];
        foreach ($sessions as $s) {
            $done = QuizAttempt::where('session_id', $s->id)->whereNotNull('submitted_at')->pluck('user_id')->unique();
            if ($s->settings['show_score'] ?? true) {
                foreach (User::whereIn('id', $done)->where('status', 'active')->get() as $u) {
                    $byUser[$u->id]['user'] = $u;
                    $byUser[$u->id]['s'][] = $s;
                }
            }

            $teacher = $s->host_id ? User::find($s->host_id) : null;
            if ($teacher && $teacher->status === 'active') {
                $missing = $this->notify->classStudents($s->class_id)->reject(fn ($u) => $done->contains($u->id))->count();
                if ($missing > 0) {
                    $this->notify->send($teacher, 'overdue', "$missing siswa belum mengumpulkan {$s->title}", 'Tenggat sudah lewat.', route('assignments.show', $s->id, false), 'overdue:'.$s->id);
                }
            }
        }

        foreach ($byUser as $row) {
            $list = $row['s'];
            $this->notify->send(
                $row['user'], 'grade_ready',
                count($list) === 1 ? "Nilai {$list[0]->title} sudah keluar" : count($list).' nilai baru',
                null, route('student.dashboard', [], false), 'ready:'.$list[0]->id,
            );
        }
        foreach ($sessions as $s) {
            $s->settings = ($s->settings ?? []) + ['closed_notified' => true];
            $s->save();
        }

        return count($byUser);
    }

    /** Ringkasan harian untuk guru: pengumpulan hari ini. */
    public function teacherDigest(): int
    {
        $n = 0;
        $sessions = QuizSession::where('mode', 'self')->whereNotNull('host_id')->get()->groupBy('host_id');
        foreach ($sessions as $hostId => $mine) {
            $count = QuizAttempt::whereIn('session_id', $mine->pluck('id'))->where('submitted_at', '>=', now()->startOfDay())->count();
            $teacher = User::find($hostId);
            if ($count > 0 && $teacher && $teacher->status === 'active') {
                $this->notify->send($teacher, 'digest', "Hari ini $count pengumpulan kuis baru", 'Buka laporan untuk melihat hasil siswa.', route('quizzes.index', [], false), 'digest:'.now()->toDateString());
                $n++;
            }
        }

        return $n;
    }

    /** Hanya dipanggil dari perintah konsol per sekolah. */
    public function runFor(School $school, string $job): int
    {
        return app(\App\Core\Tenancy\TenantContext::class)->run($school, fn () => match ($job) {
            'due' => $this->dueTomorrow(),
            'closed' => $this->closed(),
            'digest' => $this->teacherDigest(),
            'release' => $this->notify->releaseHeld(),
            'prune' => $this->notify->prune(),
            default => 0,
        });
    }
}
