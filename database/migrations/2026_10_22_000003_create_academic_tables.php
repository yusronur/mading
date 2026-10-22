<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('academic_years', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20); // 2026/2027
            $table->date('starts_on');
            $table->date('ends_on');
            $table->unsignedTinyInteger('active_semester')->default(1); // 1 | 2
            $table->boolean('is_active')->default(false);
            $table->timestampsTz();
            $table->unique(['school_id', 'name']);
        });
        Rls::enable('academic_years');

        Schema::create('classes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('academic_year_id')->constrained()->cascadeOnDelete();
            $table->string('name', 30); // 7A
            $table->unsignedTinyInteger('grade')->nullable(); // 7, 8, 9
            $table->foreignUuid('homeroom_teacher_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->unique(['school_id', 'academic_year_id', 'name']);
        });
        Rls::enable('classes');

        Schema::create('class_members', function (Blueprint $table) {
            $table->foreignUuid('class_id')->constrained('classes')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->timestampsTz();
            $table->primary(['class_id', 'user_id']);
            $table->index(['school_id', 'user_id']);
        });
        Rls::enable('class_members');
    }

    public function down(): void
    {
        Schema::dropIfExists('class_members');
        Schema::dropIfExists('classes');
        Schema::dropIfExists('academic_years');
    }
};
