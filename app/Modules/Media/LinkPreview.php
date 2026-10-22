<?php

namespace App\Modules\Media;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Pratinjau tautan (PST-04): judul, deskripsi, gambar, dan sematan YouTube.
 *
 * Pengamanan SSRF: hanya http/https port 80/443, semua alamat IP tujuan harus
 * publik, IP dipaku (CURLOPT_RESOLVE) agar tidak bisa diganti lewat DNS,
 * pengalihan diikuti manual dan dicek ulang, ukuran dan waktu dibatasi.
 */
class LinkPreview
{
    private const MAX_BYTES = 512 * 1024;

    private const MAX_REDIRECTS = 3;

    public static function youtubeId(string $url): ?string
    {
        $pattern = '~^https?://(?:www\.|m\.|music\.)?(?:youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/|v/)|youtu\.be/|youtube-nocookie\.com/embed/)([A-Za-z0-9_-]{11})~i';

        return preg_match($pattern, $url, $m) ? $m[1] : null;
    }

    public function preview(string $url): array
    {
        $url = trim($url);
        if (! $this->validUrl($url)) {
            return ['url' => $url, 'error' => 'Tautan tidak valid.'];
        }

        return Cache::remember('link:'.sha1($url), now()->addDay(), function () use ($url) {
            $base = ['url' => $url, 'site' => strtolower((string) parse_url($url, PHP_URL_HOST))];

            if ($yt = self::youtubeId($url)) {
                $title = null;
                $oembed = $this->safeGet('https://www.youtube.com/oembed?format=json&url='.urlencode('https://www.youtube.com/watch?v='.$yt));
                if ($oembed && ($json = json_decode($oembed['body'], true))) {
                    $title = $json['title'] ?? null;
                }

                return $base + [
                    'type' => 'youtube',
                    'youtube_id' => $yt,
                    'title' => $title ? mb_substr($title, 0, 200) : 'Video YouTube',
                    'description' => null,
                    'image' => "https://i.ytimg.com/vi/{$yt}/hqdefault.jpg",
                ];
            }

            $res = $this->safeGet($url);
            if (! $res || ! str_contains(strtolower($res['type']), 'html')) {
                return $base + ['type' => 'link', 'title' => $base['site'], 'description' => null, 'image' => null];
            }

            return $base + ['type' => 'link'] + $this->parse($res['body'], $res['url']);
        });
    }

    /**
     * Unduh gambar pratinjau dan simpan di server sekolah, supaya peramban
     * siswa tidak memuat gambar dari situs luar. Mengembalikan WebP kecil.
     */
    public function downloadImage(?string $url): ?string
    {
        if (! $url) {
            return null;
        }

        $res = $this->safeGet($url, 3 * 1024 * 1024);
        if (! $res || ! str_starts_with(strtolower($res['type']), 'image/')) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'lnk');
        file_put_contents($tmp, $res['body']);

        try {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                return null;
            }

            return (new ImageProcessor)->process($tmp, $mime)['thumb'];
        } catch (\Throwable) {
            return null;
        } finally {
            @unlink($tmp);
        }
    }

    public function validUrl(string $url): bool
    {
        if (strlen($url) > 2000 || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $parts = parse_url($url);

        return in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && ! empty($parts['host'])
            && ! isset($parts['user'])
            && in_array($parts['port'] ?? null, [null, 80, 443], true);
    }

    /** @return array{body:string,type:string,url:string}|null */
    protected function safeGet(string $url, int $maxBytes = self::MAX_BYTES): ?array
    {
        for ($i = 0; $i <= self::MAX_REDIRECTS; $i++) {
            if (! $this->validUrl($url)) {
                return null;
            }

            $host = strtolower(parse_url($url, PHP_URL_HOST));
            $ip = $this->publicIp($host);
            if (! $ip) {
                return null;
            }

            $port = parse_url($url, PHP_URL_PORT) ?: (strtolower(parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

            try {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
                    'stream' => true,
                ])->withHeaders([
                    'User-Agent' => 'MadingoBot/1.0 (+pratinjau tautan sekolah)',
                    'Accept' => 'text/html,application/xhtml+xml,application/json;q=0.9,*/*;q=0.5',
                    'Accept-Language' => 'id,en;q=0.8',
                ])->connectTimeout(3)->timeout(5)->get($url);
            } catch (\Throwable) {
                return null;
            }

            if ($response->redirect() && ($location = $response->header('Location'))) {
                $url = $this->absolute($location, $url);

                continue;
            }

            if (! $response->successful()) {
                return null;
            }

            $body = '';
            $stream = $response->toPsrResponse()->getBody();
            while (! $stream->eof() && strlen($body) < $maxBytes) {
                $body .= $stream->read(16384);
            }

            return ['body' => $body, 'type' => (string) $response->header('Content-Type'), 'url' => $url];
        }

        return null;
    }

    /** Alamat IP publik pertama untuk host; null bila ada yang privat/khusus. */
    protected function publicIp(string $host): ?string
    {
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if (! $ips) {
            return null;
        }

        foreach ($ips as $ip) {
            if (! $this->isPublic($ip)) {
                return null;
            }
        }

        return $ips[0];
    }

    public function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }

        // CGNAT 100.64.0.0/10 dan jaringan uji tidak tercakup flag di atas
        $long = ip2long($ip);
        if ($long !== false) {
            foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['198.18.0.0', 15]] as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if (($long & $mask) === (ip2long($net) & $mask)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function parse(string $html, string $url): array
    {
        $meta = [];
        if (preg_match_all('/<meta\s[^>]*>/i', $html, $tags)) {
            foreach ($tags[0] as $tag) {
                if (preg_match('/(?:property|name)\s*=\s*["\']([^"\']+)["\']/i', $tag, $k) && preg_match('/content\s*=\s*["\']([^"\']*)["\']/i', $tag, $v)) {
                    $meta[strtolower($k[1])] = html_entity_decode($v[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }
        }

        $title = $meta['og:title'] ?? $meta['twitter:title'] ?? null;
        if (! $title && preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $t)) {
            $title = html_entity_decode(trim($t[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $image = $meta['og:image'] ?? $meta['twitter:image'] ?? null;
        $image = $image ? $this->absolute($image, $url) : null;
        if ($image && ! $this->validUrl($image)) {
            $image = null;
        }

        $clip = fn (?string $s, int $n) => $s ? mb_substr(trim(preg_replace('/\s+/u', ' ', strip_tags($s))), 0, $n) : null;

        return [
            'title' => $clip($title, 200) ?: strtolower((string) parse_url($url, PHP_URL_HOST)),
            'description' => $clip($meta['og:description'] ?? $meta['description'] ?? null, 300),
            'image' => $image,
            'site' => $clip($meta['og:site_name'] ?? null, 80) ?: strtolower((string) parse_url($url, PHP_URL_HOST)),
        ];
    }

    private function absolute(string $href, string $base): string
    {
        if (preg_match('~^https?://~i', $href)) {
            return $href;
        }
        $p = parse_url($base);
        $root = $p['scheme'].'://'.$p['host'].(isset($p['port']) ? ':'.$p['port'] : '');
        if (str_starts_with($href, '//')) {
            return $p['scheme'].':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $root.$href;
        }
        $dir = isset($p['path']) ? preg_replace('~/[^/]*$~', '/', $p['path']) : '/';

        return $root.$dir.$href;
    }
}
