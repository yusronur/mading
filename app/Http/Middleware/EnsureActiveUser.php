<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Akun yang dinonaktifkan admin langsung keluar dari sesi yang sedang berjalan. */
class EnsureActiveUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->status !== 'active') {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return redirect()->route('login')->with('error', 'Akun Anda tidak aktif. Hubungi admin sekolah.');
        }

        return $next($request);
    }
}
