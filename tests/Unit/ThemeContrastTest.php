<?php

namespace Tests\Unit;

use App\Core\Theme\BuiltinThemes;
use App\Core\Theme\ThemeCss;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** THM-05 / Blueprint 3.5: kontras teks minimal WCAG AA (4,5:1). */
class ThemeContrastTest extends TestCase
{
    public static function palettes(): array
    {
        $cases = [];
        foreach (BuiltinThemes::all() as $theme) {
            foreach (['light', 'dark'] as $mode) {
                $cases["{$theme['key']}-{$mode}"] = [$theme['tokens'][$mode]];
            }
        }

        return $cases;
    }

    #[DataProvider('palettes')]
    public function test_text_pairs_meet_aa(array $p): void
    {
        $pairs = [
            ['text', 'bg'], ['text', 'surface'], ['text', 'surface2'],
            ['muted', 'surface'], ['muted', 'bg'],
            ['accent_text', 'accent'],
            ['danger', 'surface'], ['success', 'surface'], ['warning', 'surface'],
        ];

        foreach ($pairs as [$fg, $bg]) {
            $ratio = ThemeCss::contrast($p[$fg], $p[$bg]);
            $this->assertGreaterThanOrEqual(4.5, $ratio, "Kontras {$fg} di atas {$bg} hanya ".round($ratio, 2));
        }
    }

    public function test_css_output_rejects_unsafe_values(): void
    {
        $css = ThemeCss::render([
            'base' => ['radius' => '18px;}body{display:none', 'font_display' => 'Comic</style>'],
            'light' => ['bg' => '#FFFFFF', 'text' => 'red;}', 'shadow' => 'url(javascript:alert(1))'],
            'dark' => [],
        ]);

        $this->assertStringContainsString('--m-bg:#FFFFFF', $css);
        $this->assertStringNotContainsString('display:none', $css);
        $this->assertStringNotContainsString('</style>', $css);
        $this->assertStringNotContainsString('red;', $css);
        $this->assertStringNotContainsString('javascript', $css);
    }
}
