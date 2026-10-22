<?php

namespace App\Modules\Media;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Post;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AttachmentController extends Controller
{
    /** Unggah lampiran untuk kartu (sebelum kartu disimpan). */
    public function store(Request $request, Board $board, AttachmentService $service): JsonResponse
    {
        Gate::authorize('post', $board);
        $request->validate(['file' => ['required', 'file']], [], ['file' => 'berkas']);

        try {
            $attachment = $service->store($request->file('file'), $request->user(), $board);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($attachment->toCard(), 201);
    }

    /** Lihat/unduh berkas; hak akses mengikuti papan/kartu pemiliknya. */
    public function show(Request $request, Attachment $attachment, ?string $variant = null): Response
    {
        abort_unless($this->canView($request, $attachment), 403);

        $path = $variant === 'thumb' && $attachment->thumb_path ? $attachment->thumb_path : $attachment->path;
        $disk = Attachment::disk();
        abort_unless($disk->exists($path), 404);

        $mime = $variant === 'thumb' ? 'image/webp' : $attachment->mime;
        $inline = $variant !== 'unduh' && preg_match('#^(image|audio|video)/|^application/pdf$#', $mime);

        $headers = [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes(preg_replace('/[^\w.\- ]+/u', '_', $attachment->original_name)).'"; filename*=UTF-8\'\''.rawurlencode($attachment->original_name),
            'Cache-Control' => 'private, max-age=86400',
        ];
        // Penampil PDF bawaan Chrome tidak berjalan di bawah CSP sandbox
        if ($mime !== 'application/pdf') {
            $headers['Content-Security-Policy'] = "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox";
        }

        return response()->file($disk->path($path), $headers);
    }

    /** Hapus lampiran yang belum ditempel (batal unggah). */
    public function destroy(Request $request, Attachment $attachment): JsonResponse
    {
        abort_unless($attachment->owner_id === $request->user()->id && $attachment->attachable_id === null, 403);
        $attachment->purge();

        return response()->json(['ok' => true]);
    }

    private function canView(Request $request, Attachment $attachment): bool
    {
        $user = $request->user();

        if ($attachment->owner_id === $user->id || $user->isAdmin()) {
            return true;
        }

        if ($attachment->attachable_type === 'post') {
            $post = Post::with('board')->find($attachment->attachable_id);

            return $post && $post->board && Gate::forUser($user)->allows('viewPost', [$post->board, $post]);
        }

        if ($attachment->attachable_type === 'question') {
            return \App\Modules\Quiz\QuizAccess::canViewQuestionMedia($user, $attachment->attachable_id);
        }

        return false;
    }
}
