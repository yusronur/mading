<?php

namespace App\Modules\Quiz\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Modules\Media\AttachmentService;
use App\Modules\Quiz\QuestionImporter;
use App\Modules\Quiz\QuestionTemplates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/** Impor soal dari Excel/Word dengan pratinjau (QZ-07). */
class QuestionImportController extends Controller
{
    private const RULES = ['file' => ['required', 'file', 'max:20480', 'mimes:xlsx,xls,csv,txt,docx,zip']];

    public function preview(Request $request, QuestionBank $bank, QuestionImporter $importer): JsonResponse
    {
        QuestionBankController::authorizeBank($request, $bank);
        $request->validate(self::RULES, [], ['file' => 'berkas']);

        try {
            $rows = $this->parse($request->file('file'), $importer);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'rows' => array_map(fn ($r) => [
                'line' => $r['line'],
                'type' => $r['type'],
                'type_label' => Question::TYPES[$r['type']] ?? '?',
                'prompt' => mb_strimwidth(preg_replace('/\s+/', ' ', $r['prompt']), 0, 160, '…'),
                'options' => count($r['options']),
                'key' => $r['key'],
                'images' => count($r['images']),
                'errors' => $r['errors'],
            ], $rows),
            'valid' => count(array_filter($rows, fn ($r) => ! $r['errors'])),
            'total' => count($rows),
        ]);
    }

    public function store(Request $request, QuestionBank $bank, QuestionImporter $importer, AttachmentService $media): RedirectResponse
    {
        QuestionBankController::authorizeBank($request, $bank);
        $request->validate(self::RULES, [], ['file' => 'berkas']);

        try {
            $rows = $this->parse($request->file('file'), $importer);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        $user = $request->user();
        $valid = array_values(array_filter($rows, fn ($r) => ! $r['errors']));
        $imageErrors = 0;
        @set_time_limit(300);

        DB::transaction(function () use ($valid, $bank, $user, $media, &$imageErrors) {
            $pos = (int) $bank->questions()->max('position');

            foreach ($valid as $r) {
                $q = $bank->questions()->create([
                    'author_id' => $user->id,
                    'type' => $r['type'],
                    'prompt' => $this->paragraphs($r['prompt']),
                    'explanation' => $r['explanation'] !== '' ? $this->paragraphs($r['explanation']) : null,
                    'data' => $r['data'],
                    'tags' => $r['tags'],
                    'difficulty' => $r['difficulty'],
                    'points' => $r['points'],
                    'position' => ++$pos,
                ]);

                foreach ($r['images'] as $img) {
                    $tmp = tempnam(sys_get_temp_dir(), 'qimg');
                    file_put_contents($tmp, $img['content']);
                    try {
                        $a = $media->store(new UploadedFile($tmp, $img['name'], null, null, true), $user, null, 'questions/'.$user->id);
                        if ($a->kind === 'image') {
                            $a->update(['attachable_type' => 'question', 'attachable_id' => $q->id]);
                        } else {
                            $a->purge();
                            $imageErrors++;
                        }
                    } catch (RuntimeException) {
                        $imageErrors++;
                    } finally {
                        @unlink($tmp);
                    }
                }
            }
        });

        $skipped = count($rows) - count($valid);
        $msg = count($valid).' soal diimpor ke "'.$bank->name.'".'
            .($skipped ? " {$skipped} baris bermasalah dilewati." : '')
            .($imageErrors ? " {$imageErrors} gambar gagal dibaca." : '');

        return back()->with(count($valid) ? 'success' : 'error', count($valid) ? $msg : 'Tidak ada soal valid untuk diimpor.');
    }

    public function template(string $type, QuestionTemplates $templates): Response
    {
        abort_unless(in_array($type, ['xlsx', 'docx'], true), 404);

        return response($type === 'xlsx' ? $templates->xlsx() : $templates->docx(), 200, [
            'Content-Type' => $type === 'xlsx'
                ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="templat-soal-madingo.'.$type.'"',
        ]);
    }

    private function parse(UploadedFile $file, QuestionImporter $importer): array
    {
        return $importer->parse($file->getRealPath(), $file->getClientOriginalExtension());
    }

    /** Teks polos (baris baru) menjadi paragraf HTML aman. */
    private function paragraphs(string $text): string
    {
        return collect(preg_split('/\n+/', trim($text)))
            ->map(fn ($l) => '<p>'.e(trim($l)).'</p>')
            ->implode('');
    }
}
