<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/inc/lists.php';

use PHPUnit\Framework\TestCase;

final class CommonFunctionsTest extends TestCase
{
    public function testGenerateKeyDefaultSize(): void
    {
        $key = generate_key();
        $this->assertIsString($key);
        $this->assertEquals(10, strlen($key));
    }

    public function testGenerateKeyCustomSize(): void
    {
        $key = generate_key(20);
        $this->assertEquals(20, strlen($key));
    }

    public function testGenerateSlug(): void
    {
        $slug = generate_slug("São Paulo");
        $this->assertEquals("sao-paulo", $slug);
    }

    public function testGenerateSlugWithSpaces(): void
    {
        $slug = generate_slug("Hello World");
        $this->assertEquals("hello-world", $slug);
    }

    public function testRemoveAccents(): void
    {
        $text = remove_accents("José São Água");
        $this->assertEquals("Jose Sao Agua", $text);
    }

    public function testUpAccents(): void
    {
        $text = up_accents("josé");
        $this->assertEquals("JOSÉ", $text);
    }

    public function testDownAccents(): void
    {
        $text = down_accents("JOSÉ");
        $this->assertEquals("josé", $text);
    }

    public function testSanitizeString(): void
    {
        $text = sanitize_string("abc123!@");
        $this->assertEquals("abc123", $text);
    }

    public function testSanitizeStringDigitsOnly(): void
    {
        $text = sanitize_string("CPF 123.456.789-00", true);
        $this->assertEquals("12345678900", $text);
    }

    public function testSanitizeStringNullReturnsNull(): void
    {
        $this->assertNull(sanitize_string(null));
    }

    public function testSetUrlAddsParams(): void
    {
        $url = set_url("http://example.com", ["page" => "2"]);
        $this->assertStringContainsString("page=2", $url);
    }

    public function testSetUrlPreservesExistingParams(): void
    {
        $url = set_url("http://example.com?a=1", ["b" => "2"]);
        $this->assertStringContainsString("a=1", $url);
        $this->assertStringContainsString("b=2", $url);
    }

    public function testSetUrlOverridesExistingParam(): void
    {
        $url = set_url("/lista?page=1&sort=asc", ["page" => "2"]);
        $this->assertSame("/lista?sort=asc&page=2", $url);
    }

    public function test_csv_sanitize_cell_prefixes_formula_leading_chars(): void
    {
        $this->assertSame("'=HYPERLINK(\"x\")", csv_sanitize_cell('=HYPERLINK("x")'));
        $this->assertSame("'+1+1", csv_sanitize_cell('+1+1'));
        $this->assertSame("'-2", csv_sanitize_cell('-2'));
        $this->assertSame("'@SUM(1)", csv_sanitize_cell('@SUM(1)'));
        // Benign values pass through untouched
        $this->assertSame('Carlos', csv_sanitize_cell('Carlos'));
        $this->assertSame('a@b.com', csv_sanitize_cell('a@b.com')); // '@' only matched at position 0
        $this->assertSame('', csv_sanitize_cell(null));
    }

    public function testSetUrlPreservesValueWithEquals(): void
    {
        $url = set_url("http://example.com?redirect=a=b", ["page" => "1"]);
        $this->assertStringContainsString("redirect=a=b", $url);
    }

    public function testSetUrlHandlesValuelessSegment(): void
    {
        // Must not emit a warning and must keep the flag
        $url = set_url("http://example.com?debug&x=1", ["y" => "2"]);
        $this->assertStringContainsString("debug=", $url);
        $this->assertStringContainsString("x=1", $url);
    }

    public function test_canonical_url_uses_configured_constant(): void
    {
        // Usa uma constante dedicada ao teste: SITE_CANONICAL_URL pode já vir
        // definida (vazia) pelo kernel de teste, o que dispararia o fail-closed
        // de canonical_url. Uma constante própria garante o branch "configurado".
        if (!defined('TEST_CANONICAL_URL')) {
            define('TEST_CANONICAL_URL', 'http://leggo.local');
        }
        $this->assertSame('http://leggo.local', canonical_url('TEST_CANONICAL_URL'));
    }

    public function test_redact_email_body_strips_token_urls_and_hex(): void
    {
        $html = '<a href="https://x.tld/redefinir-senha/abc123def456abc123def456abc123de">link</a> ref deadbeefdeadbeefdeadbeefdeadbeef';
        $out  = redact_email_body($html);
        $this->assertStringNotContainsString('abc123def456abc123def456abc123de', $out);
        $this->assertStringNotContainsString('deadbeefdeadbeefdeadbeefdeadbeef', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }
}
