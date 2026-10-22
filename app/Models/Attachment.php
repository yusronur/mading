<?php

namespace App\Models;

use App\Core\Tenancy\BelongsToSchool;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    use BelongsToSchool, HasUuids;

    protected $fillable = [
        'school_id', 'owner_id', 'board_id', 'attachable_type', 'attachable_id', 'kind', 'path', 'thumb_path',
        'original_name', 'mime', 'size', 'width', 'height',
    ];

    protected $casts = ['size' => 'integer', 'width' => 'integer', 'height' => 'integer'];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    public static function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk(config('madingo.storage.disk', 'local'));
    }

    /** Hapus berkas fisik beserta barisnya. */
    public function purge(): void
    {
        self::disk()->delete(array_filter([$this->path, $this->thumb_path]));
        $this->delete();
    }

    public function toCard(): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'name' => $this->original_name,
            'mime' => $this->mime,
            'size' => $this->size,
            'width' => $this->width,
            'height' => $this->height,
            'url' => route('attachments.show', $this->id),
            'thumb_url' => $this->thumb_path ? route('attachments.show', [$this->id, 'thumb']) : null,
            'download_url' => route('attachments.show', [$this->id, 'unduh']),
        ];
    }
}
