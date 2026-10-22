<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 8: moderasi, laporan, tautan rahasia, anggota papan (MOD-01..05, MOD-08). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('moderation_reason', 60)->nullable()->after('status');
            $table->unsignedInteger('report_count')->default(0)->after('comment_count');
        });

        Schema::table('comments', function (Blueprint $table) {
            $table->string('moderation_reason', 60)->nullable()->after('status');
        });

        Schema::table('boards', function (Blueprint $table) {
            $table->string('secret_token', 40)->nullable()->after('code');
            $table->index(['school_id', 'secret_token']);
        });

        Schema::create('post_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('reason', 200)->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampsTz();
            $table->unique(['post_id', 'user_id']);
        });
        Rls::enable('post_reports');

        // Anggota papan: member (masuk lewat tautan/kode rahasia) atau editor (kolaborator guru)
        Schema::create('board_members', function (Blueprint $table) {
            $table->foreignUuid('board_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10)->default('member'); // member | editor
            $table->timestampsTz();
            $table->primary(['board_id', 'user_id']);
            $table->index(['school_id', 'user_id']);
        });
        Rls::enable('board_members');
    }

    public function down(): void
    {
        Schema::dropIfExists('board_members');
        Schema::dropIfExists('post_reports');
        Schema::table('boards', fn (Blueprint $t) => $t->dropColumn('secret_token'));
        Schema::table('comments', fn (Blueprint $t) => $t->dropColumn('moderation_reason'));
        Schema::table('posts', fn (Blueprint $t) => $t->dropColumn(['moderation_reason', 'report_count']));
    }
};
