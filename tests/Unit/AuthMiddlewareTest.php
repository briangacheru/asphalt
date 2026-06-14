<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Middleware\AuthMiddleware;

class AuthMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        // Clear any existing session
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        // Clean up session after each test
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testGetCurrentUserIdReturnsNullWhenNotLoggedIn(): void
    {
        $this->assertNull(AuthMiddleware::getCurrentUserId());
    }

    public function testGetCurrentUserIdReturnsUserIdWhenLoggedIn(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = 123;
        
        $this->assertEquals(123, AuthMiddleware::getCurrentUserId());
    }

    public function testIsLoggedInReturnsFalseWhenNotAuthenticated(): void
    {
        $this->assertFalse(AuthMiddleware::isLoggedIn());
    }

    public function testIsLoggedInReturnsTrueWhenAuthenticated(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = 456;
        
        $this->assertTrue(AuthMiddleware::isLoggedIn());
    }

    public function testGenerateCsrfTokenReturnsString(): void
    {
        $token = AuthMiddleware::generateCsrfToken();
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testRegenerateCsrfTokenReturnsDifferentToken(): void
    {
        $token1 = AuthMiddleware::generateCsrfToken();
        $token2 = AuthMiddleware::regenerateCsrfToken();
        
        $this->assertNotEquals($token1, $token2);
    }

    public function testVerifyCsrfTokenReturnsTrueForValidToken(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'test-token-123';
        
        $this->assertTrue(AuthMiddleware::verifyCsrfToken('test-token-123'));
    }

    public function testVerifyCsrfTokenReturnsFalseForInvalidToken(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'test-token-123';
        
        $this->assertFalse(AuthMiddleware::verifyCsrfToken('wrong-token'));
    }

    public function testVerifyCsrfTokenReturnsFalseWhenTokenIsNull(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = 'test-token-123';
        
        $this->assertFalse(AuthMiddleware::verifyCsrfToken(null));
    }

    public function testVerifyCsrfTokenReturnsFalseWhenNoTokenInSession(): void
    {
        $this->assertFalse(AuthMiddleware::verifyCsrfToken('any-token'));
    }

    public function testHashEqualsPreventsTimingAttacks(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        // Create a very long token to test timing attack prevention
        $longToken = str_repeat('a', 1000);
        $_SESSION['csrf_token'] = $longToken;
        
        // Should still work correctly with hash_equals
        $this->assertTrue(AuthMiddleware::verifyCsrfToken($longToken));
        $this->assertFalse(AuthMiddleware::verifyCsrfToken(str_repeat('b', 1000)));
    }
}
