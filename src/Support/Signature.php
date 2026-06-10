<?php

namespace HelfenTalk\Connect\Support;

/**
 * HMAC-SHA256 signature, byte-identical to HeflenTalk-be's ConnectSignature.
 * Signs "{timestamp}.{rawBody}" with the shared Connect secret.
 */
class Signature
{
    public static function expected(string $secret, string $timestamp, string $body): string
    {
        return hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $body, string $signature): bool
    {
        return hash_equals(self::expected($secret, $timestamp, $body), $signature);
    }
}
