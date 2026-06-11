<?php

namespace HelfenTalk\Connect\Support;

/**
 * Mints a user-context JWT for an already-authenticated user, so the client's
 * chat UI never has to sign tokens itself.
 *
 * The token is a standard HS256 JWT signed with the Connect secret
 * (config('helfentalk.api_key')) — the SAME secret HeflenTalk uses to verify it.
 * Claims match what HeflenTalk expects: user_id (required), name, role,
 * department (all optional). user_id is read from the column named by
 * config('helfentalk.auth.key') so it lines up with the actions menu's acting
 * user lookup.
 *
 * No third-party JWT library is required: HS256 is base64url(header).base64url(
 * payload).base64url(HMAC-SHA256), which firebase/php-jwt on HeflenTalk's side
 * verifies natively.
 */
class UserTokenIssuer
{
    /**
     * Build a signed user-context token for the given authenticated user.
     *
     * @return array{token: string, expires_in: int}|null  Null when the plugin
     *         cannot issue (no Connect secret, or the user has no id on the
     *         configured key column).
     */
    public function issue(object $user): ?array
    {
        $secret = (string) config('helfentalk.api_key', '');

        if ($secret === '')
        {
            return null;
        }

        $key = (string) config('helfentalk.auth.key', 'id');
        $userId = $user->{$key} ?? null;

        if ($userId === null || $userId === '')
        {
            return null;
        }

        $ttl = max(60, (int) config('helfentalk.token.ttl', 900));
        $now = time();

        $claims = array_filter([
            'user_id' => $userId,
            'name' => $this->name($user),
            'role' => $this->role($user),
            'department' => $this->department($user),
            'iat' => $now,
            'exp' => $now + $ttl,
        ], static fn ($value) => $value !== null);

        return [
            'token' => $this->encode($claims, $secret),
            'expires_in' => $ttl,
        ];
    }

    /**
     * The user's display name. Uses token.name_field when set, else auto-detects
     * a 'name' or 'full_name' attribute.
     */
    protected function name(object $user): ?string
    {
        $field = config('helfentalk.token.name_field');

        if (is_string($field) && $field !== '')
        {
            return $this->stringOrNull($user->{$field} ?? null);
        }

        return $this->stringOrNull($user->name ?? $user->full_name ?? null);
    }

    /**
     * The user's role. Uses token.role_field when set; otherwise auto-detects
     * Spatie's getRoleNames() (first role), then a plain 'role' attribute.
     */
    protected function role(object $user): ?string
    {
        $field = config('helfentalk.token.role_field');

        if (is_string($field) && $field !== '')
        {
            return $this->stringOrNull($user->{$field} ?? null);
        }

        if (method_exists($user, 'getRoleNames'))
        {
            $role = $user->getRoleNames()->first();

            if ($role !== null && $role !== '')
            {
                return (string) $role;
            }
        }

        return $this->stringOrNull($user->role ?? null);
    }

    /**
     * Optional department claim, only when token.department_field is configured.
     */
    protected function department(object $user): ?string
    {
        $field = config('helfentalk.token.department_field');

        if (is_string($field) && $field !== '')
        {
            return $this->stringOrNull($user->{$field} ?? null);
        }

        return null;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        return ($value === null || $value === '') ? null : (string) $value;
    }

    /**
     * HS256-encode the claims. Produces a standard JWT.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function encode(array $payload, string $secret): string
    {
        $segments = [
            $this->b64((string) json_encode(['typ' => 'JWT', 'alg' => 'HS256'])),
            $this->b64((string) json_encode($payload)),
        ];

        $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
        $segments[] = $this->b64($signature);

        return implode('.', $segments);
    }

    protected function b64(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
