<!doctype html>
<html lang="id">
<body style="margin:0;padding:24px;background:#f4f4f8;font-family:Arial,Helvetica,sans-serif;color:#1b1b2f">
    <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px">
        <p style="margin:0 0 4px;font-size:13px;color:#666">{{ $schoolName }}</p>
        <h1 style="margin:0 0 16px;font-size:22px">Atur ulang sandi</h1>
        <p style="line-height:1.5">Halo <b>{{ $name }}</b>,<br>
            kami menerima permintaan mengatur ulang sandi untuk akun <b>{{ $username }}</b> di {{ $appName }}.</p>
        <p style="text-align:center;margin:28px 0">
            <a href="{{ $url }}" style="background:#5b21b6;color:#ffffff;text-decoration:none;font-weight:bold;padding:14px 26px;border-radius:12px;display:inline-block">Buat sandi baru</a>
        </p>
        <p style="line-height:1.5;font-size:14px;color:#444">Tautan ini berlaku <b>{{ $minutes }} menit</b> dan hanya bisa dipakai sekali.
            Jika tombol tidak berfungsi, salin alamat ini ke peramban:<br>
            <span style="word-break:break-all;color:#5b21b6">{{ $url }}</span></p>
        <p style="line-height:1.5;font-size:14px;color:#444">Bukan kamu yang meminta? Abaikan email ini; sandimu tidak berubah.</p>
    </div>
</body>
</html>
