<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tahap 7: komentar berutas satu tingkat dan reaksi (INT-01, INT-02, INT-05). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->boolean('is_anonymous')->default(false);
            $table->string('status', 20)->default('published'); // published | pending | hidden
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->index(['school_id', 'post_id', 'created_at']);
        });
        // FK ke tabel sendiri dipasang setelah primary key ada
        Schema::table('comments', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('comments')->cascadeOnDelete();
        });
        Rls::enable('comments');

        Schema::create('reactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10); // like | star | vote | grade
            $table->smallInteger('value');
            $table->timestampsTz();
            $table->unique(['post_id', 'user_id', 'kind']);
            $table->index(['school_id', 'post_id', 'kind']);
        });
        Rls::enable('reactions');

        // updated_at bermikrodetik agar penanda versi sinkron berubah walau
        // dua perubahan terjadi di detik yang sama (ADR-014)
        foreach (['boards', 'posts', 'board_sections', 'comments', 'reactions'] as $t) {
            DB::statement("ALTER TABLE {$t} ALTER COLUMN updated_at TYPE timestamptz(6)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('comments');
    }
};
