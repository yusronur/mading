<?php

namespace App\Modules\Admin\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SchoolSetting;
use App\Models\Theme;
use App\Modules\Board\ProfanityFilter;
use App\Modules\Media\StorageQuota;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(TenantContext $tenant, StorageQuota $quota): Response
    {
        $school = $tenant->school();
        $setting = SchoolSetting::firstOrCreate(['school_id' => $school->id]);

        return Inertia::render('Admin/Settings', [
            'settings' => [
                'name' => $school->name,
                'npsn' => $school->npsn,
                'app_display_name' => $setting->app_display_name,
                'default_theme_id' => $setting->default_theme_id,
                'bad_words' => implode(', ', $setting->settings['bad_words'] ?? []),
                'allowed_words' => implode(', ', $setting->settings['allowed_words'] ?? []),
                'storage_quota_mb' => (int) ($setting->settings['storage_quota_mb'] ?? config('madingo.storage.quota_mb')),
                'max_upload_mb' => (int) ($setting->settings['max_upload_mb'] ?? config('madingo.storage.max_upload_mb')),
                'gamify_enabled' => (bool) ($setting->settings['gamify_enabled'] ?? true),
            ],
            'storage' => [
                'used' => $quota->usedBytes(),
                'used_label' => StorageQuota::human($quota->usedBytes()),
                'quota' => $quota->quotaBytes(),
            ],
            'php_upload_limit' => ini_get('upload_max_filesize'),
            'default_words' => count(ProfanityFilter::DEFAULT_WORDS),
            'themes' => Theme::orderBy('sort')->get(['id', 'name', 'key']),
        ]);
    }

    public function update(Request $request, TenantContext $tenant): RedirectResponse
    {
        $school = $tenant->school();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'npsn' => ['nullable', 'digits:8', Rule::unique('schools', 'npsn')->ignore($school->id)],
            'app_display_name' => ['required', 'string', 'max:80'],
            'default_theme_id' => ['nullable', 'uuid', Rule::in(Theme::pluck('id')->all())],
            'bad_words' => ['nullable', 'string', 'max:5000'],
            'allowed_words' => ['nullable', 'string', 'max:5000'],
            'storage_quota_mb' => ['required', 'integer', 'min:100', 'max:1048576'],
            'max_upload_mb' => ['required', 'integer', 'min:1', 'max:512'],
            'gamify_enabled' => ['required', 'boolean'],
        ], [], [
            'storage_quota_mb' => 'kuota penyimpanan',
            'max_upload_mb' => 'batas unggahan',
            'name' => 'nama sekolah',
            'app_display_name' => 'nama aplikasi',
            'default_theme_id' => 'tema bawaan',
        ]);

        $school->update(['name' => $data['name'], 'npsn' => $data['npsn'] ?: null]);
        $words = fn (?string $v) => array_values(array_unique(array_filter(array_map(
            fn ($w) => mb_strtolower(trim($w)), preg_split('/[,\n;]+/', (string) $v)
        ))));

        $setting = SchoolSetting::firstOrCreate(['school_id' => $school->id]);
        $setting->fill([
            'app_display_name' => $data['app_display_name'],
            'default_theme_id' => $data['default_theme_id'],
            'settings' => array_merge($setting->settings ?? [], [
                'bad_words' => $words($data['bad_words'] ?? ''),
                'allowed_words' => $words($data['allowed_words'] ?? ''),
                'storage_quota_mb' => (int) $data['storage_quota_mb'],
                'max_upload_mb' => (int) $data['max_upload_mb'],
                'gamify_enabled' => (bool) $data['gamify_enabled'],
            ]),
        ])->save();

        AuditLog::record('school.settings_updated', null, ['name' => $data['name']]);

        return back()->with('success', 'Pengaturan sekolah disimpan.');
    }
}
