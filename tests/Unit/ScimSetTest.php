<?php

namespace Tests\Unit;

use App\Scim\ScimSet;
use InvalidArgumentException;
use Tests\TestCase;

class ScimSetTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config([
            'dootask.scim.server_url' => 'https://auth.example.com',
            'dootask.scim.issuer' => '',
            'dootask.scim.client_id' => 'dootask',
        ]);
    }

    public function test_parse_validates_uniauth_set_claims(): void
    {
        $set = ScimSet::parse($this->token());

        $this->assertSame('/Users/user-1', $set['sub_id']['uri']);
        $this->assertArrayHasKey('urn:ietf:params:scim:event:prov:patch:notice', $set['events']);
    }

    public function test_parse_rejects_wrong_audience(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('audience');

        ScimSet::parse($this->token(['aud' => 'other-client']));
    }

    public function test_parse_rejects_wrong_issuer(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('issuer');

        ScimSet::parse($this->token(['iss' => 'https://other.example.com']));
    }

    public function test_parse_rejects_expired_token(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('过期');

        ScimSet::parse($this->token(['exp' => time() - 1]));
    }

    public function test_parse_rejects_future_issued_at(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('签发时间');

        ScimSet::parse($this->token(['iat' => time() + 120]));
    }

    public function test_parse_rejects_missing_expiry(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('过期');

        ScimSet::parse($this->token(['exp' => null]));
    }

    private function token(array $override = []): string
    {
        $payload = array_merge([
            'iss' => 'https://auth.example.com',
            'aud' => 'dootask',
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => 'event-1',
            'sub_id' => ['format' => 'scim', 'uri' => '/Users/user-1'],
            'events' => ['urn:ietf:params:scim:event:prov:patch:notice' => []],
        ], $override);

        return $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'secevent+jwt'])) . '.'
            . $this->base64Url(json_encode($payload)) . '.signature';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
