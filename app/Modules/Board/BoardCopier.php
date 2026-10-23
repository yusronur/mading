<?php

namespace App\Modules\Board;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Menyalin papan beserta kolom dan (opsional) kartunya. Dipakai untuk
 * duplikat, template, dan salin ke rombel/tahun ajaran baru (Tahap 18).
 */
class BoardCopier
{
    /**
     * @param  string  $posts  none | staff (kartu buatan guru saja) | all (semua kartu tayang)
     * @param  array  $overrides  kolom papan baru (title, visibility, class_id, is_template, ...)
     */
    public function copy(Board $source, User $by, array $overrides = [], string $posts = 'none'): Board
    {
        return DB::transaction(function () use ($source, $by, $overrides, $posts) {
            $copy = $source->replicate(['code', 'secret_token', 'archived_at', 'folder_id']);
            $copy->fill([
                'owner_id' => $by->id,
                'code' => Board::generateCode(),
                'is_frozen' => false,
                'is_template' => false,
                'template_shared' => false,
            ]);
            $copy->forceFill($overrides);
            $copy->secret_token = $copy->visibility === 'link' ? Str::random(32) : null;
            // Jadwal lama tidak relevan untuk papan baru
            $settings = $copy->settings ?? [];
            unset($settings['opens_at'], $settings['closes_at']);
            $copy->settings = $settings;
            $copy->save();

            $sectionMap = [];
            foreach ($source->sections()->get() as $section) {
                $new = $section->replicate();
                $new->board_id = $copy->id;
                $new->save();
                $sectionMap[$section->id] = $new->id;
            }

            if ($posts !== 'none') {
                $this->copyPosts($source, $copy, $sectionMap, $posts === 'staff');
            }

            return $copy;
        });
    }

    private function copyPosts(Board $source, Board $copy, array $sectionMap, bool $staffOnly): void
    {
        $disk = Attachment::disk();
        $query = $source->posts()->where('status', 'published')->with('attachments');
        if ($staffOnly) {
            $query->whereHas('author', fn ($q) => $q->where('role', '!=', User::ROLE_STUDENT));
        }

        foreach ($query->get() as $post) {
            $newPost = $post->replicate(['comment_count', 'reaction_count', 'report_count']);
            $newPost->fill([
                'board_id' => $copy->id,
                'section_id' => $sectionMap[$post->section_id] ?? null,
                'link' => $post->link ? array_diff_key($post->link, ['image_path' => true]) : null,
                // daftar centang dimulai kosong lagi
                'checklist' => $post->checklist ? array_map(fn ($i) => ['text' => $i['text'], 'done' => false], $post->checklist) : null,
            ]);
            $newPost->save();

            foreach ($post->attachments as $a) {
                if (! $disk->exists($a->path)) {
                    continue;
                }
                $dir = 'schools/'.$copy->school_id.'/boards/'.$copy->id;
                $name = (string) Str::uuid7();
                $path = $dir.'/'.$name.'.'.pathinfo($a->path, PATHINFO_EXTENSION);
                $thumb = $a->thumb_path ? $dir.'/'.$name.'-thumb.webp' : null;
                $disk->copy($a->path, $path);
                if ($thumb && $disk->exists($a->thumb_path)) {
                    $disk->copy($a->thumb_path, $thumb);
                }
                $na = $a->replicate();
                $na->fill(['board_id' => $copy->id, 'attachable_id' => $newPost->id, 'path' => $path, 'thumb_path' => $thumb, 'owner_id' => $a->owner_id]);
                $na->save();
            }
        }
    }
}
