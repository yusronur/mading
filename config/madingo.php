<?php

return [

    /*
    | Nama merek platform. Header aplikasi memakai nama tampilan sekolah
    | (school_settings.app_display_name); nama merek hanya muncul sebagai
    | "Didukung Madingo" di halaman login dan footer.
    */
    'brand' => env('APP_NAME', 'Madingo'),

    'version' => '0.2.0-alpha.1',

    'tenant' => [
        // single | domain
        'mode' => env('TENANT_MODE', 'single'),
        'default_slug' => env('TENANT_DEFAULT_SLUG', 'smpn1wajak'),
        'default_name' => env('TENANT_DEFAULT_NAME', 'SMP Negeri 1 Wajak'),
        'default_npsn' => env('TENANT_DEFAULT_NPSN'),
        'base_domain' => env('TENANT_BASE_DOMAIN', 'madingo.id'),
    ],

    // Peran database aplikasi (non-superuser, tunduk pada RLS)
    'app_db_role' => env('DB_USERNAME', 'madingo_app'),

    'auth' => [
        'max_attempts' => 5,
        'decay_seconds' => 60,
        // Masa berlaku "ingat saya" di HP siswa (AUTH-06)
        'remember_days' => (int) env('AUTH_REMEMBER_DAYS', 30),
        'default_student_password' => 'siswa123',
        'default_teacher_password' => 'guru12345',
    ],

    // Bawaan per sekolah; admin bisa mengubah di Pengaturan (school_settings.settings)
    // Alamat WebSocket (Reverb) yang dibuka peramban. Bisa beda dari REVERB_HOST/PORT
    // (alamat yang dipakai Laravel untuk mengirim siaran), mis. lewat proxy :443.
    'realtime' => [
        'host' => env('REVERB_CLIENT_HOST', env('REVERB_HOST')),
        'port' => (int) env('REVERB_CLIENT_PORT', env('REVERB_PORT', 443)),
        'scheme' => env('REVERB_CLIENT_SCHEME', env('REVERB_SCHEME', 'https')),
        'path' => env('REVERB_CLIENT_PATH', ''),
    ],

    'quiz' => [
        // Batas tamu per kuis publik (Kahoot-style); kuis lebih besar dipecah jadi beberapa tugas
        'max_guests' => (int) env('QUIZ_MAX_GUESTS', 300),
    ],

    'storage' => [
        'disk' => env('MADINGO_DISK', 'local'),
        'quota_mb' => (int) env('MADINGO_QUOTA_MB', 5120),
        'max_upload_mb' => (int) env('MADINGO_MAX_UPLOAD_MB', 20),
        'image_max_px' => 1600,
        'thumb_px' => 480,
    ],

    'import' => [
        'max_rows' => 3000,
        'max_file_kb' => 5120,
    ],
];
