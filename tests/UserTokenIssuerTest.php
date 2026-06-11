<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\Support\UserTokenIssuer;
use Illuminate\Support\Collection;
use Orchestra\Testbench\TestCase;

class UserTokenIssuerTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        $app['config']->set('helfentalk.api_key', 'test-connect-secret');
        $app['config']->set('helfentalk.auth.key', 'uuid');
        $app['config']->set('helfentalk.token', [
            'enabled' => true,
            'ttl' => 600,
            'role_field' => null,
            'name_field' => null,
            'department_field' => null,
        ]);
    }

    /**
     * Decode a JWT we issued and assert its signature is valid against the secret.
     *
     * @return array<string, mixed>
     */
    private function decodeVerified(string $token, string $secret): array
    {
        [$h, $p, $s] = explode('.', $token);

        $expected = rtrim(strtr(base64_encode(hash_hmac('sha256', "$h.$p", $secret, true)), '+/', '-_'), '=');
        $this->assertSame($expected, $s, 'signature must verify against the secret');

        return (array) json_decode(base64_decode(strtr($p, '-_', '+/')), true);
    }

    public function test_issues_a_token_with_expected_claims(): void
    {
        $user = new TokenUser(['uuid' => 'abc-123', 'full_name' => 'Ahmad', 'role' => 'manager']);

        $issued = (new UserTokenIssuer)->issue($user);

        $this->assertSame(600, $issued['expires_in']);

        $claims = $this->decodeVerified($issued['token'], 'test-connect-secret');

        $this->assertSame('abc-123', $claims['user_id']);   // from auth.key = uuid
        $this->assertSame('Ahmad', $claims['name']);          // auto-detected full_name
        $this->assertSame('manager', $claims['role']);        // plain 'role' attribute
        $this->assertArrayNotHasKey('department', $claims);   // not configured → omitted
        $this->assertGreaterThan(time() - 5, $claims['iat']);
        $this->assertSame($claims['iat'] + 600, $claims['exp']);
    }

    public function test_auto_detects_spatie_role(): void
    {
        $user = new SpatieTokenUser(['uuid' => 'u-9', 'name' => 'Boss']);

        $claims = $this->decodeVerified((new UserTokenIssuer)->issue($user)['token'], 'test-connect-secret');

        $this->assertSame('u-9', $claims['user_id']);
        $this->assertSame('Boss', $claims['name']);
        $this->assertSame('company_admin', $claims['role']); // first Spatie role
    }

    public function test_honours_explicit_field_config(): void
    {
        config()->set('helfentalk.token.role_field', 'job_title');
        config()->set('helfentalk.token.name_field', 'display');
        config()->set('helfentalk.token.department_field', 'dept');

        $user = new TokenUser([
            'uuid' => 'x1', 'display' => 'Siti', 'job_title' => 'operator', 'dept' => 'Ops',
            // these must be ignored in favour of the configured fields
            'name' => 'WRONG', 'role' => 'WRONG',
        ]);

        $claims = $this->decodeVerified((new UserTokenIssuer)->issue($user)['token'], 'test-connect-secret');

        $this->assertSame('Siti', $claims['name']);
        $this->assertSame('operator', $claims['role']);
        $this->assertSame('Ops', $claims['department']);
    }

    public function test_returns_null_without_connect_secret(): void
    {
        config()->set('helfentalk.api_key', '');

        $this->assertNull((new UserTokenIssuer)->issue(new TokenUser(['uuid' => 'a'])));
    }

    public function test_returns_null_when_key_column_is_absent(): void
    {
        // auth.key = uuid but the user has no uuid value.
        $this->assertNull((new UserTokenIssuer)->issue(new TokenUser(['name' => 'No Id'])));
    }
}

/**
 * Minimal user stub: attributes are read as object properties.
 */
class TokenUser
{
    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value)
        {
            $this->{$key} = $value;
        }
    }
}

/**
 * Stub mimicking Spatie's HasRoles getRoleNames().
 */
class SpatieTokenUser
{
    public function __construct(array $attributes)
    {
        foreach ($attributes as $key => $value)
        {
            $this->{$key} = $value;
        }
    }

    public function getRoleNames(): Collection
    {
        return collect(['company_admin', 'manager']);
    }
}
