<?php

namespace Tests\Unit;

use App\Support\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

class HtmlSanitizerTest extends TestCase
{
    private function clean(string $html): ?string
    {
        return (new HtmlSanitizer)->clean($html);
    }

    public function test_keeps_simple_formatting(): void
    {
        $this->assertSame('<p><strong>Tebal</strong> <em>miring</em> <u>garis</u></p><ul><li>satu</li></ul>',
            $this->clean('<p><b>Tebal</b> <i>miring</i> <u>garis</u></p><ul><li>satu</li></ul>'));
    }

    public function test_removes_scripts_handlers_and_dangerous_links(): void
    {
        $out = $this->clean('<p onclick="x()">Hai<script>alert(1)</script><img src=x onerror=alert(1)><a href="javascript:alert(1)">klik</a><iframe src="//evil"></iframe></p><style>p{}</style>');
        $this->assertSame('<p>Haiklik</p>', $out);
    }

    public function test_links_get_safe_attributes(): void
    {
        $out = $this->clean('<a href="https://contoh.id" style="color:red" class="x">web</a>');
        $this->assertSame('<p><a href="https://contoh.id" target="_blank" rel="noopener noreferrer nofollow ugc">web</a></p>', $out);
    }

    public function test_plain_text_is_wrapped_and_escaped(): void
    {
        $this->assertSame('<p>1 &lt; 2 &amp; 3</p>', $this->clean('1 &lt; 2 &amp; 3'));
        $this->assertNull($this->clean('<p>   </p><p><br></p>'));
    }

    public function test_to_text(): void
    {
        $this->assertSame("Baris 1\nBaris 2", HtmlSanitizer::toText('<p>Baris 1</p><p>Baris 2</p>'));
    }
}
