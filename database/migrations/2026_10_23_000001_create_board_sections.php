<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tahap 5: kolom (section) untuk format Shelf dan urutan manual kartu (BRD-05, BRD-13). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->decimal('position', 20, 10)->default(0);
            $table->timestampsTz();
            $table->index(['school_id', 'board_id', 'position']);
        });
        Rls::enable('board_sections');

        Schema::table('posts', function (Blueprint $table) {
            $table->foreignUuid('section_id')->nullable()->after('board_id')->constrained('board_sections')->nullOnDelete();
        });

        // Urutan manual awal = urutan waktu (terbaru di atas)
        DB::statement('UPDATE posts SET position = -EXTRACT(EPOCH FROM created_at)');
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
        Schema::dropIfExists('board_sections');
    }
};
