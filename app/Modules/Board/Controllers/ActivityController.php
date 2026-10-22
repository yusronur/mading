<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardActivity;
use App\Modules\Board\Activities;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use RuntimeException;

/** Aktivitas live di papan (LIV-01..04). */
class ActivityController extends Controller
{
    public function __construct(private Activities $activities) {}

    public function show(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('view', $board);

        return response()->json($this->payload($request, $board))->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('manage', $board);
        abort_if($board->archived_at, 403);

        $d = $request->validate([
            'type' => ['required', Rule::in(array_keys(BoardActivity::TYPES))],
            'title' => ['nullable', 'string', 'max:200'],
            'seconds' => ['required_if:type,timer', 'nullable', 'integer', 'min:5', 'max:7200'],
            'question' => ['required_if:type,poll', 'nullable', 'string', 'max:200'],
            'options' => ['required_if:type,poll', 'nullable', 'array', 'min:2', 'max:6'],
            'options.*' => ['nullable', 'string', 'max:80'],
            'results' => ['nullable', Rule::in(['live', 'closed'])],
            'prompt' => ['required_if:type,cloud', 'nullable', 'string', 'max:200'],
            'max_words' => ['nullable', 'integer', 'min:1', 'max:5'],
            'source' => ['nullable', Rule::in(['class', 'list'])],
            'names' => ['nullable', 'string', 'max:5000'],
            'no_repeat' => ['nullable', 'boolean'],
        ], [
            'seconds.required_if' => 'Isi lama timer.',
            'question.required_if' => 'Tulis pertanyaan poll.',
            'options.required_if' => 'Isi minimal 2 pilihan.',
            'prompt.required_if' => 'Tulis pertanyaan untuk word cloud.',
        ]);

        try {
            if ($d['type'] === 'poll' && count(array_filter($d['options'] ?? [], fn ($o) => trim((string) $o) !== '')) < 2) {
                throw new RuntimeException('Isi minimal 2 pilihan.');
            }
            $config = $this->activities->buildConfig($board, $d['type'], $d);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->activities->start($board, $request->user(), $d['type'], $d['title'] ?? null, $config);

        return response()->json($this->payload($request, $board));
    }

    public function respond(Request $request, Board $board, BoardActivity $activity): JsonResponse
    {
        $this->ensureBelongs($board, $activity);
        Gate::authorize('interact', $board);
        $data = $request->validate(['value' => ['required']]);

        try {
            $this->activities->respond($activity, $request->user(), $data['value']);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($request, $board));
    }

    public function act(Request $request, Board $board, BoardActivity $activity): JsonResponse
    {
        $this->ensureBelongs($board, $activity);
        Gate::authorize('manage', $board);
        $data = $request->validate([
            'action' => ['required', 'string', 'max:20'],
            'seconds' => ['nullable', 'integer', 'min:5', 'max:7200'],
            'text' => ['nullable', 'string', 'max:60'],
        ]);

        try {
            $this->activities->act($activity, $data['action'], $data);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($request, $board));
    }

    public function payload(Request $request, Board $board): array
    {
        $user = $request->user();
        $activity = $this->activities->current($board);

        return [
            'activity' => $activity ? $this->activities->present($activity, $user, $this->activities->canManage($user, $board)) : null,
            'channel' => $this->activities->channel($board),
        ];
    }

    private function ensureBelongs(Board $board, BoardActivity $activity): void
    {
        abort_unless($activity->board_id === $board->id, 404);
    }
}
