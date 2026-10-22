<?php

namespace App\Modules\Board;

use App\Models\SchoolSetting;

/**
 * Filter kata kasar Bahasa Indonesia (MOD-02), termasuk slang, singkatan, dan
 * penyamaran sederhana (angka pengganti huruf, huruf diulang, diselipi titik).
 * Teks yang kena tidak ditolak, tetapi ditahan untuk diperiksa guru.
 * Admin sekolah dapat menambah kata atau mengecualikan kata bawaan.
 */
class ProfanityFilter
{
    public const DEFAULT_WORDS = [
        // umpatan umum
        'anjing', 'anjir', 'anjrit', 'anjay', 'njing', 'babi', 'bangsat', 'bajingan', 'brengsek', 'keparat', 'kampret',
        'bego', 'goblok', 'goblog', 'tolol', 'idiot', 'dungu', 'tai', 'taik', 'monyet',
        'sialan', 'sompret', 'asu', 'jancok', 'jancuk', 'cok', 'cuk', 'jamput', 'matamu', 'ndasmu', 'gendeng', 'kontol',
        'memek', 'ngentot', 'ngewe', 'entot', 'jembut', 'pepek', 'titit', 'peler', 'pelacur', 'lonte', 'perek', 'sundal',
        'bencong', 'coli', 'colmek', 'sange', 'bokep', 'mesum', 'tetek', 'toket', 'pantek',
        'puki', 'pukimak', 'kimak', 'cibai', 'bangke',
        // kata yang juga dipakai dalam pelajaran (mis. setan, bangkai, cacat) sengaja tidak dimasukkan;
        // admin dapat menambahkannya di Pengaturan bila perlu
        // singkatan dan ejaan slang
        'anj', 'ajg', 'anjg', 'anjgg', 'bgst', 'bngst', 'bgsd', 'bjgn', 'kntl', 'kntol', 'mmk', 'ngntd', 'ngtd', 'ngnt',
        'gblk', 'gblg', 'tll', 'tlol', 'bgo', 'jnck', 'jncuk', 'jnc', 'cuki', 'tae', 'tlo', 'pntk', 'kmprt', 'bacot', 'bct',
        'fuck', 'fck', 'fak', 'shit', 'bitch', 'asshole', 'bastard', 'dick', 'pussy', 'wtf', 'stfu',
    ];

    private const LEET = ['4' => 'a', '@' => 'a', '1' => 'i', '!' => 'i', '3' => 'e', '0' => 'o', '5' => 's', '$' => 's', '7' => 't', '9' => 'g', '8' => 'b', '6' => 'g', '2' => 'z'];

    private ?array $words = null;

    public function __construct(private ?array $extra = null, private ?array $allowed = null) {}

    /** Kata kasar pertama yang ditemukan, atau null. */
    public function find(?string $text): ?string
    {
        if ($text === null || trim($text) === '') {
            return null;
        }

        $words = $this->words();
        $text = mb_strtolower(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        // "a.n.j.i.n.g" / "a n j i n g" -> "anjing"
        $text = preg_replace_callback('/\b(?:\p{L}[\s.\-_*]){2,}\p{L}\b/u', fn ($m) => preg_replace('/[\s.\-_*]/u', '', $m[0]), $text);

        foreach (preg_split('/[^\p{L}\p{N}@$!]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            foreach ($this->variants($token) as $variant) {
                if (isset($words[$variant])) {
                    return $variant;
                }
            }
        }

        return null;
    }

    public function words(): array
    {
        if ($this->words !== null) {
            return $this->words;
        }

        $settings = ($this->extra === null || $this->allowed === null) ? (SchoolSetting::first()?->settings ?? []) : [];
        $extra = $this->extra ?? ($settings['bad_words'] ?? []);
        $allowed = array_map('mb_strtolower', $this->allowed ?? ($settings['allowed_words'] ?? []));

        $list = array_diff(array_merge(self::DEFAULT_WORDS, array_map(fn ($w) => mb_strtolower(trim($w)), $extra)), $allowed);

        return $this->words = array_fill_keys(array_filter($list), true);
    }

    /** Bentuk asli, ganti angka/simbol, dan huruf berulang diringkas. */
    private function variants(string $token): array
    {
        $leet = strtr($token, self::LEET);
        $squeezed = preg_replace('/(\p{L})\1+/u', '$1', $leet);
        // "anjiiing" -> "anjing" (huruf ganda asli dipertahankan sekali)
        $twice = preg_replace('/(\p{L})\1{2,}/u', '$1$1', $leet);

        return array_unique([$token, $leet, $squeezed, $twice]);
    }
}
