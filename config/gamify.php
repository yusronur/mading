<?php

/*
| Aturan gamifikasi (GAM-01..07). XP hanya untuk aktivitas bermakna dan dibatasi
| per hari agar tidak bisa dikejar dengan spam. Semua angka bisa disesuaikan di sini.
*/
return [
    // Total XP maksimal per siswa per hari
    'daily_cap' => 150,

    // Poin dasar per jenis kejadian
    'xp' => [
        'post' => 10,       // kartu tayang (otomatis atau disetujui guru)
        'comment' => 3,     // komentar bermakna di kartu orang lain
        'reaction' => 1,    // memberi reaksi
        'activity' => 2,    // ikut poll / word cloud
        'quiz' => 10,       // selesai mengerjakan kuis (+ bonus nilai sampai 20)
        'live' => 10,       // ikut kuis live (+ bonus ketepatan sampai 20)
        'podium' => [1 => 30, 2 => 20, 3 => 10], // juara kuis live
    ],
    'quiz_bonus_max' => 20,

    // Batas XP per hari untuk jenis yang mudah di-spam
    'category_caps' => ['post' => 40, 'comment' => 15, 'reaction' => 5, 'activity' => 10],

    // Komentar dihitung "bermakna" bila minimal sekian karakter (tanpa tag)
    'min_comment_chars' => 12,

    // XP untuk mencapai level n = level_factor * (n-1)^2
    'level_factor' => 60,
    'level_titles' => ['Pemula', 'Penjelajah', 'Pengamat', 'Kreator', 'Bintang', 'Juara', 'Legenda'],

    'reasons' => [
        'post' => 'Kartu tayang',
        'comment' => 'Komentar bermakna',
        'reaction' => 'Memberi reaksi',
        'activity' => 'Ikut aktivitas live',
        'quiz' => 'Selesai mengerjakan kuis',
        'live' => 'Ikut kuis live',
        'podium' => 'Juara kuis live',
    ],

    // Lencana (GAM-03): diberikan otomatis. `reason` + `count` = jumlah kejadian bertanda itu.
    'badges' => [
        'first_post' => ['name' => 'Kartu Pertama', 'icon' => '🌱', 'text' => 'Kartu pertamamu tayang di papan.', 'reason' => 'post', 'count' => 1],
        'feedback' => ['name' => 'Pemberi Feedback', 'icon' => '💬', 'text' => '10 komentar bermakna untuk teman.', 'reason' => 'comment', 'count' => 10],
        'idea_star' => ['name' => 'Ide Berbintang', 'icon' => '⭐', 'text' => 'Kartumu mendapat 5 reaksi dari teman.', 'reason' => 'idea_star', 'count' => 1],
        'quiz_5' => ['name' => 'Rajin Kuis', 'icon' => '📝', 'text' => 'Menyelesaikan 5 kuis.', 'reason' => 'quiz', 'count' => 5],
        'ace' => ['name' => 'Nilai Sempurna', 'icon' => '🎯', 'text' => 'Mendapat nilai 100 pada sebuah kuis.', 'reason' => 'ace', 'count' => 1],
        'podium' => ['name' => 'Juara Live', 'icon' => '🏆', 'text' => 'Masuk tiga besar kuis live.', 'reason' => 'podium', 'count' => 1],
        'activist' => ['name' => 'Aktif di Kelas', 'icon' => '🎡', 'text' => 'Ikut 10 aktivitas live (poll, word cloud).', 'reason' => 'activity', 'count' => 10],
        'streak_5' => ['name' => 'Rajin 5 Hari', 'icon' => '🔥', 'text' => 'Aktif 5 hari sekolah berturut-turut.', 'streak' => 5],
        'streak_15' => ['name' => 'Rajin 15 Hari', 'icon' => '🌋', 'text' => 'Aktif 15 hari sekolah berturut-turut.', 'streak' => 15],
        'level_5' => ['name' => 'Naik Daun', 'icon' => '🚀', 'text' => 'Mencapai level 5.', 'level' => 5],
    ],

    // Hadiah kosmetik (GAM-05): terbuka otomatis saat total XP tercapai, tidak ada "belanja".
    'avatars' => [
        'kucing' => ['emoji' => '🐱', 'name' => 'Kucing', 'xp' => 0],
        'rubah' => ['emoji' => '🦊', 'name' => 'Rubah', 'xp' => 0],
        'panda' => ['emoji' => '🐼', 'name' => 'Panda', 'xp' => 0],
        'kodok' => ['emoji' => '🐸', 'name' => 'Kodok', 'xp' => 0],
        'burung_hantu' => ['emoji' => '🦉', 'name' => 'Burung Hantu', 'xp' => 120],
        'gurita' => ['emoji' => '🐙', 'name' => 'Gurita', 'xp' => 240],
        'unicorn' => ['emoji' => '🦄', 'name' => 'Unicorn', 'xp' => 400],
        'naga' => ['emoji' => '🐲', 'name' => 'Naga', 'xp' => 600],
        'robot' => ['emoji' => '🤖', 'name' => 'Robot', 'xp' => 850],
        'roket' => ['emoji' => '🚀', 'name' => 'Roket', 'xp' => 1100],
        'alien' => ['emoji' => '👾', 'name' => 'Alien Piksel', 'xp' => 1400],
        'singa' => ['emoji' => '🦁', 'name' => 'Singa', 'xp' => 1800],
    ],
    // `ring` = nilai CSS background untuk cincin di sekeliling avatar
    'frames' => [
        'polos' => ['name' => 'Polos', 'xp' => 0, 'ring' => 'transparent'],
        'perunggu' => ['name' => 'Perunggu', 'xp' => 100, 'ring' => 'linear-gradient(135deg,#CD7F32,#8C5A1E)'],
        'perak' => ['name' => 'Perak', 'xp' => 300, 'ring' => 'linear-gradient(135deg,#E5E7EB,#8A93A3)'],
        'emas' => ['name' => 'Emas', 'xp' => 600, 'ring' => 'linear-gradient(135deg,#FFE27A,#D99A00)'],
        'pelangi' => ['name' => 'Pelangi', 'xp' => 1000, 'ring' => 'conic-gradient(#ff5a5f,#ffd23f,#7be495,#3ee0cf,#7c6bff,#ff4fd8,#ff5a5f)'],
        'api' => ['name' => 'Api', 'xp' => 1500, 'ring' => 'linear-gradient(135deg,#FFD23F,#FF7A00,#E11D48)'],
    ],
];
