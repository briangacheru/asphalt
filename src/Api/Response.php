<?php

namespace App\Api;

/**
 * Minimal JSON response helper for the api/ front controller. Both methods
 * end the request — nothing in the API layer writes output any other way.
 */
class Response
{
    public static function json($data, int $status = 200): void
    {
        http_response_code($status);
        echo json_encode($data);
        exit;
    }

    public static function error(string $message, int $status = 400): void
    {
        self::json(['error' => $message], $status);
    }
}
