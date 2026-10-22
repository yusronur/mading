<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user?->isGuest()) {
            $id = $user->profile['guest_session'] ?? null;

            return $id ? redirect()->route('take.show', $id) : redirect()->route('guest.join');
        }

        return $user ? redirect()->route($user->dashboardRoute()) : redirect()->route('login');
    }
}
