<?php

namespace Database\Seeders;

use App\Core\Tenancy\TenantContext;
use App\Models\AcademicYear;
use App\Models\School;
use App\Models\SchoolFeature;
use App\Models\SchoolSetting;
use App\Models\Theme;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Menyiapkan satu sekolah: data sekolah, pengaturan, feature flag,
 * tahun ajaran aktif, dan akun admin sekolah. Aman dijalankan berulang.
 */
class SchoolSeeder
{
    public function __construct(private TenantContext $tenant) {}

    /**
     * @return array{school: School, admin: User, admin_password: ?string}
     */
    public function run(string $slug, string $name, ?string $npsn = null, ?string $adminPassword = null, ?string $host = null): array
    {
        $school = School::firstOrCreate(['slug' => $slug], ['name' => $name, 'npsn' => $npsn ?: null, 'status' => 'active']);

        if ($host) {
            $school->domains()->firstOrCreate(['host' => strtolower($host)], ['is_primary' => true]);
        }

        return $this->tenant->run($school, function () use ($school, $adminPassword) {
            $neon = Theme::where('key', 'neon')->whereNull('school_id')->first();

            SchoolSetting::firstOrCreate(['school_id' => $school->id], [
                'app_display_name' => 'Mading',
                'default_theme_id' => $neon?->id,
                'settings' => [],
            ]);

            foreach (SchoolFeature::DEFAULTS as $key => $enabled) {
                if (! SchoolFeature::where('feature_key', $key)->exists()) {
                    SchoolFeature::create(['feature_key' => $key, 'enabled' => $enabled, 'config' => []]);
                }
            }

            if (! AcademicYear::where('is_active', true)->exists()) {
                $s = AcademicYear::suggestName();
                AcademicYear::firstOrCreate(['name' => $s['name']], $s + ['is_active' => true])
                    ->update(['is_active' => true]);
            }

            $admin = User::where('username', 'admin')->first();
            $plain = null;

            if (! $admin) {
                $plain = $adminPassword ?: Str::password(12, symbols: false);
                $admin = User::create([
                    'username' => 'admin',
                    'name' => 'Admin Sekolah',
                    'password' => $plain,
                    'role' => User::ROLE_ADMIN,
                    'status' => 'active',
                    'must_change_password' => $adminPassword === null,
                ]);
            }

            return ['school' => $school, 'admin' => $admin, 'admin_password' => $plain];
        });
    }
}
