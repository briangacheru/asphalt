<?php

namespace App\Services;

/**
 * Minimal "Sign in with Google" OAuth 2.0 flow, hand-rolled via cURL rather
 * than a Composer OAuth client library — this app avoids adding dependencies
 * that require a `composer install` re-run on deploy (same reasoning as
 * DatabaseBackupService avoiding the mysqldump binary): cURL ships with
 * virtually every PHP install.
 */
class GoogleOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const USERINFO_URL = 'https://www.googleapis.com/oauth2/v3/userinfo';

    public static function isConfigured(): bool
    {
        return GOOGLE_CLIENT_ID !== '' && GOOGLE_CLIENT_SECRET !== '';
    }

    public static function redirectUri(): string
    {
        return rtrim(APP_URL, '/') . '/auth/google-callback';
    }

    public static function getAuthUrl(string $state): string
    {
        $params = [
            'client_id' => GOOGLE_CLIENT_ID,
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'prompt' => 'select_account',
        ];

        return self::AUTH_URL . '?' . http_build_query($params);
    }

    /**
     * Exchanges an authorization code for the caller's Google profile.
     *
     * @return array{sub:string,email:string,email_verified:bool,given_name?:string,family_name?:string,name?:string}|null
     */
    public static function fetchProfile(string $code): ?array
    {
        $tokenResponse = self::post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => GOOGLE_CLIENT_ID,
            'client_secret' => GOOGLE_CLIENT_SECRET,
            'redirect_uri' => self::redirectUri(),
            'grant_type' => 'authorization_code',
        ]);

        if (!$tokenResponse || empty($tokenResponse['access_token'])) {
            return null;
        }

        $profile = self::get(self::USERINFO_URL, $tokenResponse['access_token']);
        if (!$profile || empty($profile['sub']) || empty($profile['email'])) {
            return null;
        }

        return $profile;
    }

    private static function post(string $url, array $fields): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);

        if (!$ok || $response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }

    private static function get(string $url, string $accessToken): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);
        $response = curl_exec($ch);
        $ok = curl_errno($ch) === 0;
        curl_close($ch);

        if (!$ok || $response === false) {
            return null;
        }

        $decoded = json_decode($response, true);
        return is_array($decoded) ? $decoded : null;
    }
}
