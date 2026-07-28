<?php

namespace Tests\Unit;

use App\Services\UniAuthService;
use PHPUnit\Framework\TestCase;

class UniAuthServiceTest extends TestCase
{
    public function test_normalize_return_path_accepts_relative_and_same_origin_urls(): void
    {
        $origin = 'https://dootask.example.com';

        $this->assertSame('/project/1?tab=task#today', UniAuthService::normalizeReturnPath(
            '/project/1?tab=task#today',
            $origin
        ));
        $this->assertSame('/project/1?tab=task#today', UniAuthService::normalizeReturnPath(
            'https://dootask.example.com/project/1?tab=task#today',
            $origin
        ));
    }

    public function test_normalize_return_path_rejects_external_and_protocol_relative_urls(): void
    {
        $origin = 'https://dootask.example.com';

        $this->assertSame('', UniAuthService::normalizeReturnPath('//evil.example.com/path', $origin));
        $this->assertSame('', UniAuthService::normalizeReturnPath('https://evil.example.com/path', $origin));
        $this->assertSame('', UniAuthService::normalizeReturnPath('https://dootask.example.com:444/path', $origin));
        $this->assertSame('', UniAuthService::normalizeReturnPath('https://user@dootask.example.com/path', $origin));
        $this->assertSame('', UniAuthService::normalizeReturnPath('/\\evil.example.com/path', $origin));
    }

    public function test_extract_explicit_scim_email_prefers_valid_primary_email(): void
    {
        $email = UniAuthService::extractExplicitScimEmail([
            'emails' => [
                ['value' => 'secondary@example.com'],
                ['value' => 'primary@example.com', 'primary' => true],
            ],
            'userName' => 'fallback',
        ]);

        $this->assertSame('primary@example.com', $email);
    }

    public function test_extract_explicit_scim_email_rejects_invalid_values_and_username_fallback(): void
    {
        $this->assertSame('', UniAuthService::extractExplicitScimEmail([
            'emails' => [
                ['value' => 'not-an-email'],
                ['value' => ''],
            ],
            'userName' => '066182',
        ]));
        $this->assertSame('', UniAuthService::extractExplicitScimEmail([
            'userName' => 'user@example.com',
        ]));
    }

    public function test_extract_explicit_scim_email_rejects_ambiguous_emails(): void
    {
        $this->assertSame('', UniAuthService::extractExplicitScimEmail([
            'emails' => [
                ['value' => 'first@example.com'],
                ['value' => 'second@example.com'],
            ],
        ]));
        $this->assertSame('', UniAuthService::extractExplicitScimEmail([
            'emails' => [
                ['value' => 'first@example.com', 'primary' => true],
                ['value' => 'second@example.com', 'primary' => true],
            ],
        ]));
    }

    public function test_configuration_errors_reports_incomplete_configuration(): void
    {
        $errors = UniAuthService::configurationErrors([
            'scim_server_url' => '',
            'scim_client_id' => '',
            'scim_client_secret' => '',
            'issuer' => '',
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'allow_insecure_http' => false,
            'cache_store' => '',
            'scopes' => 'profile email',
            'http_timeout' => 0,
            'jwks_cache_seconds' => 0,
            'state_ttl_seconds' => 0,
            'ticket_ttl_seconds' => 0,
        ]);

        $this->assertContains('scim_server_url', $errors);
        $this->assertContains('scim_client_id', $errors);
        $this->assertContains('scim_client_secret', $errors);
        $this->assertContains('issuer', $errors);
        $this->assertContains('client_id', $errors);
        $this->assertContains('client_secret', $errors);
        $this->assertContains('redirect_uri', $errors);
        $this->assertContains('cache_store', $errors);
        $this->assertContains('scopes', $errors);
        $this->assertContains('ticket_ttl_seconds', $errors);
    }
}
