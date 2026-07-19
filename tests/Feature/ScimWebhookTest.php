<?php

namespace Tests\Feature;

use Tests\TestCase;

class ScimWebhookTest extends TestCase
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

    public function test_webhook_rejects_requests_when_secret_is_not_configured(): void
    {
        config(['dootask.scim.webhook_secret' => '']);

        $this->call('POST', '/api/scim/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/secevent+jwt',
        ], $this->setToken())->assertStatus(503)
            ->assertJson(['error' => 'webhook not configured']);
    }

    public function test_webhook_rejects_invalid_signature(): void
    {
        config(['dootask.scim.webhook_secret' => 'secret']);

        $this->call('POST', '/api/scim/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/secevent+jwt',
            'HTTP_X_SCIM_EVENT_SIGNATURE' => 'invalid',
        ], $this->setToken())->assertStatus(401)
            ->assertJson(['error' => 'invalid signature']);
    }

    public function test_webhook_accepts_valid_set_without_user_event(): void
    {
        config(['dootask.scim.webhook_secret' => 'secret']);
        $token = $this->setToken(['urn:example:event' => []]);
        $signature = hash_hmac('sha256', $token, 'secret');

        $this->call('POST', '/api/scim/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/secevent+jwt',
            'HTTP_X_SCIM_EVENT_SIGNATURE' => $signature,
        ], $token)->assertOk()
            ->assertJson(['received' => true]);
    }

    public function test_webhook_rejects_body_changed_after_signing(): void
    {
        config(['dootask.scim.webhook_secret' => 'secret']);
        $token = $this->setToken(['urn:example:event' => []]);
        $signature = hash_hmac('sha256', $token, 'secret');

        $this->call('POST', '/api/scim/webhook', [], [], [], [
            'CONTENT_TYPE' => 'application/secevent+jwt',
            'HTTP_X_SCIM_EVENT_SIGNATURE' => $signature,
        ], $token . "\n")->assertStatus(401)
            ->assertJson(['error' => 'invalid signature']);
    }

    public function test_webhook_ignores_replayed_set(): void
    {
        config(['dootask.scim.webhook_secret' => 'secret']);
        $token = $this->setToken(['urn:example:event' => []]);
        $signature = hash_hmac('sha256', $token, 'secret');
        $server = [
            'CONTENT_TYPE' => 'application/secevent+jwt',
            'HTTP_X_SCIM_EVENT_SIGNATURE' => $signature,
        ];

        $this->call('POST', '/api/scim/webhook', [], [], [], $server, $token)->assertOk();
        $this->call('POST', '/api/scim/webhook', [], [], [], $server, $token)
            ->assertOk()
            ->assertJson(['received' => true, 'duplicate' => true]);
    }

    private function setToken(array $events = []): string
    {
        $header = ['alg' => 'RS256', 'typ' => 'secevent+jwt'];
        $payload = [
            'iss' => 'https://auth.example.com',
            'aud' => 'dootask',
            'iat' => time(),
            'exp' => time() + 3600,
            'jti' => bin2hex(random_bytes(16)),
            'sub_id' => ['format' => 'scim', 'uri' => '/Users/user-1'],
            'events' => $events,
        ];

        return $this->base64Url(json_encode($header)) . '.'
            . $this->base64Url(json_encode($payload)) . '.signature';
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
