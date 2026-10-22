<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // NULL hanya untuk super-admin platform (Fase 4)
            $table->foreignUuid('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('username', 60); // NISN (siswa), NIP/username (guru)
            $table->string('name', 150);
            $table->string('email', 150)->nullable();
            $table->string('password');
            $table->string('role', 20); // super_admin | school_admin | teacher | student
            $table->char('gender', 1)->nullable(); // L | P
            $table->date('birth_date')->nullable();
            $table->jsonb('avatar')->nullable();
            $table->jsonb('profile')->default('{}'); // mis. mapel guru
            $table->string('status', 20)->default('active'); // active | inactive | alumni
            $table->boolean('must_change_password')->default(true);
            $table->timestampTz('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['school_id', 'username']);
            $table->index(['school_id', 'role', 'status']);
        });
        Rls::enable('users');

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->string('color_mode', 10)->default('system'); // system | light | dark
            $table->boolean('reduce_motion')->default(false);
            $table->unsignedSmallInteger('font_scale')->default(100); // 100 | 115 | 130
            $table->timestampsTz();
        });
        Rls::enable('user_preferences');

        // Sesi dibaca sebelum pengguna diketahui; tanpa RLS.
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('user_preferences');
        Schema::dropIfExists('users');
    }
};
