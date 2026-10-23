<?php

namespace App\Modules\Board\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Attachment;
use App\Models\Board;
use App\Models\Post;
use App\Modules\Board\Moderation;
use App\Modules\Board\Positions;
use App\Modules\Media\LinkPreview;
use App\Support\HtmlSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PostController extends Controller
{
    public function __construct(private HtmlSanitizer $sanitizer, private LinkPreview $links, private Moderation $moderation) {}

    public function store(Request $request, Board $board): RedirectResponse
    {
        Gate::authorize('post', $board);
        $data = $this->validated($request, $board);
        $user = $request->user();

        // Batas kartu per siswa per papan (MOD-08)
        $max = $board->maxPostsPerStudent();
        if ($max > 0 && ! Gate::allows('moderate', $board) && $board->posts()->where('author_id', $user->id)->count() >= $max) {
            throw ValidationException::withMessages(['body' => "Kamu sudah menempel {$max} kartu di papan ini (batas dari guru)."]);
        }

        $decision = $this->moderation->decide($board, $user, (string) $data['title'], (string) $data['body'], (string) $data['link'], $this->checklistText($data['checklist']));

        $sectionId = $data['section_id'] ?? null;
        if ($board->format === 'shelf' && ! $sectionId) {
            $sectionId = $board->sections()->value('id');
        }

        // Urutan manual: kartu baru di paling atas kolomnya
        $min = $board->posts()->where('section_id', $sectionId)->min('position');

        DB::transaction(function () use ($request, $board, $data, $sectionId, $min, $decision) {
            $post = $board->posts()->create([
                'author_id' => $request->user()->id,
                'section_id' => $sectionId,
                // Anonim hanya bila guru mengizinkannya di pengaturan papan
                'is_anonymous' => (bool) ($data['is_anonymous'] ?? false) && $board->setting('allow_anonymous', false),
                'title' => $data['title'],
                'body' => $data['body'],
                'color' => $data['color'] ?? null,
                'link' => $this->linkData($board, $data['link'] ?? null),
                'checklist' => $data['checklist'],
                'status' => $decision['status'],
                'moderation_reason' => $decision['moderation_reason'],
                'position' => Positions::between(null, $min === null ? null : (float) $min),
            ]);

            $this->syncAttachments($post, $board, $request, $data['attachment_ids'] ?? []);
        });

        $board->touch();

        return back()->with('success', $decision['status'] === 'pending'
            ? ($decision['moderation_reason'] === 'kata tidak pantas'
                ? 'Kartu ditahan karena ada kata yang kurang pantas. Guru akan memeriksanya.'
                : 'Kartu terkirim dan menunggu persetujuan guru.')
            : 'Kartu terkirim!');
    }

    public function update(Request $request, Board $board, Post $post): RedirectResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('updatePost', [$board, $post]);
        $data = $this->validated($request, $board, $post);
        $decision = $this->moderation->decide($board, $request->user(), (string) $data['title'], (string) $data['body'], (string) $data['link'], $this->checklistText($data['checklist']));

        DB::transaction(function () use ($request, $board, $post, $data, $decision) {
            $oldLink = $post->link;
            $newUrl = $data['link'] ?? null;

            $post->fill([
                'title' => $data['title'],
                'body' => $data['body'],
                'color' => $data['color'] ?? null,
                'link' => ($oldLink['url'] ?? null) === $newUrl ? $oldLink : $this->linkData($board, $newUrl),
                'checklist' => $data['checklist'],
                'edited_at' => now(),
            ]);

            // Penulis (bukan moderator) mengubah kartu: periksa ulang
            if ($decision['status'] === 'pending') {
                $post->status = 'pending';
                $post->moderation_reason = $decision['moderation_reason'];
            }

            if (($oldLink['image_path'] ?? null) && ($post->link['image_path'] ?? null) !== $oldLink['image_path']) {
                Attachment::disk()->delete($oldLink['image_path']);
            }

            // Penulis asli tidak bisa diubah; guru boleh mengubah isi kartu siswa
            if ($post->author_id === $request->user()->id && $board->setting('allow_anonymous', false)) {
                $post->is_anonymous = (bool) ($data['is_anonymous'] ?? $post->is_anonymous);
            }

            $post->save();
            $this->syncAttachments($post, $board, $request, $data['attachment_ids'] ?? []);
        });

        $board->touch();

        return back()->with('success', 'Kartu diperbarui.');
    }

    /** Pratinjau tautan untuk form kartu (sebelum disimpan). */
    public function previewLink(Request $request, Board $board): JsonResponse
    {
        Gate::authorize('post', $board);
        $data = $request->validate(['url' => ['required', 'string', 'max:2000']]);

        $preview = $this->links->preview($data['url']);

        return response()->json($preview, isset($preview['error']) ? 422 : 200);
    }

    /** Gambar pratinjau tautan yang sudah disimpan di server sekolah. */
    public function linkImage(Request $request, Board $board, Post $post): Response
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('viewPost', [$board, $post]);
        $path = $post->link['image_path'] ?? null;
        abort_unless($path && Attachment::disk()->exists($path), 404);

        return response()->file(Attachment::disk()->path($path), [
            'Content-Type' => 'image/webp',
            'Cache-Control' => 'private, max-age=604800',
        ]);
    }

    /**
     * Pindahkan kartu (geser) ke kolom lain dan/atau di antara dua kartu.
     * after_id = kartu tepat di atasnya, before_id = kartu tepat di bawahnya.
     */
    public function move(Request $request, Board $board, Post $post): RedirectResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('manage', $board);

        $data = $request->validate([
            'section_id' => ['nullable', 'uuid', Rule::exists('board_sections', 'id')->where('board_id', $board->id)],
            'after_id' => ['nullable', 'uuid'],
            'before_id' => ['nullable', 'uuid'],
        ]);

        $neighbor = fn (?string $id) => $id ? $board->posts()->whereKey($id)->value('position') : null;
        $after = $neighbor($data['after_id'] ?? null);
        $before = $neighbor($data['before_id'] ?? null);

        $post->forceFill([
            'section_id' => array_key_exists('section_id', $data) ? $data['section_id'] : $post->section_id,
            'position' => Positions::between($after === null ? null : (float) $after, $before === null ? null : (float) $before),
        ])->save();

        return back();
    }

    public function pin(Board $board, Post $post): RedirectResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('manage', $board);

        $post->forceFill(['is_pinned' => ! $post->is_pinned])->save();

        return back()->with('success', $post->is_pinned ? 'Kartu disematkan di atas.' : 'Sematan dilepas.');
    }

    public function destroy(Request $request, Board $board, Post $post): RedirectResponse
    {
        $this->ensureBelongs($board, $post);
        Gate::authorize('deletePost', [$board, $post]);

        DB::transaction(function () use ($post) {
            $post->attachments()->get()->each->purge();
            if ($path = $post->link['image_path'] ?? null) {
                Attachment::disk()->delete($path);
            }
            $post->delete();
        });

        return back()->with('success', 'Kartu dihapus.');
    }

    private function validated(Request $request, Board $board, ?Post $post = null): array
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:40000'],
            'color' => ['nullable', Rule::in(Post::COLORS)],
            'link' => ['nullable', 'string', 'max:2000'],
            'is_anonymous' => ['boolean'],
            'attachment_ids' => ['array', 'max:10'],
            'attachment_ids.*' => ['uuid'],
            'section_id' => ['nullable', 'uuid', Rule::exists('board_sections', 'id')->where('board_id', $board->id)],
            'checklist' => ['nullable', 'array', 'max:'.Post::CHECKLIST_MAX],
            'checklist.*.text' => ['nullable', 'string', 'max:200'],
            'checklist.*.done' => ['boolean'],
        ], [], ['title' => 'judul', 'body' => 'isi', 'link' => 'tautan', 'attachment_ids' => 'lampiran', 'checklist' => 'daftar centang', 'checklist.*.text' => 'butir daftar']);

        // Butir kosong dibuang; hanya teks polos yang disimpan
        $items = collect($data['checklist'] ?? [])
            ->map(fn ($i) => ['text' => trim(strip_tags((string) ($i['text'] ?? ''))), 'done' => (bool) ($i['done'] ?? false)])
            ->filter(fn ($i) => $i['text'] !== '')->values()->all();
        $data['checklist'] = $items ?: null;

        $data['title'] = ! empty($data['title']) ? trim(strip_tags($data['title'])) : null;
        $data['body'] = $this->sanitizer->clean($data['body'] ?? null);

        if (! empty($data['link']) && ! $this->links->validUrl(trim($data['link']))) {
            throw ValidationException::withMessages(['link' => 'Tautan harus diawali http:// atau https://.']);
        }
        $data['link'] = ! empty($data['link']) ? trim($data['link']) : null;

        if (mb_strlen(HtmlSanitizer::toText($data['body'])) > 5000) {
            throw ValidationException::withMessages(['body' => 'Isi kartu maksimal 5.000 karakter.']);
        }

        if (! $data['title'] && ! $data['body'] && ! $data['link'] && empty($data['attachment_ids']) && ! $data['checklist']) {
            throw ValidationException::withMessages(['body' => 'Isi judul, teks, daftar, tautan, atau lampiran.']);
        }

        return $data;
    }

    /**
     * Centang/batal satu butir daftar. Penulis kartu dan pengelola papan boleh;
     * di papan kolaboratif (allow_check_all) semua anggota boleh mencentang.
     */
    public function check(Request $request, Board $board, Post $post, int $index): JsonResponse
    {
        $this->ensureBelongs($board, $post);
        $user = $request->user();
        $can = Gate::forUser($user)->allows('updatePost', [$board, $post])
            || ($board->setting('checklist_all', false) && Gate::forUser($user)->allows('post', $board));
        abort_unless($can, 403);

        $done = $request->validate(['done' => ['required', 'boolean']])['done'];

        // Kunci baris supaya dua klik bersamaan tidak saling menimpa
        $items = DB::transaction(function () use ($post, $index, $done) {
            $fresh = Post::whereKey($post->id)->lockForUpdate()->first();
            $items = $fresh->checklist ?? [];
            abort_unless(isset($items[$index]), 404);
            $items[$index]['done'] = (bool) $done;
            $fresh->forceFill(['checklist' => $items])->save();

            return $items;
        });
        $board->touch();

        return response()->json(['checklist' => $items]);
    }

    private function checklistText(?array $items): string
    {
        return collect($items ?? [])->pluck('text')->implode("\n");
    }

    /** Pasang lampiran milik pengguna (yang belum terpakai) dan lepas yang dibuang. */
    private function syncAttachments(Post $post, Board $board, Request $request, array $ids): void
    {
        $ids = array_values(array_unique($ids));

        // Lampiran yang dihapus dari kartu
        $post->attachments()->whereNotIn('id', $ids ?: ['00000000-0000-0000-0000-000000000000'])->get()->each->purge();

        if (! $ids) {
            return;
        }

        $fresh = Attachment::whereIn('id', $ids)
            ->whereNull('attachable_id')
            ->where('owner_id', $request->user()->id)
            ->where('board_id', $board->id)
            ->get();

        foreach ($fresh as $a) {
            $a->forceFill(['attachable_type' => 'post', 'attachable_id' => $post->id])->save();
        }
    }

    private function linkData(Board $board, ?string $url): ?array
    {
        if (! $url) {
            return null;
        }

        $preview = $this->links->preview($url);
        unset($preview['error']);

        if ($bytes = $this->links->downloadImage($preview['image'] ?? null)) {
            $path = 'schools/'.$board->school_id.'/boards/'.$board->id.'/link-'.Str::uuid7().'.webp';
            Attachment::disk()->put($path, $bytes);
            $preview['image_path'] = $path;
        }
        unset($preview['image']);

        return $preview + ['url' => $url, 'type' => 'link'];
    }

    private function ensureBelongs(Board $board, Post $post): void
    {
        abort_unless($post->board_id === $board->id, 404);
    }
}
