<?php

namespace App\Modules\Quiz;

use App\Models\Attachment;
use App\Models\Question;

/** Bentuk soal untuk halaman guru (dengan kunci). */
class QuestionPresenter
{
    public static function forTeacher(Question $q): array
    {
        $key = QuestionData::answerKey($q);

        return [
            'id' => $q->id,
            'bank_id' => $q->bank_id,
            'type' => $q->type,
            'type_label' => Question::TYPES[$q->type] ?? $q->type,
            'prompt' => $q->prompt,
            'explanation' => $q->explanation,
            'tags' => $q->tags ?? [],
            'difficulty' => $q->difficulty,
            'points' => $q->points,
            'options' => in_array($q->type, ['mc', 'mcm', 'tf'], true)
                ? array_map(fn ($o) => $o + ['correct' => in_array($o['id'], $key, true)], QuestionData::forStudent($q)['options'])
                : [],
            'answers' => $q->type === 'short' ? $q->data['answers'] : [],
            'pairs' => $q->type === 'match' ? QuestionData::answerKey($q) : [],
            'extras' => $q->type === 'match' ? QuestionData::forEditor($q)['extras'] : [],
            'editor' => QuestionData::forEditor($q),
            'media' => $q->relationLoaded('attachments') ? $q->attachments->map(fn (Attachment $a) => $a->toCard())->all() : [],
            'updated_at' => $q->updated_at?->toIso8601String(),
        ];
    }
}
