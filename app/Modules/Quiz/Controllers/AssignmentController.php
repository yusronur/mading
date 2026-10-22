<?php

namespace App\Modules\Quiz\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Models\User;
use App\Modules\Quiz\QuizEngine;
use App\Modules\Quiz\QuizReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Penugasan kuis mandiri ke rombel dan laporannya (QZ-09, QZ-14, QZ-15). */
class AssignmentController extends Controller
{
    public function store(Request $request, Quiz $quiz, QuizEngine $engine, TenantContext $tenant): RedirectResponse
    {
        QuizController::authorizeQuiz($request, $quiz);

        $data = $request->validate([
            'public' => ['nullable', 'boolean'],
            'allow_guests' => ['nullable', 'boolean'],
            'duration_minutes' => ['required', 'integer', 'between:1,600'],
            'class_ids' => ['required_without:public', 'array', 'max:20'],
            'class_ids.*' => ['uuid', Rule::exists('classes', 'id')->where('school_id', $tenant->id())],
            'opens_at' => ['nullable', 'date'],
            'closes_at' => array_merge(['required', 'date', 'after:now'], $request->filled('opens_at') ? ['after:opens_at'] : []),
        ], ['closes_at.after' => 'Tenggat harus setelah sekarang dan setelah waktu buka.', 'duration_minutes.required' => 'Durasi pengerjaan wajib diisi.'], ['class_ids' => 'rombel', 'closes_at' => 'tenggat', 'duration_minutes' => 'durasi']);

        try {
            $questions = $engine->snapshot($quiz);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $tz = config('app.timezone');
        $targets = array_unique($data['class_ids'] ?? []);
        $settings = array_merge($quiz->settings(), ['duration_minutes' => (int) $data['duration_minutes']]);
        $public = (bool) ($data['public'] ?? false);
        // Publik: satu tugas tanpa rombel, dikerjakan siapa pun yang memasukkan kodenya
        if ($public) {
            $targets[] = null;
        }
        foreach ($targets as $classId) {
            QuizSession::create([
                'quiz_id' => $quiz->id,
                'host_id' => $request->user()->id,
                'class_id' => $classId,
                'mode' => 'self',
                'title' => $quiz->title,
                'opens_at' => isset($data['opens_at']) ? Carbon::parse($data['opens_at'], $tz) : null,
                'closes_at' => Carbon::parse($data['closes_at'], $tz),
                'settings' => $classId === null && ! empty($data['allow_guests']) ? $settings + ['allow_guests' => true] : $settings,
                'questions' => $questions,
            ]);
        }

        $n = count(array_filter($targets));
        $msg = $public
            ? 'Kuis publik dibuat'.($n ? " dan ditugaskan ke $n rombel" : '').'. Bagikan kode kuisnya; siapa pun yang punya kode bisa mengerjakan.'
            : "Kuis ditugaskan ke $n rombel. Siswa melihatnya di beranda, atau cukup memasukkan kode kuisnya.";

        return redirect()->route('quizzes.index')->with('success', $msg);
    }

    public function show(Request $request, QuizSession $session, QuizReport $report): Response
    {
        $this->authorizeSession($request, $session);

        return Inertia::render('Quiz/Report', [
            'session' => $this->sessionCard($session),
            'report' => $report->build($session),
        ]);
    }

    /** Pantauan langsung (JSON ringan, dipanggil berkala saat kuis berjalan). */
    public function progress(Request $request, QuizSession $session, QuizReport $report): \Illuminate\Http\JsonResponse
    {
        $this->authorizeSession($request, $session);

        return response()->json($report->progress($session))->header('Cache-Control', 'no-store');
    }

    /** Lihat jawaban satu siswa (untuk guru). */
    public function attempt(Request $request, QuizSession $session, QuizAttempt $attempt, QuizEngine $engine): Response
    {
        $this->authorizeSession($request, $session);
        abort_unless($attempt->session_id === $session->id, 404);

        return Inertia::render('Quiz/Result', [
            'session' => $this->sessionCard($session),
            'attempt' => [
                'id' => $attempt->id, 'attempt_no' => $attempt->attempt_no, 'score' => $attempt->score, 'max_score' => $attempt->max_score,
                'grade' => $attempt->grade(), 'submitted_at' => $attempt->submitted_at?->toIso8601String(), 'student' => $attempt->user?->name,
            ],
            'review' => $engine->review($attempt, $session),
            'canReview' => true,
            'teacherView' => true,
        ]);
    }

    public function update(Request $request, QuizSession $session): RedirectResponse
    {
        $this->authorizeSession($request, $session);
        $data = $request->validate([
            'action' => ['required', Rule::in(['close', 'reopen', 'extend'])],
            'closes_at' => ['required_if:action,extend', 'required_if:action,reopen', 'nullable', 'date', 'after:now'],
        ], ['closes_at.after' => 'Tenggat baru harus setelah sekarang.'], ['closes_at' => 'tenggat']);

        if ($data['action'] === 'close') {
            $session->update(['closed_at' => now()]);
            $msg = 'Kuis ditutup. Pekerjaan yang belum dikumpulkan dikumpulkan otomatis.';
        } else {
            $session->update(['closed_at' => null, 'closes_at' => Carbon::parse($data['closes_at'], config('app.timezone'))]);
            $msg = 'Tenggat diperbarui.';
        }

        return back()->with('success', $msg);
    }

    /** Hapus percobaan siswa agar bisa mengerjakan ulang. */
    public function reset(Request $request, QuizSession $session, User $user): RedirectResponse
    {
        $this->authorizeSession($request, $session);
        $count = QuizAttempt::where('session_id', $session->id)->where('user_id', $user->id)->delete();
        AuditLog::record('quiz.attempt_reset', $user, ['session' => $session->title, 'count' => $count]);

        return back()->with('success', "Pekerjaan {$user->name} dihapus; siswa bisa mengerjakan ulang.");
    }

    public function destroy(Request $request, QuizSession $session): RedirectResponse
    {
        $this->authorizeSession($request, $session);
        $session->delete();

        return redirect()->route('quizzes.index')->with('success', 'Tugas kuis dihapus.');
    }

    public function export(Request $request, QuizSession $session, QuizReport $report): StreamedResponse
    {
        $this->authorizeSession($request, $session);
        $r = $report->build($session);

        $book = new Spreadsheet;
        $s = $book->getActiveSheet()->setTitle('Nilai');
        $s->fromArray([[$session->title], ['Rombel: '.($session->schoolClass?->name ?? 'Publik (kode)').' · KKM '.$r['kkm'].' · Tenggat '.($session->closes_at?->timezone(config('app.timezone'))->format('d-m-Y H:i') ?? '-')]], null, 'A1');
        $s->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $head = ['No', 'NISN', 'Nama', 'Status', 'Skor', 'Skor maks', 'Nilai', 'Tuntas', 'Percobaan', 'Durasi (menit)', 'Dikumpulkan', 'Keluar layar'];
        $s->fromArray($head, null, 'A4');
        $s->getStyle('A4:L4')->getFont()->setBold(true);
        $status = ['done' => 'Selesai', 'working' => 'Sedang mengerjakan', 'none' => 'Belum'];
        $row = 5;
        foreach ($r['rows'] as $i => $x) {
            $s->fromArray([
                $i + 1, $x['username'], $x['name'], $status[$x['status']], $x['score'], $x['max_score'], $x['grade'],
                $x['grade'] === null ? '' : ($x['passed'] ? 'Ya' : 'Remedial'), $x['attempts'],
                $x['duration_seconds'] !== null ? round($x['duration_seconds'] / 60, 1) : '',
                $x['submitted_at'] ? Carbon::parse($x['submitted_at'])->timezone(config('app.timezone'))->format('Y-m-d H:i') : '', $x['focus_lost'],
            ], null, 'A'.$row++, true);
        }
        for ($i = 5; $i < $row; $i++) {
            $s->setCellValueExplicit('B'.$i, (string) $r['rows'][$i - 5]['username'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        foreach (range('A', 'L') as $c) {
            $s->getColumnDimension($c)->setAutoSize(true);
        }

        $a = $book->createSheet()->setTitle('Analisis butir');
        $a->fromArray(['No', 'Soal', 'Tipe', 'Poin', 'Dijawab', 'Benar', 'Indeks p', 'Tingkat'], null, 'A1');
        $a->getStyle('A1:H1')->getFont()->setBold(true);
        $rr = 2;
        foreach ($r['items'] as $it) {
            $a->fromArray([$it['no'], mb_strimwidth(trim(strip_tags($it['prompt'])), 0, 200, '…'), $it['type'], $it['points'], $it['answered'], $it['correct'], $it['p'], $it['level']], null, 'A'.$rr++, true);
        }
        $a->getColumnDimension('B')->setWidth(70);
        $book->setActiveSheetIndex(0);

        $name = 'nilai-'.Str::slug($session->title).'-'.Str::slug($session->schoolClass?->name ?? '').'.xlsx';

        return response()->streamDownload(fn () => (new Xlsx($book))->save('php://output'), $name, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function sessionCard(QuizSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'code' => $session->code,
            'join_url' => $this->joinUrl($session),
            'allow_guests' => (bool) ($session->settings['allow_guests'] ?? false),
            'class_name' => $session->schoolClass?->name ?? 'Publik (kode)',
            'opens_at' => $session->opens_at?->toIso8601String(),
            'closes_at' => $session->closes_at?->toIso8601String(),
            'status' => $session->status(),
            'settings' => $session->settings,
            'questions' => count($session->questions),
            'max_score' => $session->maxScore(),
        ];
    }

    public static function joinUrl(QuizSession $session): string
    {
        return ($session->settings['allow_guests'] ?? false)
            ? route('guest.join', ['kode' => $session->code])
            : route('boards.join-link', $session->code);
    }

    /** Guru yang membuka tautan/kode kuis diarahkan ke laporannya. */
    public static function authorizeAndRedirect(Request $request, QuizSession $session): RedirectResponse
    {
        self::authorizeSession($request, $session);

        return redirect()->route('assignments.show', $session);
    }

    public static function authorizeSession(Request $request, QuizSession $session): void
    {
        $user = $request->user();
        abort_unless($session->host_id === $user->id || $user->isAdmin() || $session->quiz?->owner_id === $user->id, 403);
    }
}
