<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Tahap 6: lampiran, pratinjau tautan, teks berformat (PST-01..04, PST-13, ADM-07). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('board_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('attachable_type', 40)->nullable(); // post | question | ...
            $table->uuid('attachable_id')->nullable();
            $table->string('kind', 10); // image | file | audio | video
            $table->string('path');
            $table->string('thumb_path')->nullable();
            $table->string('original_name', 200);
            $table->string('mime', 120);
            $table->unsignedBigInteger('size')->default(0); // byte, termasuk thumbnail
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'attachable_type', 'attachable_id']);
            $table->index(['school_id', 'owner_id', 'created_at']);
        });
        Rls::enable('attachments');

        Schema::table('posts', function (Blueprint $table) {
            $table->jsonb('link')->nullable()->after('body');
            $table->timestampTz('edited_at')->nullable()->after('comment_count');
        });

        // Isi kartu lama (teks polos) diubah ke HTML yang aman
        DB::statement(<<<'SQL'
            UPDATE posts SET body = '<p>' || replace(replace(replace(replace(body, '&', '&amp;'), '<', '&lt;'), '>', '&gt;'), E'\n', '<br>') || '</p>'
            WHERE body IS NOT NULL AND body NOT LIKE '<p>%'
        SQL);
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropColumn(['link', 'edited_at']);
        });
        Schema::dropIfExists('attachments');
    }
};
