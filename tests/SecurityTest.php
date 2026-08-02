<?php

namespace Tests;

use PHPUnit\Framework\TestCase;

class SecurityTest extends TestCase
{
    protected function setUp(): void
    {
        // Enforce session arrays initialization for CSRF assertions
        if (session_status() !== PHP_SESSION_ACTIVE) {
            $_SESSION = [];
        }
    }

    public function testRegenerateCsrfTokenCreatesToken(): void
    {
        $token = regenerateCsrfToken();
        $this->assertNotEmpty($token);
        $this->assertSame(64, strlen($token));
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    public function testIsValidCsrfToken(): void
    {
        $token = regenerateCsrfToken();
        $this->assertTrue(isValidCsrfToken($token));
        $this->assertFalse(isValidCsrfToken('invalid-token-string'));
        $this->assertFalse(isValidCsrfToken(null));
    }

    public function testIsDemoSessionFalseWhenUnset(): void
    {
        unset($_SESSION['is_demo']);
        $this->assertFalse(isDemoSession());
    }

    public function testIsDemoSessionFalseWhenNotStrictlyTrue(): void
    {
        // Only a strict `true` marks a demo session -- a truthy-but-wrong
        // type (e.g. a stray string) must not grant demo status.
        $_SESSION['is_demo'] = 'true';
        $this->assertFalse(isDemoSession());
        unset($_SESSION['is_demo']);
    }

    public function testIsDemoSessionTrueWhenSet(): void
    {
        $_SESSION['is_demo'] = true;
        $this->assertTrue(isDemoSession());
        unset($_SESSION['is_demo']);
    }

    // blockDemoWrite()'s header()+exit redirect isn't observable from plain CLI
    // PHPUnit; its check (isDemoSession()) is covered above instead.
}
