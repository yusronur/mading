<?php

namespace App\Providers;

use App\Core\Database\Provisioner;
use App\Core\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\Board;
use App\Models\ImportJob;
use App\Models\SchoolClass;
use App\Modules\Board\BoardPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /** Perintah yang mengubah skema/data massal memakai koneksi pemilik. */
    private const OWNER_COMMANDS = [
        'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
        'migrate:status', 'migrate:install', 'db:seed', 'db:wipe',
    ];

    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    public function boot(): void
    {
        $this->configureTenancyDatabase();

        Gate::policy(Board::class, BoardPolicy::class);

        // Nama pendek di kolom attachable_type (tidak membocorkan nama kelas)
        \Illuminate\Database\Eloquent\Relations\Relation::enforceMorphMap([
            'post' => \App\Models\Post::class,
            'user' => \App\Models\User::class,
            'question' => \App\Models\Question::class,
        ]);

        // Parameter rute berupa UUID; nilai lain langsung 404 tanpa menyentuh database
        foreach (['board', 'post', 'user', 'import', 'year', 'class', 'section', 'attachment', 'comment', 'folder', 'bank', 'question', 'quiz', 'session', 'attempt'] as $param) {
            Route::pattern($param, '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
        }
        Route::model('year', AcademicYear::class);
        Route::model('class', SchoolClass::class);
        Route::model('import', ImportJob::class);
        Route::model('section', \App\Models\BoardSection::class);
        Route::model('attachment', \App\Models\Attachment::class);
        Route::model('comment', \App\Models\Comment::class);
        Route::model('folder', \App\Models\BoardFolder::class);
        Route::model('bank', \App\Models\QuestionBank::class);
        Route::model('question', \App\Models\Question::class);
        Route::model('quiz', \App\Models\Quiz::class);
        Route::model('session', \App\Models\QuizSession::class);
        Route::model('attempt', \App\Models\QuizAttempt::class);

        // Pemeriksaan perubahan papan: ringan, tapi sering (tiap 2-15 detik per tab)
        RateLimiter::for('sync', fn (Request $request) => Limit::perMinute(90)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(40)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('live', fn (Request $request) => Limit::perMinute(150)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('guest-join', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
        RateLimiter::for('qr-login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));
        RateLimiter::for('quiz-answers', fn (Request $request) => Limit::perMinute(240)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('posts', fn (Request $request) => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
    }

    private function configureTenancyDatabase(): void
    {
        // Sambungan baru (mis. setelah reconnect) langsung diberi sekolah aktif
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event) {
            // Hanya bila sekolah aktif sudah diketahui. Sambungan baru tanpa
            // nilai app.current_school otomatis tidak melihat data apa pun.
            $tenant = $this->app->make(TenantContext::class);

            if ($tenant->check() && $event->connection->getName() === config('database.default')) {
                $tenant->applyToConnection($event->connection);
            }
        });

        // migrate / db:seed tanpa --database otomatis memakai koneksi pemilik,
        // lalu kembali ke koneksi aplikasi setelah perintah selesai
        $previous = [];
        Event::listen(CommandStarting::class, function (CommandStarting $event) use (&$previous) {
            if (in_array($event->command, self::OWNER_COMMANDS, true) && config('database.connections.pgsql_owner')) {
                $previous[] = config('database.default');
                config(['database.default' => 'pgsql_owner']);
            }
        });
        Event::listen(CommandFinished::class, function (CommandFinished $event) use (&$previous) {
            if (in_array($event->command, self::OWNER_COMMANDS, true) && $previous) {
                config(['database.default' => array_pop($previous)]);
            }
        });

        // Setelah migrasi, beri hak akses tabel baru ke peran aplikasi
        Event::listen(MigrationsEnded::class, function () {
            $owner = config('database.connections.pgsql_owner');
            $app = config('database.connections.pgsql');

            if (($owner['driver'] ?? null) !== 'pgsql' || $owner['username'] === $app['username']) {
                return;
            }

            (new Provisioner($owner['host'], (string) $owner['port'], $owner['username'], (string) $owner['password']))
                ->grantAppRole($app['username'], $owner['database']);
        });
    }
}
