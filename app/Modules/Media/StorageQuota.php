<?php

namespace App\Modules\Media;

use App\Models\Attachment;
use App\Models\SchoolSetting;

/** Kuota penyimpanan dan batas unggahan per sekolah (ADM-07). */
class StorageQuota
{
    public function quotaBytes(): int
    {
        $mb = (int) (SchoolSetting::first()?->settings['storage_quota_mb'] ?? config('madingo.storage.quota_mb'));

        return max(1, $mb) * 1024 * 1024;
    }

    public function maxUploadBytes(): int
    {
        $mb = (int) (SchoolSetting::first()?->settings['max_upload_mb'] ?? config('madingo.storage.max_upload_mb'));

        return max(1, $mb) * 1024 * 1024;
    }

    public function usedBytes(): int
    {
        return (int) Attachment::sum('size');
    }

    public function canStore(int $bytes): bool
    {
        return $this->usedBytes() + $bytes <= $this->quotaBytes();
    }

    public static function human(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 1, ',', '.').' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1, ',', '.').' MB';
        }

        return max(1, (int) round($bytes / 1024)).' KB';
    }
}
