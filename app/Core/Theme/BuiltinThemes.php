<?php

namespace App\Core\Theme;

/**
 * Tema bawaan (Blueprint Bab 7.3, THM-02).
 * Setiap tema punya varian terang dan gelap. Semua pasangan teks/latar
 * diuji kontras AA (4,5:1) di tests/Unit/ThemeContrastTest.php.
 */
class BuiltinThemes
{
    public static function all(): array
    {
        return [
            [
                'key' => 'neon',
                'name' => 'Neon Gelap',
                'sort' => 1,
                'tokens' => [
                    'base' => [
                        'radius' => '18px',
                        'font_display' => 'Fredoka',
                        'font_body' => 'Nunito',
                        'preferred_mode' => 'dark',
                    ],
                    'dark' => [
                        'bg' => '#0E0B1F',
                        'surface' => '#1A1533',
                        'surface2' => '#241D45',
                        'border' => '#3A3166',
                        'text' => '#F3F0FF',
                        'muted' => '#B3ABD9',
                        'accent' => '#7CFF6B',
                        'accent_text' => '#0E0B1F',
                        'accent2' => '#FF4FD8',
                        'accent3' => '#29E7FF',
                        'success' => '#7CFF6B',
                        'warning' => '#FFD43B',
                        'danger' => '#FF7A93',
                        'shadow' => '0 0 0 1px rgba(124,255,107,0.18), 0 8px 24px rgba(0,0,0,0.45)',
                    ],
                    'light' => [
                        'bg' => '#F4F1FF',
                        'surface' => '#FFFFFF',
                        'surface2' => '#ECE7FF',
                        'border' => '#D9D2F5',
                        'text' => '#1B1530',
                        'muted' => '#5A5178',
                        'accent' => '#5B21B6',
                        'accent_text' => '#FFFFFF',
                        'accent2' => '#B0177F',
                        'accent3' => '#0E7490',
                        'success' => '#15803D',
                        'warning' => '#A16207',
                        'danger' => '#BE123C',
                        'shadow' => '0 6px 0 rgba(91,33,182,0.12)',
                    ],
                ],
            ],
            [
                'key' => 'pastel',
                'name' => 'Pastel Cerah',
                'sort' => 2,
                'tokens' => [
                    'base' => [
                        'radius' => '22px',
                        'font_display' => 'Baloo 2',
                        'font_body' => 'Nunito',
                        'preferred_mode' => 'light',
                    ],
                    'light' => [
                        'bg' => '#FFF8F1',
                        'surface' => '#FFFFFF',
                        'surface2' => '#FFF0E3',
                        'border' => '#F1DFD0',
                        'text' => '#2B2240',
                        'muted' => '#6B6183',
                        'accent' => '#6D4AE0',
                        'accent_text' => '#FFFFFF',
                        'accent2' => '#C2416C',
                        'accent3' => '#1F7A6B',
                        'success' => '#1F7A4D',
                        'warning' => '#9A5B00',
                        'danger' => '#C0392B',
                        'shadow' => '0 6px 0 rgba(43,34,64,0.08)',
                    ],
                    'dark' => [
                        'bg' => '#1F1B2E',
                        'surface' => '#2A2540',
                        'surface2' => '#342E4F',
                        'border' => '#463F63',
                        'text' => '#FFF5EC',
                        'muted' => '#CFC4E0',
                        'accent' => '#C4B5FD',
                        'accent_text' => '#1F1B2E',
                        'accent2' => '#FFB3C7',
                        'accent3' => '#9BE7D6',
                        'success' => '#9BE7B0',
                        'warning' => '#FFD58A',
                        'danger' => '#FF9C9C',
                        'shadow' => '0 6px 0 rgba(0,0,0,0.25)',
                    ],
                ],
            ],
        ];
    }
}
