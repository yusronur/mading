<?php

namespace App\Modules\Profile\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Modules\Gamify\Gamify;
use App\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    public function edit(Request $request, Gamify $gamify): Response
    {
        $user = $request->user();
        $xp = $gamify->unlockXp($user);
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
                'avatar' => $pref?->avatar,
                'frame' => $pref?->frame,
            ],
            'catalog' => $gamify->catalog($user),
            'xp' => $xp === PHP_INT_MAX ? null : $xp,
            'show_cosmetics' => $user->role !== \App\Models\User::ROLE_GUEST,
            'themes' => Theme::orderBy('sort')->get(['id', 'name', 'key', 'tokens', 'xp_required'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'key' => $t->key,
                    'xp_required' => $t->xp_required,
                    'locked' => $t->xp_required > $xp,
                    'preview' => [
                        'light' => array_intersect_key($t->tokens['light'] ?? [], array_flip(['bg', 'surface', 'accent', 'accent2', 'text'])),
                        'dark' => array_intersect_key($t->tokens['dark'] ?? [], array_flip(['bg', 'surface', 'accent', 'accent2', 'text'])),
                    ],
                ]),
        ]);
    }

    public function updatePreferences(Request $request, Gamify $gamify): RedirectResponse
    {
        $xp = $gamify->unlockXp($request->user());
        $data = $request->validate([
            'theme_id' => ['nullable', 'uuid', Rule::in(Theme::where('xp_required', '<=', min($xp, 1_000_000_000))->pluck('id')->all())],
            'color_mode' => ['required', 'in:system,light,dark'],
            'reduce_motion' => ['boolean'],
            'font_scale' => ['required', 'in:100,115,130'],
            'avatar' => ['nullable', Rule::in(collect(config('gamify.avatars'))->filter(fn ($a) => $a['xp'] <= $xp)->keys()->all())],
            'frame' => ['nullable', Rule::in(collect(config('gamify.frames'))->filter(fn ($a) => $a['xp'] <= $xp)->keys()->all())],
        ]);

        UserPreference::updateOrCreate(['user_id' => $request->user()->id], $data);
        $request->user()->unsetRelation('preference');

        return back()->with('success', 'Tampilan disimpan.');
    }
}
