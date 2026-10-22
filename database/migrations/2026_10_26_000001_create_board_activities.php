<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 14: aktivitas live di papan (timer, poll, word cloud, spin wheel; LIV-01..04). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('board_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('type', 10);                 // timer | poll | cloud | wheel
            $table->string('status', 10)->default('open'); // open | closed | ended
            $table->string('title', 200)->nullable();
            $table->jsonb('config');
            $table->jsonb('state')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'board_id', 'status']);
        });
        Rls::enable('board_activities');

        Schema::create('activity_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('activity_id')->constrained('board_activities')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('value', 120);               // id pilihan poll, atau kata (word cloud)
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['activity_id', 'user_id', 'value']);
            $table->index(['school_id', 'activity_id']);
        });
        Rls::enable('activity_responses');
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_responses');
        Schema::dropIfExists('board_activities');
    }
};
