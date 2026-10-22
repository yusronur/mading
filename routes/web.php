<?php

use App\Modules\Admin\Controllers\AcademicYearController;
use App\Modules\Admin\Controllers\AuditController;
use App\Modules\Admin\Controllers\ClassController;
use App\Modules\Admin\Controllers\CredentialController;
use App\Modules\Admin\Controllers\DashboardController;
use App\Modules\Admin\Controllers\ImportController;
use App\Modules\Admin\Controllers\SettingsController;
use App\Modules\Admin\Controllers\UserController;
use App\Modules\Auth\Controllers\HomeController;
use App\Modules\Auth\Controllers\LoginController;
use App\Modules\Auth\Controllers\PasswordController;
use App\Modules\Auth\Controllers\QrLoginController;
use App\Modules\Board\Controllers\BoardController;
use App\Modules\Board\Controllers\PostController;
use App\Modules\Board\Controllers\ExportController;
use App\Modules\Board\Controllers\InteractionController;
use App\Modules\Board\Controllers\LibraryController;
use App\Modules\Board\Controllers\ModerationController;
use App\Modules\Board\Controllers\SectionController;
use App\Modules\Media\AttachmentController;
use App\Modules\Profile\Controllers\ProfileController;
use App\Modules\Quiz\Controllers\AssignmentController;
use App\Modules\Quiz\Controllers\QuestionBankController;
use App\Modules\Quiz\Controllers\GuestJoinController;
use App\Modules\Quiz\Controllers\QuizController;
use App\Modules\Quiz\Controllers\TakeController;
use App\Modules\Quiz\Controllers\QuestionController;
use App\Modules\Quiz\Controllers\QuestionImportController;
use Illuminate\Support\Facades\Route;

/*
| URL memakai Bahasa Indonesia agar mudah dibaca guru dan siswa;
| nama rute memakai bahasa Inggris untuk kode.
*/

// Tamu kuis publik: kode + nama, tanpa akun (dibatasi ketat, lihat GuestJoinController)
Route::get('/tamu', [GuestJoinController::class, 'show'])->name('guest.join');
Route::post('/tamu', [GuestJoinController::class, 'store'])->middleware('throttle:guest-join')->name('guest.store');

Route::middleware('guest')->group(function () {
    Route::get('/masuk', [LoginController::class, 'create'])->name('login');
    Route::post('/masuk', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/keluar', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/ganti-sandi', [PasswordController::class, 'edit'])->name('password.change');
    Route::put('/ganti-sandi', [PasswordController::class, 'update'])->name('password.update');
});

Route::get('/', HomeController::class)->name('home');

// Masuk dengan kartu QR (AUTH-03): tanpa middleware guest agar HP bersama bisa berganti akun
Route::get('/masuk/qr/{token}', QrLoginController::class)->where('token', '[A-Za-z0-9]{40}')
    ->middleware('throttle:qr-login')->name('login.qr');

Route::middleware(['auth', 'password.changed'])->group(function () {
    // Profil dan tampilan (semua peran)
    Route::get('/profil', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('/profil/tampilan', [ProfileController::class, 'updatePreferences'])->name('profile.preferences');

    // Papan
    Route::post('/papan', [BoardController::class, 'store'])->name('boards.store');
    Route::post('/papan/gabung', [BoardController::class, 'join'])->name('boards.join');
    Route::get('/gabung/{code}', [BoardController::class, 'joinLink'])->where('code', '[A-Za-z0-9]{6}')->name('boards.join-link');
    Route::post('/papan/{board}/arsip', [LibraryController::class, 'archive'])->name('boards.archive');
    Route::post('/papan/{board}/duplikat', [LibraryController::class, 'duplicate'])->name('boards.duplicate');
    Route::post('/papan/{board}/folder', [LibraryController::class, 'moveToFolder'])->name('boards.move-folder');
    Route::post('/folder', [LibraryController::class, 'storeFolder'])->name('folders.store');
    Route::put('/folder/{folder}', [LibraryController::class, 'updateFolder'])->name('folders.update');
    Route::delete('/folder/{folder}', [LibraryController::class, 'destroyFolder'])->name('folders.destroy');
    Route::get('/papan/{board}/kolaborator', [LibraryController::class, 'collaborators'])->name('boards.collaborators');
    Route::post('/papan/{board}/kolaborator', [LibraryController::class, 'addCollaborator'])->name('boards.collaborators.add');
    Route::delete('/papan/{board}/kolaborator/{user}', [LibraryController::class, 'removeCollaborator'])->name('boards.collaborators.remove');
    Route::get('/papan/{board}/ekspor.{format}', [ExportController::class, 'spreadsheet'])->where('format', 'xlsx|csv')->name('boards.export');
    Route::get('/papan/{board}/cetak', [ExportController::class, 'print'])->name('boards.print');
    Route::get('/papan/{board}', [BoardController::class, 'show'])->name('boards.show');
    Route::post('/papan/{board}/kartu', [PostController::class, 'store'])
        ->middleware('throttle:posts')->name('posts.store');
    Route::put('/papan/{board}', [BoardController::class, 'update'])->name('boards.update');
    Route::delete('/papan/{board}', [BoardController::class, 'destroy'])->name('boards.destroy');
    Route::post('/papan/{board}/kode-baru', [BoardController::class, 'regenerateCode'])->name('boards.regenerate-code');
    Route::get('/papan/{board}/sinkron', [BoardController::class, 'sync'])->middleware('throttle:sync')->name('boards.sync');
    Route::put('/papan/{board}/kartu/{post}', [PostController::class, 'update'])->name('posts.update');
    Route::post('/papan/{board}/pratinjau-tautan', [PostController::class, 'previewLink'])->middleware('throttle:30,1')->name('posts.preview-link');
    Route::get('/papan/{board}/kartu/{post}/gambar-tautan', [PostController::class, 'linkImage'])->name('posts.link-image');
    Route::post('/papan/{board}/lampiran', [AttachmentController::class, 'store'])->middleware('throttle:uploads')->name('attachments.store');
    Route::get('/berkas/{attachment}/{variant?}', [AttachmentController::class, 'show'])->where('variant', 'thumb|unduh')->name('attachments.show');
    Route::delete('/berkas/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::get('/papan/{board}/kartu/{post}/komentar', [InteractionController::class, 'comments'])->name('comments.index');
    Route::post('/papan/{board}/kartu/{post}/komentar', [InteractionController::class, 'storeComment'])->middleware('throttle:posts')->name('comments.store');
    Route::delete('/papan/{board}/komentar/{comment}', [InteractionController::class, 'destroyComment'])->name('comments.destroy');
    Route::post('/papan/{board}/kartu/{post}/reaksi', [InteractionController::class, 'react'])->middleware('throttle:sync')->name('posts.react');
    Route::get('/papan/{board}/moderasi', [ModerationController::class, 'queue'])->name('moderation.queue');
    Route::post('/papan/{board}/kartu/{post}/moderasi', [ModerationController::class, 'post'])->name('moderation.post');
    Route::post('/papan/{board}/komentar/{comment}/moderasi', [ModerationController::class, 'comment'])->name('moderation.comment');
    Route::post('/papan/{board}/kartu/{post}/lapor', [ModerationController::class, 'report'])->middleware('throttle:posts')->name('posts.report');
    Route::get('/p/{token}', [ModerationController::class, 'openLink'])->where('token', '[A-Za-z0-9]{20,40}')->name('boards.link');
    Route::post('/papan/{board}/kartu/{post}/pindah', [PostController::class, 'move'])->name('posts.move');
    Route::post('/papan/{board}/kartu/{post}/sematkan', [PostController::class, 'pin'])->name('posts.pin');
    Route::post('/papan/{board}/kolom', [SectionController::class, 'store'])->name('sections.store');
    Route::put('/papan/{board}/kolom/{section}', [SectionController::class, 'update'])->name('sections.update');
    Route::post('/papan/{board}/kolom/{section}/pindah', [SectionController::class, 'move'])->name('sections.move');
    Route::delete('/papan/{board}/kolom/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
    Route::delete('/papan/{board}/kartu/{post}', [PostController::class, 'destroy'])->name('posts.destroy');

    // Kartu akun: admin (semua rombel) dan wali kelas (rombelnya sendiri)
    Route::prefix('kartu-akun')->name('credentials.')->middleware('role:teacher,school_admin')->group(function () {
        Route::get('/', [CredentialController::class, 'index'])->name('index');
        Route::post('/buat', [CredentialController::class, 'generate'])->name('generate');
        Route::post('/reset', [CredentialController::class, 'reset'])->name('reset');
        Route::post('/qr', [CredentialController::class, 'qr'])->name('qr');
        Route::get('/cetak', [CredentialController::class, 'print'])->name('print');
    });

    // Bank soal (QZ-01..07): milik pribadi tiap guru
    Route::middleware('role:teacher,school_admin')->group(function () {
        Route::get('/bank-soal', [QuestionBankController::class, 'index'])->name('banks.index');
        Route::post('/bank-soal', [QuestionBankController::class, 'store'])->name('banks.store');
        Route::get('/bank-soal/templat/{type}', [QuestionImportController::class, 'template'])->where('type', 'xlsx|docx')->name('banks.template');
        Route::post('/bank-soal/media', [QuestionController::class, 'upload'])->middleware('throttle:uploads')->name('questions.upload');
        Route::put('/bank-soal/{bank}', [QuestionBankController::class, 'update'])->name('banks.update');
        Route::get('/bank-soal/{bank}/cetak', [QuestionBankController::class, 'print'])->name('banks.print');
        Route::post('/bank-soal/{bank}/salin', [QuestionBankController::class, 'copy'])->name('banks.copy');
        Route::delete('/bank-soal/{bank}', [QuestionBankController::class, 'destroy'])->name('banks.destroy');
        Route::post('/bank-soal/{bank}/soal', [QuestionController::class, 'store'])->name('questions.store');
        Route::post('/bank-soal/{bank}/urutan', [QuestionController::class, 'reorder'])->name('questions.reorder');
        Route::post('/bank-soal/{bank}/impor/pratinjau', [QuestionImportController::class, 'preview'])->name('banks.import.preview');
        Route::post('/bank-soal/{bank}/impor', [QuestionImportController::class, 'store'])->name('banks.import');
        Route::put('/soal/{question}', [QuestionController::class, 'update'])->name('questions.update');
        Route::delete('/soal/{question}', [QuestionController::class, 'destroy'])->name('questions.destroy');
        Route::post('/soal/{question}/duplikat', [QuestionController::class, 'duplicate'])->name('questions.duplicate');

        // Kuis mandiri (QZ-08, 09, 14, 15)
        Route::get('/kuis', [QuizController::class, 'index'])->name('quizzes.index');
        Route::post('/kuis', [QuizController::class, 'store'])->name('quizzes.store');
        Route::get('/kuis/pilih-soal/{bank}', [QuizController::class, 'picker'])->name('quizzes.picker');
        Route::get('/kuis/{quiz}/ubah', [QuizController::class, 'edit'])->name('quizzes.edit');
        Route::put('/kuis/{quiz}', [QuizController::class, 'update'])->name('quizzes.update');
        Route::delete('/kuis/{quiz}', [QuizController::class, 'destroy'])->name('quizzes.destroy');
        Route::post('/kuis/{quiz}/duplikat', [QuizController::class, 'duplicate'])->name('quizzes.duplicate');
        Route::post('/kuis/{quiz}/tugaskan', [AssignmentController::class, 'store'])->name('assignments.store');
        Route::get('/tugas-kuis/{session}', [AssignmentController::class, 'show'])->name('assignments.show');
        Route::get('/tugas-kuis/{session}/progres', [AssignmentController::class, 'progress'])->middleware('throttle:sync')->name('assignments.progress');
        Route::put('/tugas-kuis/{session}', [AssignmentController::class, 'update'])->name('assignments.update');
        Route::delete('/tugas-kuis/{session}', [AssignmentController::class, 'destroy'])->name('assignments.destroy');
        Route::get('/tugas-kuis/{session}/ekspor', [AssignmentController::class, 'export'])->name('assignments.export');
        Route::get('/tugas-kuis/{session}/jawaban/{attempt}', [AssignmentController::class, 'attempt'])->name('assignments.attempt');
        Route::post('/tugas-kuis/{session}/ulang/{user}', [AssignmentController::class, 'reset'])->name('assignments.reset');
    });

    // Siswa mengerjakan kuis
    Route::get('/kerjakan/{session}', [TakeController::class, 'show'])->name('take.show');
    Route::post('/kerjakan/{session}/mulai', [TakeController::class, 'start'])->middleware('role:student,guest')->name('take.start');
    Route::get('/lembar/{attempt}', [TakeController::class, 'attempt'])->name('take.attempt');
    Route::post('/lembar/{attempt}/jawab', [TakeController::class, 'answer'])->middleware('throttle:quiz-answers')->name('take.answer');
    Route::post('/lembar/{attempt}/fokus', [TakeController::class, 'focus'])->middleware('throttle:quiz-answers')->name('take.focus');
    Route::post('/lembar/{attempt}/kumpulkan', [TakeController::class, 'submit'])->name('take.submit');
    Route::get('/hasil/{attempt}', [TakeController::class, 'result'])->name('take.result');

    // Guru
    Route::get('/guru', [BoardController::class, 'teacherDashboard'])
        ->middleware('role:teacher,school_admin')->name('teacher.dashboard');

    // Siswa
    Route::get('/siswa', [BoardController::class, 'studentDashboard'])
        ->middleware('role:student')->name('student.dashboard');

    // Admin sekolah
    Route::prefix('admin')->name('admin.')->middleware('role:school_admin')->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        Route::get('/pengaturan', [SettingsController::class, 'edit'])->name('settings.edit');
        Route::put('/pengaturan', [SettingsController::class, 'update'])->name('settings.update');

        Route::get('/tahun-ajaran', [AcademicYearController::class, 'index'])->name('years.index');
        Route::post('/tahun-ajaran', [AcademicYearController::class, 'store'])->name('years.store');
        Route::put('/tahun-ajaran/{year}', [AcademicYearController::class, 'update'])->name('years.update');
        Route::post('/tahun-ajaran/{year}/aktifkan', [AcademicYearController::class, 'activate'])->name('years.activate');

        Route::get('/rombel', [ClassController::class, 'index'])->name('classes.index');
        Route::post('/rombel', [ClassController::class, 'store'])->name('classes.store');
        Route::put('/rombel/{class}', [ClassController::class, 'update'])->name('classes.update');

        Route::get('/pengguna', [UserController::class, 'index'])->name('users.index');
        Route::post('/pengguna', [UserController::class, 'store'])->name('users.store');
        Route::post('/pengguna/{user}/reset-sandi', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::put('/pengguna/{user}/status', [UserController::class, 'updateStatus'])->name('users.status');

        Route::get('/log-aktivitas', [AuditController::class, 'index'])->name('audit.index');

        Route::get('/impor', [ImportController::class, 'index'])->name('imports.index');
        Route::post('/impor', [ImportController::class, 'store'])->name('imports.store');
        Route::get('/impor/templat/{type}', [ImportController::class, 'template'])->name('imports.template');
        Route::get('/impor/{import}', [ImportController::class, 'show'])->name('imports.show');
        Route::post('/impor/{import}/proses', [ImportController::class, 'process'])->name('imports.process');
        Route::delete('/impor/{import}', [ImportController::class, 'destroy'])->name('imports.destroy');
        Route::get('/impor/{import}/akun', [ImportController::class, 'credentials'])->name('imports.credentials');
    });
});
