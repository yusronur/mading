<?php

namespace App\Modules\Auth\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Auth\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetController extends Controller
{
    public function __construct(private PasswordReset $reset) {}

    public function forgot(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    public function send(Request $request): RedirectResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:60']], [], ['username' => 'NISN / username']);

        $this->reset->request($data['username'], $request);

        return back()->with('success', 'Jika akun ditemukan dan punya email terdaftar, tautan reset sandi sudah dikirim (berlaku '.PasswordReset::MINUTES.' menit). Cek juga folder spam. Tidak ada email? Minta admin atau wali kelas mereset sandimu.');
    }

    public function form(string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'valid' => (bool) $this->reset->find($token)]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [], ['password' => 'sandi baru']);

        if (! $this->reset->complete($token, $data['password'])) {
            return redirect()->route('password.forgot')->with('error', 'Tautan reset sudah kedaluwarsa atau sudah dipakai. Minta tautan baru.');
        }

        return redirect()->route('login')->with('success', 'Sandi berhasil diganti. Silakan masuk dengan sandi baru.');
    }
}
