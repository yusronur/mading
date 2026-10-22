<?php

namespace App\Events;

use App\Models\QuizSession;
use Illuminate\Foundation\Events\Dispatchable;

/** Kuis live selesai (bukan siaran): dipakai modul lain, mis. gamifikasi untuk bonus juara. */
class LiveQuizFinished
{
    use Dispatchable;

    public function __construct(public QuizSession $session) {}
}
