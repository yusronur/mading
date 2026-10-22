<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kuis live (QZ-10..13): status permainan di sesi, tim per pemain, skor kecepatan per jawaban.
 * Lihat ADR-019 (status di PostgreSQL, bukan Redis) dan ADR-020 (Reverb hanya siaran).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_sessions', function (Blueprint $table) {
            // {phase, index, order[], opens_at, deadline_at, version, kicked[]}
            $table->jsonb('live')->nullable()->after('questions');
        });
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->unsignedSmallInteger('team')->nullable()->after('attempt_no');
        });
        Schema::table('quiz_answers', function (Blueprint $table) {
            $table->unsignedInteger('live_score')->nullable()->after('points');
            $table->unsignedInteger('elapsed_ms')->nullable()->after('live_score');
        });
    }

    public function down(): void
    {
        Schema::table('quiz_answers', fn (Blueprint $t) => $t->dropColumn(['live_score', 'elapsed_ms']));
        Schema::table('quiz_attempts', fn (Blueprint $t) => $t->dropColumn('team'));
        Schema::table('quiz_sessions', fn (Blueprint $t) => $t->dropColumn('live'));
    }
};
