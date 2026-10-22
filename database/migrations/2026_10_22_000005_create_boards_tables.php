<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignUuid('class_id')->nullable()->constrained('classes')->nullOnDelete();
            $table->char('code', 6);
            $table->string('title', 150);
            $table->text('description')->nullable();
            $table->string('format', 20)->default('wall');
            $table->string('visibility', 20)->default('class'); // private | class | school | link
            $table->string('visitor_permission', 20)->default('write'); // read | write | moderate
            $table->jsonb('settings')->default('{}');
            $table->foreignUuid('theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->boolean('theme_locked')->default(false);
            $table->boolean('is_frozen')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->unique(['school_id', 'code']);
            $table->index(['school_id', 'owner_id', 'updated_at']);
            $table->index(['school_id', 'class_id']);
        });
        Rls::enable('boards');

        Schema::create('posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('author_id')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_anonymous')->default(false);
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->string('color', 20)->nullable();
            $table->string('status', 20)->default('published'); // pending | published | hidden | rejected
            $table->decimal('position', 20, 10)->default(0);
            $table->boolean('is_pinned')->default(false);
            $table->unsignedInteger('reaction_count')->default(0);
            $table->unsignedInteger('comment_count')->default(0);
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['school_id', 'board_id', 'status', 'position']);
            $table->index(['school_id', 'author_id', 'created_at']);
        });
        Rls::enable('posts');
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('boards');
    }
};
