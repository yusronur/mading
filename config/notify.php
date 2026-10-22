<?php

/**
 * Notifikasi: lonceng (selalu) dan Web Push (opsional, gratis lewat standar browser).
 * Push sengaja dibatasi agar tidak membanjiri: lihat Notify::policy().
 */
return [
    // Kunci VAPID (buat dengan: php artisan madingo:vapid). Kosong = push nonaktif, lonceng tetap jalan.
    'vapid' => [
        'public' => env('VAPID_PUBLIC_KEY'),
        'private' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:'.env('MAIL_FROM_ADDRESS', 'admin@example.com')),
    ],

    'daily_cap' => 5,          // push per orang per hari (di luar pesan manual admin)
    'quiet_from' => 21,        // jam tenang: push ditahan, dikirim sebagai ringkasan pagi
    'quiet_until' => 6,
    'keep_days' => 30,         // riwayat lonceng
    'digest_hour' => 15,       // ringkasan harian guru
    'reminder_hour' => 7,      // pengingat tenggat H-1

    // Hanya layanan push resmi peramban yang boleh dihubungi (mencegah SSRF dari endpoint palsu)
    'push_hosts' => [
        'fcm.googleapis.com', 'android.googleapis.com', 'updates.push.services.mozilla.com',
        '*.push.services.mozilla.com', 'web.push.apple.com', '*.push.apple.com',
        '*.notify.windows.com', '*.push.microsoft.com',
    ],

    // Jenis notifikasi. push=false berarti hanya masuk lonceng.
    'kinds' => [
        'quiz_assigned' => ['label' => 'Kuis atau tugas baru', 'roles' => ['student'], 'push' => true],
        'quiz_due' => ['label' => 'Pengingat tenggat (H-1)', 'roles' => ['student'], 'push' => true],
        'grade_ready' => ['label' => 'Nilai sudah keluar', 'roles' => ['student'], 'push' => true],
        'live_started' => ['label' => 'Kuis live dimulai', 'roles' => ['student'], 'push' => true],
        'digest' => ['label' => 'Ringkasan pengumpulan harian', 'roles' => ['teacher'], 'push' => true],
        'system' => ['label' => 'Masalah sistem', 'roles' => ['school_admin'], 'push' => true],
        'manual' => ['label' => 'Pesan dari admin', 'roles' => ['school_admin', 'teacher', 'student'], 'push' => true, 'fixed' => true],
        'comment' => ['label' => 'Komentar', 'roles' => ['school_admin', 'teacher', 'student'], 'push' => false],
        'reaction' => ['label' => 'Suka', 'roles' => ['school_admin', 'teacher', 'student'], 'push' => false],
        'submitted' => ['label' => 'Siswa mengumpulkan', 'roles' => ['school_admin', 'teacher'], 'push' => false],
        'reward' => ['label' => 'Level dan lencana', 'roles' => ['student'], 'push' => false],
        'overdue' => ['label' => 'Tenggat terlewat', 'roles' => ['school_admin', 'teacher'], 'push' => false],
    ],
];
