<?php

namespace Tests\Unit;

use App\Scim\ScimClient;
use App\Scim\ScimPrincipalSynchronizer;
use App\Scim\ScimResourceNotFoundException;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class ScimPrincipalSynchronizerTest extends TestCase
{
    public function test_collects_one_owner_and_deduplicated_admins(): void
    {
        $references = $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['value' => 'user-owner'],
            ],
            'admins' => [
                ['value' => 'user-admin'],
                ['value' => 'user-admin'],
                ['value' => 'user-owner'],
            ],
        ]]);

        $this->assertSame(['Group 数据应用 owner'], $references['user-owner']);
        $this->assertSame(['Group 数据应用 admin'], $references['user-admin']);
        $this->assertCount(2, $references);
    }

    public function test_group_without_owner_uses_no_principal_references(): void
    {
        $references = $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'admins' => [],
        ]]);

        $this->assertSame([], $references);
    }

    public function test_rejects_group_with_multiple_owners(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('实际 2 名');

        $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['value' => 'user-owner-1'],
                ['value' => 'user-owner-2'],
            ],
        ]]);
    }

    public function test_rejects_reference_without_value(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('用户引用缺少 value');

        $this->collectReferences([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['display' => '050668'],
            ],
        ]]);
    }

    public function test_missing_principal_reports_user_group_and_role(): void
    {
        $client = new class extends ScimClient {
            public function __construct()
            {
            }

            public function getUserById(string $id): array
            {
                throw new ScimResourceNotFoundException('SCIM 用户不存在');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'SCIM 负责人或协管用户不存在: missing-user (Group 数据应用 owner)'
        );

        (new ScimPrincipalSynchronizer())->sync([[
            'id' => 'group-1',
            'displayName' => '数据应用',
            'owners' => [
                ['value' => 'missing-user'],
            ],
        ]], [], $client);
    }

    private function collectReferences(array $groups): array
    {
        $method = new ReflectionMethod(ScimPrincipalSynchronizer::class, 'collectReferences');
        $method->setAccessible(true);
        return $method->invoke(new ScimPrincipalSynchronizer(), $groups);
    }
}
