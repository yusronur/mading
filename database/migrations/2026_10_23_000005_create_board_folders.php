<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 9: folder papan, ikon, latar (BRD-01, BRD-16). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 60);
            $table->timestampsTz();
            $table->index(['school_id', 'owner_id']);
        });
        Rls::enable('board_folders');

        Schema::table('boards', function (Blueprint $table) {
            $table->string('icon', 16)->nullable()->after('description');
            $table->string('background', 20)->nullable()->after('icon');
            $table->foreignUuid('folder_id')->nullable()->after('class_id')->constrained('board_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table) {
            $table->dropConstrainedForeignId('folder_id');
            $table->dropColumn(['icon', 'background']);
        });
        Schema::dropIfExists('board_folders');
    }
};
