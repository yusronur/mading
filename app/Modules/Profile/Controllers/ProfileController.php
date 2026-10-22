<?php

namespace App\Modules\Profile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user();
        $pref = $user->preference;

        return Inertia::render('Profile/Edit', [
            'profile' => [
                'name' => $user->name,
                'username' => $user->username,
                'class' => $user->isStudent() ? $user->currentClass()?->name : null,
            ],
            'preferences' => [
                'theme_id' => $pref?->theme_id,
                'color_mode' => $pref?->color_mode ?? 'system',
                'reduce_motion' => (bool) ($pref?->reduce_motion ?? false),
                'font_scale' => (int) ($pref?->font_scale ?? 100),
            ],
            'themes' => Theme::orderBy('sort')->get(['id', 'name', 'key', 'tokens', 'xp_required'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'key' => $t->key,
                    'locked' => $t->xp_required > 0, // XP aktif di Fase 2
                    'preview' => [
                        'light' => array_intersect_key($t->tokens['light'] ?? [], array_flip(['bg', 'surface', 'accent', 'accent2', 'text'])),
                        'dark' => array_intersect_key($t->tokens['dark'] ?? [], array_flip(['bg', 'surface', 'accent', 'accent2', 'text'])),
                    ],
                ]),
        ]);
    }

    public function updatePreferences(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'theme_id' => ['nullable', 'uuid', Rule::in(Theme::where('xp_required', 0)->pluck('id')->all())],
            'color_mode' => ['required', 'in:system,light,dark'],
            'reduce_motion' => ['boolean'],
            'font_scale' => ['required', 'in:100,115,130'],
        ]);

        UserPreference::updateOrCreate(['user_id' => $request->user()->id], $data);

        return back()->with('success', 'Tampilan disimpan.');
    }
}
