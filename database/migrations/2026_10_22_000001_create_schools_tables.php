<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabel platform: dibaca sebelum sekolah diketahui, tanpa RLS.
        Schema::create('schools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 60)->unique();
            $table->string('name', 150);
            $table->string('npsn', 10)->nullable()->unique();
            $table->string('timezone', 40)->default('Asia/Jakarta');
            $table->string('status', 20)->default('active'); // active | trial | suspended
            $table->unsignedInteger('storage_quota_mb')->default(10240);
            $table->timestampsTz();
        });

        Schema::create('school_domains', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('host', 190)->unique();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();
        });

        Schema::create('themes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->string('name', 80);
            $table->jsonb('tokens');
            $table->boolean('is_builtin')->default(false);
            $table->unsignedInteger('xp_required')->default(0);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestampsTz();
            $table->unique(['school_id', 'key']);
        });
        Rls::enableWithShared('themes');

        Schema::create('school_settings', function (Blueprint $table) {
            $table->foreignUuid('school_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('app_display_name', 80)->default('Mading');
            $table->string('logo_path')->nullable();
            $table->string('accent_color', 9)->nullable();
            $table->foreignUuid('default_theme_id')->nullable()->constrained('themes')->nullOnDelete();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();
        });
        Rls::enable('school_settings');

        Schema::create('school_features', function (Blueprint $table) {
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->string('feature_key', 40);
            $table->boolean('enabled')->default(true);
            $table->jsonb('config')->default('{}');
            $table->timestampsTz();
            $table->primary(['school_id', 'feature_key']);
        });
        Rls::enable('school_features');
    }

    public function down(): void
    {
        Schema::dropIfExists('school_features');
        Schema::dropIfExists('school_settings');
        Schema::dropIfExists('themes');
        Schema::dropIfExists('school_domains');
        Schema::dropIfExists('schools');
    }
};
