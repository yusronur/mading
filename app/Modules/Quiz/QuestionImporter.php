<?php

namespace App\Modules\Quiz;

use DOMDocument;
use DOMXPath;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use ZipArchive;

/**
 * Impor soal dari templat Excel atau Word (QZ-07).
 *
 * Hasil parse: daftar baris
 *   {line, type, prompt, options[], key, explanation, topic, difficulty, points, images[], errors[], data?}
 * Baris tanpa error sudah berisi `data` hasil QuestionData::normalize.
 */
class QuestionImporter
{
    public const MAX_QUESTIONS = 300;

    private const TYPE_ALIASES = [
        'pg' => 'mc', 'pilihan ganda' => 'mc', 'mc' => 'mc', 'abcd' => 'mc',
        'pgk' => 'mcm', 'pg kompleks' => 'mcm', 'pilihan ganda kompleks' => 'mcm', 'mcm' => 'mcm', 'kompleks' => 'mcm',
        'bs' => 'tf', 'benar salah' => 'tf', 'benar/salah' => 'tf', 'b/s' => 'tf', 'tf' => 'tf',
        'is' => 'short', 'isian' => 'short', 'isian singkat' => 'short', 'short' => 'short', 'isian pendek' => 'short',
    ];

    private const DIFFICULTY = ['mudah' => 1, 'sedang' => 2, 'sulit' => 3, 'sukar' => 3, '1' => 1, '2' => 2, '3' => 3];

    public function parse(string $path, string $extension): array
    {
        $rows = match (strtolower($extension)) {
            'xlsx', 'xls', 'csv' => $this->parseSpreadsheet($path),
            'docx' => $this->parseDocx($path),
            default => throw new RuntimeException('Gunakan berkas .xlsx atau .docx dari templat.'),
        };

        if (! $rows) {
            throw new RuntimeException('Tidak ada soal yang terbaca. Pastikan memakai format templat.');
        }
        if (count($rows) > self::MAX_QUESTIONS) {
            throw new RuntimeException('Maksimal '.self::MAX_QUESTIONS.' soal per impor. Pecah menjadi beberapa berkas.');
        }

        return array_map(fn ($r) => $this->finish($r), $rows);
    }

    // ------------------------------------------------------------ Excel

    private function parseSpreadsheet(string $path): array
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $data = $reader->load($path)->getSheet(0)->toArray(null, true, false, false);
        } catch (\Throwable) {
            throw new RuntimeException('Berkas Excel tidak dapat dibaca.');
        }

        $aliases = [
            'type' => ['tipe', 'jenis', 'tipe soal'],
            'prompt' => ['soal', 'pertanyaan', 'teks soal'],
            'a' => ['a', 'opsi a', 'pilihan a'], 'b' => ['b', 'opsi b', 'pilihan b'], 'c' => ['c', 'opsi c', 'pilihan c'],
            'd' => ['d', 'opsi d', 'pilihan d'], 'e' => ['e', 'opsi e', 'pilihan e'], 'f' => ['f', 'opsi f', 'pilihan f'],
            'key' => ['kunci', 'kunci jawaban', 'jawaban'],
            'explanation' => ['pembahasan', 'penjelasan'],
            'topic' => ['topik', 'materi', 'label'],
            'difficulty' => ['kesulitan', 'tingkat kesulitan', 'level'],
            'points' => ['poin', 'skor', 'bobot'],
        ];

        $header = null;
        $cols = [];
        foreach (array_slice($data, 0, 10, true) as $i => $row) {
            $found = [];
            foreach ($row as $c => $v) {
                $v = strtolower(trim((string) $v));
                foreach ($aliases as $field => $names) {
                    if (! isset($found[$field]) && in_array($v, $names, true)) {
                        $found[$field] = $c;
                    }
                }
            }
            if (isset($found['prompt'], $found['key'])) {
                [$header, $cols] = [$i, $found];
                break;
            }
        }
        if ($header === null) {
            throw new RuntimeException('Kolom "Soal" dan "Kunci" tidak ditemukan. Gunakan templat Excel.');
        }

        $rows = [];
        for ($i = $header + 1; $i < count($data); $i++) {
            $get = fn (string $f) => isset($cols[$f]) ? trim((string) ($data[$i][$cols[$f]] ?? '')) : '';
            if ($get('prompt') === '' && $get('key') === '') {
                continue;
            }
            $options = [];
            foreach (QuestionData::OPTION_IDS as $l) {
                if (($t = $get($l)) !== '') {
                    $options[] = $t;
                }
            }
            $rows[] = [
                'line' => $i + 1,
                'type' => $get('type'),
                'prompt' => $get('prompt'),
                'options' => $options,
                'key' => $get('key'),
                'explanation' => $get('explanation'),
                'topic' => $get('topic'),
                'difficulty' => $get('difficulty'),
                'points' => $get('points'),
                'images' => [],
            ];
        }

        return $rows;
    }

    // ------------------------------------------------------------ Word

    private function parseDocx(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true || ($xml = $zip->getFromName('word/document.xml')) === false) {
            throw new RuntimeException('Berkas Word tidak dapat dibaca. Simpan sebagai .docx.');
        }

        $rels = [];
        if (($relXml = $zip->getFromName('word/_rels/document.xml.rels')) !== false) {
            $rd = new DOMDocument;
            $rd->loadXML($relXml, LIBXML_NONET);
            foreach ($rd->getElementsByTagName('Relationship') as $r) {
                $rels[$r->getAttribute('Id')] = $r->getAttribute('Target');
            }
        }

        $formats = $this->numberingFormats($zip);

        $doc = new DOMDocument;
        $doc->loadXML($xml, LIBXML_NONET | LIBXML_PARSEHUGE);
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $xp->registerNamespace('a', 'http://schemas.openxmlformats.org/drawingml/2006/main');
        $xp->registerNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/officeDocument/2006/math');

        $rows = [];
        $cur = null;
        $field = null; // bagian terakhir yang sedang diisi: prompt | explanation
        $push = function () use (&$cur, &$rows) {
            if ($cur) {
                $rows[] = $cur;
            }
            $cur = null;
        };

        foreach ($xp->query('//w:body//w:p') as $n => $p) {
            $text = '';
            foreach ($xp->query('.//w:t | .//w:tab | .//w:br | .//m:t', $p) as $node) {
                $text .= $node->localName === 't' ? $node->textContent : ' ';
            }
            $text = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $text));

            // Penomoran otomatis Word: nomornya tidak ada di teks, jadi tambahkan
            $numId = $xp->evaluate('string(w:pPr/w:numPr/w:numId/@w:val)', $p);
            if ($numId !== '' && $numId !== '0' && $text !== '') {
                $fmt = $formats[$numId][(int) $xp->evaluate('string(w:pPr/w:numPr/w:ilvl/@w:val)', $p)] ?? 'decimal';
                if (in_array($fmt, ['upperLetter', 'lowerLetter'], true)) {
                    $text = 'A. '.$text;
                } elseif ($fmt === 'decimal' && ! preg_match('/^\d{1,3}\s*[.)]/', $text)) {
                    $text = '1. '.$text;
                }
            }

            $images = [];
            foreach ($xp->query('.//a:blip/@r:embed', $p) as $attr) {
                $target = $rels[$attr->value] ?? null;
                if ($target && ($bin = $zip->getFromName('word/'.ltrim($target, '/'))) !== false) {
                    $images[] = ['name' => basename($target), 'content' => $bin];
                }
            }

            if (preg_match('/^(\d{1,3})\s*[.)]\s*(.*)$/su', $text, $m) && ! preg_match('/^\d+[.,]\d/', $text)) {
                $push();
                $cur = ['line' => count($rows) + 1, 'type' => '', 'prompt' => trim($m[2]), 'options' => [], 'key' => '', 'explanation' => '', 'topic' => '', 'difficulty' => '', 'points' => '', 'images' => $images];
                $field = 'prompt';

                continue;
            }
            if (! $cur) {
                continue; // judul/petunjuk sebelum soal pertama
            }

            if (preg_match('/^([A-Fa-f])\s*[.)]\s+(.+)$/su', $text, $m) && $field !== 'explanation') {
                $cur['options'][] = trim($m[2]);
                $field = 'option';
            } elseif (preg_match('/^(kunci(?:\s+jawaban)?|jawaban)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['key'] = trim($m[2]);
                $field = 'key';
            } elseif (preg_match('/^(pembahasan|penjelasan)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['explanation'] = trim($m[2]);
                $field = 'explanation';
            } elseif (preg_match('/^(topik|materi)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['topic'] = trim($m[2]);
            } elseif (preg_match('/^(kesulitan|tingkat)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['difficulty'] = trim($m[2]);
            } elseif (preg_match('/^(tipe|jenis)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['type'] = trim($m[2]);
            } elseif (preg_match('/^(poin|skor|bobot)\s*:\s*(.*)$/iu', $text, $m)) {
                $cur['points'] = trim($m[2]);
            } elseif ($field === 'prompt' && ($text !== '' || $images)) {
                if ($text !== '') {
                    $cur['prompt'] .= "\n".$text;
                }
                $cur['images'] = array_merge($cur['images'], $images);
            } elseif ($field === 'explanation' && $text !== '') {
                $cur['explanation'] .= "\n".$text;
            }
        }
        $push();
        $zip->close();

        return $rows;
    }

    /** numId => [ilvl => numFmt] dari word/numbering.xml */
    private function numberingFormats(ZipArchive $zip): array
    {
        if (($xml = $zip->getFromName('word/numbering.xml')) === false) {
            return [];
        }
        $d = new DOMDocument;
        $d->loadXML($xml, LIBXML_NONET);
        $xp = new DOMXPath($d);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

        $abstract = [];
        foreach ($xp->query('//w:abstractNum') as $an) {
            foreach ($xp->query('w:lvl', $an) as $lvl) {
                $abstract[$an->getAttribute('w:abstractNumId')][(int) $lvl->getAttribute('w:ilvl')] = $xp->evaluate('string(w:numFmt/@w:val)', $lvl);
            }
        }
        $out = [];
        foreach ($xp->query('//w:num') as $num) {
            $out[$num->getAttribute('w:numId')] = $abstract[$xp->evaluate('string(w:abstractNumId/@w:val)', $num)] ?? [];
        }

        return $out;
    }

    // ------------------------------------------------------------ Umum

    private function finish(array $r): array
    {
        $r['errors'] = [];
        $r['prompt'] = trim($r['prompt']);
        $r['images'] = array_slice($r['images'], 0, 4);

        if ($r['prompt'] === '' && ! $r['images']) {
            $r['errors'][] = 'Teks soal kosong.';
        }
        if (mb_strlen($r['prompt']) > 5000) {
            $r['errors'][] = 'Teks soal terlalu panjang.';
        }

        $type = $this->resolveType($r);
        $r['type'] = $type;

        if ($type === null) {
            $r['errors'][] = 'Tipe soal tidak dikenali (PG, PGK, BS, atau IS).';
        } elseif ($r['key'] === '') {
            $r['errors'][] = 'Kunci jawaban kosong.';
        } else {
            try {
                $r['data'] = QuestionData::normalize($type, match ($type) {
                    'mc', 'mcm' => ['options' => $r['options'], 'correct' => $this->letters($r['key'])],
                    'tf' => ['correct' => $r['key']],
                    'short' => ['answers' => preg_split('/\s*[|;]\s*/u', $r['key'])],
                });
            } catch (ValidationException $e) {
                $r['errors'][] = collect($e->errors())->flatten()->first();
            }
        }

        $d = strtolower(trim($r['difficulty']));
        $r['difficulty'] = self::DIFFICULTY[$d] ?? 2;
        $r['points'] = max(1, min(100, (int) ($r['points'] ?: 1)));
        $r['tags'] = array_values(array_filter(array_map('trim', preg_split('/[,;]/', $r['topic'])))) ;
        unset($r['topic']);

        return $r;
    }

    private function resolveType(array $r): ?string
    {
        $t = strtolower(trim($r['type']));
        if ($t !== '') {
            return self::TYPE_ALIASES[$t] ?? null;
        }

        $key = strtolower(trim($r['key']));
        if ($r['options']) {
            return count($this->letters($key)) > 1 ? 'mcm' : 'mc';
        }
        if (in_array($key, ['b', 's', 'benar', 'salah'], true)) {
            return 'tf';
        }

        return 'short';
    }

    /** "A, C" / "A;C" / "AC" -> ['a','c'] */
    private function letters(string $key): array
    {
        $key = strtolower(preg_replace('/[\s,;\/]+/', '', $key));

        return preg_match('/^[a-f]{1,6}$/', $key) ? str_split($key) : [$key];
    }
}
