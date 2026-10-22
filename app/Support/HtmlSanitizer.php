<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Pembersih HTML berbasis daftar putih untuk isi kartu/komentar (PST-01).
 * Hanya format sederhana: paragraf, tebal, miring, garis bawah, coret,
 * daftar, kutipan, dan tautan http/https/mailto. Semua atribut lain dibuang.
 */
class HtmlSanitizer
{
    private const ALLOWED = ['p', 'br', 'strong', 'em', 'u', 's', 'ul', 'ol', 'li', 'a', 'blockquote', 'code'];

    private const RENAME = ['b' => 'strong', 'i' => 'em', 'strike' => 's', 'del' => 's', 'div' => 'p', 'h1' => 'p', 'h2' => 'p', 'h3' => 'p', 'h4' => 'p'];

    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'form', 'textarea', 'select', 'button'];

    public function clean(?string $html, int $maxLength = 20000): ?string
    {
        if ($html === null || trim($html) === '') {
            return null;
        }

        $html = mb_substr($html, 0, $maxLength * 2);

        $doc = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="root">'.$html.'</div>', LIBXML_NONET | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $doc->getElementById('root');
        if (! $root) {
            return null;
        }

        $out = new DOMDocument;
        $container = $out->createElement('div');
        $out->appendChild($container);
        $this->copyChildren($root, $container, $out);

        $result = '';
        foreach ($container->childNodes as $child) {
            $result .= $out->saveHTML($child);
        }

        $result = trim($result);

        // Teks tanpa paragraf dibungkus agar konsisten
        if ($result !== '' && ! preg_match('/^<(p|ul|ol|blockquote)\b/', $result)) {
            $result = '<p>'.$result.'</p>';
        }

        return $this->isEmpty($result) ? null : mb_substr($result, 0, $maxLength);
    }

    /** Teks polos untuk filter kata, pencarian, dan ekspor. */
    public static function toText(?string $html): string
    {
        if (! $html) {
            return '';
        }
        $html = preg_replace('#<(br|/p|/li|/blockquote)\b[^>]*>#i', "\n", $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function copyChildren(DOMNode $from, DOMNode $to, DOMDocument $doc): void
    {
        foreach ($from->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $to->appendChild($doc->createTextNode($node->nodeValue));

                continue;
            }

            if (! $node instanceof DOMElement) {
                continue; // komentar, CDATA, dll.
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                continue;
            }

            $tag = self::RENAME[$tag] ?? $tag;

            if (! in_array($tag, self::ALLOWED, true)) {
                // Tag tak dikenal: ambil isinya saja
                $this->copyChildren($node, $to, $doc);

                continue;
            }

            // Hindari <p> di dalam <p>
            if ($tag === 'p' && $to instanceof DOMElement && in_array(strtolower($to->tagName), ['p', 'li'], true)) {
                $this->copyChildren($node, $to, $doc);

                continue;
            }

            $el = $doc->createElement($tag);

            if ($tag === 'a') {
                $href = $this->safeHref($node->getAttribute('href'));
                if ($href === null) {
                    $this->copyChildren($node, $to, $doc);

                    continue;
                }
                $el->setAttribute('href', $href);
                $el->setAttribute('target', '_blank');
                $el->setAttribute('rel', 'noopener noreferrer nofollow ugc');
            }

            $to->appendChild($el);
            $this->copyChildren($node, $el, $doc);
        }
    }

    private function safeHref(string $href): ?string
    {
        $href = trim($href);
        if ($href === '' || strlen($href) > 2000) {
            return null;
        }

        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https', 'mailto'], true) ? $href : null;
    }

    private function isEmpty(string $html): bool
    {
        return trim(str_replace("\u{00A0}", ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) === '';
    }
}
