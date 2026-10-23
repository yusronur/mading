<?php

namespace App\Modules\Recap;

use App\Models\Board;
use App\Models\PostGrade;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Quiz\QuizEngine;
use Illuminate\Support\Facades\DB;

/**
 * Rekap nilai satu rombel (Tahap 18): nilai kuis/tugas kuis dan nilai rubrik
 * papan dalam satu tabel. Guru melihat miliknya sendiri; admin bisa semua guru.
 */
class Recap
{
    public const MAX_COLUMNS = 60;

    public function __construct(private QuizEngine $engine) {}

    public function build(SchoolClass $class, User $viewer, bool $allTeachers = false): array
    {
        $students = User::whereIn('id', DB::table('class_members')->where('class_id', $class->id)->pluck('user_id'))
            ->where('role', User::ROLE_STUDENT)->orderBy('name')->get(['id', 'name', 'username']);

        $sessions = QuizSession::where('class_id', $class->id)
            ->when(! $allTeachers, fn ($q) => $q->where('host_id', $viewer->id))
            ->with('host:id,name')->orderBy('created_at')->limit(self::MAX_COLUMNS)->get();

        $boards = Board::where('class_id', $class->id)->where('is_template', false)
            ->when(! $allTeachers, fn ($q) => $q->where('owner_id', $viewer->id))
            ->with('owner:id,name')->orderBy('created_at')->limit(self::MAX_COLUMNS)->get()
            ->filter(fn (Board $b) => $b->rubric() !== [])->values();

        $columns = [];
        $values = []; // [colKey][userId] => nilai

        $attempts = QuizAttempt::whereIn('session_id', $sessions->pluck('id'))->whereNotNull('submitted_at')
            ->get()->groupBy('session_id');
        foreach ($sessions as $s) {
            $key = 'q:'.$s->id;
            $columns[] = [
                'key' => $key, 'kind' => $s->isLive() ? 'live' : 'quiz', 'title' => $s->title,
                'date' => ($s->closes_at ?? $s->created_at)?->toIso8601String(), 'teacher' => $s->host?->name,
                'kkm' => (int) ($s->settings['kkm'] ?? 75), 'url' => $s->isLive() ? null : route('assignments.show', $s->id),
            ];
            foreach (($attempts[$s->id] ?? collect())->groupBy('user_id') as $uid => $list) {
                $values[$key][$uid] = $this->engine->countedAttempt($list, $s)?->grade();
            }
        }

        $grades = PostGrade::whereIn('board_id', $boards->pluck('id'))
            ->groupBy('board_id', 'student_id')->selectRaw('board_id, student_id, avg(grade) as g, count(*) as n')->get();
        foreach ($boards as $b) {
            $columns[] = [
                'key' => 'b:'.$b->id, 'kind' => 'board', 'title' => $b->title, 'date' => $b->created_at?->toIso8601String(),
                'teacher' => $b->owner?->name, 'kkm' => 75, 'url' => route('boards.show', $b->id),
            ];
        }
        foreach ($grades as $g) {
            $values['b:'.$g->board_id][$g->student_id] = (int) round((float) $g->g);
        }

        usort($columns, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        $rows = $students->map(function (User $u) use ($columns, $values) {
            $cells = [];
            foreach ($columns as $c) {
                $cells[$c['key']] = $values[$c['key']][$u->id] ?? null;
            }
            $filled = array_filter($cells, fn ($v) => $v !== null);

            return [
                'id' => $u->id, 'name' => $u->name, 'username' => $u->username, 'cells' => $cells,
                'average' => $filled ? round(array_sum($filled) / count($filled), 1) : null,
                'done' => count($filled),
            ];
        })->values()->all();

        // Rata-rata per kolom
        $colAvg = [];
        foreach ($columns as $c) {
            $vals = array_filter(array_column(array_column($rows, 'cells'), $c['key']), fn ($v) => $v !== null);
            $colAvg[$c['key']] = $vals ? round(array_sum($vals) / count($vals), 1) : null;
        }

        return ['columns' => $columns, 'rows' => $rows, 'column_average' => $colAvg];
    }
}
