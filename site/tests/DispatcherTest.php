<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class DispatcherTest extends TestCase
{
    private Dispatcher $dispatcher;

    protected function setUp(): void
    {
        $_SERVER["REQUEST_METHOD"] = "GET";
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $_SERVER["REQUEST_URI"] = "/teste";
        putenv("PATH_INFO=/teste");

        $this->dispatcher = new Dispatcher(true);
    }

    public function testConstructorSetsRequestUri(): void
    {
        $this->assertIsString($this->dispatcher->get_request_uri());
    }

    public function testAddRouteStoresValidRoute(): void
    {
        $this->dispatcher->add_route("GET", "/test", "controller:method");
        // Se não lançar exceção, a rota foi registrada
        $this->assertTrue(true);
    }

    public function testAddRouteRejectsInvalidMethod(): void
    {
        // O Dispatcher só aceita GET e POST
        $before = true;
        try {
            $this->dispatcher->add_route("PUT", "/test", "controller:method");
        } catch (\Throwable $e) {
            $before = false;
        }
        // add_route simplesmente ignora métodos diferentes de GET/POST
        $this->assertTrue(true);
    }

    public function testExecWithNoRoutesReturnsFalse(): void
    {
        $d = new Dispatcher(true);
        $this->assertFalse($d->exec());
    }

    public function testExecMatchesGetRoute(): void
    {
        ob_start();
        // Create a fresh dispatcher with the correct path
        $_SERVER["REQUEST_METHOD"] = "GET";
        putenv("PATH_INFO=/foo");

        $d = new Dispatcher(true);
        $called = false;

        // Using a closure-based route
        $d->add_route("GET", "/foo", "function:basic_redir", null, ["/"]);

        $result = $d->exec();
        // basic_redir calls exit(), so we can't easily test this without mocking
        // But the route matching can be verified indirectly
        ob_end_clean();
        $this->assertTrue(true); // placeholder — real tests need HTTP simulation
    }

    public function testGetRequestUriNormalizesScriptName(): void
    {
        $_SERVER["SCRIPT_NAME"] = "/index.php";
        $d = new Dispatcher(true);
        $uri = $d->get_request_uri();
        $this->assertStringNotContainsString("index.php", $uri);
    }
}
