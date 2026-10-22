<?php

namespace App\Modules\Media;

use App\Models\Attachment;
use App\Models\Board;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Menyimpan lampiran (PST-02, PST-03). Gambar dikecilkan dan diubah ke WebP
 * beserta thumbnail; berkas lain disimpan apa adanya setelah dicek jenisnya.
 */
class AttachmentService
{
    /** mime => [jenis, ekstensi simpan] */
    public const TYPES = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
        'image/gif' => ['image', 'gif'], 'image/bmp' => ['image', 'bmp'], 'image/x-ms-bmp' => ['image', 'bmp'],
        'application/pdf' => ['file', 'pdf'],
        'application/msword' => ['file', 'doc'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['file', 'docx'],
        'application/vnd.ms-excel' => ['file', 'xls'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['file', 'xlsx'],
        'application/vnd.ms-powerpoint' => ['file', 'ppt'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['file', 'pptx'],
        'application/vnd.oasis.opendocument.text' => ['file', 'odt'],
        'application/vnd.oasis.opendocument.spreadsheet' => ['file', 'ods'],
        'application/vnd.oasis.opendocument.presentation' => ['file', 'odp'],
        'text/plain' => ['file', 'txt'], 'text/csv' => ['file', 'csv'],
        'application/zip' => ['file', 'zip'],
        'audio/mpeg' => ['audio', 'mp3'], 'audio/mp4' => ['audio', 'm4a'], 'audio/x-m4a' => ['audio', 'm4a'],
        'audio/ogg' => ['audio', 'ogg'], 'audio/wav' => ['audio', 'wav'], 'audio/x-wav' => ['audio', 'wav'], 'audio/webm' => ['audio', 'webm'],
        'video/mp4' => ['video', 'mp4'], 'video/webm' => ['video', 'webm'], 'video/quicktime' => ['video', 'mov'],
    ];

    /** Dokumen Office terdeteksi finfo sebagai zip; dikenali dari ekstensinya. */
    private const ZIP_OFFICE = ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'];

    public function __construct(private StorageQuota $quota) {}

    public function store(UploadedFile $file, User $owner, ?Board $board = null, ?string $folder = null): Attachment
    {
        if (! $file->isValid()) {
            throw new RuntimeException('Unggahan gagal. Coba lagi.');
        }

        $size = (int) $file->getSize();
        if ($size > $this->quota->maxUploadBytes()) {
            throw new RuntimeException('Berkas terlalu besar. Maksimal '.StorageQuota::human($this->quota->maxUploadBytes()).'.');
        }
        if (! $this->quota->canStore($size)) {
            throw new RuntimeException('Kuota penyimpanan sekolah penuh. Hubungi admin sekolah.');
        }

        [$kind, $ext, $mime] = $this->detect($file);
        $dir = 'schools/'.$owner->school_id.'/'.($folder ?? ($board ? 'boards/'.$board->id : 'users/'.$owner->id));
        $name = (string) Str::uuid7();
        $disk = Attachment::disk();

        $attrs = [
            'owner_id' => $owner->id,
            'board_id' => $board?->id,
            'kind' => $kind,
            'original_name' => mb_substr($this->cleanName($file->getClientOriginalName()), 0, 200),
            'mime' => $mime,
        ];

        if ($kind === 'image') {
            $image = (new ImageProcessor)->process($file->getRealPath(), $mime);
            $path = "{$dir}/{$name}.{$image['ext']}";
            $thumb = "{$dir}/{$name}-thumb.webp";
            $disk->put($path, $image['main']);
            $disk->put($thumb, $image['thumb']);

            return Attachment::create(array_merge($attrs, [
                'path' => $path,
                'thumb_path' => $thumb,
                'mime' => $image['mime'],
                'size' => strlen($image['main']) + strlen($image['thumb']),
                'width' => $image['width'],
                'height' => $image['height'],
            ]));
        }

        $path = "{$dir}/{$name}.{$ext}";
        $disk->putFileAs($dir, $file, "{$name}.{$ext}");

        return Attachment::create($attrs + ['path' => $path, 'size' => $size]);
    }

    /** @return array{0:string,1:string,2:string} jenis, ekstensi, mime */
    private function detect(UploadedFile $file): array
    {
        $mime = (string) $file->getMimeType(); // dari isi berkas (finfo), bukan dari peramban
        $ext = strtolower($file->getClientOriginalExtension());

        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true) && in_array($ext, self::ZIP_OFFICE, true)) {
            $mime = array_search(['file', $ext], self::TYPES, true) ?: $mime;
        }
        if ($mime === 'application/octet-stream' && in_array($ext, ['mp3', 'm4a'], true)) {
            $mime = $ext === 'mp3' ? 'audio/mpeg' : 'audio/mp4';
        }
        if ($mime === 'video/mp4' && $ext === 'm4a') {
            $mime = 'audio/mp4';
        }

        if (! isset(self::TYPES[$mime])) {
            throw new RuntimeException('Jenis berkas ini tidak didukung. Gunakan gambar, PDF, dokumen Office, audio, atau video.');
        }

        [$kind, $defaultExt] = self::TYPES[$mime];

        return [$kind, $defaultExt, $mime];
    }

    private function cleanName(string $name): string
    {
        $name = preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '', $name) ?: 'berkas';

        return trim($name) === '' ? 'berkas' : $name;
    }
}
