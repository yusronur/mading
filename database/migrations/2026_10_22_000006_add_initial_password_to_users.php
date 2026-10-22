<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sandi awal disimpan terenkripsi (APP_KEY) selama pengguna belum
     * mengganti sandi, agar admin bisa mencetak kartu akun kapan saja.
     * Kolom dikosongkan otomatis begitu pengguna mengganti sandinya.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('initial_password')->nullable()->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('initial_password');
        });
    }
};
