<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\Quiz;
use App\Models\QuizSession;
use App\Models\SchoolClass;
use App\Modules\Quiz\QuestionPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Kuis guru: susun dari bank soal, pengaturan (QZ-08, QZ-09). */
class QuizController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $year = AcademicYear::active();

        $quizzes = Quiz::where('owner_id', $user->id)
            ->withCount('questions')
            ->with(['sessions' => fn ($q) => $q->with('schoolClass:id,name')->withCount(['attempts as done_count' => fn ($a) => $a->whereNotNull('submitted_at')->select(DB::raw('count(distinct user_id)'))])->latest()])
            ->latest('updated_at')->get();

        $classSizes = DB::table('class_members')->whereIn('class_id', $quizzes->pluck('sessions')->flatten()->pluck('class_id')->filter()->unique())
            ->groupBy('class_id')->selectRaw('class_id, count(*) n')->pluck('n', 'class_id');

        return Inertia::render('Quiz/Index', [
            'quizzes' => $quizzes->map(fn (Quiz $q) => [
                'id' => $q->id,
                'title' => $q->title,
                'description' => $q->description,
                'questions_count' => $q->questions_count,
                'settings' => $q->settings(),
                'updated_at' => $q->updated_at?->toIso8601String(),
                'sessions' => $q->sessions->map(fn (QuizSession $s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'join_url' => \App\Modules\Quiz\Controllers\AssignmentController::joinUrl($s),
                    'class_name' => $s->schoolClass?->name ?? 'Publik (kode)',
                    'opens_at' => $s->opens_at?->toIso8601String(),
                    'closes_at' => $s->closes_at?->toIso8601String(),
                    'status' => $s->status(),
                    'done' => (int) $s->done_count,
                    'students' => (int) ($classSizes[$s->class_id] ?? 0),
                ])->values(),
            ]),
            'classes' => $year ? SchoolClass::where('academic_year_id', $year->id)->orderBy('grade')->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:150']], [], ['title' => 'judul kuis']);
        $quiz = Quiz::create(['owner_id' => $request->user()->id, 'title' => strip_tags($data['title']), 'settings' => Quiz::DEFAULTS]);

        return redirect()->route('quizzes.edit', $quiz);
    }

    public function edit(Request $request, Quiz $quiz): Response
    {
        $this->authorizeQuiz($request, $quiz);
        $user = $request->user();

        return Inertia::render('Quiz/Edit', [
            'quiz' => [
                'id' => $quiz->id,
                'title' => $quiz->title,
                'description' => $quiz->description,
                'settings' => $quiz->settings(),
                'assigned' => $quiz->sessions()->exists(),
            ],
            'questions' => $quiz->questions()->with('attachments')->get()->map(fn (Question $q) => QuestionPresenter::forTeacher($q) + [
                'quiz_points' => $q->pivot->points,
            ]),
            // Bank pribadi + bank publik guru lain (hanya untuk diambil soalnya)
            'banks' => QuestionBank::visibleTo($user)->with('owner:id,name')->withCount('questions')->orderBy('name')->get()
                ->map(fn (QuestionBank $b) => [
                    'id' => $b->id, 'name' => $b->name, 'subject' => $b->subject, 'grade' => $b->grade, 'questions_count' => $b->questions_count,
                    'mine' => $b->owner_id === $user->id, 'owner' => $b->owner?->name,
                ])->values(),
            'types' => Question::TYPES,
            'difficulties' => Question::DIFFICULTIES,
        ]);
    }

    /** Daftar soal satu bank untuk dipilih (JSON). */
    public function picker(Request $request, QuestionBank $bank): JsonResponse
    {
        abort_unless($bank->owner_id === $request->user()->id || $bank->is_public, 403);

        return response()->json($bank->questions()->with('attachments')->orderBy('position')->get()
            ->map(fn (Question $q) => QuestionPresenter::forTeacher($q)));
    }

    public function update(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeQuiz($request, $quiz);
        $user = $request->user();

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            'settings.shuffle_questions' => ['boolean'],
            'settings.shuffle_options' => ['boolean'],
            'settings.duration_minutes' => ['required', 'integer', 'between:1,600'],
            'settings.attempts' => ['required', 'integer', 'between:0,10'],
            'settings.scoring' => ['required', Rule::in(['best', 'last'])],
            'settings.review' => ['required', Rule::in(['after_submit', 'after_close', 'never'])],
            'settings.show_score' => ['boolean'],
            'settings.kkm' => ['required', 'integer', 'between:0,100'],
            'questions' => ['array', 'max:200'],
            'questions.*.id' => ['required', 'uuid'],
            'questions.*.points' => ['nullable', 'integer', 'between:1,100'],
        ], [], ['title' => 'judul', 'settings.kkm' => 'KKM', 'settings.duration_minutes' => 'durasi', 'settings.attempts' => 'kesempatan']);

        // Hanya soal dari bank sendiri atau bank publik guru lain
        $ids = collect($data['questions'] ?? [])->pluck('id');
        $owned = Question::whereIn('id', $ids)->whereHas('bank', fn ($b) => $b->visibleTo($user))->pluck('id')->flip();

        DB::transaction(function () use ($quiz, $data, $owned) {
            $quiz->update([
                'title' => strip_tags($data['title']),
                'description' => isset($data['description']) ? strip_tags($data['description']) : null,
                'settings' => array_merge(Quiz::DEFAULTS, array_intersect_key($data['settings'] ?? [], Quiz::DEFAULTS)),
            ]);

            $sync = [];
            foreach (array_values($data['questions'] ?? []) as $i => $q) {
                if (isset($owned[$q['id']])) {
                    $sync[$q['id']] = ['id' => (string) Str::uuid7(), 'position' => $i + 1, 'points' => $q['points'] ?? null, 'school_id' => $quiz->school_id];
                }
            }
            $quiz->questions()->sync([]);
            $quiz->questions()->attach($sync);
        });

        return back()->with('success', 'Kuis disimpan. Tugas yang sudah berjalan tetap memakai versi lamanya.');
    }

    public function duplicate(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeQuiz($request, $quiz);

        $copy = DB::transaction(function () use ($quiz) {
            $copy = $quiz->replicate();
            $copy->title = Str::limit($quiz->title.' (salinan)', 150, '');
            $copy->save();
            $copy->questions()->attach($quiz->questions->mapWithKeys(fn ($q) => [$q->id => [
                'id' => (string) Str::uuid7(), 'position' => $q->pivot->position, 'points' => $q->pivot->points, 'school_id' => $quiz->school_id,
            ]])->all());

            return $copy;
        });

        return redirect()->route('quizzes.edit', $copy)->with('success', 'Kuis disalin.');
    }

    public function destroy(Request $request, Quiz $quiz): RedirectResponse
    {
        $this->authorizeQuiz($request, $quiz);

        $used = \App\Models\QuizAttempt::whereIn('session_id', $quiz->sessions()->pluck('id'))->exists();
        if ($used) {
            return back()->with('error', 'Kuis ini sudah dikerjakan siswa, jadi tidak dihapus agar nilainya tetap aman. Buat salinan bila ingin mengubah besar-besaran.');
        }

        $quiz->sessions()->delete();
        $quiz->delete();

        return redirect()->route('quizzes.index')->with('success', 'Kuis dihapus.');
    }

    public static function authorizeQuiz(Request $request, Quiz $quiz): void
    {
        abort_unless($quiz->owner_id === $request->user()->id, 403);
    }
}
