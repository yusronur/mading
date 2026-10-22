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
            // Konfigurasi WebSocket untuk Echo; null = pakai pengecekan berkala saja
            'realtime' => fn () => $user && config('broadcasting.default') === 'reverb' && config('broadcasting.connections.reverb.key') ? [
                'key' => config('broadcasting.connections.reverb.key'),
                'host' => config('madingo.realtime.host') ?: $request->getHost(),
                'port' => config('madingo.realtime.port'),
                'scheme' => config('madingo.realtime.scheme'),
                'path' => config('madingo.realtime.path'),
            ] : null,
            'theme' => fn () => app(ThemeResolver::class)->resolve($user, $board instanceof Board ? $board : null),
            // `id` unik per pesan: sinkron papan (partial reload) tidak mengirim ulang flash,
            // dan klien mengingat props lama; id mencegah pesan lama muncul lagi sebagai toast
            'flash' => function () use ($request) {
                $success = $request->session()->get('success');
                $error = $request->session()->get('error');

                return ['success' => $success, 'error' => $error, 'id' => ($success || $error) ? (string) \Illuminate\Support\Str::uuid() : null];
            },
        ];
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim(preg_replace('/[^\pL\s]/u', '', $name)));

        return mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1).mb_substr($parts[1] ?? '', 0, 1));
    }
}
