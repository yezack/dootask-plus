<?php

namespace Tests\Unit;

use App\Scim\ScimUserMapper;
use PHPUnit\Framework\TestCase;

class ScimUserMapperTest extends TestCase
{
    public function test_extract_email_prefers_first_valid_email(): void
    {
        $email = ScimUserMapper::extractEmail([
            'userName' => '066182',
            'emails' => [
                ['value' => 'invalid'],
                ['value' => '066182@sjq.sh'],
            ],
        ]);

        $this->assertSame('066182@sjq.sh', $email);
    }

    public function test_extract_email_rejects_ambiguous_multiple_valid_emails(): void
    {
        $this->assertSame('', ScimUserMapper::extractEmail([
            'userName' => '066182',
            'emails' => [
                ['value' => '066182@sjq.sh'],
                ['value' => 'other@sjq.sh'],
            ],
        ]));
    }

    public function test_extract_email_prefers_unique_primary_email(): void
    {
        $this->assertSame('066182@sjq.sh', ScimUserMapper::extractEmail([
            'emails' => [
                ['value' => 'other@sjq.sh'],
                ['value' => '066182@sjq.sh', 'primary' => true],
            ],
        ]));
    }

    public function test_extract_primary_email_requires_exactly_one_primary(): void
    {
        $this->assertSame('066182@sjq.sh', ScimUserMapper::extractPrimaryEmail([
            'emails' => [
                ['value' => 'other@sjq.sh'],
                ['value' => '066182@sjq.sh', 'primary' => true],
            ],
        ]));
        $this->assertSame('', ScimUserMapper::extractPrimaryEmail([
            'emails' => [
                ['value' => '066182@sjq.sh'],
            ],
        ]));
        $this->assertSame('', ScimUserMapper::extractPrimaryEmail([
            'emails' => [
                ['value' => '066182@sjq.sh', 'primary' => true],
                ['value' => 'other@sjq.sh', 'primary' => true],
            ],
        ]));
    }

    public function test_extract_email_falls_back_to_default_domain(): void
    {
        $this->assertSame('066182@sjq.sh', ScimUserMapper::extractEmail([
            'userName' => '066182',
        ]));
    }

    public function test_extract_email_rejects_invalid_email_like_username(): void
    {
        $this->assertSame('', ScimUserMapper::extractEmail([
            'userName' => 'invalid@',
        ]));
    }

    public function test_extract_email_rejects_username_that_cannot_form_email(): void
    {
        $this->assertSame('', ScimUserMapper::extractEmail([
            'userName' => 'invalid user',
        ]));
    }

    public function test_map_converts_enterprise_fields(): void
    {
        $mapped = ScimUserMapper::map([
            'id' => 'external-1',
            'userName' => '066182',
            'displayName' => '唐佳辉',
            'active' => true,
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => [
                'division' => ' 警察 ',
                'title' => '中队长',
                'department' => '松江分局',
                'organization' => 'org-1',
            ],
            'urn:ietf:params:scim:schemas:extension:custom:2.0:User' => [
                'phone' => ' 13800138000 ',
            ],
            'groups' => [
                ['value' => 'group-direct', 'type' => 'direct'],
                ['value' => 'group-parent', 'type' => 'indirect'],
            ],
        ]);

        $this->assertSame('唐佳辉', $mapped['nickname']);
        $this->assertSame('警察 - 中队长', $mapped['profession']);
        $this->assertSame('松江分局', $mapped['department']);
        $this->assertSame('13800138000', $mapped['tel']);
        $this->assertNull($mapped['disable_at']);
        $this->assertSame('external-1', $mapped['_scim']['external_id']);
        $this->assertSame('org-1', $mapped['_scim']['org_id']);
        $this->assertSame(['group-direct'], $mapped['direct_group_ids']);
    }

    public function test_map_ignores_malformed_extensions(): void
    {
        $mapped = ScimUserMapper::map([
            'userName' => '066182',
            'active' => true,
            'urn:ietf:params:scim:schemas:extension:enterprise:2.0:User' => 'invalid',
            'urn:ietf:params:scim:schemas:extension:custom:2.0:User' => 'invalid',
        ]);

        $this->assertSame('066182', $mapped['nickname']);
        $this->assertNull($mapped['profession']);
        $this->assertSame('', $mapped['department']);
        $this->assertSame([], $mapped['direct_group_ids']);
        $this->assertSame('', $mapped['tel']);
    }
}
