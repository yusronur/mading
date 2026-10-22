<?php

namespace App\Modules\Notify;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Web Push standar (RFC 8030/8291/8292) memakai OpenSSL bawaan PHP, tanpa paket tambahan.
 * Pesan dienkripsi end-to-end ke peramban (aes128gcm); layanan push Google/Apple/Mozilla
 * hanya meneruskan data terenkripsi dan tidak menarik biaya.
 */
class WebPush
{
    private const SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public function __construct(private ?string $public = null, private ?string $private = null, private ?string $subject = null)
    {
        $this->public ??= config('notify.vapid.public');
        $this->private ??= config('notify.vapid.private');
        $this->subject ??= config('notify.vapid.subject');
    }

    public function configured(): bool
    {
        return $this->public && $this->private && extension_loaded('openssl');
    }

    public function publicKey(): ?string
    {
        return $this->public;
    }

    // ---------------------------------------------------------------- kunci

    /** @return array{public:string,private:string} base64url, siap ditaruh di .env */
    public static function generateKeys(): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($key)['ec'];

        return [
            'public' => self::b64(self::point($d)),
            'private' => self::b64(str_pad($d['d'], 32, "\0", STR_PAD_LEFT)),
        ];
    }

    private static function point(array $ec): string
    {
        return "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    public static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    public static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4), true) ?: '';
    }

    private static function publicPem(string $point): string
    {
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin(self::SPKI_PREFIX).$point), 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    // ---------------------------------------------------------------- VAPID

    public function authorization(string $endpoint, ?int $now = null): string
    {
        $aud = parse_url($endpoint, PHP_URL_SCHEME).'://'.parse_url($endpoint, PHP_URL_HOST);
        $input = self::b64(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])).'.'
            .self::b64(json_encode(['aud' => $aud, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $this->subject]));

        $pem = $this->ecPrivatePem();
        if (! openssl_sign($input, $der, $pem, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Gagal menandatangani token VAPID.');
        }

        return 'vapid t='.$input.'.'.self::b64(self::derToRaw($der)).', k='.$this->public;
    }

    private function ecPrivatePem(): string
    {
        $der = hex2bin('30770201010420').self::unb64($this->private)
            .hex2bin('a00a06082a8648ce3d030107a144034200').self::unb64($this->public);

        return "-----BEGIN EC PRIVATE KEY-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END EC PRIVATE KEY-----\n";
    }

    /** ECDSA DER -> R||S (64 byte) sebagaimana diminta JWT ES256. */
    public static function derToRaw(string $der): string
    {
        $off = 3;                       // 30 len 02
        if (ord($der[1]) & 0x80) {
            $off += ord($der[1]) & 0x7F;
        }
        $rLen = ord($der[$off]);
        $r = substr($der, $off + 1, $rLen);
        $off += 1 + $rLen + 1;          // lewati 02
        $sLen = ord($der[$off]);
        $s = substr($der, $off + 1, $sLen);

        return str_pad(ltrim($r, "\0"), 32, "\0", STR_PAD_LEFT).str_pad(ltrim($s, "\0"), 32, "\0", STR_PAD_LEFT);
    }

    // ------------------------------------------------------------ enkripsi

    /** Enkripsi aes128gcm (RFC 8291). $salt dan $asKey hanya untuk pengujian. */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?string $salt = null, $asKey = null): string
    {
        $uaPublic = self::unb64($p256dh);
        $authSecret = self::unb64($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) < 16) {
            throw new RuntimeException('Kunci langganan push tidak valid.');
        }

        $asKey ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::point(openssl_pkey_get_details($asKey)['ec']);
        $peer = openssl_pkey_get_public(self::publicPem($uaPublic));
        if (! $peer || ($shared = openssl_pkey_derive($peer, $asKey, 32)) === false) {
            throw new RuntimeException('Kunci langganan push tidak valid.');
        }

        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $cipher = openssl_encrypt($payload."\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

        return $salt.pack('N', 4096).chr(65).$asPublic.$cipher.$tag;
    }

    // ------------------------------------------------------------ pengiriman

    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || (isset($p['port']) && $p['port'] !== 443)) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach (config('notify.push_hosts', []) as $allowed) {
            if ($allowed === $host || (str_starts_with($allowed, '*.') && str_ends_with($host, substr($allowed, 1)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Kirim ke banyak langganan sekaligus (paralel per 20). Hasil per id langganan:
     * 'ok' | 'gone' (langganan sudah mati, hapus) | 'fail' (coba lagi nanti).
     *
     * @param  array<int,array{id:string,endpoint:string,p256dh:string,auth:string}>  $subs
     * @return array<string,string>
     */
    public function sendMany(array $subs, array $message, int $ttl = 86400): array
    {
        $out = [];
        $json = json_encode($message, JSON_UNESCAPED_UNICODE);

        foreach (array_chunk($subs, 20) as $chunk) {
            $ready = [];
            foreach ($chunk as $sub) {
                if (! $this->configured() || ! self::allowedEndpoint($sub['endpoint'])) {
                    $out[$sub['id']] = 'gone';

                    continue;
                }
                try {
                    $ready[$sub['id']] = [$sub, self::encrypt($json, $sub['p256dh'], $sub['auth'])];
                } catch (Throwable) {
                    $out[$sub['id']] = 'gone';
                }
            }
            if (! $ready) {
                continue;
            }

            try {
                $responses = Http::pool(function ($pool) use ($ready, $ttl) {
                    foreach ($ready as $id => [$sub, $body]) {
                        $pool->as($id)->withHeaders([
                            'Authorization' => $this->authorization($sub['endpoint']),
                            'Content-Encoding' => 'aes128gcm',
                            'TTL' => (string) $ttl,
                            'Urgency' => 'normal',
                        ])->withBody($body, 'application/octet-stream')->timeout(8)->post($sub['endpoint']);
                    }
                });
            } catch (Throwable) {
                foreach ($ready as $id => $_) {
                    $out[$id] = 'fail';
                }

                continue;
            }

            foreach ($ready as $id => $_) {
                $r = $responses[$id] ?? null;
                $out[$id] = match (true) {
                    $r instanceof \Illuminate\Http\Client\Response && $r->successful() => 'ok',
                    $r instanceof \Illuminate\Http\Client\Response && in_array($r->status(), [404, 410], true) => 'gone',
                    default => 'fail',
                };
            }
        }

        return $out;
    }
}
