<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tahap 10: login QR siswa (AUTH-03, ADM-09).
 * Token asli disimpan terenkripsi (untuk mencetak ulang kartu), pencarian
 * memakai hash SHA-256. Membuat token baru otomatis mencabut token lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('qr_token')->nullable()->after('initial_password');
            $table->char('qr_token_hash', 64)->nullable()->after('qr_token');
            $table->timestampTz('qr_token_created_at')->nullable()->after('qr_token_hash');
            $table->index(['school_id', 'qr_token_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['school_id', 'qr_token_hash']);
            $table->dropColumn(['qr_token', 'qr_token_hash', 'qr_token_created_at']);
        });
    }
};
