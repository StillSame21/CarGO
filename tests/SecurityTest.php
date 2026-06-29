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
}
