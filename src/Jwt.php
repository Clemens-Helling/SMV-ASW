<?php

declare(strict_types=1);

namespace Smv;

/**
 * Minimales JWT (nur HS256), kompatibel zu den bisher von python-jose ausgestellten Tokens.
 * Der Algorithmus ist fest verdrahtet; "alg" aus dem Header wird nie zur Auswahl benutzt.
 */
final class Jwt
{
    /** @param array<string, mixed> $claims */
    public static function encode(array $claims, string $secret): string
    {
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $payload = self::b64(json_encode($claims, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $signature = self::b64(hash_hmac('sha256', $header . '.' . $payload, $secret, true));
        return $header . '.' . $payload . '.' . $signature;
    }

    /**
     * @return array<string, mixed>|null Claims oder null, wenn Token ungültig/abgelaufen ist
     */
    public static function decode(string $token, string $secret, ?int $now = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $s] = $parts;

        $header = self::jsonObject($h);
        if ($header === null || ($header['alg'] ?? null) !== 'HS256') {
            return null;
        }

        $expected = self::b64(hash_hmac('sha256', $h . '.' . $p, $secret, true));
        if (!hash_equals($expected, $s)) {
            return null;
        }

        $claims = self::jsonObject($p);
        if ($claims === null || !isset($claims['exp']) || !is_int($claims['exp']) && !is_float($claims['exp'])) {
            return null;
        }
        if ($claims['exp'] <= ($now ?? time())) {
            return null;
        }
        return $claims;
    }

    private static function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /** @return array<string, mixed>|null */
    private static function jsonObject(string $b64): ?array
    {
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);
        return is_array($data) && !array_is_list($data) ? $data : null;
    }
}
