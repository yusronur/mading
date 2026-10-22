<?php

use App\Core\Database\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tahap 19: pusat notifikasi (lonceng), langganan Web Push, dan riwayat pesan manual admin. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 30);
            $table->string('title', 150);
            $table->string('body', 300)->nullable();
            $table->string('url', 300)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->unsignedSmallInteger('count')->default(1);
            $table->string('push_state', 12)->default('none'); // none|sent|held|capped|online|off
            $table->timestampTz('pushed_at')->nullable();
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'pushed_at']);
            $table->unique(['user_id', 'dedupe_key']);
        });
        Rls::enable('app_notifications');

        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            $table->char('endpoint_hash', 64)->unique();
            $table->string('p256dh', 100);
            $table->string('auth', 40);
            $table->string('agent', 160)->nullable();
            $table->timestampTz('last_ok_at')->nullable();
            $table->timestampsTz();

            $table->index('user_id');
        });
        Rls::enable('push_subscriptions');

        Schema::create('push_broadcasts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('school_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('sender_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('audience', 20);
            $table->string('target', 150)->nullable();
            $table->string('title', 150);
            $table->string('body', 300)->nullable();
            $table->string('url', 300)->nullable();
            $table->unsignedInteger('recipients')->default(0);
            $table->unsignedInteger('with_push')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->index('created_at');
        });
        Rls::enable('push_broadcasts');

        Schema::table('user_preferences', function (Blueprint $table) {
            $table->jsonb('notify_off')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_preferences', fn (Blueprint $table) => $table->dropColumn('notify_off'));
        Schema::dropIfExists('push_broadcasts');
        Schema::dropIfExists('push_subscriptions');
        Schema::dropIfExists('app_notifications');
    }
};
