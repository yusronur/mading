<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * `php artisan db:seed` hanya mengisi data platform (tema bawaan).
 * Penyiapan sekolah dan akun admin dilakukan oleh `php artisan madingo:install`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PlatformSeeder::class);
    }
}
