<?php

namespace App\Core\Theme;

/**
 * Mengubah token tema menjadi CSS variable (ADR-008).
 * Hanya nilai yang lolos validasi yang dicetak, sehingga tema kustom
 * sekolah kelak tidak bisa menyisipkan CSS berbahaya.
 */
class ThemeCss
{
    public const COLOR_KEYS = [
        'bg', 'surface', 'surface2', 'border', 'text', 'muted', 'accent', 'accent_text',
        'accent2', 'accent3', 'success', 'warning', 'danger',
    ];

    public const FONTS = ['Fredoka', 'Baloo 2', 'Nunito', 'Inter'];

    public static function render(array $tokens): string
    {
        $base = $tokens['base'] ?? [];
        $light = self::vars($tokens['light'] ?? []);
        $dark = self::vars($tokens['dark'] ?? []);

        $common = [];
        if (isset($base['radius']) && preg_match('/^\d{1,2}px$/', $base['radius'])) {
            $common[] = '--m-radius:'.$base['radius'];
        }
        foreach (['font_display' => '--m-font-display', 'font_body' => '--m-font-body'] as $key => $var) {
            $font = $base[$key] ?? 'Nunito';
            if (in_array($font, self::FONTS, true)) {
                $common[] = $var.":'".$font."', ui-sans-serif, system-ui, sans-serif";
            }
        }
        $common = implode(';', $common);

        // data-mode di <html>: light | dark | system
        return ":root{{$common};{$light}}"
            .":root[data-mode=dark]{{$dark}}"
            ."@media (prefers-color-scheme: dark){:root[data-mode=system]{{$dark}}}";
    }

    private static function vars(array $palette): string
    {
        $out = [];

        foreach (self::COLOR_KEYS as $key) {
            $value = $palette[$key] ?? null;
            if (is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value)) {
                $out[] = '--m-'.str_replace('_', '-', $key).':'.$value;
            }
        }

        $shadow = $palette['shadow'] ?? null;
        if (is_string($shadow) && preg_match('/^[0-9a-z #(),.\-]{1,160}$/i', $shadow)) {
            $out[] = '--m-shadow:'.$shadow;
        }

        return implode(';', $out);
    }

    /** Rasio kontras WCAG antara dua warna hex. */
    public static function contrast(string $a, string $b): float
    {
        $l1 = self::luminance($a);
        $l2 = self::luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $rgb = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $lin = array_map(function ($c) {
            $c /= 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $lin[0] + 0.7152 * $lin[1] + 0.0722 * $lin[2];
    }
}
