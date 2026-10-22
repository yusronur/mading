<?php

namespace App\Modules\Quiz;

use App\Models\Question;
use App\Models\User;

/** Hak akses media soal: pemilik soal, guru pengampu tugas, siswa yang mengerjakan. */
class QuizAccess
{
    public static function canViewQuestionMedia(User $user, ?string $questionId): bool
    {
        $q = $questionId ? Question::withTrashed()->find($questionId) : null;

        if ($q && $q->author_id === $user->id) {
            return true;
        }

        // Soal di bank publik boleh dilihat guru lain (untuk dipakai di kuisnya)
        if ($q && ! $user->isStudent() && $q->bank()->where('is_public', true)->exists()) {
            return true;
        }

        // Peserta atau pengampu tugas kuis yang memuat soal ini (snapshot)
        $sessions = \App\Models\QuizSession::withTrashed()->whereRaw('questions @> ?::jsonb', [json_encode([['id' => $questionId]])]);

        return (clone $sessions)->where('host_id', $user->id)->exists()
            || \App\Models\QuizAttempt::where('user_id', $user->id)->whereIn('session_id', $sessions->select('id'))->exists();
    }
}
