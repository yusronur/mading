<?php

namespace Tests\Unit;

use App\Modules\Notify\WebPush;
use PHPUnit\Framework\TestCase;

/** Enkripsi Web Push dan token VAPID diuji bolak-balik tanpa jaringan. */
class WebPushTest extends TestCase
{
    public function test_payload_round_trips_with_subscriber_key(): void
    {
        // Peramban: pasangan kunci + rahasia auth
        $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $ec = openssl_pkey_get_details($ua)['ec'];
        $uaPublic = "\x04".str_pad($ec['x'], 32, "\0", STR_PAD_LEFT).str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);

        $message = json_encode(['title' => 'Kuis baru: Ekosistem ✓', 'body' => 'Tenggat besok', 'url' => '/']);
        $blob = WebPush::encrypt($message, WebPush::b64($uaPublic), WebPush::b64($auth));

        // Bongkar seperti peramban (RFC 8291 §3.4)
        $salt = substr($blob, 0, 16);
        $this->assertSame(4096, unpack('N', substr($blob, 16, 4))[1]);
        $idLen = ord($blob[20]);
        $this->assertSame(65, $idLen);
        $asPublic = substr($blob, 21, $idLen);
        $cipher = substr($blob, 21 + $idLen);

        $spki = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$asPublic;
        $peer = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki), 64, "\n")."-----END PUBLIC KEY-----\n");
        $shared = openssl_pkey_derive($peer, $ua, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0".$uaPublic.$asPublic, $auth);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        $this->assertNotFalse($plain, 'dekripsi harus berhasil');
        $this->assertSame($message."\x02", $plain);
    }

    public function test_vapid_token_is_a_valid_es256_jwt(): void
    {
        $keys = WebPush::generateKeys();
        $this->assertSame(65, strlen(WebPush::unb64($keys['public'])));
        $this->assertSame(32, strlen(WebPush::unb64($keys['private'])));

        $push = new WebPush($keys['public'], $keys['private'], 'mailto:admin@sekolah.test');
        $header = $push->authorization('https://fcm.googleapis.com/fcm/send/abc', 1_800_000_000);
        $this->assertMatchesRegularExpression('/^vapid t=([\w-]+)\.([\w-]+)\.([\w-]+), k='.preg_quote($keys['public'], '/').'$/', $header);

        preg_match('/t=([\w-]+)\.([\w-]+)\.([\w-]+),/', $header, $m);
        $claims = json_decode(WebPush::unb64($m[2]), true);
        $this->assertSame('https://fcm.googleapis.com', $claims['aud']);
        $this->assertSame(1_800_000_000 + 43200, $claims['exp']);

        // Tanda tangan R||S diubah kembali ke DER lalu diverifikasi dengan kunci publik
        $raw = WebPush::unb64($m[3]);
        $this->assertSame(64, strlen($raw));
        $der = $this->rawToDer($raw);
        $pub = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode(hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').WebPush::unb64($keys['public'])), 64, "\n")."-----END PUBLIC KEY-----\n");
        $this->assertSame(1, openssl_verify($m[1].'.'.$m[2], $der, $pub, OPENSSL_ALGO_SHA256));
    }

    private function rawToDer(string $raw): string
    {
        $int = function (string $b) {
            $b = ltrim($b, "\0");
            if ($b === '' || ord($b[0]) & 0x80) {
                $b = "\0".$b;
            }

            return "\x02".chr(strlen($b)).$b;
        };
        $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

        return "\x30".chr(strlen($body)).$body;
    }
}
