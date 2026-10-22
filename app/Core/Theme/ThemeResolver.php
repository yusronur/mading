<?php

namespace App\Core\Theme;

use App\Models\Board;
use App\Models\SchoolSetting;
use App\Models\Theme;
use App\Models\User;

/**
 * Prioritas tema (THM-03): kunci papan oleh guru > pilihan siswa > bawaan sekolah.
 */
class ThemeResolver
{
    public function resolve(?User $user, ?Board $board = null): array
    {
        $preference = $user?->preference;
        $theme = null;

        if ($board && $board->theme_locked && $board->theme_id) {
            $theme = $board->theme;
        }

        // Tema pilihan yang belum terbuka (XP turun/akun berubah) diabaikan
        $chosen = $preference?->theme;
        if ($chosen && $user && $chosen->xp_required > app(\App\Modules\Gamify\Gamify::class)->unlockXp($user)) {
            $chosen = null;
        }
        $theme ??= $chosen;
        $theme ??= ($board && $board->theme_id) ? $board->theme : null;
        $theme ??= SchoolSetting::first()?->defaultTheme;
        $theme ??= Theme::orderBy('sort')->first();

        $tokens = $theme?->tokens ?? BuiltinThemes::all()[0]['tokens'];
        $mode = $preference?->color_mode ?? 'system';

        if ($mode === 'system' && ! $preference) {
            $mode = $tokens['base']['preferred_mode'] ?? 'system';
        }

        return [
            'id' => $theme?->id,
            'key' => $theme?->key ?? 'neon',
            'name' => $theme?->name ?? 'Neon Gelap',
            'mode' => $mode,
            'reduce_motion' => (bool) ($preference?->reduce_motion ?? false),
            'font_scale' => (int) ($preference?->font_scale ?? 100),
            'locked' => (bool) ($board?->theme_locked && $board?->theme_id),
            'css' => ThemeCss::render($tokens),
        ];
    }
}
