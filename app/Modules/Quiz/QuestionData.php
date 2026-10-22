<?php

namespace App\Modules\Quiz;

use App\Models\Question;
use Illuminate\Validation\ValidationException;

/**
 * Bentuk data tiap tipe soal, validasinya, versi aman untuk siswa, dan
 * penilaiannya. Penilaian selalu di server; kunci tidak dikirim ke siswa (QZ-18).
 *
 *   mc    {options: [{id, text}], answer: id}
 *   mcm   {options: [{id, text}], answers: [id, ...]}
 *   tf    {answer: bool}
 *   short {answers: [string, ...]}   (huruf besar/kecil dan spasi diabaikan)
 *   match {pairs: [{id, left, right_id}], rights: [{id, text}]}
 *         `rights` memuat jawaban benar + pengecoh; id kanan acak sehingga urutan
 *         dan id tidak membocorkan pasangannya. Jawaban siswa: {id_kiri: id_kanan}.
 */
class QuestionData
{
    public const MAX_OPTIONS = 6;

    public const OPTION_IDS = ['a', 'b', 'c', 'd', 'e', 'f'];

    public const MAX_PAIRS = 8;

    public const MAX_EXTRAS = 4;

    /**
     * Normalisasi masukan editor/impor. Opsi diberi id a-f sesuai urutan.
     *
     * @param  array{options?: array, correct?: array|string|int|bool|null, answers?: array}  $in
     */
    public static function normalize(string $type, array $in): array
    {
        $fail = fn (string $msg) => throw ValidationException::withMessages(['spec' => $msg]);

        switch ($type) {
            case 'mc':
            case 'mcm':
                $texts = array_values(array_filter(array_map(
                    fn ($o) => trim(strip_tags((string) (is_array($o) ? ($o['text'] ?? '') : $o))),
                    $in['options'] ?? []
                ), fn ($t) => $t !== ''));

                if (count($texts) < 2) {
                    $fail('Isi minimal 2 pilihan jawaban.');
                }
                if (count($texts) > self::MAX_OPTIONS) {
                    $fail('Maksimal '.self::MAX_OPTIONS.' pilihan jawaban.');
                }
                foreach ($texts as $t) {
                    if (mb_strlen($t) > 1000) {
                        $fail('Teks pilihan jawaban terlalu panjang (maks. 1000 karakter).');
                    }
                }

                $options = array_map(fn ($t, $i) => ['id' => self::OPTION_IDS[$i], 'text' => $t], $texts, array_keys($texts));
                $ids = array_column($options, 'id');

                // "correct" berupa indeks (0..n) dari editor, atau huruf dari impor
                $correct = array_values(array_unique(array_filter(array_map(
                    fn ($c) => is_int($c) || ctype_digit((string) $c) ? (self::OPTION_IDS[(int) $c] ?? null) : strtolower(trim((string) $c)),
                    (array) ($in['correct'] ?? [])
                ), fn ($c) => $c !== null && in_array($c, $ids, true))));
                sort($correct);

                if ($type === 'mc') {
                    if (count($correct) !== 1) {
                        $fail('Pilih tepat satu jawaban benar.');
                    }

                    return ['options' => $options, 'answer' => $correct[0]];
                }

                if (count($correct) < 1) {
                    $fail('Tandai minimal satu jawaban benar.');
                }

                return ['options' => $options, 'answers' => $correct];

            case 'tf':
                $v = $in['correct'] ?? null;
                if (is_string($v)) {
                    $v = match (strtolower(trim($v))) {
                        'b', 'benar', 'true', '1', 'ya', 't' => true,
                        's', 'salah', 'false', '0', 'tidak', 'f' => false,
                        default => null,
                    };
                }
                if (! is_bool($v) && ! is_int($v)) {
                    $fail('Pilih Benar atau Salah sebagai kunci.');
                }

                return ['answer' => (bool) $v];

            case 'short':
                $answers = array_values(array_unique(array_filter(array_map(
                    fn ($a) => mb_substr(trim(strip_tags((string) $a)), 0, 200),
                    $in['answers'] ?? []
                ), fn ($a) => $a !== '')));
                if (! $answers) {
                    $fail('Isi minimal satu kunci jawaban.');
                }
                if (count($answers) > 10) {
                    $fail('Maksimal 10 kunci jawaban.');
                }

                return ['answers' => $answers];

            case 'match':
                $clip = fn ($v) => trim(strip_tags((string) $v));
                $rows = [];
                foreach ((array) ($in['pairs'] ?? []) as $p) {
                    $l = $clip(is_array($p) ? ($p['left'] ?? '') : '');
                    $r = $clip(is_array($p) ? ($p['right'] ?? '') : '');
                    if ($l === '' && $r === '') {
                        continue;
                    }
                    if ($l === '' || $r === '') {
                        $fail('Setiap pasangan harus punya sisi kiri dan sisi kanan.');
                    }
                    if (mb_strlen($l) > 200 || mb_strlen($r) > 200) {
                        $fail('Teks pasangan terlalu panjang (maks. 200 karakter).');
                    }
                    $rows[] = [$l, $r];
                }
                if (count($rows) < 2) {
                    $fail('Isi minimal 2 pasangan.');
                }
                if (count($rows) > self::MAX_PAIRS) {
                    $fail('Maksimal '.self::MAX_PAIRS.' pasangan.');
                }
                $extras = array_values(array_unique(array_filter(array_map(fn ($e) => mb_substr($clip($e), 0, 200), (array) ($in['extras'] ?? [])), fn ($e) => $e !== '')));
                if (count($extras) > self::MAX_EXTRAS) {
                    $fail('Maksimal '.self::MAX_EXTRAS.' pilihan pengecoh.');
                }

                $newId = fn () => 'r'.bin2hex(random_bytes(3));
                $pairs = [];
                $rights = [];
                foreach ($rows as $i => [$l, $r]) {
                    $rid = $newId();
                    $pairs[] = ['id' => 'p'.($i + 1), 'left' => $l, 'right_id' => $rid];
                    $rights[] = ['id' => $rid, 'text' => $r];
                }
                foreach ($extras as $e) {
                    $rights[] = ['id' => $newId(), 'text' => $e];
                }

                return ['pairs' => $pairs, 'rights' => $rights];
        }

        $fail('Tipe soal tidak dikenal.');
    }

    /** Data untuk editor guru: opsi + indeks jawaban benar. */
    public static function forEditor(Question $q): array
    {
        $d = $q->data;

        return match ($q->type) {
            'mc' => ['options' => array_column($d['options'], 'text'), 'correct' => [array_search($d['answer'], self::OPTION_IDS, true)]],
            'mcm' => ['options' => array_column($d['options'], 'text'), 'correct' => array_map(fn ($a) => array_search($a, self::OPTION_IDS, true), $d['answers'])],
            'tf' => ['correct' => $d['answer']],
            'short' => ['answers' => $d['answers']],
            'match' => self::matchForEditor($d),
            default => [],
        };
    }

    private static function matchForEditor(array $d): array
    {
        $text = array_column($d['rights'], 'text', 'id');
        $used = array_column($d['pairs'], 'right_id');

        return [
            'pairs' => array_map(fn ($p) => ['left' => $p['left'], 'right' => $text[$p['right_id']] ?? ''], $d['pairs']),
            'extras' => array_values(array_map(fn ($r) => $r['text'], array_filter($d['rights'], fn ($r) => ! in_array($r['id'], $used, true)))),
        ];
    }

    /** Data aman untuk siswa: tanpa kunci. */
    public static function forStudent(Question $q, bool $shuffle = false, ?int $seed = null): array
    {
        return match ($q->type) {
            'mc', 'mcm' => ['options' => $shuffle ? self::shuffle($q->data['options'], $seed) : $q->data['options'], 'multiple' => $q->type === 'mcm'],
            'tf' => ['options' => [['id' => 'true', 'text' => 'Benar'], ['id' => 'false', 'text' => 'Salah']], 'multiple' => false],
            // Kolom kanan SELALU diacak bila ada seed, kalau tidak urutannya membocorkan pasangan
            'match' => [
                'lefts' => array_map(fn ($p) => ['id' => $p['id'], 'text' => $p['left']], $q->data['pairs']),
                'rights' => $seed !== null ? self::shuffle($q->data['rights'], $seed) : $q->data['rights'],
            ],
            default => [],
        };
    }

    /** Kunci jawaban dalam bentuk yang bisa ditampilkan saat pembahasan. */
    public static function answerKey(Question $q): array
    {
        return match ($q->type) {
            'mc' => [$q->data['answer']],
            'mcm' => $q->data['answers'],
            'tf' => [$q->data['answer'] ? 'true' : 'false'],
            'short' => $q->data['answers'],
            'match' => array_map(fn ($p) => ['left' => $p['left'], 'right' => self::rightText($q->data, $p['right_id'])], $q->data['pairs']),
            default => [],
        };
    }

    private static function rightText(array $data, ?string $id): string
    {
        return (string) (array_column($data['rights'], 'text', 'id')[$id] ?? '');
    }

    /**
     * Benar/salah tiap pasangan. Dua kartu kanan berteks sama dianggap setara.
     *
     * @return array<string, bool> id pasangan => benar
     */
    public static function pairResults(array $data, mixed $response): array
    {
        $out = [];
        foreach ($data['pairs'] as $p) {
            $given = is_array($response) ? ($response[$p['id']] ?? null) : null;
            $out[$p['id']] = is_string($given) && self::rightText($data, $given) !== ''
                && self::rightText($data, $given) === self::rightText($data, $p['right_id']);
        }

        return $out;
    }

    /**
     * Nilai satu jawaban. Pilihan ganda kompleks: semua-atau-tidak.
     * Menjodohkan: nilai sebagian, sebanding dengan jumlah pasangan yang benar.
     *
     * @return array{correct: bool, ratio: float}
     */
    public static function grade(Question $q, mixed $response): array
    {
        if ($q->type === 'match') {
            $res = self::pairResults($q->data, $response);
            $ok = count(array_filter($res));

            return ['correct' => $ok === count($res), 'ratio' => count($res) ? round($ok / count($res), 4) : 0.0];
        }

        $correct = match ($q->type) {
            'mc' => is_string($response) && $response === $q->data['answer'],
            'mcm' => is_array($response) && self::sameSet($response, $q->data['answers']),
            'tf' => in_array($response, ['true', 'false', true, false], true)
                && (($response === true || $response === 'true') === (bool) $q->data['answer']),
            'short' => is_string($response) && in_array(self::normalizeText($response), array_map([self::class, 'normalizeText'], $q->data['answers']), true),
            default => false,
        };

        return ['correct' => $correct, 'ratio' => $correct ? 1.0 : 0.0];
    }

    /** Huruf kecil, spasi dirapikan, titik akhir & koma desimal disamakan. */
    public static function normalizeText(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/\s+/u', ' ', $s);
        $s = rtrim($s, '.!');
        $s = preg_replace('/(\d),(\d)/', '$1.$2', $s);

        return trim($s);
    }

    private static function sameSet(array $a, array $b): bool
    {
        $a = array_values(array_unique(array_map('strval', $a)));
        $b = array_values(array_unique(array_map('strval', $b)));
        sort($a);
        sort($b);

        return $a === $b;
    }

    private static function shuffle(array $items, ?int $seed): array
    {
        if ($seed !== null) {
            mt_srand($seed);
        }
        for ($i = count($items) - 1; $i > 0; $i--) {
            $j = $seed !== null ? mt_rand(0, $i) : random_int(0, $i);
            [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
        }
        if ($seed !== null) {
            mt_srand();
        }

        return $items;
    }
}
