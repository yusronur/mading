<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 15: XP, level, lencana, streak (GAM-01..07). */
return new class extends Migration
{
    public function up(): void
    {
        // Buku besar XP. Unik per (siswa, jenis sumber, id sumber, alasan) agar tidak ada XP ganda.
        Schema::create('xp_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('source_type', 30);
            $table->string('source_id', 64);
            $table->string('reason', 30);
            $table->integer('points')->default(0);
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['user_id', 'source_type', 'source_id', 'reason']);
            $table->index(['school_id', 'user_id', 'created_at']);
        });
        Rls::enable('xp_events');

        Schema::create('user_levels', function (Blueprint $table) {
            $table->foreignUuid('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->integer('xp')->default(0);
            $table->unsignedSmallInteger('level')->default(1);
            $table->unsignedSmallInteger('streak')->default(0);
            $table->unsignedSmallInteger('best_streak')->default(0);
            $table->date('last_active_on')->nullable();
            $table->jsonb('notices')->default('[]');
            $table->timestampsTz();
            $table->index(['school_id', 'xp']);
        });
        Rls::enable('user_levels');

        Schema::create('user_badges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('badge', 30);
            $table->timestampTz('earned_at')->useCurrent();
            $table->unique(['user_id', 'badge']);
        });
        Rls::enable('user_badges');

        // Guru dapat mematikan papan peringkat per rombel (GAM-06)
        Schema::table('classes', function (Blueprint $table) {
            $table->boolean('leaderboard')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('classes', fn (Blueprint $table) => $table->dropColumn('leaderboard'));
        Schema::dropIfExists('user_badges');
        Schema::dropIfExists('user_levels');
        Schema::dropIfExists('xp_events');
    }
};
