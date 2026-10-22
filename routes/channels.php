<?php

use App\Models\QuizSession;
use App\Models\User;
use App\Modules\Quiz\LiveQuiz;
use Illuminate\Support\Facades\Broadcast;

/*
| Kanal siaran (Blueprint 8.3). Semua kanal privat dan memuat school_id agar
| tidak ada kebocoran antarsekolah; isi siaran hanya sinyal perubahan.
*/

Broadcast::channel('school.{schoolId}.live.{sessionId}', function (User $user, string $schoolId, string $sessionId) {
    if ($user->school_id !== $schoolId) {
        return false;
    }
    $session = QuizSession::find($sessionId);

    return $session !== null && app(LiveQuiz::class)->canWatch($user, $session);
});
