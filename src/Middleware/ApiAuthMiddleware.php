<?php

namespace App\Middleware;

use App\Api\Response;
use App\Services\ApiTokenService;

/**
 * Bearer-token authentication for the JSON API (api/index.php), separate
 * from AuthMiddleware's session-cookie auth used by the web app.
 */
class ApiAuthMiddleware
{
    /** Reads the "Authorization: Bearer <token>" header, tolerating hosts that don't pass it through to $_SERVER by default. */
    public static function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;

        if (!$header && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
        }

        if (!$header || !preg_match('/^Bearer\s+(.+)$/i', trim($header), $matches)) {
            return null;
        }

        return trim($matches[1]);
    }

    /** Validates the request's bearer token; sends a 401 JSON error and exits if missing/invalid. Returns the authenticated user_id otherwise. */
    public static function authenticate(\PDO $pdo): int
    {
        $token = self::extractToken();

        if (!$token) {
            Response::error('Missing Authorization header.', 401);
        }

        $userId = ApiTokenService::resolve($pdo, $token);

        if (!$userId) {
            Response::error('Invalid or expired token.', 401);
        }

        return $userId;
    }
}
