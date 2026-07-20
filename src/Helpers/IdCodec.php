<?php

namespace App\Helpers;

/**
 * Encodes and decodes numeric database IDs for use in URLs and forms, so raw,
 * sequential primary keys are never exposed to the client.
 *
 * This is the single source of truth for the ID token format. Every page
 * that needs to put an ID in a URL or read one back calls encode()/decode()
 * here — change the algorithm in this file only, nowhere else.
 *
 * Tokens are authenticated ciphertext (AES-256-GCM via the openssl
 * extension — chosen over libsodium because openssl ships on virtually
 * every PHP install, including shared hosting that omits sodium): without
 * ID_ENCRYPTION_KEY a token cannot be decoded, forged, or have its integer
 * value inferred, and any tampering is rejected outright.
 */
class IdCodec
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;
    private const KEY_BYTES = 32;

    private static ?string $key = null;

    /**
     * Encode a positive integer ID into a URL-safe token.
     * Returns an empty string for a non-positive/invalid ID.
     */
    public static function encode(int|string|null $id): string
    {
        $id = (int) $id;
        if ($id <= 0) {
            return '';
        }

        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $tag = '';
        $ciphertext = openssl_encrypt(
            (string) $id,
            self::CIPHER,
            self::key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            self::TAG_LENGTH
        );

        return self::base64UrlEncode($iv . $tag . $ciphertext);
    }

    /**
     * Decode a token back into its original positive integer ID.
     * Returns null if the token is missing, malformed, or fails authentication
     * (tampered, forged, or encoded with a different key).
     */
    public static function decode(mixed $token): ?int
    {
        if (!is_string($token) || $token === '') {
            return null;
        }

        $raw = self::base64UrlDecode($token);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        $headerLength = $ivLength + self::TAG_LENGTH;
        if ($raw === false || strlen($raw) <= $headerLength) {
            return null;
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $headerLength);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false || !ctype_digit($plaintext)) {
            return null;
        }

        $id = (int) $plaintext;

        return $id > 0 ? $id : null;
    }

    /**
     * Convenience wrapper for legacy call sites that used (int)($_GET['id'] ?? 0)
     * and want a non-nullable int back (0 = absent/invalid, never a real ID).
     */
    public static function decodeOrZero(mixed $token): int
    {
        return self::decode($token) ?? 0;
    }

    private static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }

        $configured = Environment::get('ID_ENCRYPTION_KEY');
        if (!$configured) {
            throw new \RuntimeException(
                'ID_ENCRYPTION_KEY is not set. Generate one with: ' .
                "php -r \"echo base64_encode(random_bytes(32)), PHP_EOL;\""
            );
        }

        $key = base64_decode((string) $configured, true);
        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException('ID_ENCRYPTION_KEY must be a base64-encoded 32-byte key.');
        }

        return self::$key = $key;
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $data): string|false
    {
        $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');

        return base64_decode(strtr($padded, '-_', '+/'), true);
    }
}
