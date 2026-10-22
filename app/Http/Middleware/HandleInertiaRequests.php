<?php

namespace App\Http\Middleware;

use App\Core\Tenancy\TenantContext;
use App\Core\Theme\ThemeResolver;
use App\Models\Board;
use App\Models\SchoolSetting;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    public function share(Request $request): array
    {
        $user = $request->user();
        $school = app(TenantContext::class)->school();
        $setting = $school ? SchoolSetting::first() : null;
        $board = $request->route('board');

        return [
            ...parent::share($request),
            'app' => [
                'brand' => config('madingo.brand'),
                'version' => config('madingo.version'),
            ],
            'school' => $school ? [
                'name' => $school->name,
                'display_name' => $setting?->app_display_name ?? 'Mading',
            ] : null,
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'role_label' => User::ROLE_LABELS[$user->role] ?? $user->role,
                    'initials' => $this->initials($user->name),
                    'is_homeroom' => $user->isTeacher() && $user->homeroomClassIds() !== [],
                ] : null,
            ],
            'theme' => fn () => app(ThemeResolver::class)->resolve($user, $board instanceof Board ? $board : null),
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/[^\pL\s]/u', '', $name)));

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }
}
