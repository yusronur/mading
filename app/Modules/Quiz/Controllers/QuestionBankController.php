<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Modules\Quiz\QuestionCopier;
use App\Modules\Quiz\QuestionData;
use App\Modules\Quiz\QuestionPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Bank soal pribadi guru (QZ-01). */
class QuestionBankController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->validate([
            'bank' => ['nullable', 'uuid'],
            'q' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'in:'.implode(',', array_keys(Question::TYPES))],
            'difficulty' => ['nullable', 'integer', 'between:1,3'],
            'tag' => ['nullable', 'string', 'max:40'],
            'sort' => ['nullable', 'in:manual,newest,oldest'],
        ]);
        $sort = $filters['sort'] ?? 'manual';

        $banks = QuestionBank::where('owner_id', $user->id)->withCount('questions')->orderBy('name')->get();
        // Bank publik guru lain: hanya dibaca, bisa disalin ke bank sendiri
        $shared = QuestionBank::where('is_public', true)->where('owner_id', '!=', $user->id)->with('owner:id,name')->withCount('questions')->orderBy('name')->get();
        $bank = $banks->firstWhere('id', $filters['bank'] ?? null) ?? $shared->firstWhere('id', $filters['bank'] ?? null) ?? $banks->first() ?? $shared->first();

        $questions = $bank ? $bank->questions()
            ->with('attachments')
            ->when($filters['q'] ?? null, fn ($q, $t) => $q->where(fn ($w) => $w->where('prompt', 'ilike', "%{$t}%")->orWhere('explanation', 'ilike', "%{$t}%")))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['difficulty'] ?? null, fn ($q, $d) => $q->where('difficulty', $d))
            ->when($filters['tag'] ?? null, fn ($q, $t) => $q->whereRaw('tags @> ?::jsonb', [json_encode([$t])]))
            ->when($sort === 'manual', fn ($q) => $q->orderBy('position')->orderBy('created_at'))
            ->when($sort === 'newest', fn ($q) => $q->orderByDesc('created_at')->orderByDesc('id'))
            ->when($sort === 'oldest', fn ($q) => $q->orderBy('created_at')->orderBy('id'))
            ->get()
            ->map(fn (Question $q) => QuestionPresenter::forTeacher($q)) : collect();

        $tags = $bank ? Question::where('bank_id', $bank->id)->pluck('tags')->flatten()->filter()->unique()->sort()->values() : collect();

        return Inertia::render('Quiz/Bank', [
            'banks' => $banks->map(fn (QuestionBank $b) => $this->card($b, true)),
            'shared' => $shared->map(fn (QuestionBank $b) => $this->card($b, false)),
            'bankId' => $bank?->id,
            'questions' => $questions,
            'tags' => $tags,
            'filters' => array_diff_key($filters, ['bank' => 1, 'sort' => 1]),
            'sort' => $sort,
            'types' => Question::TYPES,
            'difficulties' => Question::DIFFICULTIES,
            'subjects' => QuestionBank::where('owner_id', $user->id)->whereNotNull('subject')->distinct()->orderBy('subject')->pluck('subject'),
        ]);
    }

    /** Lembar cetak soal ringkas (kunci opsional di sisi halaman) untuk pengerjaan luring. */
    public function print(Request $request, QuestionBank $bank): Response
    {
        abort_unless($bank->owner_id === $request->user()->id || $bank->is_public, 403);

        $questions = $bank->questions()->with('attachments')->orderBy('position')->orderBy('created_at')->get()
            ->map(function (Question $q) {
                $row = QuestionPresenter::forTeacher($q);
                if ($q->type === 'match') {
                    // Urutan kartu kanan diacak tetap per soal; kunci berupa huruf kartu kanannya
                    $st = QuestionData::forStudent($q, true, crc32($q->id));
                    $index = array_flip(array_column($st['rights'], 'id'));
                    $row['lefts'] = $st['lefts'];
                    $row['rights'] = $st['rights'];
                    $row['match_key'] = array_map(fn ($p) => chr(65 + $index[$p['right_id']]), $q->data['pairs']);
                }

                return $row;
            })->values();

        return Inertia::render('Quiz/BankPrint', [
            'bank' => ['id' => $bank->id, 'name' => $bank->name, 'subject' => $bank->subject, 'grade' => $bank->grade, 'owner' => $bank->owner?->name ?? $request->user()->name],
            'questions' => $questions,
            'printedAt' => now()->translatedFormat('j F Y'),
        ]);
    }

    private function card(QuestionBank $b, bool $mine): array
    {
        return [
            'id' => $b->id, 'name' => $b->name, 'subject' => $b->subject, 'grade' => $b->grade,
            'description' => $b->description, 'count' => $b->questions_count,
            'is_public' => $b->is_public, 'mine' => $mine, 'owner' => $b->owner?->name,
        ];
    }

    /** Salin bank publik guru lain (beserta soal dan medianya) menjadi bank pribadi. */
    public function copy(Request $request, QuestionBank $bank, QuestionCopier $copier): RedirectResponse
    {
        abort_unless($bank->is_public || $bank->owner_id === $request->user()->id, 403);

        $new = $copier->copyBank($bank, $request->user());

        return redirect()->route('banks.index', ['bank' => $new->id])->with('success', "Bank disalin ke bank pribadimu ({$new->questions()->count()} soal). Kamu bebas mengubahnya.");
    }

    public function store(Request $request): RedirectResponse
    {
        $bank = QuestionBank::create($this->validated($request) + ['owner_id' => $request->user()->id]);

        return redirect()->route('banks.index', ['bank' => $bank->id])->with('success', 'Bank soal dibuat. Tambahkan soal atau impor dari Excel/Word.');
    }

    public function update(Request $request, QuestionBank $bank): RedirectResponse
    {
        $this->authorizeBank($request, $bank);
        $bank->update($this->validated($request));

        return back()->with('success', 'Bank soal disimpan.');
    }

    public function destroy(Request $request, QuestionBank $bank): RedirectResponse
    {
        $this->authorizeBank($request, $bank);
        $bank->delete();

        return redirect()->route('banks.index')->with('success', 'Bank soal dihapus.');
    }

    public static function authorizeBank(Request $request, QuestionBank $bank): void
    {
        abort_unless($bank->owner_id === $request->user()->id, 403);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'subject' => ['nullable', 'string', 'max:60'],
            'grade' => ['nullable', 'integer', 'between:1,12'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_public' => ['boolean'],
        ], [], ['name' => 'nama bank soal', 'subject' => 'mapel', 'grade' => 'kelas']);

        $data['is_public'] = (bool) ($data['is_public'] ?? false);

        return array_map(fn ($v) => is_string($v) ? strip_tags($v) : $v, $data);
    }
}
