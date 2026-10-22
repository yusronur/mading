<?php

namespace App\Modules\Quiz;

use App\Models\Attachment;
use App\Models\Question;
use App\Models\QuestionBank;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Menyalin bank/soal (termasuk berkas media) untuk guru lain. */
class QuestionCopier
{
    public function copyBank(QuestionBank $source, User $to): QuestionBank
    {
        return DB::transaction(function () use ($source, $to) {
            $copy = QuestionBank::create([
                'owner_id' => $to->id,
                'name' => Str::limit($source->name.($source->owner_id === $to->id ? ' (salinan)' : ''), 120, ''),
                'subject' => $source->subject,
                'grade' => $source->grade,
                'description' => $source->description,
                'is_public' => false, // salinan selalu pribadi
            ]);

            foreach ($source->questions()->with('attachments')->orderBy('position')->get() as $i => $q) {
                $this->copyQuestion($q, $copy, $to, $i + 1);
            }

            return $copy;
        });
    }

    public function copyQuestion(Question $q, QuestionBank $into, User $by, int $position): Question
    {
        $new = $q->replicate();
        $new->bank_id = $into->id;
        $new->author_id = $by->id;
        $new->position = $position;
        $new->save();

        $disk = Attachment::disk();
        foreach ($q->attachments as $a) {
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
            $na->fill(['attachable_id' => $new->id, 'owner_id' => $by->id, 'path' => $path, 'thumb_path' => $thumb])->save();
        }

        return $new;
    }
}
