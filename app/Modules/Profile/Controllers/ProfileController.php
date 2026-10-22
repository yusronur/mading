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
                'email' => $user->email,
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

    /** Email untuk reset sandi. Mengganti/menghapus email butuh sandi saat ini agar sesi yang terbajak tidak bisa mengalihkan reset. */
    public function updateEmail(Request $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'email' => ['nullable', 'string', 'email:rfc', 'max:150'],
            'current_password' => ['required', 'string'],
        ], ['email.email' => 'Format email tidak valid.'], ['current_password' => 'sandi saat ini']);

        if (! \Illuminate\Support\Facades\Hash::check($data['current_password'], $user->password)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['current_password' => 'Sandi saat ini tidak sesuai.']);
        }

        $user->forceFill(['email' => ! empty($data['email']) ? mb_strtolower(trim($data['email'])) : null])->save();
        \App\Models\AuditLog::record('user.email_changed', $user, ['username' => $user->username]);

        return back()->with('success', $user->email ? 'Email disimpan. Kamu bisa reset sandi lewat email ini.' : 'Email dihapus.');
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
