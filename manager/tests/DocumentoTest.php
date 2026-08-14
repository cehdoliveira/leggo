<?php

declare(strict_types=1);

require_once __DIR__ . '/../app/inc/lists.php';

use PHPUnit\Framework\TestCase;

final class DocumentoTest extends TestCase
{
    /**
     * @dataProvider validCpfProvider
     */
    public function test_validate_cpf_accepts_valid_documents(string $cpf): void
    {
        $this->assertTrue(validate_cpf($cpf), "Esperava que '$cpf' fosse um CPF valido");
    }

    public static function validCpfProvider(): array
    {
        return [
            ['529.982.247-25'],
            ['52998224725'],
            ['111.444.777-35'],
            ['11144477735'],
        ];
    }

    /**
     * @dataProvider invalidCpfProvider
     */
    public function test_validate_cpf_rejects_invalid_documents(?string $cpf): void
    {
        $this->assertFalse(validate_cpf($cpf), 'Esperava que o CPF fosse invalido');
    }

    public static function invalidCpfProvider(): array
    {
        return [
            ['123.456.789-00'],
            ['111.111.111-11'],
            ['00000000000'],
            ['1234567890'],
            ['123456789012'],
            [''],
            ['abc.def.ghi-jk'],
            [null],
        ];
    }

    /**
     * @dataProvider validCnpjProvider
     */
    public function test_validate_cnpj_accepts_valid_documents(string $cnpj): void
    {
        $this->assertTrue(validate_cnpj($cnpj), "Esperava que '$cnpj' fosse um CNPJ valido");
    }

    public static function validCnpjProvider(): array
    {
        return [
            ['11.222.333/0001-81'],
            ['11222333000181'],
        ];
    }

    /**
     * @dataProvider invalidCnpjProvider
     */
    public function test_validate_cnpj_rejects_invalid_documents(?string $cnpj): void
    {
        $this->assertFalse(validate_cnpj($cnpj), 'Esperava que o CNPJ fosse invalido');
    }

    public static function invalidCnpjProvider(): array
    {
        return [
            ['11.222.333/0001-82'],
            ['11111111111111'],
            ['1122233300018'],
            [''],
            [null],
        ];
    }
}
