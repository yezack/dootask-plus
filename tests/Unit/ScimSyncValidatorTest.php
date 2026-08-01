<?php

namespace Tests\Unit;

use App\Scim\ScimSyncValidator;
use PHPUnit\Framework\TestCase;

class ScimSyncValidatorTest extends TestCase
{
    private const ENTERPRISE = 'urn:ietf:params:scim:schemas:extension:enterprise:2.0:Group';

    public function test_accepts_consistent_organization_group_and_user_resources(): void
    {
        $result = (new ScimSyncValidator())->validate(
            [['id' => 'org-1', 'displayName' => '松江分局']],
            [[
                'id' => 'group-1',
                'displayName' => '数据应用',
                self::ENTERPRISE => ['organization' => 'org-1'],
                'owners' => [['value' => 'user-1']],
                'admins' => [],
            ]],
            [[
                'id' => 'user-1',
                'active' => true,
                'emails' => [['value' => 'user@example.com', 'primary' => true]],
                'groups' => [['value' => 'group-1', 'type' => 'direct']],
            ]],
            'user@example.com'
        );

        $this->assertTrue($result['valid']);
        $this->assertSame(1, $result['organizations']);
        $this->assertSame(1, $result['groups']);
        $this->assertSame(1, $result['users']);
        $this->assertSame([], $result['errors']);
    }

    public function test_reports_missing_organization_and_direct_group(): void
    {
        $result = (new ScimSyncValidator())->validate(
            [],
            [[
                'id' => 'group-1',
                self::ENTERPRISE => ['organization' => 'org-missing'],
                'owners' => [],
            ]],
            [[
                'id' => 'user-1',
                'active' => true,
                'emails' => [['value' => 'user@example.com', 'primary' => true]],
                'groups' => [['value' => 'group-missing', 'type' => 'direct']],
            ]]
        );

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn(string $error) => str_contains($error, 'Organization 不存在')));
        $this->assertTrue(collect($result['errors'])->contains(fn(string $error) => str_contains($error, 'direct Group 不存在')));
    }

    public function test_rejects_inactive_principal_and_missing_target_email(): void
    {
        $result = (new ScimSyncValidator())->validate(
            [['id' => 'org-1']],
            [[
                'id' => 'group-1',
                self::ENTERPRISE => ['organization' => 'org-1'],
                'owners' => [['value' => 'user-1']],
            ]],
            [[
                'id' => 'user-1',
                'active' => false,
                'emails' => [['value' => 'user@example.com', 'primary' => true]],
            ]],
            'missing@example.com'
        );

        $this->assertFalse($result['valid']);
        $this->assertTrue(collect($result['errors'])->contains(fn(string $error) => str_contains($error, '引用的用户未启用')));
        $this->assertContains('SCIM 中未找到邮箱用户: missing@example.com', $result['errors']);
    }
}
