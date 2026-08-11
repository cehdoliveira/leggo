<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Cobre resolve_ordenation() e ordenation_header() (plano 003) — a allowlist
 * que impede injecao no ORDER BY e a alternancia clique-a-clique dos
 * cabecalhos.
 */
final class OrdenationTest extends TestCase
{
    private const ALLOWED = ['name', 'slug', 'created_at'];

    public function testDefaultWhenParamIsAbsent(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation(null, self::ALLOWED));
    }

    public function testDecomposesColumnAndDirection(): void
    {
        $this->assertSame(['slug', 'desc'], resolve_ordenation('slug-desc', self::ALLOWED));
        $this->assertSame(['created_at', 'asc'], resolve_ordenation('created_at-asc', self::ALLOWED));
    }

    public function testColumnOutsideAllowlistFallsBackToDefault(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation('password-asc', self::ALLOWED));
    }

    public function testInjectionAttemptFallsBackToDefault(): void
    {
        $malicious = "name-asc,(select 1 from users)";
        $this->assertSame(['name', 'asc'], resolve_ordenation($malicious, self::ALLOWED));

        $this->assertSame(['name', 'asc'], resolve_ordenation("name asc; drop table users", self::ALLOWED));
        $this->assertSame(['name', 'asc'], resolve_ordenation("name-asc'", self::ALLOWED));
    }

    public function testUnknownDirectionFallsBackToDefault(): void
    {
        $this->assertSame(['name', 'asc'], resolve_ordenation('slug-sideways', self::ALLOWED));
    }

    public function testCustomDefaults(): void
    {
        $this->assertSame(['created_at', 'desc'], resolve_ordenation(null, self::ALLOWED, 'created_at', 'desc'));
    }

    public function testHeaderOfTheActiveColumnOffersTheOppositeDirection(): void
    {
        $this->assertSame(['name-desc', 'bi bi-caret-up-fill'], ordenation_header('name', 'name', 'asc'));
        $this->assertSame(['name-asc', 'bi bi-caret-down-fill'], ordenation_header('name', 'name', 'desc'));
    }

    public function testHeaderOfOtherColumnsOffersAscAndNeutralIcon(): void
    {
        $this->assertSame(['slug-asc', 'bi bi-arrow-down-up'], ordenation_header('slug', 'name', 'asc'));
    }
}
