<?php

namespace App\Modules\Board;

use App\Models\Board;
use App\Models\User;
use App\Support\HtmlSanitizer;
use Illuminate\Support\Facades\Gate;

/** Menentukan status kartu/komentar baru: tayang atau ditahan (MOD-01, MOD-02). */
class Moderation
{
    public function __construct(private ProfanityFilter $filter) {}

    /** @return array{status:string, moderation_reason:?string} */
    public function decide(Board $board, User $user, string ...$texts): array
    {
        if (Gate::forUser($user)->allows('moderate', $board)) {
            return ['status' => 'published', 'moderation_reason' => null];
        }

        foreach ($texts as $text) {
            if ($this->filter->find(HtmlSanitizer::toText($text))) {
                return ['status' => 'pending', 'moderation_reason' => 'kata tidak pantas'];
            }
        }

        if ($board->requiresApproval()) {
            return ['status' => 'pending', 'moderation_reason' => 'perlu persetujuan'];
        }

        return ['status' => 'published', 'moderation_reason' => null];
    }
}
