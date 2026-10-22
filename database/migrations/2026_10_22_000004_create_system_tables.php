<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 20); // students | teachers
            $table->string('status', 20)->default('preview'); // preview | processing | done | failed
            $table->string('original_name');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->jsonb('rows')->nullable(); // hasil parsing + status per baris
            $table->text('credentials')->nullable(); // terenkripsi; akun baru + sandi awal
            $table->text('error_message')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->timestampsTz();
            $table->index(['school_id', 'created_at']);
        });
        Rls::enable('import_jobs');

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->uuid('actor_id')->nullable();
            $table->string('action', 60);
            $table->string('target_type', 60)->nullable();
            $table->uuid('target_id')->nullable();
            $table->jsonb('meta')->default('{}');
            $table->string('ip', 45)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['school_id', 'created_at']);
        });
        Rls::enable('audit_logs');
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('import_jobs');
    }
};
