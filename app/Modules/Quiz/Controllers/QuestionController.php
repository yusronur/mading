<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Modules\Media\AttachmentService;
use App\Modules\Quiz\QuestionData;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Soal: tipe PG, PGK, B/S, isian singkat, menjodohkan; media dan rumus (QZ-03, QZ-04, QZ-06). */
class QuestionController extends Controller
{
    public const MAX_MEDIA = 3;

    public function store(Request $request, QuestionBank $bank, HtmlSanitizer $html): RedirectResponse
    {
        QuestionBankController::authorizeBank($request, $bank);
        $attrs = $this->validated($request, $html);

        DB::transaction(function () use ($request, $bank, $attrs) {
            $q = $bank->questions()->create($attrs + [
                'author_id' => $request->user()->id,
                'position' => (int) $bank->questions()->max('position') + 1,
            ]);
            $this->syncMedia($request, $q);
        });

        return back()->with('success', 'Soal disimpan.');
    }

    public function update(Request $request, Question $question, HtmlSanitizer $html): RedirectResponse
    {
        $this->authorizeQuestion($request, $question);
        $attrs = $this->validated($request, $html);

        DB::transaction(function () use ($request, $question, $attrs) {
            $question->update($attrs);
            $this->syncMedia($request, $question);
        });

        return back()->with('success', 'Soal diperbarui.');
    }

    public function destroy(Request $request, Question $question): RedirectResponse
    {
        $this->authorizeQuestion($request, $question);
        $question->delete();

        return back()->with('success', 'Soal dihapus.');
    }

    public function duplicate(Request $request, Question $question): RedirectResponse
    {
        $this->authorizeQuestion($request, $question);

        DB::transaction(function () use ($question) {
            $copy = $question->replicate();
            $copy->position = (int) Question::where('bank_id', $question->bank_id)->max('position') + 1;
            $copy->save();

            $disk = Attachment::disk();
            foreach ($question->attachments as $a) {
                if (! $disk->exists($a->path)) {
                    continue;
                }
                $dir = dirname($a->path);
                $name = (string) Str::uuid7();
                $path = $dir.'/'.$name.'.'.pathinfo($a->path, PATHINFO_EXTENSION);
                $disk->copy($a->path, $path);
                $thumb = null;
                if ($a->thumb_path && $disk->exists($a->thumb_path)) {
                    $thumb = $dir.'/'.$name.'-thumb.webp';
                    $disk->copy($a->thumb_path, $thumb);
                }
                $na = $a->replicate();
                $na->fill(['attachable_id' => $copy->id, 'path' => $path, 'thumb_path' => $thumb])->save();
            }
        });

        return back()->with('success', 'Soal diduplikat.');
    }

    /** Urutkan ulang soal dalam bank. */
    public function reorder(Request $request, QuestionBank $bank): JsonResponse
    {
        QuestionBankController::authorizeBank($request, $bank);
        $ids = $request->validate(['ids' => ['required', 'array', 'max:1000'], 'ids.*' => ['uuid']])['ids'];

        DB::transaction(function () use ($bank, $ids) {
            foreach (array_values($ids) as $i => $id) {
                Question::where('bank_id', $bank->id)->whereKey($id)->update(['position' => $i + 1]);
            }
        });

        return response()->json(['ok' => true]);
    }

    /** Unggah gambar/audio/video soal sebelum soal disimpan. */
    public function upload(Request $request, AttachmentService $service): JsonResponse
    {
        $request->validate(['file' => ['required', 'file']], [], ['file' => 'berkas']);

        try {
            $a = $service->store($request->file('file'), $request->user(), null, 'questions/'.$request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        if (! in_array($a->kind, ['image', 'audio', 'video'], true)) {
            $a->purge();

            return response()->json(['message' => 'Media soal hanya gambar, audio, atau video.'], 422);
        }

        return response()->json($a->toCard(), 201);
    }

    private function validated(Request $request, HtmlSanitizer $html): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(array_keys(Question::TYPES))],
            'prompt' => ['required', 'string', 'max:20000'],
            'explanation' => ['nullable', 'string', 'max:20000'],
            'spec' => ['required', 'array'],
            'spec.options' => ['nullable', 'array', 'max:'.QuestionData::MAX_OPTIONS],
            'spec.options.*' => ['nullable', 'string', 'max:1000'],
            'spec.answers' => ['nullable', 'array', 'max:10'],
            'spec.answers.*' => ['nullable', 'string', 'max:200'],
            'spec.pairs' => ['nullable', 'array', 'max:'.QuestionData::MAX_PAIRS],
            'spec.pairs.*.left' => ['nullable', 'string', 'max:200'],
            'spec.pairs.*.right' => ['nullable', 'string', 'max:200'],
            'spec.extras' => ['nullable', 'array', 'max:'.QuestionData::MAX_EXTRAS],
            'spec.extras.*' => ['nullable', 'string', 'max:200'],
            'tags' => ['nullable', 'array', 'max:5'],
            'tags.*' => ['string', 'max:40'],
            'difficulty' => ['required', 'integer', 'between:1,3'],
            'points' => ['required', 'integer', 'between:1,100'],
            'media' => ['nullable', 'array', 'max:'.self::MAX_MEDIA],
            'media.*' => ['uuid'],
        ], [], ['prompt' => 'teks soal', 'explanation' => 'pembahasan', 'points' => 'poin', 'difficulty' => 'kesulitan']);

        $prompt = $html->clean($data['prompt']);
        if ($prompt === null && empty($data['media'])) {
            throw \Illuminate\Validation\ValidationException::withMessages(['prompt' => 'Teks soal wajib diisi.']);
        }

        return [
            'type' => $data['type'],
            'prompt' => $prompt ?? '',
            'explanation' => $html->clean($data['explanation'] ?? null),
            'data' => QuestionData::normalize($data['type'], $request->input('spec', [])),
            'tags' => array_values(array_unique(array_filter(array_map(fn ($t) => trim(strip_tags($t)), $data['tags'] ?? [])))),
            'difficulty' => $data['difficulty'],
            'points' => $data['points'],
        ];
    }

    private function syncMedia(Request $request, Question $q): void
    {
        $ids = $request->input('media', []);
        $user = $request->user();

        Attachment::whereIn('id', $ids)->where('owner_id', $user->id)
            ->where(fn ($w) => $w->whereNull('attachable_id')->orWhere(fn ($x) => $x->where('attachable_type', 'question')->where('attachable_id', $q->id)))
            ->update(['attachable_type' => 'question', 'attachable_id' => $q->id]);

        $q->attachments()->whereNotIn('id', $ids)->get()->each->purge();
    }

    private function authorizeQuestion(Request $request, Question $question): void
    {
        abort_unless($question->author_id === $request->user()->id, 403);
    }
}
