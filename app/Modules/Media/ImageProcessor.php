<?php

namespace App\Modules\Media;

use GdImage;
use RuntimeException;

/**
 * Kecilkan gambar dan ubah ke WebP (GD). GIF bergerak disimpan apa adanya
 * agar animasinya tidak hilang; thumbnailnya tetap WebP.
 */
class ImageProcessor
{
    public function process(string $path, string $mime): array
    {
        if (! function_exists('imagewebp')) {
            throw new RuntimeException('Ekstensi GD dengan dukungan WebP belum aktif di server.');
        }

        $info = @getimagesize($path);
        if (! $info || $info[0] < 1 || $info[1] < 1) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new RuntimeException('Resolusi gambar terlalu besar.');
        }

        $src = $this->load($path, $mime);
        $src = $this->orient($src, $path, $mime);

        $max = (int) config('madingo.storage.image_max_px', 1600);
        $thumbPx = (int) config('madingo.storage.thumb_px', 480);

        $thumb = $this->encode($this->resize($src, $thumbPx), 72);

        if ($mime === 'image/gif' && $this->isAnimated($path)) {
            $main = file_get_contents($path);
            $result = ['main' => $main, 'ext' => 'gif', 'mime' => 'image/gif', 'width' => imagesx($src), 'height' => imagesy($src)];
        } else {
            $resized = $this->resize($src, $max);
            $result = ['main' => $this->encode($resized, 80), 'ext' => 'webp', 'mime' => 'image/webp', 'width' => imagesx($resized), 'height' => imagesy($resized)];
        }

        return $result + ['thumb' => $thumb];
    }

    private function load(string $path, string $mime): GdImage
    {
        $img = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/bmp', 'image/x-ms-bmp' => @imagecreatefrombmp($path),
            default => false,
        };

        if (! $img) {
            throw new RuntimeException('Gambar tidak dapat dibaca.');
        }

        if (! imageistruecolor($img)) {
            imagepalettetotruecolor($img);
        }
        imagealphablending($img, true);
        imagesavealpha($img, true);

        return $img;
    }

    /** Foto HP sering tersimpan miring; ikuti tag EXIF Orientation. */
    private function orient(GdImage $img, string $path, string $mime): GdImage
    {
        if ($mime !== 'image/jpeg' || ! function_exists('exif_read_data')) {
            return $img;
        }

        $exif = @exif_read_data($path);
        $o = (int) ($exif['Orientation'] ?? 1);

        return match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => $img,
        } ?: $img;
    }

    private function resize(GdImage $src, int $max): GdImage
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, $max / max($w, $h));
        if ($scale >= 1) {
            return $src;
        }

        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $dst = imagecreatetruecolor($nw, $nh);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private function encode(GdImage $img, int $quality): string
    {
        ob_start();
        imagewebp($img, null, $quality);

        return (string) ob_get_clean();
    }

    private function isAnimated(string $path): bool
    {
        $content = (string) file_get_contents($path, false, null, 0, 2_000_000);

        return preg_match_all('#\x00\x21\xF9\x04.{4}\x00(\x2C|\x21)#s', $content) > 1;
    }
}
