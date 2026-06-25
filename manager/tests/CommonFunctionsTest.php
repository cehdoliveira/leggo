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

    public function test_redact_email_body_strips_token_urls_and_hex(): void
    {
        $html = '<a href="https://x.tld/redefinir-senha/abc123def456abc123def456abc123de">link</a> ref deadbeefdeadbeefdeadbeefdeadbeef';
        $out  = redact_email_body($html);
        $this->assertStringNotContainsString('abc123def456abc123def456abc123de', $out);
        $this->assertStringNotContainsString('deadbeefdeadbeefdeadbeefdeadbeef', $out);
        $this->assertStringContainsString('[REDACTED]', $out);
    }
}
